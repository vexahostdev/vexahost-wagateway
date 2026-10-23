<?php

namespace App\Jobs;

use App\Models\WaSession;
use App\Services\Notifications\Notifier;
use App\Services\SessionService;
use App\Support\AlasanPutus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Menyelaraskan status sesi dengan keadaan sebenarnya di engine, lalu
 * menyambungkan ulang sesi yang putus. Callback dari engine sudah menangani
 * kasus normal; job ini menutup kasus engine mati mendadak sehingga tidak
 * sempat mengabari siapa pun.
 */
class SyncSessionStatusJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(SessionService $sessions): void
    {
        $candidates = WaSession::query()
            ->with('workspace')
            ->whereIn('status', ['connected', 'connecting', 'disconnected'])
            ->where('driver', 'wwebjs')
            ->get();

        foreach ($candidates as $session) {
            try {
                $before = $session->status;
                $session = $sessions->syncStatus($session);

                if ($before === 'connected' && $session->status !== 'connected') {
                    Log::warning('Sesi WhatsApp terputus', ['session_id' => $session->id]);
                }

                /*
                 | Menyelaraskan status boleh untuk sesi mana pun — itu cuma
                 | membaca. Yang TIDAK boleh adalah menjalankannya lagi untuk
                 | workspace yang layanannya sudah mati: `releaseSessions()`
                 | melepasnya setelah masa tenggang, lalu job ini menjalankannya
                 | lagi satu menit kemudian, tiap menit, selamanya. Penegakan
                 | langganan yang bisa dibatalkan oleh penjadwal kita sendiri
                 | bukan penegakan.
                 |
                 | Dan hanya sesi yang punya kredensial untuk DIPULIHKAN —
                 | `connected_at` terisi, aturan yang sama dengan
                 | BootstrapController. Sesi yang belum pernah tertaut, atau yang
                 | baru diputus tautannya, tidak punya apa pun untuk dipulihkan:
                 | menjalankannya hanya memunculkan kode QR yang tidak ditatap
                 | siapa pun, berganti tiap 20 detik, memakan satu slot engine,
                 | dan dicoba lagi tiap menit sampai ambang.
                */
                if ($session->status !== 'disconnected'
                    || ! $session->auto_reconnect
                    || $session->connected_at === null
                    || ! $session->workspace?->isActive()) {
                    continue;
                }

                /*
                 | Nomor yang ditolak WhatsApp atau diambil alih perangkat lain
                 | tidak dicoba sendiri. Login berulang ke akun yang diblokir
                 | menambah sinyal buruk; merebut balik perangkat yang diambil
                 | alih memulai saling tendang. Dilewati DIAM-DIAM, sebelum
                 | pemeriksaan ambang: penjelasannya sudah ada di `last_error`,
                 | dan kabar "berhenti setelah sekian percobaan" untuk nomor
                 | yang tidak pernah dicoba adalah kabar yang salah.
                */
                if (AlasanPutus::hentikanOtomatis($session->disconnect_reason) && ! $this->cobaSekaliLagi($session)) {
                    continue;
                }

                /*
                 | Batas percobaan. Tanpa ini, sesi yang penyebab kegagalannya
                 | TIDAK akan membaik sendiri dicoba lagi tiap menit selamanya —
                 | dan tiap percobaan membebani engine dan soket WhatsApp.
                 |
                 | Yang berhenti hanya penyambungan OTOMATIS. Barisnya sengaja
                 | TIDAK ditandai `failed`: status itu mengeluarkannya dari
                 | pemulihan otomatis `BootstrapController`, dan sesi yang
                 | kredensialnya masih utuh tidak boleh kehilangan pemulihan
                 | gara-gara satu rentetan kegagalan yang mungkin sudah lewat.
                 | Tombol Hubungkan tetap bekerja, dan menekannya mengembalikan
                 | jatahnya penuh.
                */
                if (! $session->bolehSambungOtomatis()) {
                    Log::warning('Penyambungan otomatis dihentikan setelah percobaan berulang', [
                        'session_id' => $session->id,
                        'percobaan' => $session->connect_failures,
                    ]);

                    $this->beriTahuMenyerah($session);

                    continue;
                }

                // Dicatat SEBELUM mencoba, bukan sesudah. Kegagalan di jalur ini
                // bisa datang sebagai event yang hilang di tengah jalan, dan
                // penghitung yang cuma naik saat kegagalan terlapor adalah
                // penghitung yang tidak pernah naik justru pada kasus terburuk.
                $session->catatPercobaanSambung();

                $sessions->connect($session, otomatis: true);
            } catch (\Throwable $e) {
                Log::warning('Gagal menyelaraskan status sesi', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Nomor yang diambil alih SEKALI dalam sehari dicoba lagi sekali, setelah
     * jeda dua menit.
     *
     * Pengambil alih yang paling mungkin bukan orang lain, melainkan kita
     * sendiri: engine di container lama yang belum mati saat deploy — terutama
     * deploy pertama versi yang membawa kunci data, ketika engine lama belum
     * mengenal kunci itu dan masih merebut balik perangkatnya. Tanpa satu
     * percobaan ini, nomor yang sehat diam sampai pemiliknya menekan Hubungkan.
     *
     * Kalau diambil alih lagi dalam 24 jam, itu sungguhan, dan tidak ada
     * percobaan kedua: merebut balik berulang kali adalah saling tendang yang
     * membuat WhatsApp mencabut perangkatnya.
     */
    private function cobaSekaliLagi(WaSession $session): bool
    {
        if ($session->disconnect_reason !== AlasanPutus::REPLACED) {
            return false;
        }

        $kejadian = $session->diambilAlih24Jam();

        return $kejadian->count() === 1 && $kejadian->first()->lte(now()->subMinutes(2));
    }

    /**
     * Nomor yang berhenti dicoba wajib mengatakan kenapa.
     *
     * Berhenti mencoba diam-diam menghasilkan gejala yang persis sama dengan
     * gangguan: nomor terputus, tidak ada yang terjadi, tidak ada penjelasan.
     * Pelanggan menyimpulkan satu-satunya hal yang masuk akal — ada yang rusak —
     * lalu menghubungi kami untuk sesuatu yang tinggal ditekan sekali.
     *
     * `last_error` dipakai karena kolom itu memang muka pengguna: ia tampil di
     * kartu sesi dan di modal QR. Penandanya memuat jumlah percobaan supaya satu
     * rentetan menghasilkan satu kabar, bukan satu kabar tiap menit.
     */
    private function beriTahuMenyerah(WaSession $session): void
    {
        /*
         | Satu kalimat saja: yang sampai di sini selalu nomor yang PERNAH
         | tertaut dan punya kredensial tersimpan, jadi menekan Hubungkan
         | memang tindakan yang benar.
         |
         | Dulu ada kalimat kedua untuk nomor yang belum pernah tertaut
         | (produksi 8 September 2026: satu nomor punya empat baris sesi yang
         | tidak satu pun pernah `connected`). Nomor seperti itu sekarang tidak
         | lagi disambungkan otomatis sama sekali — lihat penjagaan
         | `connected_at` di atas — dan yang dibutuhkan pemiliknya memang scan
         | QR, bukan percobaan otomatis yang memunculkan QR tanpa penonton.
        */
        $kalimat = 'Kami berhenti mencoba menyambungkan nomor ini setelah '
            .$session->connect_failures.' percobaan. Tekan Hubungkan untuk mencoba lagi.';

        if ($session->last_error !== $kalimat) {
            $session->forceFill(['last_error' => $kalimat])->save();
        }

        if (! $session->workspace) {
            return;
        }

        app(Notifier::class)->keWorkspace(
            workspace: $session->workspace,
            type: 'session.reconnect_gave_up',
            title: "Nomor {$session->name} berhenti disambungkan otomatis",
            body: $kalimat,
            url: route('sessions.index', absolute: false),
            level: 'danger',
            dedupe: 'reconnect-gave-up:'.$session->id.':'.$session->connect_failures,
        );
    }
}
