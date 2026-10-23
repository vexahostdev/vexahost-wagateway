<?php

namespace Tests\Feature;

use App\Models\SessionBackup;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\Providers\WwebjsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SessionBackupTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private WaSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('session-backups');

        $this->workspace = Workspace::create(['name' => 'Toko Uji', 'slug' => 'toko-uji']);
        $this->session = $this->workspace->sessions()->create(['name' => 'CS', 'status' => 'connected']);
    }

    private function signedHeaders(string $path, string $content, ?string $digest = null): array
    {
        $timestamp = (string) now()->getTimestamp();
        $secret = config('gateway.engine.hmac_secret');

        $signed = $digest !== null
            ? $timestamp.'.'.$path.'.'.$digest
            : $timestamp.'.'.$content;

        $signature = hash_hmac('sha256', $signed, $secret);

        $headers = [
            'X-Engine-Timestamp' => $timestamp,
            'X-Engine-Signature' => $signature,
        ];

        if ($digest !== null) {
            $headers['X-Engine-Body-Sha256'] = $digest;
        }

        return $headers;
    }

    /**
     * Payload cadangan berbentuk asli: creds.json berisi kunci perangkat dan
     * sudah tertaut ke sebuah nomor. Cadangan selain itu ditolak di setiap
     * langkah — saat diunggah, saat dipilih, dan oleh engine sendiri.
     */
    private function payloadLayak(string $penanda = 'a'): string
    {
        $kunci = ['private' => ['type' => 'Buffer', 'data' => base64_encode('priv')], 'public' => ['type' => 'Buffer', 'data' => base64_encode('pub')]];

        return json_encode([
            'version' => 1,
            'sessionId' => $this->session->id,
            'files' => [
                'creds.json' => json_encode([
                    'noiseKey' => $kunci,
                    'signedIdentityKey' => $kunci,
                    'registrationId' => 4321,
                    'me' => ['id' => '628123456789:1@s.whatsapp.net'],
                ]),
                'app-state-sync-key-1.json' => json_encode(['keyData' => $penanda]),
            ],
        ]);
    }

    private function simpanCadangan(string $isi, string $path, ?\DateTimeInterface $waktu = null): SessionBackup
    {
        Storage::disk('session-backups')->put($path, $isi);

        return SessionBackup::create([
            'wa_session_id' => $this->session->id,
            'disk' => 'session-backups',
            'path' => $path,
            'size' => strlen($isi),
            'checksum' => hash('sha256', $isi),
            'backed_up_at' => $waktu ?? now(),
        ]);
    }

    private function unggah(string $payload)
    {
        $digest = hash('sha256', $payload);
        $path = "internal/engine/session-backup/{$this->session->id}";

        return $this->call(
            'POST',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeaders($path, $payload, $digest) + [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            $payload
        );
    }

    public function test_store_menyimpan_state_baileys_json_dan_checksum(): void
    {
        $jsonPayload = $this->payloadLayak();
        $digest = hash('sha256', $jsonPayload);

        $response = $this->unggah($jsonPayload);

        $response->assertOk();
        $response->assertJson(['success' => true, 'data' => ['checksum' => $digest]]);

        // Verifikasi di database
        $backup = SessionBackup::where('wa_session_id', $this->session->id)->first();
        $this->assertNotNull($backup);
        $this->assertMatchesRegularExpression('#^'.$this->session->id.'/session-.+\.json$#', $backup->path);
        $this->assertSame($digest, $backup->checksum);
        $this->assertSame(strlen($jsonPayload), $backup->size);

        // Verifikasi di storage
        Storage::disk('session-backups')->assertExists($backup->path);
        $this->assertSame($jsonPayload, Storage::disk('session-backups')->get($backup->path));
    }

    public function test_store_menolak_kredensial_yang_rusak_atau_belum_tertaut(): void
    {
        // JSON sah, tapi creds.json di dalamnya tidak bisa dipakai menyambung.
        foreach ([
            ['creds.json' => json_encode(['me' => ['id' => '628123:1@s.whatsapp.net']])],
            ['creds.json' => json_encode(['noiseKey' => ['x' => 1], 'signedIdentityKey' => ['x' => 1]])],
            ['creds.json' => '{"noiseKey":'],
            ['pre-key-1.json' => '{}'],
        ] as $files) {
            $this->unggah(json_encode(['version' => 1, 'files' => $files]))
                ->assertStatus(422)
                ->assertJsonPath('error.message', 'Cadangan ditolak: kredensial di dalamnya rusak atau belum tertaut.');
        }

        $this->assertSame(0, SessionBackup::count());
    }

    public function test_store_menyimpan_tiga_cadangan_terakhir_dan_membuang_sisanya(): void
    {
        foreach (range(1, 5) as $ke) {
            $this->unggah($this->payloadLayak("ke-{$ke}"))->assertOk();
        }

        $tersimpan = SessionBackup::where('wa_session_id', $this->session->id)->orderBy('id')->get();

        $this->assertCount(SessionBackup::SIMPAN, $tersimpan);
        $this->assertStringContainsString('ke-3', Storage::disk('session-backups')->get($tersimpan->first()->path));
        $this->assertCount(SessionBackup::SIMPAN, Storage::disk('session-backups')->allFiles($this->session->id),
            'Berkas cadangan yang dibuang harus ikut terhapus dari disk');
    }

    public function test_store_menolak_payload_bukan_json(): void
    {
        $rawPayload = 'ini bukan json valid';
        $digest = hash('sha256', $rawPayload);
        $path = "internal/engine/session-backup/{$this->session->id}";
        $headers = $this->signedHeaders($path, $rawPayload, $digest);

        $response = $this->call(
            'POST',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            $rawPayload
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.message', 'Isi backup bukan JSON yang valid.');
    }

    public function test_store_menolak_checksum_yang_tidak_cocok(): void
    {
        $jsonPayload = json_encode(['test' => true]);
        $digest = str_repeat('a', 64);
        $path = "internal/engine/session-backup/{$this->session->id}";
        $headers = $this->signedHeaders($path, $jsonPayload, $digest);

        $response = $this->call(
            'POST',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
            $jsonPayload
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.message', 'Isi backup tidak cocok dengan checksum yang ditandatangani.');
    }

    public function test_exists_menjawab_keberadaan_backup(): void
    {
        $path = "internal/engine/session-backup/{$this->session->id}/exists";
        $headers = $this->signedHeaders($path, '');

        $res1 = $this->call(
            'GET',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json'])
        );
        $res1->assertOk()->assertJsonPath('data.exists', false);

        // Cadangan yang kredensialnya tidak layak tidak dihitung "ada": engine
        // memakai jawaban ini untuk memutuskan apakah ia memulihkan.
        $this->simpanCadangan('{}', "{$this->session->id}/session.json");

        $res2 = $this->call(
            'GET',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeaders($path, '') + ['Accept' => 'application/json'])
        );
        $res2->assertOk()->assertJsonPath('data.exists', false);

        $this->simpanCadangan($this->payloadLayak(), "{$this->session->id}/session-baru.json");

        $res3 = $this->call(
            'GET',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeaders($path, '') + ['Accept' => 'application/json'])
        );
        $res3->assertOk()->assertJsonPath('data.exists', true);
    }

    public function test_show_mengunduh_session_json(): void
    {
        $content = $this->payloadLayak();
        $this->simpanCadangan($content, "{$this->session->id}/session.json");

        $path = "internal/engine/session-backup/{$this->session->id}";
        $headers = $this->signedHeaders($path, '');

        $response = $this->call(
            'GET',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers)
        );

        $response->assertOk();
        $response->assertHeader('X-Backup-Sha256', hash('sha256', $content));
        $this->assertSame($content, $response->getContent());
    }

    public function test_show_melewati_cadangan_terbaru_yang_rusak_dan_menyajikan_yang_layak(): void
    {
        $sehat = $this->payloadLayak('sehat');
        $this->simpanCadangan($sehat, "{$this->session->id}/session-lama.json", now()->subMinutes(10));

        // Terpotong — persis yang dihasilkan unggahan yang terputus.
        $this->simpanCadangan(substr($sehat, 0, 60), "{$this->session->id}/session-baru.json", now());

        $path = "internal/engine/session-backup/{$this->session->id}";

        $response = $this->call(
            'GET',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeaders($path, ''))
        );

        $response->assertOk();
        $this->assertSame($sehat, $response->getContent(),
            'Cadangan terbaru yang rusak tidak boleh menyembunyikan cadangan sehat di bawahnya');
    }

    public function test_destroy_menghapus_backup(): void
    {
        Storage::disk('session-backups')->put("{$this->session->id}/session.json", '{}');
        SessionBackup::create([
            'wa_session_id' => $this->session->id,
            'disk' => 'session-backups',
            'path' => "{$this->session->id}/session.json",
            'size' => 2,
            'checksum' => hash('sha256', '{}'),
            'backed_up_at' => now(),
        ]);
        $kedua = $this->simpanCadangan($this->payloadLayak(), "{$this->session->id}/session-kedua.json");

        $path = "internal/engine/session-backup/{$this->session->id}";
        $headers = $this->signedHeaders($path, '');

        $response = $this->call(
            'DELETE',
            '/'.$path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers)
        );

        $response->assertOk();
        Storage::disk('session-backups')->assertMissing("{$this->session->id}/session.json");
        Storage::disk('session-backups')->assertMissing($kedua->path);
        $this->assertNull(SessionBackup::where('wa_session_id', $this->session->id)->first());
    }

    public function test_wwebjs_provider_mengabaikan_backup_lama_berformat_zip_dan_mulai_bersih(): void
    {
        // Simulasi berkas biner zip era Chromium
        $zipBinary = "PK\x03\x04".random_bytes(64);
        Storage::disk('session-backups')->put("{$this->session->id}/session.zip", $zipBinary);

        SessionBackup::create([
            'wa_session_id' => $this->session->id,
            'disk' => 'session-backups',
            'path' => "{$this->session->id}/session.zip",
            'size' => strlen($zipBinary),
            'checksum' => hash('sha256', $zipBinary),
            'backed_up_at' => now(),
        ]);

        Http::fake([
            '*/sessions/*/start' => function (Request $request) {
                $data = $request->data();
                $this->assertFalse($data['has_backup']);
                $this->assertArrayNotHasKey('backup_data', $data);

                return Http::response(['status' => 'connecting'], 200);
            },
        ]);

        app(WwebjsProvider::class)->startSession($this->session);

        Http::assertSent(fn (Request $req) => $req->data()['has_backup'] === false && ! isset($req->data()['backup_data'])
        );
    }

    public function test_wwebjs_provider_mengabaikan_backup_yang_bukan_json_atau_bukan_utf8(): void
    {
        // Berkas path session.json tetapi isinya bukan JSON valid
        $invalidJson = 'bukan-json-valid-{'.random_bytes(10);
        Storage::disk('session-backups')->put("{$this->session->id}/session.json", $invalidJson);

        SessionBackup::create([
            'wa_session_id' => $this->session->id,
            'disk' => 'session-backups',
            'path' => "{$this->session->id}/session.json",
            'size' => strlen($invalidJson),
            'checksum' => hash('sha256', $invalidJson),
            'backed_up_at' => now(),
        ]);

        Http::fake([
            '*/sessions/*/start' => function (Request $request) {
                $data = $request->data();
                $this->assertFalse($data['has_backup']);
                $this->assertArrayNotHasKey('backup_data', $data);

                return Http::response(['status' => 'connecting'], 200);
            },
        ]);

        app(WwebjsProvider::class)->startSession($this->session);

        Http::assertSent(fn (Request $req) => $req->data()['has_backup'] === false && ! isset($req->data()['backup_data'])
        );
    }

    public function test_wwebjs_provider_menyertakan_backup_yang_formatnya_valid(): void
    {
        // Nama lama `session.json` tetap terbaca: cadangan yang sudah ada di
        // produksi sebelum cadangan bergilir tidak boleh mendadak hilang.
        $validJson = $this->payloadLayak();
        $this->simpanCadangan($validJson, "{$this->session->id}/session.json");

        Http::fake([
            '*/sessions/*/start' => function (Request $request) use ($validJson) {
                $data = $request->data();
                $this->assertTrue($data['has_backup']);
                $this->assertSame($validJson, $data['backup_data']);

                return Http::response(['status' => 'connecting'], 200);
            },
        ]);

        app(WwebjsProvider::class)->startSession($this->session);

        Http::assertSent(fn (Request $req) => $req->data()['has_backup'] === true && $req->data()['backup_data'] === $validJson
        );
    }

    public function test_wwebjs_provider_mengabaikan_backup_yang_kredensialnya_tidak_layak(): void
    {
        $this->simpanCadangan(
            json_encode(['version' => 1, 'files' => ['creds.json' => '{}']]),
            "{$this->session->id}/session.json"
        );

        Http::fake(['*/sessions/*/start' => Http::response(['status' => 'connecting'])]);

        app(WwebjsProvider::class)->startSession($this->session);

        Http::assertSent(fn (Request $req) => $req->data()['has_backup'] === false && ! isset($req->data()['backup_data']));
    }

    public function test_wwebjs_provider_meneruskan_penanda_hanya_pulihkan(): void
    {
        Http::fake(['*/sessions/*/start' => Http::response(['status' => 'credentials_missing'])]);

        app(WwebjsProvider::class)->startSession($this->session, hanyaPulihkan: true);
        app(WwebjsProvider::class)->startSession($this->session);

        $kiriman = collect(Http::recorded())->map(fn ($pasangan) => $pasangan[0]->data()['restore_only']);

        $this->assertSame([true, false], $kiriman->all());
    }

    public function test_perintah_bersihkan_backup_lama_mode_kering_tidak_menghapus_apa_pun(): void
    {
        // Backup lama .zip
        Storage::disk('session-backups')->put("{$this->session->id}/session.zip", 'dummy-zip');
        $oldBackup = SessionBackup::create([
            'wa_session_id' => $this->session->id,
            'disk' => 'session-backups',
            'path' => "{$this->session->id}/session.zip",
            'size' => 9,
            'checksum' => hash('sha256', 'dummy-zip'),
            'backed_up_at' => now()->subDays(10),
        ]);

        // Backup baru .json
        $newSession = $this->workspace->sessions()->create(['name' => 'CS2', 'status' => 'connected']);
        Storage::disk('session-backups')->put("{$newSession->id}/session.json", '{}');
        $validBackup = SessionBackup::create([
            'wa_session_id' => $newSession->id,
            'disk' => 'session-backups',
            'path' => "{$newSession->id}/session.json",
            'size' => 2,
            'checksum' => hash('sha256', '{}'),
            'backed_up_at' => now(),
        ]);

        $this->artisan('vexahost:bersihkan-backup-lama', ['--dry-run' => true])
            ->expectsOutputToContain('Mode kering')
            ->assertSuccessful();

        $this->assertDatabaseHas('session_backups', ['id' => $oldBackup->id]);
        $this->assertDatabaseHas('session_backups', ['id' => $validBackup->id]);
        Storage::disk('session-backups')->assertExists("{$this->session->id}/session.zip");
        Storage::disk('session-backups')->assertExists("{$newSession->id}/session.json");
    }

    public function test_perintah_bersihkan_backup_lama_menghapus_zip_dan_mempertahankan_json(): void
    {
        // Backup lama .zip
        Storage::disk('session-backups')->put("{$this->session->id}/session.zip", 'dummy-zip');
        $oldBackup = SessionBackup::create([
            'wa_session_id' => $this->session->id,
            'disk' => 'session-backups',
            'path' => "{$this->session->id}/session.zip",
            'size' => 9,
            'checksum' => hash('sha256', 'dummy-zip'),
            'backed_up_at' => now()->subDays(10),
        ]);

        // Backup baru .json
        $newSession = $this->workspace->sessions()->create(['name' => 'CS2', 'status' => 'connected']);
        Storage::disk('session-backups')->put("{$newSession->id}/session.json", '{}');
        $validBackup = SessionBackup::create([
            'wa_session_id' => $newSession->id,
            'disk' => 'session-backups',
            'path' => "{$newSession->id}/session.json",
            'size' => 2,
            'checksum' => hash('sha256', '{}'),
            'backed_up_at' => now(),
        ]);

        $this->artisan('vexahost:bersihkan-backup-lama')
            ->expectsOutputToContain('berhasil dihapus')
            ->assertSuccessful();

        $this->assertDatabaseMissing('session_backups', ['id' => $oldBackup->id]);
        $this->assertDatabaseHas('session_backups', ['id' => $validBackup->id]);
        Storage::disk('session-backups')->assertMissing("{$this->session->id}/session.zip");
        Storage::disk('session-backups')->assertExists("{$newSession->id}/session.json");
    }
}
