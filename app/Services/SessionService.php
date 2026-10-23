<?php

namespace App\Services;

use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\Providers\ProviderManager;
use App\Support\EngineError;
use App\Support\KapasitasPlatform;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SessionService
{
    public function __construct(private readonly ProviderManager $providers) {}

    public function create(Workspace $workspace, string $name, string $driver = 'wwebjs'): WaSession
    {
        if (! $workspace->canAddSession()) {
            throw new RuntimeException(
                "Workspace sudah memakai seluruh jatah sesi ({$workspace->max_sessions})."
            );
        }

        /*
         | Batas platform, bukan batas workspace.
         |
         | `canAddSession()` di atas cuma tahu jatah paket workspace ini. Yang
         | tidak diketahui siapa pun sampai detik terakhir adalah bahwa SELURUH
         | platform hanya sanggup menjalankan `WA_MAX_SESSIONS` nomor sekaligus.
         | Tanpa pemeriksaan ini, baris sesinya lahir dengan sukses, muncul di
         | dashboard sebagai nomor yang tinggal dihubungkan, lalu gagal
         | selamanya di tombol Hubungkan.
         |
         | Diperiksa di sini dan bukan cuma di checkout karena keduanya menjaga
         | hal berbeda: checkout menjaga pelanggan BARU dari membayar sia-sia,
         | ini menjaga pelanggan lama yang jatah paketnya masih sisa dari
         | membuat sesi yang tidak akan pernah jalan.
        */
        if (! $workspace->isExempt() && KapasitasPlatform::slotHabis()) {
            throw new RuntimeException(KapasitasPlatform::kalimat());
        }

        // Sesi dihapus lunak, tapi indeks unik (workspace_id, name) tidak peduli
        // deleted_at — tanpa ini, memakai ulang nama sesi yang sudah dihapus
        // gagal dengan galat duplikat yang tidak bisa dipahami pengguna.
        // Dihapus permanen, bukan dipulihkan: baris baru mendapat ULID baru,
        // sehingga tidak mewarisi folder kredensial lama di engine kalau
        // logout sempat gagal saat penghapusan.
        $workspace->sessions()->onlyTrashed()->where('name', $name)->get()
            ->each(fn (WaSession $stale) => $stale->forceDelete());

        return $workspace->sessions()->create([
            'name' => $name,
            'driver' => $driver,
            'status' => 'pending',
        ]);
    }

    /**
     * @param  bool  $otomatis  true untuk penjadwal: sesinya hanya boleh
     *                          DIPULIHKAN dari kredensial yang ada. Tanpa
     *                          kredensial, engine menolak menjalankannya alih-alih
     *                          memunculkan kode QR yang tidak ditatap siapa pun.
     */
    public function connect(WaSession $session, bool $otomatis = false): WaSession
    {
        // `disconnect_reason` ikut dikosongkan: menekan Hubungkan adalah
        // keputusan manusia untuk mencoba lagi, termasuk untuk nomor yang tadi
        // diblokir atau diambil alih. Kalau keadaannya masih sama, engine akan
        // melaporkan alasan yang sama lagi.
        $session->update([
            'status' => 'connecting',
            'last_error' => null,
            'disconnect_reason' => null,
        ]);

        $session->catatKejadian('connect_requested', $otomatis ? 'otomatis' : 'manual');

        try {
            $this->providers->for($session)->startSession($session, $otomatis);
        } catch (ConnectionException $e) {
            // Engine tidak terjangkau itu keadaan sementara — paling sering
            // karena container-nya sedang di-deploy ulang. Menandainya `failed`
            // membuat sesi dikeluarkan dari daftar pemulihan otomatis di
            // BootstrapController, jadi nomor yang sebenarnya masih tertaut
            // tampak putus dan seolah harus di-scan ulang setiap deploy.
            // `last_error` ikut tampil di kartu sesi dan di modal QR, jadi ia
            // sama-sama muka pengguna — rincian teknisnya ke log, bukan ke sini.
            Log::warning('Engine tidak terjangkau saat menjalankan sesi', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            $session->update(['status' => 'disconnected', 'last_error' => EngineError::pesan($e)]);

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Engine menolak menjalankan sesi', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            $session->update(['status' => 'failed', 'last_error' => EngineError::pesan($e)]);

            throw $e;
        }

        return $session->refresh();
    }

    public function disconnect(WaSession $session): WaSession
    {
        $this->providers->for($session)->stopSession($session);

        $session->update(['status' => 'disconnected', 'qr_payload' => null]);

        $session->catatKejadian('stopped');

        return $session;
    }

    /**
     * Memutus tautan perangkat sepenuhnya. Berbeda dari disconnect: kredensial
     * dibuang di kedua sisi, jadi menghubungkan lagi berarti scan QR baru.
     */
    public function logout(WaSession $session): WaSession
    {
        $this->providers->for($session)->logoutSession($session);

        $session->update([
            'status' => 'disconnected',
            'qr_payload' => null,
            'phone_number' => null,
            'push_name' => null,
            'connected_at' => null,
            'disconnect_reason' => null,
        ]);

        $session->hapusCadangan();

        $session->catatKejadian('unlinked');

        return $session;
    }

    /**
     * Menyelaraskan status di database dengan keadaan sebenarnya di engine.
     * Dipanggil terjadwal karena engine bisa saja mati tanpa sempat mengirim
     * callback disconnected (mis. container dibunuh).
     */
    /**
     * Keadaan sesi menurut engine, ditanyakan saat itu juga.
     *
     * Dipakai dashboard selama modal QR terbuka. Baris di database hanya
     * seakurat callback terakhir yang berhasil sampai — satu event `ready` yang
     * hilang (Laravel sedang restart, jaringan antar container tersendat)
     * membuat modal menunggu selamanya padahal nomornya sudah tertaut sejak
     * tadi. Bertanya langsung membuat keadaan sebenarnya muncul dalam hitungan
     * detik, tanpa bergantung pada scheduled task yang berjalan tiap menit.
     *
     * @return array{status: string, phone_number?: ?string, push_name?: ?string, loading_percent?: ?int}
     */
    public function liveStatus(WaSession $session): array
    {
        $state = $this->providers->for($session)->status($session);
        $status = $state['status'] ?? null;

        // Hanya menulis kalau memang berbeda. Endpoint ini dipanggil tiap tiga
        // detik selama ada orang menatap modal; menulis baris yang sama
        // berulang kali membebani database tanpa mengubah apa pun.
        if ($status !== null && $status !== $session->status) {
            $perubahan = ['status' => $status];

            if ($status === 'connected') {
                $session->catatNomorTertaut(PhoneNumber::normalize($state['phone_number'] ?? null));

                $perubahan += [
                    'phone_number' => $state['phone_number'] ?? $session->phone_number,
                    'push_name' => $state['push_name'] ?? $session->push_name,
                    'qr_payload' => null,
                    'qr_expires_at' => null,
                    'connected_at' => $session->connected_at ?? now(),
                    'last_seen_at' => now(),
                    'last_error' => null,
                    'connect_failures' => 0,
                ];
            }

            $session->update($perubahan);
        }

        return $state;
    }

    public function syncStatus(WaSession $session): WaSession
    {
        $state = $this->providers->for($session)->status($session);

        $perubahan = [
            'status' => $state['status'],
            'phone_number' => $state['phone_number'] ?? $session->phone_number,
            'push_name' => $state['push_name'] ?? $session->push_name,
            'last_seen_at' => now(),
        ];

        // Sesi yang terbaca `connected` di engine sudah membuktikan dirinya,
        // walau event `ready`-nya kebetulan hilang di jalan. Tanpa reset di
        // sini, sesi yang sehat bisa tetap membawa sisa penghitung dari
        // rentetan kegagalan lama dan menyerah terlalu cepat pada putus
        // berikutnya.
        if ($state['status'] === 'connected') {
            $perubahan['connect_failures'] = 0;
            $session->catatNomorTertaut(PhoneNumber::normalize($state['phone_number'] ?? null));
        }

        $session->update($perubahan);

        return $session;
    }
}
