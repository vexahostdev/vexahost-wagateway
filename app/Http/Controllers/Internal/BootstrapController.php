<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SessionEvent;
use App\Models\WaSession;
use App\Support\AlasanPutus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Dipanggil engine sekali saat boot. Engine sendiri tidak menyimpan daftar
 * sesi — daftar itu ada di sini — sehingga container engine sepenuhnya bisa
 * dibuang dan dibangun ulang tanpa kehilangan apa pun.
 */
class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $sessions = WaSession::query()
            // Workspace yang layanannya sudah mati tidak ikut dipulihkan.
            // Tanpa ini, tiap engine di-deploy ulang seluruh sesi milik
            // pelanggan yang menunggak kembali hidup dan memakan slot dari
            // tiga yang tersedia untuk semua pelanggan.
            ->layananHidup()
            ->where('driver', 'wwebjs')
            ->where('auto_reconnect', true)
            /*
             | Sesi yang BELUM PERNAH tersambung tidak ikut dipulihkan otomatis.
             |
             | Ini sudah lama jadi maksudnya — komentar di bawah menuliskannya —
             | tapi kuerinya tidak pernah menegakkannya: `disconnected` ikut
             | terjaring tanpa memandang `connected_at`, dan sesi yang gagal
             | pada percobaan pertama langsung menjadi `disconnected`.
             |
             | Produksi 8 September 2026 memperlihatkan akibatnya: satu nomor
             | punya EMPAT baris sesi yang tidak satu pun pernah `connected`,
             | dan keempatnya diserahkan ke engine setiap kali ia menyala.
             | Baris mati itulah bahan bakar badai percobaannya.
             |
             | Yang hilang dengan penjagaan ini: tidak ada. Sesi yang belum
             | pernah tertaut tidak punya kredensial untuk dipulihkan — ia cuma
             | akan memunculkan QR yang tidak ada yang men-scan.
            */
            ->whereNotNull('connected_at')
            ->where(function ($query): void {
                $query->whereIn('status', ['connected', 'connecting', 'disconnected'])
                    // Yang berstatus `failed` tapi pernah tersambung tetap
                    // dipulihkan: kredensialnya masih ada di volume, dan status
                    // itu paling sering tertinggal dari kegagalan sesaat —
                    // engine yang sedang di-deploy ulang, bukan tautan nomor
                    // yang benar-benar putus. Membiarkannya di luar daftar ini
                    // berarti memaksa scan ulang untuk masalah yang sudah lewat.
                    ->orWhere(fn ($q) => $q->where('status', 'failed')->whereNotNull('connected_at'));
            })
            /*
             | Batas percobaan berlaku DI SINI JUGA, dan itu celah yang sempat
             | terlewat.
             |
             | `SyncSessionStatusJob` sudah menghormati `connect_failures`, tapi
             | ia hanya menyentuh sesi `disconnected`. Jalur ini berbeda: ia
             | memulihkan `failed` yang pernah tersambung — dan justru sesi
             | seperti itulah yang punya riwayat gagal panjang. Tanpa penjagaan
             | di sini, tiap engine dijalankan ulang seluruh riwayat kegagalannya
             | dilupakan dan badai percobaannya mulai lagi dari nol.
             |
             | `supervisi_engine` di start.sh menjalankan ulang engine 3 detik
             | setelah ia mati. Engine yang tidak stabil karena itu memanggil
             | bootstrap berkali-kali, dan tiap panggilan menyalakan sesi engine
             | untuk setiap sesi di daftar ini.
             |
             | Penghitungnya nol lagi begitu sesi benar-benar `connected`, dan
             | juga saat pelanggan menekan Hubungkan. Jadi yang dikecualikan di
             | sini hanya sesi yang sudah berulang kali gagal DAN belum ada
             | manusia yang menyentuhnya.
            */
            ->where('connect_failures', '<', WaSession::BATAS_PERCOBAAN_SAMBUNG)
            // Nomor yang ditolak WhatsApp atau diambil alih perangkat lain
            // menunggu manusia — alasannya sama dengan di SyncSessionStatusJob.
            // NULL ditulis eksplisit: `NOT IN` atas NULL bernilai NULL dan akan
            // membuang seluruh sesi yang tidak pernah putus sama sekali.
            ->where(fn ($q) => $q->whereNull('disconnect_reason')
                ->orWhereNotIn('disconnect_reason', [AlasanPutus::BANNED, AlasanPutus::REPLACED]))
            ->get(['id', 'name', 'status']);

        /*
         | Menyerahkan sesi ke engine ADALAH percobaan penyambungan, jadi ia
         | dihitung seperti percobaan lainnya.
         |
         | `supervisi_engine` di start.sh menjalankan ulang engine tiga detik
         | setelah ia mati. Engine yang tidak stabil karena itu memanggil
         | endpoint ini berkali-kali, dan tiap panggilan menyalakan sesi engine
         | untuk SETIAP sesi di daftar ini. Tanpa dihitung, jalur ini adalah
         | badai percobaan yang tidak punya rem sama sekali.
         |
         | Satu UPDATE untuk seluruh daftar, bukan satu per baris: endpoint ini
         | dipanggil saat engine baru menyala, yaitu saat Laravel juga sedang
         | sibuk melayani boot-nya sendiri.
        */
        if ($sessions->isNotEmpty()) {
            DB::table('wa_sessions')
                ->whereIn('id', $sessions->pluck('id'))
                ->increment('connect_failures');
        }

        // Penanda "engine menyala ulang" di riwayat tiap sesi — tanpanya
        // logout yang selalu terjadi sesaat setelah deploy tidak terlihat polanya.
        SessionEvent::catatBanyak($sessions->pluck('id'), 'restore_requested', 'boot');

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn (WaSession $s) => [
                'session_id' => $s->id,
                'name' => $s->name,
                'last_known_status' => $s->status,
            ]),
        ]);
    }
}
