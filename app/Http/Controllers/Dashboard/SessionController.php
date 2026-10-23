<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWorkspaceSelected;
use App\Models\AuditLog;
use App\Models\WaSession;
use App\Services\RemPengiriman;
use App\Services\SessionService;
use App\Support\EngineError;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request, RemPengiriman $rem): View
    {
        $sessions = EnsureWorkspaceSelected::from($request)->sessions()->orderBy('name')->get();

        return view('dashboard.sessions.index', [
            'sessions' => $sessions,
            // Ditampilkan di tiap kartu supaya pesan yang ditunda tidak pernah
            // terasa seperti gateway yang lambat: angkanya dan jalan keluarnya
            // ada di tempat yang sama.
            'pemanasan' => $sessions->mapWithKeys(fn (WaSession $s) => [$s->id => $rem->statusPemanasan($s)]),
        ]);
    }

    /**
     * Pemilik menyatakan nomornya sudah lama aktif di ponsel, jadi tidak perlu
     * dipanaskan. Risikonya dijelaskan di dialog konfirmasi; keputusannya milik
     * pemilik nomor.
     */
    public function skipWarmup(Request $request, string $id, RemPengiriman $rem): RedirectResponse
    {
        $session = $this->find($request, $id);

        $dilepas = $rem->lewatiPemanasan($session);

        $session->catatKejadian('warmup_skipped', null, null, $dilepas > 0 ? "{$dilepas} pesan terjadwal dilepas" : null);
        AuditLog::record('session.warmup_skipped', $session, ['pesan_dilepas' => $dilepas]);

        return back()->with('status', $dilepas > 0
            ? "Masa pemanasan dilewati. {$dilepas} pesan yang tadinya terjadwal mulai dikirim sekarang."
            : 'Masa pemanasan dilewati. Pengiriman dari nomor ini tidak lagi dibatasi per hari.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $workspace = EnsureWorkspaceSelected::from($request);

        try {
            $session = $this->sessions->create($workspace, $data['name']);
        } catch (\Throwable $e) {
            return back()->withErrors(['name' => EngineError::pesan($e)])->withInput();
        }

        AuditLog::record('session.created', $session, ['name' => $session->name]);

        return redirect()->route('sessions.index')
            ->with('status', "Sesi '{$session->name}' dibuat. Klik Hubungkan lalu scan QR-nya.");
    }

    public function connect(Request $request, string $id): RedirectResponse
    {
        $session = $this->find($request, $id);

        // Permintaan manusia mengembalikan jatah percobaan otomatis ke penuh.
        // Orang yang menekan tombol ini biasanya baru memperbaiki sesuatu —
        // memberinya sisa jatah dari rentetan kegagalan sebelumnya berarti
        // penjadwal menyerah lagi setelah satu percobaan, tanpa alasan yang
        // bisa dilihat siapa pun.
        $session->resetPercobaanSambung();

        try {
            $this->sessions->connect($session);
        } catch (\Throwable $e) {
            Log::warning('Gagal menjalankan sesi dari dashboard', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['session' => EngineError::pesan($e)]);
        }

        AuditLog::record('session.connect', $session);

        return back()->with('open_qr', $session->id);
    }

    public function disconnect(Request $request, string $id): RedirectResponse
    {
        $session = $this->find($request, $id);

        try {
            $this->sessions->disconnect($session);
        } catch (\Throwable $e) {
            Log::warning('Gagal menghentikan sesi lewat dashboard', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            /*
             | Barisnya tetap ditandai berhenti. Engine yang tidak menjawab
             | berarti ia tidak sedang menjalankan sesi ini juga — menolak
             | permintaan berhenti hanya menyisakan baris yang mengaku hidup
             | padahal tidak ada yang menjalankannya, dan tombol Hentikan yang
             | tidak pernah bisa berhasil.
            */
            $session->update(['status' => 'disconnected', 'qr_payload' => null]);
            AuditLog::record('session.disconnect', $session, ['engine' => 'tidak menjawab']);

            return back()->with('status', 'Sesi ditandai berhenti. '.EngineError::pesan($e));
        }

        AuditLog::record('session.disconnect', $session);

        return back()->with('status', 'Sesi dihentikan.');
    }

    public function logout(Request $request, string $id): RedirectResponse
    {
        $session = $this->find($request, $id);

        try {
            $this->sessions->logout($session);
        } catch (\Throwable $e) {
            Log::warning('Gagal memutus tautan nomor lewat dashboard', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            /*
             | Berbeda dari Hentikan: di sini keadaan lokal TIDAK diubah.
             |
             | Memutus tautan membuang kredensial di kedua sisi, dan kegagalan
             | yang paling sering di sini adalah habis waktu (15 detik) — bukan
             | bukti bahwa perintahnya tidak sampai. Menandai nomornya terputus
             | padahal engine masih memegangnya menghasilkan dashboard yang
             | bilang "belum tertaut" sementara nomor aslinya masih menerima
             | pesan, dan itu jauh lebih membingungkan daripada tombol yang
             | jujur bilang gagal. `SyncSessionStatusJob` merapikan keadaan
             | sebenarnya dalam satu menit.
            */
            return back()->withErrors([
                'session' => EngineError::pesan($e)
                    .' Kalau perintahnya ternyata sudah sampai, status nomor menyesuaikan sendiri dalam satu menit.',
            ]);
        }

        AuditLog::record('session.logout', $session);

        return back()->with(
            'status',
            'Tautan nomor diputus. Klik Hubungkan untuk scan QR lagi — boleh dengan nomor yang sama atau nomor lain.'
        );
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $session = $this->find($request, $id);

        try {
            $this->sessions->logout($session);
        } catch (\Throwable) {
            // Engine tidak bisa dihubungi — baris tetap dihapus supaya dashboard
            // tidak menyisakan sesi hantu yang tidak bisa diapa-apakan.
        }

        AuditLog::record('session.deleted', $session, ['name' => $session->name]);
        $session->delete();

        return redirect()->route('sessions.index')->with('status', 'Sesi dihapus.');
    }

    /**
     * Dipanggil modal QR setiap 3 detik. Sengaja polling, bukan websocket:
     * satu request kecil tiap 3 detik hanya saat modal terbuka jauh lebih murah
     * daripada menjalankan Reverb khusus untuk ini.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $session = $this->find($request, $id);
        $loadingPercent = null;

        // Sesi yang belum selesai ditanyakan langsung ke engine, tidak cukup
        // membaca baris database. Baris itu hanya seakurat callback terakhir
        // yang berhasil sampai; satu event `ready` yang hilang membuat modal
        // menampilkan "Menyiapkan sesi" tanpa akhir padahal nomornya sudah
        // tertaut. Sesi yang sudah `connected` atau `failed` tidak perlu
        // ditanyakan lagi — jawabannya tidak akan berubah tanpa ada aksi baru.
        $state = null;
        if (! in_array($session->status, ['connected', 'failed'], true)) {
            try {
                $state = $this->sessions->liveStatus($session);
                $loadingPercent = $state['loading_percent'] ?? null;
                if (! empty($state['qr']) && ! $session->qr_payload) {
                    $session->update([
                        'status' => 'qr',
                        'qr_payload' => $state['qr'],
                        'qr_expires_at' => now()->addSeconds(config('gateway.qr_ttl_seconds')),
                    ]);
                }
                $session->refresh();
            } catch (\Throwable $e) {
                // Engine tidak terjangkau bukan alasan menggagalkan polling —
                // jawab dengan keadaan terakhir yang diketahui, dan biarkan
                // percobaan tiga detik berikutnya mencoba lagi.
                Log::debug('Gagal menanyakan status sesi ke engine', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $qr = $session->hasFreshQr() ? $session->qr_payload : null;
        if (! $qr && ! empty($state['qr']) && $session->status === 'qr') {
            $qr = $state['qr'];
        }

        return response()->json([
            'status' => $session->status,
            'qr' => $qr,
            'phone_number' => $session->phone_number,
            'push_name' => $session->push_name,
            'loading_percent' => $loadingPercent,
            'error' => $session->last_error,
        ]);
    }

    private function find(Request $request, string $id): WaSession
    {
        return EnsureWorkspaceSelected::from($request)->sessions()->findOrFail($id);
    }
}
