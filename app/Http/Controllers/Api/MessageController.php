<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;
use App\Models\WaSession;
use App\Services\MessageDispatcher;
use App\Support\EngineError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MessageController extends ApiController
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function text(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string'],
            'to' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:4096'],
        ]);

        $session = $this->resolveSession($request, $data['session_id'] ?? null);

        if (! $session) {
            return $this->fail('Tidak ada sesi WhatsApp yang bisa dipakai. Hubungkan satu sesi terlebih dahulu.', 422);
        }

        try {
            $message = $this->dispatcher->queue($session, $data['to'], [
                'type' => 'text',
                'body' => $data['message'],
                'api_key_id' => $this->apiKey($request)->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail(EngineError::pesan($e), 422);
        }

        return $this->ok($this->present($message), 202);
    }

    public function media(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string'],
            'to' => ['required', 'string', 'max:40'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'type' => ['nullable', Rule::in(['image', 'document', 'video', 'audio'])],
            'file' => ['required', 'file', 'max:16384'], // WhatsApp menolak lampiran di atas ±16 MB
        ]);

        $session = $this->resolveSession($request, $data['session_id'] ?? null);

        if (! $session) {
            return $this->fail('Tidak ada sesi WhatsApp yang bisa dipakai.', 422);
        }

        $file = $request->file('file');
        $path = $file->store((string) $this->workspace($request)->id, 'media');

        try {
            $message = $this->dispatcher->queue($session, $data['to'], [
                'type' => $data['type'] ?? 'document',
                'body' => $data['caption'] ?? null,
                'media_path' => $path,
                'media_mime' => $file->getClientMimeType(),
                'media_filename' => $file->getClientOriginalName(),
                'api_key_id' => $this->apiKey($request)->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('media')->delete($path);

            return $this->fail(EngineError::pesan($e), 422);
        }

        return $this->ok($this->present($message), 202);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string'],
            'to' => ['required', 'array', 'min:1', 'max:1000'],
            'to.*' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:4096'],
        ]);

        $session = $this->resolveSession($request, $data['session_id'] ?? null);

        if (! $session) {
            return $this->fail('Tidak ada sesi WhatsApp yang bisa dipakai.', 422);
        }

        $result = $this->dispatcher->queueBulk($session, $data['to'], [
            'type' => 'text',
            'body' => $data['message'],
            'api_key_id' => $this->apiKey($request)->id,
        ]);

        return $this->ok([
            'batch_id' => $result['batch_id'],
            'queued' => count($result['messages']),
            // Bagian dari `queued` yang ditunda masa pemanasan nomor pengirim.
            'scheduled' => count(array_filter($result['messages'], fn (Message $m) => $m->scheduled_at !== null)),
            'rejected' => $result['rejected'],
            'message_ids' => array_map(fn (Message $m) => $m->id, $result['messages']),
        ], 202);
    }

    public function template(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string'],
            'to' => ['required', 'string', 'max:40'],
            'template' => ['required', 'string', 'max:100'],
            'variables' => ['nullable', 'array'],
        ]);

        $template = $this->workspace($request)->templates()
            ->where('slug', $data['template'])
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return $this->fail("Template '{$data['template']}' tidak ditemukan atau tidak aktif.", 404);
        }

        $session = $this->resolveSession($request, $data['session_id'] ?? null);

        if (! $session) {
            return $this->fail('Tidak ada sesi WhatsApp yang bisa dipakai.', 422);
        }

        try {
            $message = $this->dispatcher->queue($session, $data['to'], [
                'type' => 'text',
                'body' => $template->render($data['variables'] ?? []),
                'api_key_id' => $this->apiKey($request)->id,
            ]);
        } catch (\Throwable $e) {
            return $this->fail(EngineError::pesan($e), 422);
        }

        return $this->ok($this->present($message), 202);
    }

    public function index(Request $request): JsonResponse
    {
        $messages = $this->workspace($request)->messages()
            ->when($request->query('session_id'), fn ($q, $v) => $q->where('wa_session_id', $v))
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('batch_id'), fn ($q, $v) => $q->where('batch_id', $v))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return $this->ok([
            'items' => $messages->getCollection()->map(fn (Message $m) => $this->present($m)),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $message = $this->workspace($request)->messages()->findOrFail($id);

        return $this->ok($this->present($message));
    }

    /**
     * Kalau pemanggil tidak menyebut sesi, dipilih satu sesi workspace yang sedang
     * terhubung. Sesi platform tidak pernah dipilih otomatis — nomor itu hanya
     * untuk pesan atas nama VexaHost, bukan trafik workspace.
     */
    private function resolveSession(Request $request, ?string $sessionId): ?WaSession
    {
        $workspace = $this->workspace($request);

        if ($sessionId) {
            return $workspace->sessions()->find($sessionId);
        }

        // Tanpa session_id, sesi terhubung tertua yang dipakai. Sengaja tidak
        // ada penyaringan lain: penyaringan yang tak terlihat di dashboard
        // pernah membuat pengirim yang sudah hijau tetap ditolak dengan pesan
        // "tidak ada sesi yang bisa dipakai", tanpa petunjuk apa pun.
        return $workspace->sessions()
            ->where('status', 'connected')
            ->orderBy('created_at')
            ->first();
    }

    private function present(Message $message): array
    {
        return [
            'id' => $message->id,
            'session_id' => $message->wa_session_id,
            'direction' => $message->direction,
            'to' => $message->to_number,
            'from' => $message->from_number,
            'type' => $message->type,
            'body' => $message->body,
            'status' => $message->status,
            'error' => $message->error,
            'batch_id' => $message->batch_id,
            'wa_message_id' => $message->wa_message_id,
            'created_at' => $message->created_at->toIso8601String(),
            // Terisi kalau pesannya ditunda masa pemanasan nomor pengirim —
            // supaya integrasi tahu pesannya pasti terkirim, hanya belum sekarang.
            'scheduled_at' => $message->scheduled_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
        ];
    }
}
