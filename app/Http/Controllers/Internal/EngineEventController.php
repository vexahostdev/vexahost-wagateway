<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\WaSession;
use App\Services\MessageDispatcher;
use App\Services\Notifications\BillingMessages;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\WhatsAppNotifier;
use App\Services\RemPengiriman;
use App\Services\WebhookDispatcher;
use App\Support\AlasanPutus;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Menerima seluruh kejadian dari engine Node: QR baru, sesi siap, sesi putus,
 * pesan masuk, dan perubahan status pengiriman.
 *
 * Endpoint ini yang membuat dashboard bisa menampilkan keadaan sebenarnya tanpa
 * menanyai engine terus-menerus.
 */
class EngineEventController extends Controller
{
    public function __construct(
        private readonly WebhookDispatcher $webhooks,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'event' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
        ]);

        $session = WaSession::with('workspace')->find($data['session_id']);

        if (! $session) {
            // Sesi sudah dihapus di dashboard tapi engine belum tahu. Balas 200
            // supaya engine berhenti mengulang, dan beri tahu agar dimatikan.
            return response()->json(['success' => true, 'action' => 'stop_session']);
        }

        $payload = $data['payload'] ?? [];

        match ($data['event']) {
            'qr' => $this->onQr($session, $payload),
            'authenticated' => $session->update(['status' => 'connecting', 'qr_payload' => null]),
            'ready' => $this->onReady($session, $payload),
            'disconnected' => $this->onDisconnected($session, $payload),
            'auth_failure' => $this->onAuthFailure($session, $payload),
            'message' => $this->onIncomingMessage($session, $payload),
            'message_ack' => $this->onAck($session, $payload),
            default => Log::info('Event engine tidak dikenal', ['event' => $data['event']]),
        };

        return response()->json(['success' => true]);
    }

    private function onQr(WaSession $session, array $payload): void
    {
        $session->update([
            'status' => 'qr',
            // Sudah berupa data URI PNG dari engine.
            'qr_payload' => $payload['qr_image'] ?? null,
            // whatsapp-web.js menerbitkan QR baru dengan jeda tidak tetap —
            // pengamatan menunjukkan 20 sampai 60 detik. Masa berlaku harus
            // lebih panjang dari jeda terlama itu, kalau tidak akan ada celah
            // di mana QR dianggap kedaluwarsa padahal penggantinya belum
            // datang, dan modal di dashboard mendadak kosong saat pengguna
            // sedang bersiap men-scan. QR yang basi bukan masalah: begitu event
            // berikutnya tiba, nilainya langsung ditimpa.
            'qr_expires_at' => now()->addSeconds(config('gateway.qr_ttl_seconds')),
        ]);

        $this->webhooks->dispatch($session->workspace, WebhookDispatcher::EVENT_SESSION_QR, [
            'session_id' => $session->id,
            'status' => 'qr',
        ]);
    }

    private function onReady(WaSession $session, array $payload): void
    {
        $nomor = PhoneNumber::normalize($payload['phone_number'] ?? null);

        $session->catatNomorTertaut($nomor);

        $session->update([
            'status' => 'connected',
            'phone_number' => $nomor,
            'push_name' => $payload['push_name'] ?? null,
            'qr_payload' => null,
            'qr_expires_at' => null,
            'connected_at' => now(),
            'last_seen_at' => now(),
            'last_error' => null,
            'disconnect_reason' => null,
            // Satu-satunya bukti yang tidak bisa dibantah bahwa penyambungannya
            // berhasil. Penghitung percobaan otomatis kembali nol HANYA di sini
            // dan pada permintaan manusia — bukan pada `connecting`, yang justru
            // status yang dipegang sesi macet selamanya.
            'connect_failures' => 0,
        ]);

        $session->catatKejadian('connected');

        $this->notifySessionStatus($session, 'connected');
    }

    private function onDisconnected(WaSession $session, array $payload): void
    {
        $alasan = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
        $kode = is_numeric($payload['status_code'] ?? null) ? (int) $payload['status_code'] : null;

        $sempatTersambung = $session->connected_at !== null && $session->status === 'connected';
        $penanda = 'session-down:'.$session->id.':'.optional($session->connected_at)->timestamp;

        // `last_error` tampil di kartu sesi dan di modal QR — muka pengguna,
        // jadi diisi kalimat, bukan kode mentah dari engine.
        $perubahan = [
            'status' => 'disconnected',
            'qr_payload' => null,
            'last_error' => AlasanPutus::kalimat($alasan),
            // Dibaca penjadwal dan bootstrap: nomor yang diblokir atau diambil
            // alih tidak dicoba sendiri sampai manusia menekan Hubungkan.
            'disconnect_reason' => $alasan,
        ];

        if (AlasanPutus::perluTautUlang($alasan)) {
            // Sesinya kembali ke keadaan "belum tertaut", sama dengan setelah
            // Putus tautan. Tanpa ini penjadwal dan bootstrap terus mencoba
            // memulihkan nomor yang tidak punya kredensial untuk dipulihkan.
            $perubahan += [
                'phone_number' => null,
                'push_name' => null,
                'connected_at' => null,
                'connect_failures' => 0,
            ];
        }

        $session->update($perubahan);

        if (AlasanPutus::cadanganMati($alasan)) {
            $session->hapusCadangan();
        }

        $session->catatKejadian('disconnected', $alasan, $kode);

        $this->notifySessionStatus($session, 'disconnected', $alasan);

        // Diambil alih untuk pertama kalinya hari ini dicoba ulang sendiri dua
        // menit lagi (SyncSessionStatusJob::cobaSekaliLagi) — penyebab
        // tersering adalah container lama saat deploy. Mengabari pemiliknya
        // sekarang berarti kabar menakutkan untuk sesuatu yang pulih sendiri.
        // Webhook di atas tetap terkirim: itu keadaan sebenarnya.
        if ($alasan === AlasanPutus::REPLACED && $session->diambilAlih24Jam()->count() === 1) {
            return;
        }

        /*
         | Pemberitahuan paling berharga di produk ini.
         |
         | Gateway yang mati diam membuat pelanggan baru sadar setelah berhari-
         | hari pesan tidak terkirim — biasanya saat pelanggan *mereka* yang
         | mengeluh. Satu pesan ke nomor tagihan mengubah kegagalan diam menjadi
         | kegagalan yang terlihat dalam hitungan menit.
         |
         | Hanya untuk sesi yang tadinya benar-benar tersambung: sesi yang
         | memang belum pernah discan bukan gangguan, dan mengabarkannya berarti
         | mengganggu orang dengan sesuatu yang sudah mereka ketahui.
         |
         | Penandanya memuat `connected_at` supaya pemutusan berikutnya — setelah
         | nomor itu tersambung lagi — tetap dikabarkan, sementara rentetan
         | disconnect dari satu kejadian yang sama tidak berubah jadi spam.
        */
        if ($sempatTersambung && $session->workspace) {
            app(WhatsAppNotifier::class)->toWorkspace(
                $session->workspace,
                BillingMessages::sessionDisconnected($session, $alasan),
                $penanda,
                6,
            );

            // Penandanya sama persis dengan yang di atas, jadi rentetan
            // disconnect dari satu kejadian yang sama tidak berubah jadi
            // sederet baris identik di lonceng.
            app(Notifier::class)->keWorkspace(
                workspace: $session->workspace,
                type: 'session.disconnected',
                title: "Nomor {$session->name} terputus",
                body: AlasanPutus::perluTautUlang($alasan)
                    ? 'Nomor itu perlu ditautkan ulang lewat scan kode QR. Buka halaman Sesi untuk menghubungkannya.'
                    : 'Pesan keluar dari nomor itu berhenti terkirim. Buka halaman Sesi untuk '
                        .'menghubungkannya lagi — kredensialnya masih tersimpan.',
                url: route('sessions.index', absolute: false),
                level: 'danger',
                dedupe: $penanda,
            );
        }
    }

    private function onAuthFailure(WaSession $session, array $payload): void
    {
        $session->update([
            'status' => 'failed',
            'qr_payload' => null,
            'last_error' => $payload['message'] ?? 'Autentikasi WhatsApp gagal.',
        ]);

        $session->catatKejadian('auth_failure', null, null, $payload['message'] ?? null);

        /*
         | Kegagalan yang BENAR-BENAR dilaporkan ikut dihitung.
         |
         | `SyncSessionStatusJob` sudah menghitung percobaannya sendiri, tapi ia
         | hanya menyentuh sesi `disconnected`. Percobaan yang dimulai dari
         | tempat lain tidak pernah terhitung di mana pun: pemulihan otomatis
         | saat engine menyala (`BootstrapController` memulihkan `failed` yang
         | pernah tersambung), dan tombol Hubungkan.
         |
         | Produksi 8 September 2026 menunjukkan persis lubang itu: dua sesi
         | berstatus `failed` punya proses engine hidup, dan salah satunya beranak
         | tiap menit. `failed` tidak pernah masuk daftar kandidat penjadwal,
         | jadi penghitungnya tidak pernah naik, jadi tidak ada yang berhenti.
        */
        $session->catatPercobaanSambung();

        $this->notifySessionStatus($session, 'failed');
    }

    private function onIncomingMessage(WaSession $session, array $payload): void
    {
        /*
         | Pesan masuk ikut berhenti saat langganan mati.
         |
         | Sampai 6 September 2026 hanya pengiriman keluar yang ditutup, dan
         | pesan masuk tetap tercatat serta tetap dikirim ke webhook pelanggan
         | sepanjang masa tenggang. Itu berarti integrasi mereka tetap berjalan
         | separuh — separuh yang justru paling banyak dipakai orang yang
         | memakai gateway ini untuk mencatat percakapan ke CRM — tanpa satu
         | rupiah pun dibayar.
         |
         | Yang TIDAK hilang, dan wajib disebut supaya tidak menakutkan lebih
         | dari semestinya: pesannya sendiri tetap ada di WhatsApp pelanggan.
         | Kita perangkat tertaut, bukan pemilik akunnya — yang berhenti cuma
         | pencatatan dan penerusannya ke sistem mereka.
         |
         | Yang sengaja TIDAK ikut ditutup: peristiwa siklus hidup sesi (qr,
         | ready, disconnected) dan ack pesan yang sudah telanjur terkirim.
         | Keduanya justru yang memberi tahu sistem pelanggan bahwa nomornya
         | berhenti; membisukannya berarti kegagalan yang tidak bisa dilacak
         | siapa pun.
        */
        if (! $session->workspace?->isActive()) {
            Log::info('Pesan masuk diabaikan karena langganan tidak aktif', [
                'workspace_id' => $session->workspace_id,
                'session_id' => $session->id,
            ]);

            return;
        }

        $waId = $payload['wa_message_id'] ?? null;

        /*
         | Pesan yang sama bisa datang dua kali.
         |
         | WhatsApp mengirim ulang pesan yang penerimaannya belum sempat
         | dikonfirmasi sebelum sambungan putus — dan sambungan putus tiap
         | deploy. Tanpa penyaring ini satu pesan menjadi dua baris dan dua
         | webhook, dan integrasi yang membalas otomatis menjawabnya dua kali.
         | Nilai null diperiksa lebih dulu: `where(kolom, null)` menjadi
         | `whereNull` dan akan cocok dengan pesan mana pun tanpa id.
        */
        if ($waId !== null && Message::where('wa_session_id', $session->id)
            ->where('direction', 'inbound')
            ->where('wa_message_id', $waId)
            ->exists()) {
            return;
        }

        $chatId = $payload['chat_id'] ?? null;
        if (is_string($chatId) && str_ends_with($chatId, '@s.whatsapp.net')) {
            $chatId = substr($chatId, 0, -strlen('@s.whatsapp.net')).'@c.us';
        }

        $message = Message::create([
            'workspace_id' => $session->workspace_id,
            'wa_session_id' => $session->id,
            'direction' => 'inbound',
            'wa_message_id' => $waId,
            'chat_id' => $chatId,
            'from_number' => PhoneNumber::normalize($payload['from'] ?? null),
            'to_number' => $session->phone_number,
            'type' => $payload['type'] ?? 'text',
            'body' => $payload['body'] ?? null,
            'status' => 'delivered',
        ]);

        $this->dispatcher->incrementUsage($session->workspace, 'messages_received');

        $this->webhooks->dispatch($session->workspace, WebhookDispatcher::EVENT_MESSAGE_RECEIVED, [
            'message_id' => $message->id,
            'session_id' => $session->id,
            'from' => $message->from_number,
            'chat_id' => $message->chat_id,
            'type' => $message->type,
            'body' => $message->body,
            'is_group' => str_ends_with((string) $message->chat_id, '@g.us'),
            'received_at' => $message->created_at->toIso8601String(),
            // Waktu pengirim mengirimnya, dan penanda pesan yang menumpuk selama
            // nomor offline. Tumpukan itu datang sekaligus tiap sesi tersambung
            // lagi — setiap deploy — dan tanpa dua kolom ini integrasi yang
            // membalas otomatis menjawab seluruhnya dalam satu ledakan.
            'sent_at' => is_numeric($payload['timestamp'] ?? null) && (int) $payload['timestamp'] > 0
                ? now()->setTimestamp((int) $payload['timestamp'])->toIso8601String()
                : null,
            'is_backlog' => (bool) ($payload['is_backlog'] ?? false),
        ]);

        // STOP di grup bukan permintaan berhenti dari satu orang ke bisnis ini.
        if (! str_ends_with((string) $message->chat_id, '@g.us') && $message->from_number !== null) {
            app(RemPengiriman::class)->catatJawaban($session, $message->from_number, $message->body);
        }
    }

    /**
     * WhatsApp mengirim ack bertingkat: 1 terkirim ke server, 2 sampai ke HP
     * tujuan, 3 dibaca. Ack bisa datang tidak berurutan, jadi Message
     * memastikan status hanya boleh maju.
     */
    private function onAck(WaSession $session, array $payload): void
    {
        $ourId = $payload['message_id'] ?? null;
        $waId = $payload['wa_message_id'] ?? null;

        // Tanpa penjagaan ini, `where('wa_message_id', null)` diterjemahkan
        // Laravel menjadi `whereNull(...)` — dan itu cocok dengan pesan mana pun
        // yang belum punya id WhatsApp, yaitu pesan yang masih mengantre. Satu
        // ack tanpa id akan menandai pesan yang belum terkirim sebagai terbaca.
        if ($ourId === null && $waId === null) {
            return;
        }

        $message = Message::where('wa_session_id', $session->id)
            ->where(function ($q) use ($ourId, $waId): void {
                if ($ourId !== null) {
                    $q->orWhere('id', $ourId);
                }

                if ($waId !== null) {
                    $q->orWhere('wa_message_id', $waId);
                }
            })
            ->first();

        if (! $message) {
            return;
        }

        $status = match ((int) ($payload['ack'] ?? 0)) {
            1 => 'sent',
            2 => 'delivered',
            3, 4 => 'read',
            default => null,
        };

        if ($status === null || ! $message->advanceStatus($status)) {
            return;
        }

        $message->forceFill([
            'delivered_at' => $status === 'delivered' ? now() : $message->delivered_at,
            'read_at' => $status === 'read' ? now() : $message->read_at,
        ])->save();

        $this->webhooks->dispatch($session->workspace, WebhookDispatcher::EVENT_MESSAGE_STATUS, [
            'message_id' => $message->id,
            'status' => $status,
            'to' => $message->to_number,
        ], $message->api_key_id);
    }

    private function notifySessionStatus(WaSession $session, string $status, ?string $alasan = null): void
    {
        $this->webhooks->dispatch($session->workspace, WebhookDispatcher::EVENT_SESSION_STATUS, [
            'session_id' => $session->id,
            'name' => $session->name,
            'status' => $status,
            'phone_number' => $session->phone_number,
            // Untuk `disconnected`: sistem pelanggan perlu tahu apakah nomornya
            // akan pulih sendiri atau harus discan ulang oleh manusia.
            'reason' => $alasan,
        ]);
    }
}
