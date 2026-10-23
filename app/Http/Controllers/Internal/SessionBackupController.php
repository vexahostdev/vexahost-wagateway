<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SessionBackup;
use App\Models\WaSession;
use App\Support\CadanganSesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Penyimpanan backup sesi untuk autentikasi Baileys.
 *
 * Menggantikan format zip RemoteAuth milik whatsapp-web.js. Kredensial Baileys
 * berupa berkas JSON (creds.json dan berkas kunci) yang dibundel menjadi satu
 * payload JSON terstruktur, ditandatangani checksum SHA-256, dan disimpan di sini.
 *
 * Beberapa cadangan terakhir disimpan berdampingan (`SessionBackup::SIMPAN`),
 * dan yang disajikan ke engine selalu yang terbaru YANG LAYAK.
 */
class SessionBackupController extends Controller
{
    private const DISK = 'session-backups';

    public function store(Request $request, string $sessionId): JsonResponse
    {
        $session = WaSession::find($sessionId);

        if (! $session) {
            return response()->json(['success' => false, 'error' => ['message' => 'Sesi tidak ditemukan.']], 404);
        }

        // Nama unik per unggahan, bukan satu nama yang ditimpa: menimpa berarti
        // unggahan rusak menggantikan satu-satunya cadangan sehat.
        $path = "{$session->id}/session-".now()->format('Ymd-His-v').'-'.Str::lower(Str::random(4)).'.json';
        $tmp = tempnam(sys_get_temp_dir(), 'wa-backup-');

        try {
            $stream = $request->getContent(true);
            $out = fopen($tmp, 'wb');
            if (is_resource($stream)) {
                stream_copy_to_stream($stream, $out);
            } else {
                $in = fopen('php://input', 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);

            $checksum = hash_file('sha256', $tmp);
            $claimed = $request->header('X-Engine-Body-Sha256');

            if ($claimed && ! hash_equals($claimed, $checksum)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Isi backup tidak cocok dengan checksum yang ditandatangani.'],
                ], 422);
            }

            $content = file_get_contents($tmp);
            if (! json_validate($content)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Isi backup bukan JSON yang valid.'],
                ], 422);
            }

            // JSON yang sah belum tentu kredensial yang bisa dipakai. Engine
            // sudah menolak mengunggah yang tidak layak; ini penjagaan kedua
            // untuk engine versi lama dan untuk apa pun yang lolos dari sana.
            if (! CadanganSesi::layak($content)) {
                return response()->json([
                    'success' => false,
                    'error' => ['message' => 'Cadangan ditolak: kredensial di dalamnya rusak atau belum tertaut.'],
                ], 422);
            }

            Storage::disk(self::DISK)->put($path, $content);
            $size = strlen($content);

            $session->backups()->create([
                'disk' => self::DISK,
                'path' => $path,
                'size' => $size,
                'checksum' => $checksum,
                'backed_up_at' => now(),
            ]);

            $session->backups()
                ->orderByDesc('backed_up_at')
                ->orderByDesc('id')
                ->get()
                ->slice(SessionBackup::SIMPAN)
                ->each(fn (SessionBackup $lama) => $lama->buang());

            return response()->json(['success' => true, 'data' => ['size' => $size, 'checksum' => $checksum]]);
        } finally {
            @unlink($tmp);
        }
    }

    public function show(string $sessionId): Response|JsonResponse
    {
        $backup = SessionBackup::terbaikUntuk($sessionId);

        if (! $backup) {
            return response()->json(['success' => false, 'error' => ['message' => 'Backup tidak ada.']], 404);
        }

        return response($backup->isiLayak(), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="session.json"',
            'X-Backup-Sha256' => $backup->checksum,
        ]);
    }

    /**
     * Dijawab dari cadangan yang LAYAK. Engine memakai jawaban ini untuk
     * memutuskan apakah ia memulihkan; "ada" untuk cadangan yang pasti
     * ditolak hanya memindahkan kegagalannya ke langkah berikutnya.
     */
    public function exists(string $sessionId): JsonResponse
    {
        $backup = SessionBackup::terbaikUntuk($sessionId);

        return response()->json([
            'success' => true,
            'data' => [
                'exists' => $backup !== null,
                'backed_up_at' => $backup?->backed_up_at?->toIso8601String(),
                'checksum' => $backup?->checksum,
            ],
        ]);
    }

    public function destroy(string $sessionId): JsonResponse
    {
        SessionBackup::where('wa_session_id', $sessionId)
            ->get()
            ->each(fn (SessionBackup $backup) => $backup->buang());

        return response()->json(['success' => true]);
    }
}
