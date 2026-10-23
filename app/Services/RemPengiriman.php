<?php

namespace App\Services;

use App\Jobs\SendMessageJob;
use App\Models\Message;
use App\Models\OptOut;
use App\Models\WaSession;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rem untuk pola pengiriman yang membuat nomor dibatasi WhatsApp.
 *
 * Semua nomor pelanggan keluar dari server yang sama, jadi satu nomor yang
 * dipakai ceroboh ikut memperburuk nasib nomor lain. Rem di sini menahan tiga
 * pola yang paling sering berujung blokir — nomor baru yang langsung dipakai
 * kirim massal, dua sistem yang saling membalas otomatis, dan pesan ke orang
 * yang sudah meminta berhenti — tanpa menghalangi pemakaian sah:
 *
 *  - **Tidak ada pesan yang hilang diam-diam.** Yang melewati batas pemanasan
 *    dijadwalkan, bukan dibuang. Yang ditolak ditolak SEBELUM tercatat,
 *    dengan alasan yang bisa dibaca, dan tidak memotong kuota.
 *  - **OTP dan balasan ke orang yang baru menghubungi tidak pernah ditunda.**
 *    Keduanya ditunggu penerimanya dan hampir tidak pernah dilaporkan; menunda
 *    OTP sampai besok sama dengan menggagalkannya.
 *  - **Kuota paket tetap bisa terpakai penuh.** Batas pemanasan hanya berlaku
 *    30 hari pertama sebuah nomor di gateway ini, dan pemiliknya bisa
 *    melewatinya untuk nomor yang sudah lama aktif.
 */
class RemPengiriman
{
    /**
     * @return Carbon|null waktu kirim kalau pesannya ditunda masa pemanasan;
     *                     null berarti langsung masuk antrean
     *
     * @throws RuntimeException pesan yang ditolak
     */
    public function periksa(WaSession $session, ?string $nomor, string $chatId, bool $otp = false): ?Carbon
    {
        $this->remBalasBalasan($session, $chatId);

        if ($nomor !== null && ! $otp) {
            $this->tolakYangBerhenti($session, $nomor);
        }

        return $this->jadwalPemanasan($session, $nomor, $otp);
    }

    /**
     * Membaca balasan masuk sebagai permintaan berhenti atau berlangganan lagi.
     */
    public function catatJawaban(WaSession $session, string $nomor, ?string $isi): void
    {
        $kata = $this->kataKunci($isi);

        if ($kata === null) {
            return;
        }

        if (in_array($kata, config('gateway.rem.kata_berhenti'), true)) {
            // `touch()` supaya STOP yang diulang setelah sempat berlangganan
            // lagi memperbarui waktunya — waktu itu yang menentukan balasan
            // mana yang terhitung "menghubungi lagi".
            OptOut::updateOrCreate(
                ['workspace_id' => $session->workspace_id, 'phone' => $nomor],
                ['keyword' => $kata],
            )->touch();

            return;
        }

        if (in_array($kata, config('gateway.rem.kata_mulai'), true)) {
            OptOut::where('workspace_id', $session->workspace_id)->where('phone', $nomor)->delete();
        }
    }

    /**
     * Keadaan masa pemanasan untuk ditampilkan; null kalau tidak sedang berlaku.
     *
     * @return array{hari: int, sampai: int, batas: int, terpakai: int, tertunda: int}|null
     */
    public function statusPemanasan(WaSession $session): ?array
    {
        if (! $this->dalamPemanasan($session)) {
            return null;
        }

        $hariIni = now()->startOfDay();

        return [
            'hari' => $this->hariKe($session, $hariIni),
            'sampai' => $this->lamaPemanasan(),
            'batas' => $this->batasHarian($session, $hariIni),
            'terpakai' => (int) DB::table('warmup_counters')
                ->where('wa_session_id', $session->id)
                ->where('day', $hariIni->toDateString())
                ->value('sent'),
            'tertunda' => Message::where('wa_session_id', $session->id)
                ->where('status', 'queued')
                ->where('scheduled_at', '>', now())
                ->count(),
        ];
    }

    /**
     * Pemilik menyatakan nomornya sudah lama aktif. Pesan yang sudah
     * dijadwalkan ikut dilepas sekarang — kalau tidak, melewati pemanasan
     * baru terasa besok pagi, dan tombolnya terlihat tidak bekerja.
     *
     * @return int jumlah pesan terjadwal yang dilepas
     */
    public function lewatiPemanasan(WaSession $session): int
    {
        $session->forceFill(['warmup_skipped_at' => now()])->save();

        $dilepas = 0;

        Message::where('wa_session_id', $session->id)
            ->where('status', 'queued')
            ->where('scheduled_at', '>', now())
            ->select('id')
            ->chunkById(500, function ($pesan) use (&$dilepas): void {
                $ids = $pesan->pluck('id');

                Message::whereIn('id', $ids)->update(['scheduled_at' => null]);

                // Job tertunda yang lama tetap ada di antrean dan akan berhenti
                // sendiri saat tiba waktunya: ia hanya mengirim pesan yang masih
                // `queued`, sedangkan job baru di bawah sudah mengirimnya.
                $ids->each(fn (string $id) => SendMessageJob::dispatch($id));

                $dilepas += $ids->count();
            });

        return $dilepas;
    }

    /**
     * Peringatan dini untuk satu nomor: dari pesan yang dikirim antara 26 jam
     * dan `kesehatan_jam_tunggu` jam lalu, berapa yang tidak pernah sampai ke
     * ponsel penerima.
     *
     * Yang diukur pesan yang TIDAK SAMPAI, bukan yang gagal dikirim: nomor yang
     * sedang dibatasi WhatsApp tetap menerima pesan kita di server (centang
     * satu), tapi tidak meneruskannya. Itu tanda paling awal yang bisa dilihat
     * dari sisi kita — jauh sebelum nomornya benar-benar diblokir.
     *
     * @return array{total: int, tertahan: int}|null null kalau sampelnya terlalu
     *                                               kecil untuk berarti apa-apa
     */
    public function periksaKesehatan(WaSession $session): ?array
    {
        $tunggu = (int) config('gateway.rem.kesehatan_jam_tunggu');

        $baris = Message::query()
            // Rentang `created_at` di kolom berindeks lebih dulu, baru saringan
            // lain — kueri ini berjalan tiap jam untuk setiap nomor tersambung.
            // Tujuh hari, bukan 26 jam: pesan yang ditunda masa pemanasan dibuat
            // beberapa hari sebelum benar-benar terkirim.
            ->where('workspace_id', $session->workspace_id)
            ->whereBetween('created_at', [now()->subDays(7), now()->subHours($tunggu)])
            ->where('wa_session_id', $session->id)
            ->where('direction', 'outbound')
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [now()->subHours(26), now()->subHours($tunggu)])
            // Status pengiriman ke grup tidak berarti hal yang sama.
            ->where('chat_id', 'not like', '%@g.us')
            ->selectRaw("count(*) as total, sum(case when status = 'sent' then 1 else 0 end) as tertahan")
            ->first();

        $total = (int) ($baris->total ?? 0);

        if ($total < (int) config('gateway.rem.kesehatan_minimal_pesan')) {
            return null;
        }

        return ['total' => $total, 'tertahan' => (int) $baris->tertahan];
    }

    private function remBalasBalasan(WaSession $session, string $chatId): void
    {
        $maks = (int) config('gateway.rem.balas_balasan_maks');
        $menit = (int) config('gateway.rem.balas_balasan_menit');
        $jendela = intdiv(now()->getTimestamp(), $menit * 60);
        $kunci = "rem:balas:{$session->id}:".md5($chatId).":{$jendela}";

        Cache::add($kunci, 0, now()->addMinutes($menit * 2));

        if ((int) Cache::increment($kunci) <= $maks) {
            return;
        }

        $tujuan = str_ends_with($chatId, '@g.us') ? 'grup ini' : explode('@', $chatId)[0];
        $buka = Carbon::createFromTimestamp(($jendela + 1) * $menit * 60, config('app.timezone'));

        // Satu kabar per chat per jendela, berapa pun pesan yang tertolak.
        app(Notifier::class)->keWorkspace(
            workspace: $session->workspace,
            type: 'sending.loop',
            title: "Pengiriman ke {$tujuan} ditahan sementara",
            body: "Lebih dari {$maks} pesan ke chat yang sama dalam {$menit} menit dari nomor {$session->name} — "
                .'biasanya tanda sistem Anda dan sistem lain saling membalas otomatis. Pengiriman ke chat itu '
                ."dibuka lagi pukul {$buka->format('H:i')}. Periksa balasan otomatis di integrasi Anda.",
            url: route('messages.index'),
            level: 'warning',
            dedupe: "rem-balas:{$session->id}:".md5($chatId).":{$jendela}",
        );

        throw new RuntimeException(
            "Pengiriman ke {$tujuan} ditahan sementara: lebih dari {$maks} pesan dalam {$menit} menit ke chat "
            .'yang sama — biasanya tanda dua sistem saling membalas otomatis. Pesan ini tidak dikirim dan tidak '
            ."memotong kuota. Coba lagi setelah pukul {$buka->format('H:i')}."
        );
    }

    private function tolakYangBerhenti(WaSession $session, string $nomor): void
    {
        $berhenti = OptOut::where('workspace_id', $session->workspace_id)->where('phone', $nomor)->first();

        // Orang yang menghubungi lagi SETELAH meminta berhenti boleh dibalas —
        // menolak membalas pertanyaannya justru alasan tersering ia kesal.
        if (! $berhenti || $this->baruMenghubungi($session->workspace_id, $nomor, $berhenti->updated_at)) {
            return;
        }

        throw new RuntimeException(
            "Penerima {$nomor} sudah meminta berhenti menerima pesan dari workspace ini (membalas "
            ."\"{$berhenti->keyword}\" pada {$berhenti->updated_at->translatedFormat('j M Y')}). Pesan tidak dikirim "
            .'dan tidak memotong kuota. Kalau penerima memintanya lagi, hapus nomornya dari daftar Berhenti '
            .'Berlangganan di dashboard.'
        );
    }

    private function jadwalPemanasan(WaSession $session, ?string $nomor, bool $otp): ?Carbon
    {
        if (! $this->dalamPemanasan($session)) {
            return null;
        }

        $hariIni = now()->startOfDay();

        if ($this->ambilJatah($session, $hariIni, $this->batasHarian($session, $hariIni))) {
            return null;
        }

        // Jatah hari ini habis, tapi dua jenis pesan tetap berangkat sekarang.
        // Tetap dihitung, supaya volumenya terlihat di angka "terpakai".
        if ($otp || ($nomor !== null && $this->baruMenghubungi($session->workspace_id, $nomor))) {
            $this->tambahHitungan($session, $hariIni);

            return null;
        }

        $terisi = DB::table('warmup_counters')
            ->where('wa_session_id', $session->id)
            ->whereBetween('day', [$hariIni->toDateString(), $hariIni->copy()->addDays($this->lamaPemanasan())->toDateString()])
            ->pluck('sent', 'day');

        // Berakhir paling lambat di hari pertama sesudah masa pemanasan, saat
        // `batasHarian()` berhenti membatasi.
        for ($geser = 1; ; $geser++) {
            $tanggal = $hariIni->copy()->addDays($geser);
            $batas = $this->batasHarian($session, $tanggal);

            if ($batas === null) {
                return $this->jamKirim($tanggal);
            }

            if ((int) ($terisi[$tanggal->toDateString()] ?? 0) < $batas && $this->ambilJatah($session, $tanggal, $batas)) {
                return $this->jamKirim($tanggal);
            }
        }
    }

    private function dalamPemanasan(WaSession $session): bool
    {
        // Nomor perusahaan sendiri sudah lama aktif dan memikul pemberitahuan
        // penagihan seluruh pelanggan — menundanya berarti tagihan yang
        // kabarnya baru sampai besok.
        return $session->warmup_started_at !== null
            && $session->warmup_skipped_at === null
            && $this->batasHarian($session, now()->startOfDay()) !== null
            && ! $session->isSpecial();
    }

    private function hariKe(WaSession $session, Carbon $tanggal): int
    {
        return (int) round($session->warmup_started_at->copy()->startOfDay()->diffInDays($tanggal->copy()->startOfDay())) + 1;
    }

    private function batasHarian(WaSession $session, Carbon $tanggal): ?int
    {
        $hariKe = $this->hariKe($session, $tanggal);

        foreach (config('gateway.rem.pemanasan') as $sampaiHari => $batas) {
            if ($hariKe <= $sampaiHari) {
                return (int) $batas;
            }
        }

        return null;
    }

    private function lamaPemanasan(): int
    {
        return (int) max(array_keys(config('gateway.rem.pemanasan')));
    }

    /**
     * Mengambil satu jatah di hari itu kalau masih ada. Pemeriksaan dan
     * penambahannya satu UPDATE bersyarat, jadi dua permintaan bersamaan tidak
     * bisa sama-sama mengambil jatah terakhir.
     */
    private function ambilJatah(WaSession $session, Carbon $tanggal, int $batas): bool
    {
        $hari = $tanggal->toDateString();

        DB::table('warmup_counters')->insertOrIgnore(['wa_session_id' => $session->id, 'day' => $hari, 'sent' => 0]);

        return DB::table('warmup_counters')
            ->where('wa_session_id', $session->id)
            ->where('day', $hari)
            ->where('sent', '<', $batas)
            ->increment('sent') > 0;
    }

    private function tambahHitungan(WaSession $session, Carbon $tanggal): void
    {
        $hari = $tanggal->toDateString();

        DB::table('warmup_counters')->insertOrIgnore(['wa_session_id' => $session->id, 'day' => $hari, 'sent' => 0]);
        DB::table('warmup_counters')->where('wa_session_id', $session->id)->where('day', $hari)->increment('sent');
    }

    private function jamKirim(Carbon $tanggal): Carbon
    {
        return $tanggal->copy()->setTime((int) config('gateway.rem.jam_kirim_tertunda'), 0);
    }

    /**
     * Apakah orang ini mengirim pesan ke workspace dalam 24 jam terakhir
     * (dan sesudah `$setelah`, kalau diberikan).
     */
    private function baruMenghubungi(int $workspaceId, string $nomor, ?Carbon $setelah = null): bool
    {
        return Message::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', now()->subDay())
            ->when($setelah !== null, fn ($q) => $q->where('created_at', '>', $setelah))
            ->where('direction', 'inbound')
            ->where('from_number', $nomor)
            ->exists();
    }

    private function kataKunci(?string $isi): ?string
    {
        if ($isi === null) {
            return null;
        }

        $bersih = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\pL\pN\s]+/u', '', $isi)));

        if ($bersih === '' || mb_strlen($bersih) > 20) {
            return null;
        }

        return mb_strtoupper($bersih);
    }
}
