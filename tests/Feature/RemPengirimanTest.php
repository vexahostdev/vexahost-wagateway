<?php

namespace Tests\Feature;

use App\Jobs\PantauNomorJob;
use App\Jobs\SendMessageJob;
use App\Models\ApiKey;
use App\Models\Message;
use App\Models\Notification;
use App\Models\OptOut;
use App\Models\SpecialNumber;
use App\Models\User;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\MessageDispatcher;
use App\Services\RemPengiriman;
use App\Support\Pemangkas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Rem pengiriman: masa pemanasan nomor baru, rem balas-balasan bot, daftar
 * berhenti berlangganan, dan peringatan dini.
 *
 * Yang dijaga di sini bukan cuma bahwa remnya bekerja, tapi bahwa ia TIDAK
 * menghalangi pemakaian sah: pesan yang ditunda tetap terkirim, OTP dan
 * balasan tidak pernah ditunda, penolakan tidak memotong kuota, dan nomor
 * yang sudah lewat masa pemanasan tidak merasakan apa-apa.
 */
class RemPengirimanTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $pemilik;

    private WaSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::flush();

        $this->pemilik = User::create([
            'name' => 'Pemilik',
            'email' => 'pemilik@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Toko Rem',
            'slug' => 'toko-rem',
            'owner_id' => $this->pemilik->id,
            'owner_email' => $this->pemilik->email,
            'max_sessions' => 2,
            'monthly_message_quota' => 100_000,
            'api_rate_limit_per_minute' => 10_000,
            'status' => 'active',
        ]);
        $this->workspace->members()->attach($this->pemilik->id, ['role' => 'owner']);

        $this->session = $this->workspace->sessions()->create([
            'name' => 'CS',
            'driver' => 'wwebjs',
            'status' => 'connected',
            'phone_number' => '6281111111111',
            'connected_at' => now(),
            'warmup_phone' => '6281111111111',
            'warmup_started_at' => now(),
        ]);
    }

    private function kirim(string $ke, array $tambahan = []): Message
    {
        return app(MessageDispatcher::class)->queue($this->session->fresh(), $ke, ['body' => 'Halo'] + $tambahan);
    }

    private function nomor(int $i): string
    {
        return '62812'.str_pad((string) $i, 8, '0', STR_PAD_LEFT);
    }

    private function terpakai(): int
    {
        return (int) $this->workspace->fresh()->currentUsage()->messages_sent;
    }

    private function pesanMasuk(string $dari, string $isi, ?string $chatId = null): void
    {
        $body = [
            'session_id' => $this->session->id,
            'event' => 'message',
            'payload' => [
                'wa_message_id' => 'IN-'.uniqid(),
                'chat_id' => $chatId ?? "{$dari}@c.us",
                'from' => $dari,
                'type' => 'text',
                'body' => $isi,
            ],
        ];
        $json = json_encode($body);
        $timestamp = (string) now()->getTimestamp();

        $this->call('POST', '/internal/engine/events', [], [], [], $this->transformHeadersToServerVars([
            'X-Engine-Timestamp' => $timestamp,
            'X-Engine-Signature' => hash_hmac('sha256', "{$timestamp}.{$json}", config('gateway.engine.hmac_secret')),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), $json)->assertOk();
    }

    // ---------------------------------------------------------------- pemanasan

    public function test_nomor_baru_dibatasi_per_hari_dan_sisanya_dijadwalkan_bukan_dibuang(): void
    {
        $pesan = collect(range(1, 52))->map(fn (int $i) => $this->kirim($this->nomor($i)));

        $this->assertSame(50, $pesan->whereNull('scheduled_at')->count(), 'Hari pertama: 50 pesan langsung berangkat');

        $besok = now()->addDay()->startOfDay()->setTime(8, 0);
        $pesan->whereNotNull('scheduled_at')->each(fn (Message $m) => $this->assertTrue($m->scheduled_at->equalTo($besok)));

        // Pesan yang ditunda TETAP diantrekan — hanya dengan jeda — dan kuotanya
        // tercatat, karena ia pasti terkirim.
        Queue::assertPushed(SendMessageJob::class, 52);
        $this->assertSame(2, collect(Queue::pushed(SendMessageJob::class))->filter(fn ($job) => $job->delay !== null)->count());
        $this->assertSame(52, $this->terpakai());

        $status = app(RemPengiriman::class)->statusPemanasan($this->session->fresh());
        $this->assertSame(['hari' => 1, 'sampai' => 30, 'batas' => 50, 'terpakai' => 50, 'tertunda' => 2], $status);
    }

    public function test_jadwal_melompati_hari_yang_jatahnya_sudah_penuh(): void
    {
        foreach ([0, 1] as $geser) {
            DB::table('warmup_counters')->insert([
                'wa_session_id' => $this->session->id,
                'day' => now()->addDays($geser)->toDateString(),
                'sent' => 50,
            ]);
        }

        $pesan = $this->kirim($this->nomor(1));

        $this->assertTrue($pesan->scheduled_at->equalTo(now()->addDays(2)->startOfDay()->setTime(8, 0)));
    }

    public function test_batas_naik_bertahap_dan_hilang_setelah_masa_pemanasan(): void
    {
        $rem = app(RemPengiriman::class);

        foreach ([1 => 50, 3 => 50, 4 => 150, 7 => 150, 8 => 300, 14 => 300, 15 => 1000, 30 => 1000] as $hari => $batas) {
            $this->session->update(['warmup_started_at' => now()->subDays($hari - 1)]);
            $this->assertSame($batas, $rem->statusPemanasan($this->session->fresh())['batas'], "Hari ke-{$hari}");
        }

        $this->session->update(['warmup_started_at' => now()->subDays(30)]);
        $this->assertNull($rem->statusPemanasan($this->session->fresh()), 'Hari ke-31: tidak ada batas lagi');

        foreach (range(1, 60) as $i) {
            $this->assertNull($this->kirim($this->nomor($i))->scheduled_at);
        }

        $this->assertSame(0, DB::table('warmup_counters')->count(), 'Nomor lama tidak menyentuh penghitung sama sekali');
    }

    public function test_otp_dan_balasan_ke_orang_yang_baru_menghubungi_tidak_pernah_ditunda(): void
    {
        DB::table('warmup_counters')->insert(['wa_session_id' => $this->session->id, 'day' => now()->toDateString(), 'sent' => 50]);

        $this->assertNull($this->kirim($this->nomor(1), ['is_otp' => true])->scheduled_at);

        $this->pesanMasuk('6289999999999', 'Halo, pesanan saya sudah dikirim?');
        $this->assertNull($this->kirim('6289999999999')->scheduled_at, 'Balasan ke orang yang baru bertanya');

        // Pesan biasa ke orang lain tetap ditunda.
        $this->assertNotNull($this->kirim($this->nomor(2))->scheduled_at);

        $this->assertSame(52, (int) DB::table('warmup_counters')->where('day', now()->toDateString())->value('sent'),
            'Yang dibebaskan tetap dihitung, supaya angkanya jujur');
    }

    public function test_nomor_perusahaan_sendiri_tidak_dipanaskan(): void
    {
        SpecialNumber::create(['phone' => '6281111111111', 'label' => 'Notifikasi VexaHost']);
        Cache::forget('nomor_istimewa');

        foreach (range(1, 55) as $i) {
            $this->assertNull($this->kirim($this->nomor($i))->scheduled_at);
        }
    }

    public function test_lewati_pemanasan_melepas_pesan_terjadwal_saat_itu_juga(): void
    {
        foreach (range(1, 53) as $i) {
            $this->kirim($this->nomor($i));
        }

        Queue::fake();

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('sessions.warmup.skip', $this->session->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNotNull($this->session->fresh()->warmup_skipped_at);
        $this->assertSame(0, Message::whereNotNull('scheduled_at')->count());
        Queue::assertPushed(SendMessageJob::class, fn ($job) => $job->delay === null);
        Queue::assertPushed(SendMessageJob::class, 3);
        $this->assertTrue($this->session->events()->where('event', 'warmup_skipped')->exists());

        // Ditanyakan langsung ke rem: membuka dashboard di atas membuatkan
        // langganan coba gratis untuk workspace uji ini, dan jatahnya yang
        // lima pesan bukan yang sedang diuji di sini.
        $rem = app(RemPengiriman::class);
        $this->assertNull($rem->statusPemanasan($this->session->fresh()));
        $this->assertNull($rem->periksa($this->session->fresh(), $this->nomor(99), $this->nomor(99).'@c.us'));
    }

    /**
     * Keputusan Ryan: nomor yang sudah berjalan saat rem ini dipasang tidak
     * boleh merasakan perubahan apa pun sesudah deploy.
     */
    public function test_nomor_yang_sudah_tertaut_saat_migrasi_dibebaskan_dari_pemanasan(): void
    {
        $migrasi = require database_path('migrations/2026_10_23_120000_create_sending_brakes.php');
        $migrasi->down();

        $baris = fn (string $nama, ?string $nomor) => [
            'id' => $id = (string) Str::ulid(),
            'workspace_id' => $this->workspace->id,
            'name' => $nama,
            'driver' => 'wwebjs',
            'status' => $nomor ? 'connected' : 'pending',
            'phone_number' => $nomor,
            'created_at' => now()->subDays(5),
            'updated_at' => now(),
        ];

        DB::table('wa_sessions')->insert($berjalan = $baris('Sudah berjalan', '6289000000001'));
        DB::table('wa_sessions')->insert($dihapus = $baris('Sudah dihapus', '6289000000002') + ['deleted_at' => now()]);
        DB::table('wa_sessions')->insert($belum = $baris('Belum ditautkan', null));

        $migrasi->up();

        $sesiBerjalan = WaSession::find($berjalan['id']);
        $this->assertNotNull($sesiBerjalan->warmup_skipped_at);
        $this->assertNull(app(RemPengiriman::class)->statusPemanasan($sesiBerjalan), 'Nomor berumur 5 hari pun bebas');

        // Nomor dari sesi yang sudah dihapus tetap bebas kalau ditautkan ulang.
        $baru = $this->workspace->sessions()->create(['name' => 'Tautan ulang', 'driver' => 'wwebjs', 'status' => 'connecting']);
        $baru->catatNomorTertaut('6289000000002');
        $this->assertNotNull($baru->fresh()->warmup_skipped_at);

        // Sesi yang belum pernah tertaut dipanaskan saat nomor pertamanya masuk.
        $this->assertNull(WaSession::find($belum['id'])->warmup_skipped_at);
    }

    public function test_nomor_yang_ditautkan_ulang_mewarisi_umurnya(): void
    {
        $lama = $this->workspace->sessions()->create([
            'name' => 'Lama',
            'driver' => 'wwebjs',
            'status' => 'disconnected',
            'warmup_phone' => '6282222222222',
            'warmup_started_at' => now()->subDays(40),
        ]);
        $lama->delete();

        $baru = $this->workspace->sessions()->create(['name' => 'Baru', 'driver' => 'wwebjs', 'status' => 'connecting']);
        $baru->catatNomorTertaut('6282222222222');
        $this->assertTrue($baru->fresh()->warmup_started_at->lt(now()->subDays(39)), 'Menghapus lalu membuat ulang sesi tidak mengulang pemanasan');

        $baru->catatNomorTertaut('6283333333333');
        $this->assertTrue($baru->fresh()->warmup_started_at->gt(now()->subMinute()), 'Nomor yang benar-benar baru mulai dari hari pertama');
    }

    // ------------------------------------------------------- balas-balasan bot

    public function test_balas_balasan_bot_ditolak_tanpa_memotong_kuota(): void
    {
        $this->session->update(['warmup_skipped_at' => now()]);

        foreach (range(1, 40) as $i) {
            $this->kirim('6289999999999');
        }

        foreach (range(1, 2) as $i) {
            try {
                $this->kirim('6289999999999');
                $this->fail('Pesan ke-41 ke chat yang sama seharusnya ditolak');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('saling membalas otomatis', $e->getMessage());
            }
        }

        $this->assertSame(40, $this->terpakai(), 'Pesan yang ditolak tidak boleh memotong kuota');
        $this->assertSame(1, Notification::where('type', 'sending.loop')->count(), 'Satu kabar, bukan satu per pesan');

        // Chat lain tidak ikut tertahan.
        $this->kirim('6288888888888');
        $this->assertSame(41, $this->terpakai());

        // Jendela berikutnya dibuka lagi.
        $this->travel(11)->minutes();
        $this->kirim('6289999999999');
        $this->assertSame(42, $this->terpakai());
    }

    // ---------------------------------------------------- berhenti berlangganan

    public function test_balasan_stop_menghentikan_pengiriman_kecuali_otp_dan_balasan(): void
    {
        $this->session->update(['warmup_skipped_at' => now()]);

        $this->pesanMasuk('6287777777777', '  Stop. ');
        $this->assertSame('STOP', OptOut::where('phone', '6287777777777')->value('keyword'));

        try {
            $this->kirim('6287777777777');
            $this->fail('Pesan ke nomor yang membalas STOP seharusnya ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('meminta berhenti', $e->getMessage());
        }

        $this->assertSame(0, $this->terpakai(), 'Penolakan tidak memotong kuota');

        // OTP tetap terkirim — orangnya sendiri yang memintanya.
        $this->kirim('6287777777777', ['is_otp' => true]);

        // Menghubungi lagi SETELAH STOP berarti boleh dibalas.
        $this->travel(1)->minutes();
        $this->pesanMasuk('6287777777777', 'Kak, mau tanya stok');
        $this->kirim('6287777777777');

        $this->pesanMasuk('6287777777777', 'MULAI');
        $this->assertFalse(OptOut::where('phone', '6287777777777')->exists());
    }

    public function test_stop_di_grup_atau_di_tengah_kalimat_bukan_permintaan_berhenti(): void
    {
        $this->pesanMasuk('6286666666666', 'STOP', '120363000000@g.us');
        $this->pesanMasuk('6285555555555', 'jangan stop dulu ya promonya');

        $this->assertSame(0, OptOut::count());
    }

    public function test_api_menyampaikan_alasan_penolakan_dan_jadwal_apa_adanya(): void
    {
        [, $kunci] = ApiKey::issue($this->workspace, 'uji rem');

        OptOut::create(['workspace_id' => $this->workspace->id, 'phone' => '6287777777777', 'keyword' => 'STOP']);

        $ditolak = $this->withHeader('X-Api-Key', $kunci)
            ->postJson('/api/v1/messages/text', ['to' => '6287777777777', 'message' => 'Promo'])
            ->assertStatus(422);

        $this->assertStringContainsString('meminta berhenti', $ditolak->json('error.message') ?? $ditolak->json('message'),
            'Alasan aslinya harus sampai, bukan diganti kalimat galat umum');

        DB::table('warmup_counters')->insert(['wa_session_id' => $this->session->id, 'day' => now()->toDateString(), 'sent' => 50]);

        $this->withHeader('X-Api-Key', $kunci)
            ->postJson('/api/v1/messages/text', ['to' => $this->nomor(1), 'message' => 'Invoice'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.scheduled_at', now()->addDay()->startOfDay()->setTime(8, 0)->toIso8601String());
    }

    public function test_halaman_berhenti_berlangganan_hanya_milik_workspace_sendiri(): void
    {
        $milikSendiri = OptOut::create(['workspace_id' => $this->workspace->id, 'phone' => '6287777777777', 'keyword' => 'STOP']);

        $lain = Workspace::create(['name' => 'Lain', 'slug' => 'lain', 'max_sessions' => 1, 'status' => 'active']);
        $milikLain = OptOut::create(['workspace_id' => $lain->id, 'phone' => '6284444444444', 'keyword' => 'BERHENTI']);

        $sebagaiPemilik = $this->actingAs($this->pemilik)->withSession(['current_workspace_id' => $this->workspace->id]);

        $sebagaiPemilik->get(route('opt-outs.index'))
            ->assertOk()
            ->assertSee('6287777777777')
            ->assertDontSee('6284444444444');

        $sebagaiPemilik->delete(route('opt-outs.destroy', $milikLain->id))->assertNotFound();
        $this->assertTrue(OptOut::whereKey($milikLain->id)->exists());

        $sebagaiPemilik->delete(route('opt-outs.destroy', $milikSendiri->id))->assertRedirect();
        $this->assertFalse(OptOut::whereKey($milikSendiri->id)->exists());
    }

    public function test_halaman_sesi_menampilkan_masa_pemanasan_dan_jalan_keluarnya(): void
    {
        $this->kirim($this->nomor(1));

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('sessions.index'))
            ->assertOk()
            ->assertSee('Masa pemanasan')
            ->assertSee('1/50 pesan hari ini')
            ->assertSee('Lewati pemanasan');
    }

    public function test_riwayat_pesan_menyebut_jadwal_kirim_bukan_sekadar_mengantre(): void
    {
        DB::table('warmup_counters')->insert(['wa_session_id' => $this->session->id, 'day' => now()->toDateString(), 'sent' => 50]);
        $pesan = $this->kirim($this->nomor(1));
        $jam = $pesan->scheduled_at->translatedFormat('j M, H:i');

        $sebagaiPemilik = $this->actingAs($this->pemilik)->withSession(['current_workspace_id' => $this->workspace->id]);

        $sebagaiPemilik->get(route('messages.index'))->assertOk()->assertSee("dikirim {$jam}");
        $sebagaiPemilik->get(route('messages.show', $pesan->id))->assertOk()->assertSee('masa pemanasan');
    }

    // ----------------------------------------------------------- peringatan dini

    private function pesanTerkirim(int $jumlah, string $status, string $chatSuffix = '@c.us'): void
    {
        foreach (range(1, $jumlah) as $i) {
            $nomor = $this->nomor($i + random_int(0, 9_000_000));

            Message::create([
                'workspace_id' => $this->workspace->id,
                'wa_session_id' => $this->session->id,
                'direction' => 'outbound',
                'chat_id' => $chatSuffix === '@g.us' ? "1203630{$i}@g.us" : "{$nomor}@c.us",
                'to_number' => $nomor,
                'type' => 'text',
                'body' => 'Tagihan',
                'status' => $status,
            ])->forceFill(['created_at' => now()->subHours(3), 'sent_at' => now()->subHours(3)])->save();
        }
    }

    public function test_peringatan_dini_saat_banyak_pesan_tidak_sampai(): void
    {
        $this->pesanTerkirim(70, 'sent');
        $this->pesanTerkirim(50, 'delivered');

        (new PantauNomorJob)->handle(app(RemPengiriman::class));
        (new PantauNomorJob)->handle(app(RemPengiriman::class));

        $kabar = Notification::where('type', 'session.delivery_low')->get();
        $this->assertCount(1, $kabar, 'Satu kabar per hari, bukan per jam');
        $this->assertStringContainsString('70 dari 120', $kabar->first()->body);
        $this->assertSame(1, $this->session->events()->where('event', 'delivery_low')->count());
    }

    public function test_peringatan_dini_tidak_berbunyi_untuk_keadaan_wajar(): void
    {
        // Sebagian penerima memang sedang offline — itu wajar.
        $this->pesanTerkirim(30, 'sent');
        $this->pesanTerkirim(90, 'delivered');
        // Status pengiriman ke grup tidak dihitung.
        $this->pesanTerkirim(200, 'sent', '@g.us');

        (new PantauNomorJob)->handle(app(RemPengiriman::class));

        $this->assertSame(0, Notification::where('type', 'session.delivery_low')->count());
    }

    public function test_peringatan_dini_butuh_sampel_yang_cukup(): void
    {
        $this->pesanTerkirim(60, 'sent');

        (new PantauNomorJob)->handle(app(RemPengiriman::class));

        $this->assertSame(0, Notification::where('type', 'session.delivery_low')->count());
    }

    public function test_penghitung_pemanasan_dipangkas(): void
    {
        DB::table('warmup_counters')->insert([
            ['wa_session_id' => $this->session->id, 'day' => now()->subDays(10)->toDateString(), 'sent' => 50],
            ['wa_session_id' => $this->session->id, 'day' => now()->toDateString(), 'sent' => 5],
        ]);

        app(Pemangkas::class)->jalankan(hanya: 'warmup_counters');

        $this->assertSame([now()->toDateString()], DB::table('warmup_counters')->pluck('day')->all());
    }
}
