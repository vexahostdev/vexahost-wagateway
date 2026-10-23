<?php

namespace App\Services;

use App\Jobs\SendMessageJob;
use App\Models\Message;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\Notifications\BillingMessages;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\WhatsAppNotifier;
use App\Support\EngineError;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya pintu masuk pembuatan pesan keluar. Baik REST API, dashboard,
 * maupun OTP semuanya lewat sini supaya pencatatan, kuota, dan antrean
 * diperlakukan sama.
 */
class MessageDispatcher
{
    /**
     * `is_otp` membebaskan pesan dari penundaan masa pemanasan dan dari daftar
     * berhenti berlangganan — lihat RemPengiriman. Ia tidak disimpan.
     *
     * @param  array{type?: string, body?: ?string, media_path?: ?string, media_mime?: ?string, media_filename?: ?string, batch_id?: ?string, is_otp?: bool}  $attributes
     */
    public function queue(WaSession $session, string $to, array $attributes = []): Message
    {
        $workspace = $session->workspace;

        /*
         | Nomor milik perusahaan sendiri lewat tanpa pemeriksaan tagihan.
         |
         | Diperiksa dari SESI-nya, bukan dari workspace-nya: nomor itu bisa
         | menumpang di workspace pelanggan mana pun, dan menumpangnya tidak
         | boleh membuat seluruh workspace itu ikut bebas. Yang dibebaskan
         | nomornya, bukan tempat ia tertaut.
        */
        if (! $session->isSpecial()) {
            $this->guardWorkspace($workspace);
        }

        $normalized = PhoneNumber::normalize($to);

        if ($normalized === null && ! str_ends_with($to, '@g.us')) {
            throw new RuntimeException("Nomor tujuan tidak valid: {$to}");
        }

        $chatId = PhoneNumber::toChatId($to);

        // Rem pengiriman berjalan SEBELUM pesan dicatat dan kuota dinaikkan:
        // pesan yang ditolak di sana tidak boleh memotong kuota siapa pun.
        // Diambil dari container, bukan disuntikkan — alasannya sama dengan
        // notifier di bawah.
        $jadwal = app(RemPengiriman::class)->periksa(
            $session,
            $normalized,
            $chatId,
            (bool) ($attributes['is_otp'] ?? false),
        );

        $message = Message::create([
            'workspace_id' => $workspace->id,
            'wa_session_id' => $session->id,
            /*
             | API key yang mengantrekan pesan ini, kalau ada.
             |
             | DISIMPAN SAAT ANTRE, bukan dibaca lagi saat kirim. Pengiriman
             | terjadi di dalam job — di sana tidak ada request, jadi tidak ada
             | yang bisa ditanya "API key mana yang sedang dipakai". Tanpa kolom
             | ini, webhook `message.status` tidak punya cara tahu integrasi
             | mana yang berhak menerimanya.
             |
             | `null` berarti dikirim dari dashboard, dan itu benar: statusnya
             | jatuh ke webhook tingkat workspace.
            */
            'api_key_id' => $attributes['api_key_id'] ?? null,
            'direction' => 'outbound',
            'chat_id' => $chatId,
            'to_number' => $normalized ?? $to,
            'type' => $attributes['type'] ?? 'text',
            'body' => $attributes['body'] ?? null,
            'media_path' => $attributes['media_path'] ?? null,
            'media_mime' => $attributes['media_mime'] ?? null,
            'media_filename' => $attributes['media_filename'] ?? null,
            'batch_id' => $attributes['batch_id'] ?? null,
            'scheduled_at' => $jadwal,
            'status' => 'queued',
        ]);

        // Kuota dinaikkan saat antre, bukan saat terkirim: kalau dihitung
        // belakangan, satu workspace bisa mengantrekan puluhan ribu pesan dulu
        // lalu baru ketahuan melewati batas. Pesan yang ditunda masa pemanasan
        // ikut dihitung di sini — ia pasti terkirim, hanya belum hari ini.
        $this->incrementUsage($workspace, 'messages_sent');

        $job = SendMessageJob::dispatch($message->id);

        if ($jadwal !== null) {
            $job->delay($jadwal);
        }

        return $message;
    }

    /**
     * Mengantre banyak tujuan sekaligus. Semua pesan berbagi satu batch_id
     * supaya bisa ditelusuri dan dibatalkan sebagai satu kesatuan.
     *
     * @param  array<int, string>  $recipients
     * @return array{batch_id: string, messages: array<int, Message>, rejected: array<int, array{to: string, reason: string}>}
     */
    public function queueBulk(WaSession $session, array $recipients, array $attributes = []): array
    {
        $batchId = (string) Str::ulid();
        $messages = [];
        $rejected = [];

        foreach (array_unique($recipients) as $to) {
            try {
                $messages[] = $this->queue($session, $to, $attributes + ['batch_id' => $batchId]);
            } catch (\Throwable $e) {
                // Satu nomor rusak tidak boleh menggagalkan seluruh broadcast;
                // pemanggil tetap diberi tahu nomor mana yang ditolak.
                $rejected[] = ['to' => $to, 'reason' => EngineError::pesan($e)];
            }
        }

        return ['batch_id' => $batchId, 'messages' => $messages, 'rejected' => $rejected];
    }

    public function incrementUsage(Workspace $workspace, string $column, int $amount = 1): void
    {
        $period = now()->format('Y-m');

        // upsert + increment mentah supaya aman dari race saat banyak worker
        // memproses pesan workspace yang sama secara bersamaan.
        DB::table('usage_counters')->upsert(
            [[
                'workspace_id' => $workspace->id,
                'period' => $period,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['workspace_id', 'period'],
            ['updated_at']
        );

        DB::table('usage_counters')
            ->where('workspace_id', $workspace->id)
            ->where('period', $period)
            ->increment($column, $amount);
    }

    private function guardWorkspace(Workspace $workspace): void
    {
        if (! $workspace->isActive()) {
            /*
             | Dibedakan supaya pesan galatnya benar di kedua keadaan. Yang
             | masa berlakunya lewat perlu tahu bahwa ini soal perpanjangan,
             | bukan kerusakan — terutama karena yang membacanya sering kali
             | bukan manusia melainkan log aplikasi lain yang memanggil API
             | kami, dan di situlah kalimatnya menentukan apakah ada yang
             | menindaklanjuti atau tidak.
            */
            throw new RuntimeException($workspace->serviceExpired()
                ? 'Masa berlaku langganan workspace ini sudah habis. Perpanjang untuk mengirim lagi.'
                : 'Workspace sedang tidak aktif.');
        }

        /*
         | Workspace yang dibebaskan tidak tunduk pada batas pesan APA PUN —
         | jatah coba gratis, saldo PAYG, maupun kuota bulanan. Pemeriksaan ini
         | harus berada di depan ketiga cabang di bawah: dulu ia baru diperiksa
         | di cabang kuota bulanan, sehingga akun bebas yang workspace-nya masih
         | berpaket coba gratis tetap berhenti di pesan kelima.
        */
        if ($workspace->isExempt()) {
            return;
        }

        /*
         | Jatah coba gratis dihitung seumur hidup workspace, bukan per bulan.
         |
         | Kalau ia ikut jalur kuota bulanan di bawah, angkanya kembali penuh
         | tiap tanggal 1 dan lima pesan gratis diam-diam berubah menjadi lima
         | pesan gratis setiap bulan — selamanya, untuk siapa pun yang tidak
         | pernah membayar. Kueri tambahannya hanya berjalan untuk workspace
         | yang memang sedang memakai paket gratis; pelanggan berbayar tidak
         | membayar ongkosnya.
        */
        if ($workspace->isFreeTier()) {
            $jatah = (int) $workspace->monthly_message_quota;
            $terpakaiSeluruhnya = $workspace->freeMessagesUsed();

            if ($terpakaiSeluruhnya >= $jatah) {
                throw new RuntimeException(
                    "Jatah {$jatah} pesan coba gratis sudah habis. Pilih paket untuk melanjutkan."
                );
            }

            return;
        }

        /*
         | Cabang KETIGA penegakan kuota, setelah jatah coba gratis dan kuota
         | bulanan paket. Yang membatasi di sini bukan jumlah pesan dan bukan
         | tanggal, melainkan saldo.
         |
         | Pemotongannya TIDAK terjadi di sini — ia terjadi di `SendMessageJob`
         | setelah pesannya benar-benar terkirim. Pesan yang gagal karena
         | nomornya tidak terdaftar tidak boleh memotong saldo pelanggan.
         |
         | Konsekuensinya diterima sadar: broadcast besar bisa membuat saldo
         | minus sedikit, karena beberapa pesan berjalan bersamaan dan
         | masing-masing sudah lolos pemeriksaan ini. Itu sebabnya saldo
         | diperiksa dua kali — di sini saat antre, dan sekali lagi saat kirim.
        */
        if ($workspace->isPayg()) {
            $harga = (int) config('billing.payg.price_per_message');

            if ((int) $workspace->balance < $harga) {
                throw new RuntimeException(
                    'Saldo tidak cukup untuk mengirim pesan. Isi saldo dari menu Saldo di dashboard.'
                );
            }

            $this->warnIfBalanceLow($workspace);

            return;
        }

        $kuota = (int) $workspace->monthly_message_quota;

        if ($kuota === 0) {
            return;
        }

        /*
         | Pemeriksaan kuota ditulis di sini alih-alih memanggil
         | `hasQuotaRemaining()`, karena baris pemakaiannya dibutuhkan dua kali:
         | untuk menolak saat penuh, dan untuk memutuskan perlu-tidaknya
         | peringatan. Memanggil keduanya berarti dua kueri pada tabel yang
         | paling sering ditulis di sistem ini, untuk setiap pesan yang dikirim.
         |
         | `hasQuotaRemaining()` tetap ada dan tetap dipakai di tempat lain yang
         | hanya butuh jawabannya.
        */
        $terpakai = (int) $workspace->currentUsage()->messages_sent;

        if ($terpakai >= $kuota) {
            throw new RuntimeException(
                "Kuota pesan bulan ini sudah habis ({$kuota} pesan)."
            );
        }

        $this->warnIfQuotaLow($workspace, $terpakai + 1, $kuota);
    }

    /**
     * Memberi tahu saat kuota mendekati dan mencapai batasnya.
     *
     * Kuota yang habis tanpa peringatan terasa seperti kerusakan, bukan seperti
     * batas paket: pengiriman berhenti di tengah hari kerja dan pemiliknya baru
     * tahu dari pelanggan yang tidak menerima apa-apa. Satu pesan di 80% memberi
     * ruang memutuskan sebelum berhenti terjadi.
     *
     * Ini berjalan pada setiap pesan, jadi urutannya sengaja: yang paling murah
     * diperiksa lebih dulu, dan `WhatsAppNotifier` — yang memegang penanda "sudah
     * pernah dikirim" di cache — baru disentuh setelah ambangnya benar terlampaui.
     * Notifier-nya diambil dari container di sini, bukan lewat constructor,
     * karena ia sendiri memakai `MessageDispatcher` dan menyuntikkannya akan
     * menutup lingkaran.
     */
    /**
     * Memberi tahu saat saldo menipis dan saat habis.
     *
     * Ambangnya 20% dari satu kali isi saldo minimum, bukan persentase dari
     * saldo itu sendiri: "20% sisa" tidak punya arti kalau yang diisi orang
     * berbeda-beda jumlahnya. Yang berarti bagi pelanggan adalah berapa pesan
     * lagi yang bisa ia kirim.
     *
     * Notifier diambil dari container di dalam method, bukan lewat constructor,
     * karena `WhatsAppNotifier` sendiri memakai `MessageDispatcher` — menyuntikkannya
     * menutup lingkaran dan mematikan seluruh pengiriman pesan.
     */
    private function warnIfBalanceLow(Workspace $workspace): void
    {
        if (blank($workspace->billing_phone)) {
            return;
        }

        $harga = (int) config('billing.payg.price_per_message');
        $ambang = (int) (config('billing.payg.min_topup') * 0.2);
        $saldo = (int) $workspace->balance;

        // Saldo sesudah pesan ini terkirim — itu angka yang benar untuk
        // diperingatkan, bukan saldo sebelumnya.
        $sesudah = $saldo - $harga;

        $tingkat = match (true) {
            $sesudah < $harga => 'habis',
            $sesudah <= $ambang => 'hampir',
            default => null,
        };

        if ($tingkat === null) {
            return;
        }

        app(Notifier::class)->keWorkspace(
            workspace: $workspace,
            type: $tingkat === 'habis' ? 'balance.exhausted' : 'balance.low',
            title: $tingkat === 'habis'
                ? 'Saldo habis'
                : 'Saldo menipis — sisa sekitar '.number_format(intdiv($sesudah, $harga), 0, ',', '.').' pesan',
            body: $tingkat === 'habis'
                ? 'Pengiriman berhenti sampai saldo diisi. Nomor Anda tetap tertaut dan pesan masuk tetap diterima.'
                : 'Sisa saldo Rp '.number_format(max(0, $sesudah), 0, ',', '.').'.',
            url: route('balance.index'),
            level: $tingkat === 'habis' ? 'danger' : 'warning',
            dedupe: "balance:{$tingkat}:".intdiv($saldo, max($ambang, 1)),
        );

        app(WhatsAppNotifier::class)->toWorkspace(
            $workspace,
            $tingkat === 'habis'
                ? BillingMessages::balanceExhausted($workspace)
                : BillingMessages::balanceLow($workspace, $sesudah, intdiv($sesudah, $harga)),
            // Penandanya memuat ambangnya, bukan tanggal: saldo yang diisi
            // ulang lalu menipis lagi harus diperingatkan lagi, dan penanda
            // per bulan membuat peringatan kedua tidak pernah keluar.
            "balance:{$tingkat}:{$workspace->id}:".intdiv($saldo, max($ambang, 1)),
            24 * 40,
        );
    }

    private function warnIfQuotaLow(Workspace $workspace, int $terpakai, int $kuota): void
    {
        if (blank($workspace->billing_phone)) {
            return;
        }

        $tingkat = match (true) {
            $terpakai >= $kuota => 'habis',
            $terpakai >= (int) ($kuota * 0.8) => 'hampir',
            default => null,
        };

        if ($tingkat === null) {
            return;
        }

        $periode = now()->format('Y-m');

        app(WhatsAppNotifier::class)->toWorkspace(
            $workspace,
            $tingkat === 'habis'
                ? BillingMessages::quotaExhausted($workspace, $kuota)
                : BillingMessages::quotaWarning($workspace, $terpakai, $kuota),
            "quota:{$tingkat}:{$workspace->id}:{$periode}",
            24 * 40,
        );

        // Lonceng ikut, dengan penanda yang sama persis. Notifier diambil dari
        // container di sini karena alasan yang sama dengan WhatsAppNotifier —
        // apa pun yang dipakai MessageDispatcher tidak boleh disuntikkan lewat
        // constructor, kalau tidak lingkarannya menutup.
        app(Notifier::class)->keWorkspace(
            workspace: $workspace,
            type: $tingkat === 'habis' ? 'quota.exhausted' : 'quota.low',
            title: $tingkat === 'habis'
                ? 'Kuota pesan bulan ini habis'
                : 'Kuota pesan tersisa '.number_format(max(0, $kuota - $terpakai), 0, ',', '.'),
            body: $tingkat === 'habis'
                ? 'Pengiriman berhenti sampai kuota bulan depan. Nomor Anda tetap tertaut dan pesan masuk tetap diterima.'
                : 'Sudah terpakai '.number_format($terpakai, 0, ',', '.').' dari '
                    .number_format($kuota, 0, ',', '.').' pesan bulan ini.',
            url: route('billing.plans'),
            level: $tingkat === 'habis' ? 'danger' : 'warning',
            dedupe: "quota:{$tingkat}:{$periode}",
        );
    }
}
