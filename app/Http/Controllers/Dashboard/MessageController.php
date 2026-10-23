<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWorkspaceSelected;
use App\Services\MessageDispatcher;
use App\Support\EngineError;
use App\Support\TemplateBawaan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function index(Request $request): View
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        $messages = $workspace->messages()
            ->with('session')
            ->when($request->query('session'), fn ($q, $v) => $q->where('wa_session_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('direction'), fn ($q, $v) => $q->where('direction', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where(function ($sub) use ($v): void {
                $sub->where('to_number', 'like', "%{$v}%")
                    ->orWhere('from_number', 'like', "%{$v}%")
                    ->orWhere('body', 'like', "%{$v}%");
            }))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('dashboard.messages.index', [
            'messages' => $messages,
            'sessions' => $workspace->sessions()->orderBy('name')->get(),
        ]);
    }

    public function compose(Request $request): View
    {
        return view('dashboard.messages.compose', [
            'sessions' => EnsureWorkspaceSelected::from($request)->sessions()
                ->where('status', 'connected')
                ->orderBy('name')
                ->get(),
            'templates' => EnsureWorkspaceSelected::from($request)->templates()->where('is_active', true)->get(),

            // Template bawaan tersedia untuk semua workspace tanpa perlu dibuat
            // lebih dulu — halaman ini kosong bagi pendaftar baru, dan halaman
            // kosong adalah tempat orang berhenti mencoba.
            'templateBawaan' => TemplateBawaan::perKategori(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'to' => ['required', 'string'],
            'message' => ['required', 'string', 'max:4096'],
        ]);

        $workspace = EnsureWorkspaceSelected::from($request);
        $session = $workspace->sessions()->findOrFail($data['session_id']);

        // Textarea "kirim ke" menerima banyak nomor dipisah baris atau koma,
        // supaya uji coba broadcast bisa dilakukan tanpa lewat API.
        $recipients = collect(preg_split('/[\s,;]+/', $data['to']))
            ->filter()
            ->values()
            ->all();

        if (count($recipients) === 1) {
            try {
                $message = $this->dispatcher->queue($session, $recipients[0], [
                    'type' => 'text',
                    'body' => $data['message'],
                ]);
            } catch (\Throwable $e) {
                return back()->withErrors(['to' => EngineError::pesan($e)])->withInput();
            }

            return redirect()->route('messages.index')
                ->with('status', $message->scheduled_at
                    ? "Pesan dijadwalkan {$message->scheduled_at->translatedFormat('j M, H:i')} karena nomor pengirim masih dalam masa pemanasan (ID {$message->id})."
                    : "Pesan diantre (ID {$message->id}).");
        }

        $result = $this->dispatcher->queueBulk($session, $recipients, [
            'type' => 'text',
            'body' => $data['message'],
        ]);

        $note = count($result['messages']).' pesan diantre.';

        $terjadwal = count(array_filter($result['messages'], fn ($m) => $m->scheduled_at !== null));

        if ($terjadwal > 0) {
            $note .= " {$terjadwal} di antaranya dijadwalkan ke hari berikutnya karena nomor pengirim masih dalam masa pemanasan.";
        }

        if ($result['rejected'] !== []) {
            $note .= ' '.count($result['rejected']).' nomor ditolak: '
                .implode(', ', array_column($result['rejected'], 'to'));
        }

        return redirect()->route('messages.index', ['batch' => $result['batch_id']])->with('status', $note);
    }

    public function show(Request $request, string $id): View
    {
        $message = EnsureWorkspaceSelected::from($request)->messages()->with('session')->findOrFail($id);

        return view('dashboard.messages.show', ['message' => $message]);
    }
}
