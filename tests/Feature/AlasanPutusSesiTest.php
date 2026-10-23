<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Jobs\SyncSessionStatusJob;
use App\Models\Message;
use App\Models\Notification;
use App\Models\SessionBackup;
use App\Models\SessionEvent;
use App\Models\User;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\SessionService;
use App\Services\WebhookDispatcher;
use App\Support\AlasanPutus;
use App\Support\Pemangkas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Apa yang terjadi setelah engine melaporkan KENAPA sebuah nomor putus.
 *
 * Sebelum ini semua alasan diperlakukan sama: `last_error` diisi kode mentah
 * (`logged_out`, `403`, `440`) yang tampil merah di kartu sesi, penjadwal
 * mencoba menyambung lagi tiap menit apa pun alasannya, dan kredensial yang
 * sudah dicabut WhatsApp tetap disimpan — sehingga tombol Hubungkan login
 * dengan kunci mati, ditolak lagi, dan kode QR tidak pernah muncul.
 */
class AlasanPutusSesiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $pemilik;

    private WaSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('session-backups');

        $this->pemilik = User::create([
            'name' => 'Pemilik',
            'email' => 'pemilik@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Toko Uji',
            'slug' => 'toko-uji',
            'owner_id' => $this->pemilik->id,
            'owner_email' => $this->pemilik->email,
            'max_sessions' => 2,
            'status' => 'active',
        ]);
        $this->workspace->members()->attach($this->pemilik->id, ['role' => 'owner']);

        $this->session = $this->workspace->sessions()->create([
            'name' => 'CS',
            'driver' => 'wwebjs',
            'status' => 'connected',
            'phone_number' => '6281111111111',
            'connected_at' => now()->subDay(),
        ]);
    }

    private function postEvent(array $body)
    {
        $json = json_encode($body);
        $timestamp = (string) now()->getTimestamp();

        return $this->call(
            'POST',
            '/internal/engine/events',
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-Engine-Timestamp' => $timestamp,
                'X-Engine-Signature' => hash_hmac('sha256', "{$timestamp}.{$json}", config('gateway.engine.hmac_secret')),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            $json
        );
    }

    private function putus(string $alasan, ?int $kode = null): void
    {
        $this->postEvent([
            'session_id' => $this->session->id,
            'event' => 'disconnected',
            'payload' => ['reason' => $alasan, 'status_code' => $kode],
        ])->assertOk();

        $this->session->refresh();
    }

    private function cadangan(): SessionBackup
    {
        $path = "{$this->session->id}/session-uji.json";
        Storage::disk('session-backups')->put($path, '{}');

        return $this->session->backups()->create([
            'disk' => 'session-backups',
            'path' => $path,
            'size' => 2,
            'backed_up_at' => now(),
        ]);
    }

    private function engineDiam(): void
    {
        Http::fake([
            '*/status' => Http::response(['status' => 'disconnected'], 404),
            '*/start' => Http::response(['status' => 'starting'], 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function jumlahStart(): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/start'))->count();
    }

    private function bootstrapIds(): array
    {
        $timestamp = (string) now()->getTimestamp();

        return collect($this->call('GET', '/internal/engine/bootstrap', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENGINE_TIMESTAMP' => $timestamp,
            'HTTP_X_ENGINE_SIGNATURE' => hash_hmac('sha256', $timestamp.'.', config('gateway.engine.hmac_secret')),
        ], '')->json('data'))->pluck('session_id')->all();
    }

    public function test_dikeluarkan_whatsapp_membuang_cadangan_dan_kembali_ke_belum_tertaut(): void
    {
        $backup = $this->cadangan();

        $this->putus(AlasanPutus::LOGGED_OUT, 401);

        $this->assertSame('disconnected', $this->session->status);
        $this->assertNull($this->session->connected_at, 'Tanpa kredensial, jalur otomatis tidak boleh mencoba lagi');
        $this->assertNull($this->session->phone_number);
        $this->assertSame(AlasanPutus::LOGGED_OUT, $this->session->disconnect_reason);

        // Cadangan yang dicabut WhatsApp harus hilang dari baris DAN disk —
        // kalau tidak, Hubungkan memulihkan kunci mati dan QR tidak pernah muncul.
        $this->assertSame(0, $this->session->backups()->count());
        Storage::disk('session-backups')->assertMissing($backup->path);

        $kejadian = $this->session->events()->latest('id')->first();
        $this->assertSame('disconnected', $kejadian->event);
        $this->assertSame(AlasanPutus::LOGGED_OUT, $kejadian->reason);
        $this->assertSame(401, $kejadian->status_code);
    }

    public function test_kartu_sesi_menampilkan_kalimat_bukan_kode_mentah(): void
    {
        foreach ([AlasanPutus::LOGGED_OUT, AlasanPutus::BANNED, AlasanPutus::REPLACED, AlasanPutus::CREDENTIALS_MISSING, AlasanPutus::CONNECTION_LOST, '428'] as $alasan) {
            $this->session->update(['status' => 'connected', 'connected_at' => now()]);

            $this->putus($alasan);

            $this->assertNotSame($alasan, $this->session->last_error);
            $this->assertStringContainsString('Hubungkan', (string) $this->session->last_error,
                "Kalimat untuk {$alasan} harus menyebut apa yang bisa dilakukan pemiliknya");
        }
    }

    public function test_nomor_yang_diblokir_tidak_dicoba_otomatis_dan_tidak_diumumkan_menyerah(): void
    {
        $this->cadangan();
        $this->putus(AlasanPutus::BANNED, 403);

        // Kredensialnya disimpan: nomor yang dipulihkan WhatsApp tersambung lagi tanpa scan.
        $this->assertSame(1, $this->session->backups()->count());
        $this->assertNotNull($this->session->connected_at);

        $this->engineDiam();

        for ($i = 0; $i < WaSession::BATAS_PERCOBAAN_SAMBUNG + 2; $i++) {
            (new SyncSessionStatusJob)->handle(app(SessionService::class));
        }

        $this->session->refresh();

        $this->assertSame(0, $this->jumlahStart(), 'Login berulang ke akun yang diblokir menambah sinyal buruk');
        $this->assertSame(AlasanPutus::kalimat(AlasanPutus::BANNED), $this->session->last_error,
            'Penjelasan yang spesifik tidak boleh ditimpa kabar "berhenti setelah sekian percobaan"');
        $this->assertSame(0, Notification::where('type', 'session.reconnect_gave_up')->count());
        $this->assertNotContains($this->session->id, $this->bootstrapIds());
    }

    /**
     * Pengambil alih yang paling mungkin adalah container lama saat deploy —
     * terutama deploy pertama versi berkunci data, saat engine lama masih
     * merebut balik. Sekali dicoba ulang sendiri; kalau terulang, berhenti.
     */
    public function test_diambil_alih_dicoba_sekali_lagi_lalu_menunggu_manusia(): void
    {
        $this->putus(AlasanPutus::REPLACED, 440);

        $this->assertSame(0, Notification::where('type', 'session.disconnected')->count(),
            'Kejadian pertama pulih sendiri — belum perlu membuat pemiliknya panik');

        $this->engineDiam();
        (new SyncSessionStatusJob)->handle(app(SessionService::class));
        $this->assertSame(0, $this->jumlahStart(), 'Belum lewat jeda dua menit');
        $this->assertNotContains($this->session->id, $this->bootstrapIds());

        $this->travel(3)->minutes();
        (new SyncSessionStatusJob)->handle(app(SessionService::class));
        $this->assertSame(1, $this->jumlahStart(), 'Satu percobaan otomatis');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/start') && $r->data()['restore_only'] === true);

        // Tersambung, lalu diambil alih lagi pada hari yang sama: itu sungguhan.
        $this->session->update(['status' => 'connected']);
        $this->putus(AlasanPutus::REPLACED, 440);

        $this->assertSame(1, Notification::where('type', 'session.disconnected')->count());

        $this->travel(3)->minutes();
        (new SyncSessionStatusJob)->handle(app(SessionService::class));
        $this->assertSame(1, $this->jumlahStart(), 'Tidak ada percobaan kedua — itu saling tendang');

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('sessions.connect', $this->session->id));

        $this->session->refresh();
        $this->assertNull($this->session->disconnect_reason, 'Hubungkan adalah keputusan manusia untuk mencoba lagi');
        $this->assertSame(2, $this->jumlahStart());
    }

    public function test_koneksi_hilang_tetap_disambung_ulang_otomatis_sebagai_pemulihan(): void
    {
        $this->putus(AlasanPutus::CONNECTION_LOST, 428);

        $this->engineDiam();
        (new SyncSessionStatusJob)->handle(app(SessionService::class));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/start') && $r->data()['restore_only'] === true);
        $this->assertContains($this->session->id, $this->bootstrapIds());
    }

    public function test_kredensial_tidak_bisa_dipulihkan_tidak_membuang_cadangan_dan_tidak_dicoba_lagi(): void
    {
        $this->cadangan();
        $this->putus(AlasanPutus::CREDENTIALS_MISSING);

        $this->assertNull($this->session->connected_at);
        $this->assertSame(1, $this->session->backups()->count(),
            'Yang hilang kredensial di volume; cadangannya tidak terbukti mati');

        $this->engineDiam();
        (new SyncSessionStatusJob)->handle(app(SessionService::class));

        $this->assertSame(0, $this->jumlahStart());
    }

    public function test_penjadwal_tidak_menyalakan_sesi_yang_belum_pernah_tertaut(): void
    {
        $this->session->update(['status' => 'disconnected', 'connected_at' => null]);

        $this->engineDiam();

        for ($i = 0; $i < 3; $i++) {
            (new SyncSessionStatusJob)->handle(app(SessionService::class));
        }

        $this->assertSame(0, $this->jumlahStart(), 'Tanpa kredensial, menyalakannya hanya memunculkan QR tanpa penonton');
    }

    public function test_hubungkan_oleh_manusia_tidak_dibatasi_hanya_pulihkan(): void
    {
        $this->session->update(['status' => 'disconnected', 'connected_at' => null]);
        $this->engineDiam();

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('sessions.connect', $this->session->id));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/start') && $r->data()['restore_only'] === false);

        $kejadian = $this->session->events()->latest('id')->first();
        $this->assertSame('connect_requested', $kejadian->event);
        $this->assertSame('manual', $kejadian->reason);
    }

    public function test_pemberitahuan_menyebut_alasan_sebenarnya(): void
    {
        $this->putus(AlasanPutus::LOGGED_OUT, 401);

        $kabar = Notification::where('type', 'session.disconnected')->first();

        $this->assertNotNull($kabar);
        $this->assertStringContainsString('scan kode QR', $kabar->body);
        $this->assertStringNotContainsString('kredensialnya masih tersimpan', $kabar->body,
            'Kredensial yang baru dibuang tidak boleh dibilang masih tersimpan');
    }

    public function test_webhook_status_sesi_membawa_alasan_putus(): void
    {
        Queue::fake();

        $this->workspace->webhooks()->create([
            'url' => 'https://contoh.id/hook',
            'secret' => 'rahasia-webhook-12345',
            'events' => [WebhookDispatcher::EVENT_SESSION_STATUS],
            'is_active' => true,
        ]);

        $this->putus(AlasanPutus::BANNED, 403);

        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job) => $job->event === WebhookDispatcher::EVENT_SESSION_STATUS
            && $job->payload['status'] === 'disconnected'
            && $job->payload['reason'] === AlasanPutus::BANNED);
    }

    public function test_pesan_masuk_yang_dikirim_ulang_whatsapp_tidak_tercatat_dua_kali(): void
    {
        Queue::fake();

        $this->workspace->webhooks()->create([
            'url' => 'https://contoh.id/hook',
            'secret' => 'rahasia-webhook-12345',
            'events' => [WebhookDispatcher::EVENT_MESSAGE_RECEIVED],
            'is_active' => true,
        ]);

        $pesan = [
            'session_id' => $this->session->id,
            'event' => 'message',
            'payload' => [
                'wa_message_id' => 'ULANG-1',
                'chat_id' => '6289999999999@c.us',
                'from' => '6289999999999',
                'type' => 'text',
                'body' => 'halo?',
                'timestamp' => 1_700_000_000,
                'is_backlog' => true,
            ],
        ];

        $this->postEvent($pesan)->assertOk();
        $this->postEvent($pesan)->assertOk();

        $this->assertSame(1, Message::where('wa_message_id', 'ULANG-1')->count());
        Queue::assertPushed(DeliverWebhookJob::class, 1);

        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job) => $job->payload['is_backlog'] === true
            && $job->payload['sent_at'] === now()->setTimestamp(1_700_000_000)->toIso8601String());
    }

    public function test_pesan_masuk_dari_engine_lama_tetap_diterima(): void
    {
        Queue::fake();

        $this->workspace->webhooks()->create([
            'url' => 'https://contoh.id/hook',
            'secret' => 'rahasia-webhook-12345',
            'events' => [WebhookDispatcher::EVENT_MESSAGE_RECEIVED],
            'is_active' => true,
        ]);

        $this->postEvent([
            'session_id' => $this->session->id,
            'event' => 'message',
            'payload' => ['wa_message_id' => null, 'chat_id' => '6289999999999@c.us', 'from' => '6289999999999', 'type' => 'text', 'body' => 'a'],
        ])->assertOk();

        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job) => $job->payload['is_backlog'] === false
            && $job->payload['sent_at'] === null);
    }

    public function test_bootstrap_mencatat_engine_menyala_ulang_di_riwayat(): void
    {
        $this->assertContains($this->session->id, $this->bootstrapIds());

        $this->assertTrue($this->session->events()->where('event', 'restore_requested')->exists());
    }

    public function test_halaman_riwayat_koneksi_di_panel_admin(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->putus(AlasanPutus::REPLACED, 440);

        $this->actingAs($admin)
            ->get(route('admin.sessions.show', $this->session->id))
            ->assertOk()
            ->assertSee('Riwayat koneksi')
            ->assertSee('diambil alih perangkat lain')
            ->assertSee('440');

        $this->actingAs($admin)
            ->get(route('admin.sessions'))
            ->assertOk()
            ->assertSee(route('admin.sessions.show', $this->session->id), false);

        // Bagi yang bukan super admin panel ini tidak tampak pernah ada.
        $this->actingAs($this->pemilik)
            ->get(route('admin.sessions.show', $this->session->id))
            ->assertNotFound();
    }

    public function test_riwayat_koneksi_dipangkas_setelah_masa_simpan(): void
    {
        $lama = $this->session->events()->create(['event' => 'connected']);
        $lama->forceFill(['created_at' => now()->subDays(config('gateway.retention.session_events_days') + 1)])->save();
        $baru = $this->session->events()->create(['event' => 'connected']);

        app(Pemangkas::class)->jalankan(hanya: 'session_events');

        $this->assertNull(SessionEvent::find($lama->id));
        $this->assertNotNull(SessionEvent::find($baru->id));
    }
}
