<?php

namespace App\Services\Notifications;

use App\Mail\KabarTim;
use App\Mail\TesEmail;
use App\Models\Workspace;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pemberitahuan lewat email, mengiringi yang sudah ada di WhatsApp.
 *
 * Kenapa dua jalur untuk peristiwa yang sama: nomor tagihan
 * (`workspaces.billing_phone`) boleh kosong, dan yang mengisinya pun bisa
 * berganti ponsel atau keluar dari perusahaan. Email penagihan jauh lebih
 * jarang berubah dan lebih mungkin dibaca orang yang benar-benar memegang
 * anggarannya. Yang satu bukan pengganti yang lain — keduanya pelengkap
 * spanduk di dashboard, yang hanya terlihat oleh orang yang kebetulan sedang
 * membukanya.
 *
 * **Aturannya sama persis dengan `WhatsAppNotifier`, dan itu disengaja:**
 *
 * 1. **Tidak pernah melempar galat.** Tagihan tetap harus lunas meski Brevo
 *    sedang mati. Semua kegagalan berakhir di log, tidak satu pun merambat ke
 *    pemanggil.
 *
 * 2. **Satu peristiwa, satu email.** Penanda cache-nya terpisah dari milik
 *    WhatsApp (`email-notif:` vs `wa-notif:`) supaya email yang gagal tidak
 *    ikut membungkam pesan WhatsApp untuk peristiwa yang sama, dan sebaliknya.
 *
 * 3. **Pengecualian dihormati.** Workspace internal dan akun bebas tidak
 *    menerima surat penagihan untuk tagihan yang memang tidak pernah ada.
 *
 * Yang **tidak** ditiru dari `WhatsAppNotifier`: kelas ini tidak memakai
 * `MessageDispatcher`, jadi ia aman disuntikkan lewat constructor ke mana pun
 * tanpa risiko lingkaran yang mematikan seluruh pengiriman pesan.
 */
class EmailNotifier
{
    /**
     * Apakah email bisa terkirim sama sekali.
     *
     * `MAIL_MAILER=log` bukan kegagalan teknis — Laravel menerimanya dengan
     * senang hati dan menulis seluruh isi email ke berkas log tanpa satu pun
     * galat. Dari dalam aplikasi, "terkirim" dan "ditulis ke log" tampak
     * persis sama. Itulah kenapa keadaan ini harus dibaca dari konfigurasi,
     * bukan dari hasil pengiriman.
     */
    public function ready(): bool
    {
        $mailer = config('mail.default');

        if (in_array($mailer, ['log', 'array', 'null'], true)) {
            return false;
        }

        // `smtp` tanpa host jatuh ke bawaan Laravel (127.0.0.1:2525) dan gagal
        // dengan timeout, bukan dengan galat yang menyebutkan sebabnya.
        if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            return false;
        }

        $noreply = \App\Support\MailIdentity::noreply();

        return filled($noreply['address']);
    }

    /** Alamat penagihan workspace, dengan email pemiliknya sebagai cadangan. */
    public function alamat(Workspace $workspace): ?string
    {
        $alamat = $workspace->billing_email ?: $workspace->owner_email;

        return filter_var($alamat, FILTER_VALIDATE_EMAIL) ? $alamat : null;
    }

    /**
     * Mengirim ke alamat penagihan sebuah workspace.
     *
     * `$sekali` adalah kunci penanda: kalau diisi, email dengan kunci yang sama
     * tidak dikirim lagi selama masa yang ditentukan. Email kembar soal uang
     * membuat orang mengira ia ditagih dua kali.
     */
    public function toWorkspace(Workspace $workspace, Mailable $surat, ?string $sekali = null, int $ingatJam = 24): bool
    {
        if ($workspace->isExempt()) {
            return false;
        }

        $alamat = $this->alamat($workspace);

        if ($alamat === null) {
            return false;
        }

        return $this->send($alamat, $surat, $sekali, $ingatJam);
    }

    /**
     * Kabar bebas untuk satu workspace, tanpa pemanggilnya perlu menyusun
     * mailable sendiri.
     *
     * Pasangan `kabarTim()` untuk arah yang berlawanan, dan ada karena alasan
     * yang sama: jalur yang menuntut pemanggilnya membuat mailable akan
     * dilewatkan sebagian pemanggil, dan jalur yang cuma separuh terpasang
     * lebih buruk daripada yang tidak ada — ia menciptakan harapan bahwa kabar
     * selalu sampai lewat email.
     *
     * Mengembalikan `false` tanpa melempar untuk workspace yang tidak punya
     * alamat penagihan maupun pemilik. Email itu pelengkap; lonceng di dalam
     * aplikasi yang jadi patokan bahwa kabarnya sampai.
     */
    public function kabarWorkspace(
        Workspace $workspace,
        string $judul,
        string $isi,
        ?string $tautan = null,
        ?string $sekali = null,
    ): bool {
        return $this->toWorkspace($workspace, new KabarTim($judul, $isi, $tautan), $sekali);
    }

    /**
     * Kabar untuk tim, memakai teks yang sama dengan pemberitahuan WhatsApp.
     *
     * Ada supaya tiap pemanggil cukup menambah SATU baris di sebelah panggilan
     * `WhatsAppNotifier::toAdmin()` yang sudah ada. Kalau ia menuntut menyusun
     * mailable sendiri, sebagian pemanggil akan melewatkannya — dan jalur yang
     * cuma separuh terpasang lebih buruk daripada yang tidak ada sama sekali,
     * karena ia menciptakan harapan bahwa kabar selalu sampai lewat email.
     *
     * Penandanya berawalan sendiri di `send()`, jadi email yang berhasil tidak
     * pernah membungkam pesan WhatsApp untuk peristiwa yang sama.
     */
    public function kabarTim(string $judul, string $isi, ?string $sekali = null, ?string $tautan = null): bool
    {
        return $this->toAdmin(new KabarTim($judul, $isi, $tautan), $sekali);
    }

    /** Mengirim ke semua alamat tim / admin kami sendiri. */
    public function toAdmin(Mailable $surat, ?string $sekali = null, int $ingatJam = 24): bool
    {
        $penanda = $sekali ? "email-notif:{$sekali}" : null;
        if ($penanda && Cache::has($penanda)) {
            return false;
        }

        $recipients = \App\Support\MailIdentity::adminRecipients();

        if (empty($recipients)) {
            return false;
        }

        $anySuccess = false;
        foreach ($recipients as $alamat) {
            if ($this->send($alamat, clone $surat, null, $ingatJam)) {
                $anySuccess = true;
            }
        }

        if ($anySuccess && $penanda) {
            Cache::put($penanda, true, now()->addHours($ingatJam));
        }

        return $anySuccess;
    }

    /**
     * Mengirim email percobaan, dan menjelaskan kenapa kalau gagal.
     *
     * Wajib ada, bukan pelengkap. Brevo menolak pengirim yang belum
     * diverifikasi dengan `550`, dan penolakan itu **tidak terlihat di
     * antarmuka mana pun** — persis bentuk kegagalan notifikasi WhatsApp yang
     * diam. Tanpa tombol ini, satu-satunya cara menemukannya adalah menunggu
     * peristiwa penagihan sungguhan, dan yang menemukannya jadi pelanggan yang
     *
     * Sengaja TIDAK memakai penanda sekali-kirim, dan sengaja melaporkan galat
     * yang biasanya ditelan: di sinilah satu-satunya tempat pesan galat SMTP
     * justru yang paling dicari orang.
     *
     * @param string|array<int, string> $tujuan
     * @return array{berhasil: bool, pesan: string}
     */
    public function kirimTes(string|array $tujuan, string $tipe = 'noreply'): array
    {
        $recipients = is_array($tujuan)
            ? $tujuan
            : array_map('trim', explode(',', $tujuan));

        $recipients = array_values(array_unique(array_filter(
            $recipients,
            fn ($item) => is_string($item) && $item !== ''
        )));

        if (empty($recipients)) {
            return ['berhasil' => false, 'pesan' => 'Alamat email tujuan tidak sah.'];
        }

        foreach ($recipients as $alamat) {
            if (! filter_var($alamat, FILTER_VALIDATE_EMAIL)) {
                return ['berhasil' => false, 'pesan' => "Alamat email '{$alamat}' tidak sah."];
            }
        }

        if (! $this->ready()) {
            $mailer = config('mail.default');

            return ['berhasil' => false, 'pesan' => match (true) {
                in_array($mailer, ['log', 'array', 'null'], true) => "MAIL_MAILER masih '{$mailer}'. "
                    .'Isi email ditulis ke berkas log dan tidak pernah benar-benar terkirim. '
                    .'Ganti ke smtp lalu isi MAIL_HOST, MAIL_USERNAME, dan MAIL_PASSWORD.',
                $mailer === 'smtp' => 'MAIL_HOST kosong. smtp tanpa host jatuh ke 127.0.0.1:2525 '
                    .'dan gagal dengan timeout, bukan dengan galat yang menyebut sebabnya.',
                default => 'MAIL_FROM_ADDRESS kosong.',
            }];
        }

        $sender = $tipe === 'support' ? \App\Support\MailIdentity::support() : \App\Support\MailIdentity::noreply();

        $terkirim = [];
        $gagal = [];

        foreach ($recipients as $alamat) {
            try {
                Mail::to($alamat)->send(new TesEmail($tipe));
                $terkirim[] = $alamat;
            } catch (\Throwable $e) {
                Log::warning('Tes email gagal', ['tujuan' => $alamat, 'error' => $e->getMessage()]);
                $gagal[$alamat] = $e->getMessage();
            }
        }

        if (! empty($gagal) && empty($terkirim)) {
            $firstError = reset($gagal);

            // Pesan mentahnya sengaja ikut. Kegagalan Brevo yang paling sering
            // — pengirim belum diverifikasi — hanya bisa dikenali dari kode
            // 550 di dalamnya, dan halaman ini cuma dilihat admin kami sendiri.
            return ['berhasil' => false, 'pesan' => str_contains($firstError, '550')
                ? 'Server SMTP menolak pengirimnya (550). Alamat '.$sender['address']
                    .' kemungkinan belum diverifikasi sebagai sender di Brevo. Galat lengkap: '.$firstError
                : 'Gagal mengirim: '.$firstError];
        }

        if (! empty($gagal) && ! empty($terkirim)) {
            $suksesText = implode(', ', $terkirim);
            $gagalText = implode(', ', array_keys($gagal));

            return [
                'berhasil' => true,
                'pesan' => "Email terkirim ke {$suksesText}, namun gagal ke {$gagalText} lewat "
                    .config('mail.mailers.'.config('mail.default').'.host', config('mail.default'))
                    .'. Kalau tidak sampai dalam beberapa menit, periksa folder spam dan status pengirim di Brevo.',
            ];
        }

        $tujuanText = implode(', ', $terkirim);

        return ['berhasil' => true, 'pesan' => "Email terkirim ke {$tujuanText} (tipe: {$tipe}) lewat "
            .config('mail.mailers.'.config('mail.default').'.host', config('mail.default'))
            .'. Kalau tidak sampai dalam beberapa menit, periksa folder spam dan status pengirim di Brevo.'];
    }

    private function send(string $tujuan, Mailable $surat, ?string $sekali, int $ingatJam): bool
    {
        $penanda = $sekali ? "email-notif:{$sekali}" : null;

        if ($penanda && Cache::has($penanda)) {
            return false;
        }

        if (! $this->ready()) {
            Log::info('Pemberitahuan email dilewati: email keluar belum dikonfigurasi.', [
                'tujuan' => $tujuan,
                'mailer' => config('mail.default'),
            ]);

            return false;
        }

        try {
            Mail::to($tujuan)->send($surat);

            if ($penanda) {
                Cache::put($penanda, true, now()->addHours($ingatJam));
            }

            return true;
        } catch (\Throwable $e) {
            // Yang memanggil sedang mengerjakan sesuatu yang jauh lebih penting
            // — menandai tagihan lunas, menerbitkan tagihan — dan itu tidak
            // boleh gagal gara-gara SMTP sedang tersendat.
            Log::warning('Pemberitahuan email gagal dikirim.', [
                'tujuan' => $tujuan,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
