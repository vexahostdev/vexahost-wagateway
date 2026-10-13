<?php

namespace App\Jobs;

use App\Services\Billing\BalanceService;
use App\Services\Notifications\PeringatanSistem;
use App\Services\Notifications\WhatsAppNotifier;
use App\Support\KesehatanAntrean;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Mengubah pemeriksaan halaman Sistem menjadi kabar yang mengetuk sendiri.
 *
 * Seluruh keadaan di bawah sudah dideteksi `/admin/sistem` — tapi halaman itu
 * hanya menunjukkannya kepada orang yang kebetulan membukanya. Justru sifat
 * yang membuat kegagalan ini mahal adalah bahwa tidak ada yang membuka halaman
 * apa pun saat itu terjadi: engine mati dan sesi tetap tampak hijau, antrean
 * berhenti dan pesan cuma diam "Mengantre", saldo menyimpang dan tidak ada
 * satu pun galat.
 *
 * **Penanda hariannya disengaja.** Job ini berjalan tiap jam; tanpa penanda,
 * engine yang mati semalaman menghasilkan dua belas kabar yang sama dan orang
 * berhenti membacanya. `PeringatanSistem` menambahkan tanggal ke tiap kunci
 * penanda, jadi satu keadaan menghasilkan satu kabar per hari — cukup untuk
 * mengingatkan, tidak cukup untuk membuat orang mengabaikannya.
 *
 * **Ke mana kabarnya pergi diputuskan `PeringatanSistem`, bukan di sini.**
 * Yang bertingkat `danger` ikut keluar lewat email dan WhatsApp; `warning`
 * berhenti di lonceng. Aturan itu sengaja tidak diulang di tiap pemeriksaan:
 * pemeriksaan baru yang ditambahkan nanti akan lupa mengeskalasi, dan
 * kegagalan yang paling parah justru yang paling mungkin cuma berakhir di
 * lonceng yang tidak dibuka siapa pun sampai besok pagi.
 *
 * Kegagalan job ini sendiri tidak boleh menjatuhkan apa pun: ia pengawas, dan
 * pengawas yang jatuh tidak boleh membawa serta yang diawasinya.
 */
class PantauKesehatanJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(PeringatanSistem $peringatan, WhatsAppNotifier $wa, BalanceService $saldo): void
    {
        $this->engine($peringatan);
        $this->antrean($peringatan);
        $this->pengirim($peringatan, $wa);
        $this->emailKeluar($peringatan);
        $this->saldo($peringatan, $saldo);
        $this->kapasitas($peringatan);
        $this->debugProduksi($peringatan);
    }

    /**
     * Engine mati berarti TIDAK SATU PUN pesan bisa keluar, untuk seluruh
     * pelanggan sekaligus. Ini kegagalan paling mahal di sistem ini, dan
     * dashboard tetap menampilkan sesi hijau sampai penyelaras berikutnya.
     */
    private function engine(PeringatanSistem $peringatan): void
    {
        try {
            $jawaban = Http::timeout((int) config('gateway.engine.connect_timeout', 5))
                ->withToken(config('gateway.engine.token'))
                ->get(rtrim(config('gateway.engine.url'), '/').'/health');

            if ($jawaban->successful()) {
                return;
            }

            $sebab = 'Menjawab dengan status '.$jawaban->status().'.';
        } catch (\Throwable $e) {
            $sebab = $e->getMessage();
        }

        $peringatan->kabari(
            type: 'sistem.engine_mati',
            title: 'Engine WhatsApp tidak terjangkau',
            body: $sebab.' Selama ini berlangsung, tidak satu pun pesan bisa keluar — '
                .'dan dashboard pelanggan tetap menampilkan sesi mereka hijau.',
            url: route('admin.system'),
            level: 'danger',
            dedupe: 'sistem.engine_mati',
        );
    }

    /**
     * Yang diukur GERAKANNYA, bukan panjang antreannya — broadcast seribu nomor
     * memang wajar menyisakan pekerjaan berjam-jam.
     */
    private function antrean(PeringatanSistem $peringatan): void
    {
        $antrean = KesehatanAntrean::periksa();

        if ($antrean['macet']) {
            $peringatan->kabari(
                type: 'sistem.antrean_macet',
                title: 'Antrean pesan berhenti bergerak',
                body: $antrean['menunggu'].' pesan menunggu dan tidak satu pun terkirim sejak '
                    .($antrean['terakhirBergerak']?->diffForHumans() ?? 'entah kapan')
                    .'. Worker yang mati tidak menghasilkan galat apa pun — pesan cuma diam "Mengantre".',
                url: route('admin.system'),
                level: 'danger',
                dedupe: 'sistem.antrean_macet',
            );
        }

        if (($antrean['gagal'] ?? 0) > 0) {
            $peringatan->kabari(
                type: 'sistem.job_gagal',
                title: $antrean['gagal'].' job gagal menumpuk',
                body: 'Job yang gagal tidak dicoba lagi sendiri; pesan di dalamnya tidak akan pernah terkirim.',
                url: route('admin.system'),
                level: 'warning',
                dedupe: 'sistem.job_gagal',
            );
        }
    }

    /** Tanpa sesi pengirim, SELURUH pemberitahuan pelanggan diam tanpa gejala. */
    private function pengirim(PeringatanSistem $peringatan, WhatsAppNotifier $wa): void
    {
        if ($wa->ready()) {
            return;
        }

        $peringatan->kabari(
            type: 'sistem.pengirim_mati',
            title: 'Pemberitahuan WhatsApp tidak berjalan',
            body: 'Tidak ada sesi pengirim yang siap. Pelanggan tidak dikabari saat pembayarannya '
                .'lunas, kuotanya habis, atau nomornya terputus — dan tidak ada satu pun yang gagal '
                .'secara terlihat.',
            url: route('admin.exemptions'),
            level: 'danger',
            dedupe: 'sistem.pengirim_mati',
        );
    }

    /**
     * `MAIL_MAILER=log` diterima Laravel tanpa keluhan apa pun — dari dalam
     * aplikasi, "terkirim" dan "ditulis ke berkas log" tampak persis sama.
     */
    private function emailKeluar(PeringatanSistem $peringatan): void
    {
        $mailer = config('mail.default');
        $noreply = \App\Support\MailIdentity::noreply();
        $support = \App\Support\MailIdentity::support();

        $siap = ! in_array($mailer, ['log', 'array', 'null'], true)
            && ! ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host')))
            && filled($noreply['address']);

        if (! $siap) {
            $peringatan->kabari(
                type: 'sistem.email_mati',
                title: 'Email keluar belum berjalan',
                body: "MAIL_MAILER sekarang '{$mailer}'. Tidak ada satu pun email yang benar-benar "
                    .'terkirim — pemulihan kata sandi dan pemberitahuan tiket cuma janji.',
                url: route('admin.system'),
                level: 'warning',
                dedupe: 'sistem.email_mati',
            );

            return;
        }

        if (! \App\Support\MailIdentity::isDomainAllowed($noreply['address']) || ! \App\Support\MailIdentity::isDomainAllowed($support['address'])) {
            $peringatan->kabari(
                type: 'sistem.email_domain_tidak_sah',
                title: 'Domain email pengirim belum terverifikasi',
                body: "Domain pengirim ({$noreply['address']} / {$support['address']}) tidak ada dalam daftar domain yang diizinkan Brevo. Email kemungkinan besar akan ditolak (550).",
                url: route('admin.system'),
                level: 'danger',
                dedupe: 'sistem.email_domain_tidak_sah',
            );
        }
    }

    /** Saldo yang tidak bisa direkonsiliasi adalah uang pelanggan yang hilang jejaknya. */
    private function saldo(PeringatanSistem $peringatan, BalanceService $saldo): void
    {
        $tidakCocok = $saldo->workspaceTidakCocok();

        if ($tidakCocok->isEmpty()) {
            return;
        }

        $peringatan->kabari(
            type: 'sistem.saldo_tidak_cocok',
            title: $tidakCocok->count().' workspace saldonya tidak cocok dengan buku besar',
            body: $tidakCocok->take(3)->map(fn ($w) => $w->name.' (selisih Rp '
                .number_format((int) $w->balance - (int) $w->buku_besar, 0, ',', '.').')')->join('; ')
                .'. Selisih saldo tidak menghasilkan galat apa pun; yang menemukannya akan jadi '
                .'pelanggan yang merasa saldonya berkurang sendiri.',
            url: route('admin.system'),
            level: 'danger',
            dedupe: 'sistem.saldo_tidak_cocok',
        );
    }

    /**
     * Kapasitas adalah pembatas jualan, bukan kode.
     *
     * Begitu penuh, pelanggan berikutnya yang MEMBAYAR tidak bisa menautkan
     * nomornya — dan itu ketahuan dari keluhan, bukan dari mana pun.
     */
    private function kapasitas(PeringatanSistem $peringatan): void
    {
        $batas = (int) config('gateway.engine.max_sessions');

        if ($batas <= 0) {
            return;
        }

        $hidup = DB::table('wa_sessions')
            ->whereIn('status', ['connected', 'connecting', 'qr'])
            ->whereNull('deleted_at')
            ->count();

        if ($hidup < $batas) {
            return;
        }

        $peringatan->kabari(
            type: 'sistem.kapasitas_penuh',
            title: "Kapasitas sesi penuh ({$hidup}/{$batas})",
            body: 'Pelanggan berikutnya yang membayar tidak akan bisa menautkan nomornya. '
                .'Naikkan WA_MAX_SESSIONS hanya kalau RAM server memang cukup — '
                .'yang dibunuh OOM killer bisa jadi database atau proses engine.',
            url: route('admin.system'),
            level: 'warning',
            dedupe: 'sistem.kapasitas_penuh',
        );
    }

    /** Halaman galat memamerkan seluruh isi environment kepada pengunjung. */
    private function debugProduksi(PeringatanSistem $peringatan): void
    {
        if (! config('app.debug') || ! app()->environment('production')) {
            return;
        }

        $peringatan->kabari(
            type: 'sistem.debug_menyala',
            title: 'APP_DEBUG menyala di produksi',
            body: 'Setiap halaman galat memamerkan kata sandi database, secret HMAC engine, '
                .'dan kredensial Google kepada siapa pun yang membukanya.',
            url: route('admin.system'),
            level: 'danger',
            dedupe: 'sistem.debug_menyala',
        );
    }
}
