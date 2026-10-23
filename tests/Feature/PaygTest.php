<?php

namespace Tests\Feature;

use App\Jobs\SendMessageJob;
use App\Models\BalanceTransaction;
use App\Models\Message;
use App\Models\User;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\Billing\BalanceService;
use App\Services\Billing\SubscriptionService;
use App\Services\MessageDispatcher;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\WhatsAppProvider;
use App\Services\WebhookDispatcher;
use App\Support\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Pay as you go: saldo prepaid, dipotong per pesan yang benar-benar terkirim.
 *
 * Ini cabang KETIGA penegakan kuota, setelah jatah coba gratis dan kuota bulanan
 * paket — dan yang paling berisiko dari ketiganya, karena satu-satunya yang
 * menyangkut uang yang sudah dibayar di depan. Dua tes di berkas ini yang paling
 * penting: **saldo selalu sama dengan jumlah buku besarnya**, dan **pesan gagal
 * tidak memotong saldo**.
 */
class PaygTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private WaSession $session;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();

        // Antrean di suite ini `sync`, jadi tanpa ini `MessageDispatcher::queue()`
        // langsung menjalankan `SendMessageJob` di dalam dirinya sendiri — dan
        // "dipotong saat terkirim, bukan saat antre" jadi mustahil dibedakan.
        // Job-nya dijalankan sendiri lewat `jalankanJob()`, dengan provider
        // yang dipalsukan supaya jalur gagalnya bisa dipicu dengan pasti.
        Queue::fake();

        $this->pemilik = User::create([
            'name' => 'Ryan',
            'email' => 'ryan@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Toko PAYG',
            'slug' => 'toko-payg',
            'owner_id' => $this->pemilik->id,
            'owner_email' => $this->pemilik->email,
            'billing_phone' => '6281234567890',
            'max_sessions' => 1,
            'monthly_message_quota' => 0,
        ]);

        $this->workspace->members()->attach($this->pemilik->id, ['role' => 'owner']);

        $this->session = $this->workspace->sessions()->create([
            'name' => 'Nomor Utama',
            'status' => 'connected',
            'driver' => 'wwebjs',
        ]);
    }

    private function jadikanPayg(int $saldoAwal = 0): void
    {
        app(SubscriptionService::class)->switchToPayg($this->workspace);
        $this->workspace->refresh();

        if ($saldoAwal > 0) {
            app(BalanceService::class)->adjust($this->workspace, $saldoAwal, 'Saldo awal untuk pengujian');
            $this->workspace->refresh();
        }
    }

    private function harga(): int
    {
        return (int) config('billing.payg.price_per_message');
    }

    // ===================== Isi saldo =====================

    public function test_isi_saldo_menambah_saldo_hanya_setelah_admin_menandai_lunas(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueTopupInvoice($this->workspace, 50_000);

        // Tagihan sudah terbit, tapi saldonya BELUM bertambah. Uang yang belum
        // diperiksa manusia belum uang.
        $this->assertSame(0, (int) $this->workspace->fresh()->balance);
        $this->assertSame(0, BalanceTransaction::count());

        $subscriptions->markPaid($invoice);

        $this->assertSame(50_000, (int) $this->workspace->fresh()->balance);
        $this->assertSame('payg', $this->workspace->fresh()->billing_mode);
    }

    /**
     * Kode unik dan pajak menempel di `total`, TIDAK ikut jadi saldo.
     *
     * Kalau ikut, saldo bertambah sebesar angka yang tidak pernah dijanjikan ke
     * siapa pun, dan buku besarnya memuat baris yang tidak bisa dijelaskan.
     */
    public function test_saldo_yang_masuk_sebesar_yang_dijanjikan_bukan_sebesar_yang_ditransfer(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueTopupInvoice($this->workspace, 50_000);

        $this->assertGreaterThan($invoice->amount, $invoice->total, 'Kode unik tidak menempel di total.');

        $subscriptions->markPaid($invoice);

        $this->assertSame(50_000, (int) $this->workspace->fresh()->balance);
    }

    public function test_di_bawah_minimum_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Minimum isi saldo');

        app(SubscriptionService::class)->issueTopupInvoice($this->workspace, 10_000);
    }

    /**
     * Panel admin memang mengizinkan menandai lunas tagihan yang sudah ditutup,
     * jadi jalur ini bukan hipotesis. Saldo yang bertambah dua kali dari satu
     * pembayaran adalah uang yang kami berikan tanpa ada yang membayarnya.
     */
    public function test_menandai_lunas_dua_kali_tidak_menggandakan_saldo(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueTopupInvoice($this->workspace, 50_000);

        $subscriptions->markPaid($invoice);
        $subscriptions->markPaid($invoice->fresh());

        $this->assertSame(50_000, (int) $this->workspace->fresh()->balance);
        $this->assertSame(1, BalanceTransaction::where('type', 'topup')->count());
    }

    /**
     * Percabangan paling berisiko di seluruh PRD: tagihan topup yang salah
     * diperlakukan sebagai langganan memperpanjang periode yang tidak pernah
     * ada dan TIDAK menambah saldo — pelanggan membayar dan tidak menerima
     * apa pun.
     */
    public function test_tagihan_langganan_tidak_pernah_menambah_saldo(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueInvoice($this->workspace, 'prime', 'monthly');

        $subscriptions->markPaid($invoice);

        $this->assertSame(0, (int) $this->workspace->fresh()->balance);
        $this->assertSame(0, BalanceTransaction::count());
        $this->assertNotNull($this->workspace->fresh()->subscription->current_period_end);
    }

    public function test_workspace_payg_tidak_punya_tanggal_berakhir(): void
    {
        $this->jadikanPayg(50_000);

        $ws = $this->workspace->fresh();

        // Yang membatasi saldo, bukan tanggal. Seluruh kode yang membaca kedua
        // kolom ini harus tahan null.
        $this->assertNull($ws->service_until);
        $this->assertNull($ws->subscription->current_period_end);
        $this->assertTrue($ws->isActive(), 'Workspace PAYG tanpa tanggal seharusnya tetap hidup.');
    }

    // ===================== Penegakan =====================

    public function test_saldo_habis_menghentikan_pengiriman_dengan_pesan_yang_bisa_ditindaklanjuti(): void
    {
        $this->jadikanPayg(0);

        try {
            app(MessageDispatcher::class)->queue($this->session, '6281234567890', [
                'type' => 'text',
                'body' => 'halo',
            ]);
            $this->fail('Pengiriman seharusnya ditolak saat saldo kosong.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Saldo tidak cukup', $e->getMessage());
            // Menyebut apa yang harus dilakukan, bukan cuma bahwa ia gagal.
            $this->assertStringContainsString('Isi saldo', $e->getMessage());
        }

        $this->assertSame(0, Message::count());
    }

    public function test_saldo_pas_untuk_satu_pesan_masih_diterima(): void
    {
        $this->jadikanPayg($this->harga());

        $pesan = app(MessageDispatcher::class)->queue($this->session, '6281234567890', [
            'type' => 'text',
            'body' => 'halo',
        ]);

        $this->assertSame('queued', $pesan->status);
    }

    // ===================== Pemotongan =====================

    /** Dipotong saat TERKIRIM, bukan saat diantrekan. */
    public function test_pesan_memotong_saldo_saat_terkirim_bukan_saat_antre(): void
    {
        $this->jadikanPayg(10_000);

        $pesan = app(MessageDispatcher::class)->queue($this->session, '6281234567890', [
            'type' => 'text',
            'body' => 'halo',
        ]);

        // Sudah diantrekan, saldo BELUM berkurang.
        $this->assertSame(10_000, (int) $this->workspace->fresh()->balance);

        $this->jalankanJob($pesan);

        $this->assertSame(10_000 - $this->harga(), (int) $this->workspace->fresh()->balance);
        $this->assertSame(1, BalanceTransaction::where('type', 'charge')->count());
    }

    /**
     * Pesan yang gagal karena nomornya tidak terdaftar tidak boleh memotong
     * saldo pelanggan. Ini alasan seluruh pemotongan terjadi setelah kirim.
     */
    public function test_pesan_gagal_tidak_memotong_saldo(): void
    {
        $this->jadikanPayg(10_000);

        $pesan = app(MessageDispatcher::class)->queue($this->session, '6281234567890', [
            'type' => 'text',
            'body' => 'halo',
        ]);

        $this->jalankanJob($pesan, gagal: true);

        $this->assertSame('failed', $pesan->fresh()->status);
        $this->assertSame(10_000, (int) $this->workspace->fresh()->balance);
        $this->assertSame(0, BalanceTransaction::where('type', 'charge')->count());
    }

    /**
     * Job bisa dijalankan ulang setelah gagal di tengah. Pemotongan ganda untuk
     * satu pesan adalah uang pelanggan yang hilang tanpa jejak — indeks unik
     * `(workspace_id, message_id)` menjaganya bahkan saat dua worker berlomba.
     */
    public function test_satu_pesan_hanya_memotong_saldo_sekali(): void
    {
        $this->jadikanPayg(10_000);

        $pesan = app(MessageDispatcher::class)->queue($this->session, '6281234567890', [
            'type' => 'text',
            'body' => 'halo',
        ]);

        $balances = app(BalanceService::class);
        $balances->chargeMessage($this->workspace->fresh(), $pesan);
        $balances->chargeMessage($this->workspace->fresh(), $pesan);

        $this->assertSame(10_000 - $this->harga(), (int) $this->workspace->fresh()->balance);
        $this->assertSame(1, BalanceTransaction::where('type', 'charge')->count());
    }

    // ===================== Rekonsiliasi =====================

    /**
     * Inti seluruh berkas ini.
     *
     * Saldo yang tidak bisa direkonsiliasi adalah uang pelanggan yang tidak bisa
     * dipertanggungjawabkan, dan selisihnya tidak menghasilkan galat apa pun.
     */
    public function test_saldo_selalu_sama_dengan_jumlah_buku_besarnya(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $balances = app(BalanceService::class);

        $subscriptions->markPaid($subscriptions->issueTopupInvoice($this->workspace, 50_000));
        $this->workspace->refresh();

        // Campuran seluruh jenis mutasi, termasuk yang mengurangi.
        foreach (range(1, 12) as $ke) {
            $pesan = app(MessageDispatcher::class)->queue($this->session, '6281234567890', [
                'type' => 'text',
                'body' => "halo {$ke}",
            ]);

            $this->jalankanJob($pesan);
            $this->workspace->refresh();
        }

        $balances->refund($this->workspace->fresh(), 400, 'Dua pesan ternyata tidak sampai');
        $balances->adjust($this->workspace->fresh(), -1_000, 'Koreksi manual');

        $hasil = $balances->rekonsiliasi($this->workspace->fresh());

        $this->assertTrue($hasil['cocok'], "Saldo {$hasil['saldo']} tidak cocok dengan buku besar {$hasil['bukuBesar']}.");
        $this->assertSame(0, $hasil['selisih']);

        // Dan `balance_after` tiap baris membentuk rantai yang utuh — tanpa itu,
        // baris yang hilang tidak bisa ditelusuri ke tempatnya.
        $berjalan = 0;

        foreach (BalanceTransaction::where('workspace_id', $this->workspace->id)->orderBy('id')->get() as $m) {
            $berjalan += $m->amount;
            $this->assertSame($berjalan, $m->balance_after, "Rantai balance_after putus di mutasi #{$m->id}.");
        }
    }

    public function test_selisih_saldo_terlihat_di_halaman_sistem(): void
    {
        $this->jadikanPayg(50_000);

        // Merusak saldo langsung, meniru baris buku besar yang hilang.
        $this->workspace->forceFill(['balance' => 99_000])->save();

        $admin = User::create([
            'name' => 'VexaHost Finance',
            'email' => 'finance@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->assertCount(1, app(BalanceService::class)->workspaceTidakCocok());

        $this->actingAs($admin)->get('/admin/sistem')
            ->assertOk()
            ->assertSee('Saldo cocok dengan buku besar');
    }

    // ===================== Antarmuka =====================

    public function test_halaman_saldo_menampilkan_sisa_dan_riwayat(): void
    {
        $this->jadikanPayg(50_000);

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('balance.index'))
            ->assertOk()
            ->assertSee('50.000')
            ->assertSee('Saldo awal untuk pengujian');
    }

    public function test_halaman_harga_menampilkan_payg_tanpa_merusak_tiga_kartu(): void
    {
        $this->berlangganan($this->workspace);

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('billing.plans'))
            ->assertOk()
            ->assertSee('Essentials')
            ->assertSee('Prime')
            ->assertSee('Elite')
            ->assertSee('Pay as you go')
            // Harga per pesan TIDAK boleh muncul di halaman harga: angka satuan
            // di sini mengundang orang menghitung sendiri lalu menyimpulkan
            // paket bulanan lebih murah, padahal PAYG memang bukan untuk yang
            // kirimannya rutin.
            ->assertDontSee('/pesan terkirim')
            ->assertDontSee('per pesan terkirim');
    }

    /** Yang sudah memakai PAYG berhak tahu saldonya habis untuk apa. */
    public function test_harga_per_pesan_tetap_terbuka_di_halaman_saldo(): void
    {
        $this->jadikanPayg(50_000);

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('balance.index'))
            ->assertOk()
            ->assertSee('per pesan terkirim');
    }

    /**
     * Halaman paket di dashboard punya kelompok yang sama dengan halaman harga
     * publik — Bisnis dan Enterprise, dengan PAYG dan Enterprise di kelompok
     * kedua.
     *
     * Dua halaman yang menjual hal yang sama dengan bentuk berbeda membuat
     * pelanggan mengira ada pilihan yang hanya tersedia di salah satunya.
     */
    public function test_halaman_paket_punya_kelompok_bisnis_dan_enterprise(): void
    {
        $this->berlangganan($this->workspace);

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('billing.plans'))
            ->assertOk()
            ->assertSee('>Bisnis<', false)
            ->assertSee('>Enterprise<', false)
            ->assertSee('Pay as you go')
            ->assertSee('Minta penawaran');
    }

    /**
     * Yang sudah memakai PAYG dibuka langsung di kelompok Enterprise.
     *
     * Menampilkan tiga kartu bulanan lebih dulu kepada orang yang datang untuk
     * mengisi saldo adalah satu klik yang tidak perlu ada.
     */
    public function test_workspace_payg_dibuka_di_kelompok_enterprise(): void
    {
        $this->jadikanPayg(50_000);

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('billing.plans'))
            ->assertOk()
            ->assertSee("kelompok: 'enterprise'", false);
    }

    /** PAYG tidak boleh muncul di daftar paket bulanan mana pun. */
    public function test_payg_tidak_ikut_daftar_paket_bulanan(): void
    {
        $slug = array_map(fn ($p) => $p->slug, Plan::all());

        $this->assertNotContains('payg', $slug);
        $this->assertContains('essentials', $slug);
    }

    public function test_isi_saldo_lewat_dashboard_menerbitkan_tagihan(): void
    {
        $this->jadikanPayg();

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('balance.topup'), ['jumlah' => 50_000])
            ->assertRedirect();

        $invoice = $this->workspace->invoices()->latest()->first();

        $this->assertTrue($invoice->isTopup());
        $this->assertSame(50_000, $invoice->amount);
    }

    public function test_isi_saldo_di_bawah_minimum_ditolak_di_dashboard(): void
    {
        $this->jadikanPayg();

        $this->actingAs($this->pemilik)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('balance.topup'), ['jumlah' => 10_000])
            ->assertSessionHasErrors('jumlah');

        $this->assertSame(0, $this->workspace->invoices()->count());
    }

    /**
     * Menjalankan `SendMessageJob` dengan provider yang dipalsukan.
     *
     * Providernya dipalsukan supaya yang diuji pemotongan saldonya, bukan
     * engine — dan supaya jalur gagalnya bisa dipicu dengan pasti.
     */
    private function jalankanJob(Message $pesan, bool $gagal = false): void
    {
        $provider = new class($gagal) implements WhatsAppProvider
        {
            public function __construct(private readonly bool $gagal) {}

            public function send(WaSession $session, Message $message): array
            {
                if ($this->gagal) {
                    throw new ProviderException('Nomor tujuan tidak terdaftar di WhatsApp.', retryable: false);
                }

                return ['wa_message_id' => 'wamid.'.$message->id, 'raw' => []];
            }

            public function startSession(WaSession $session, bool $hanyaPulihkan = false): void {}

            public function stopSession(WaSession $session): void {}

            public function logoutSession(WaSession $session): void {}

            public function status(WaSession $session): array
            {
                return ['status' => 'connected'];
            }
        };

        $manager = new class($provider) extends ProviderManager
        {
            public function __construct(private readonly WhatsAppProvider $palsu)
            {
                // Sengaja tidak memanggil parent: yang dibutuhkan cuma `for()`.
            }

            public function for(WaSession $session): WhatsAppProvider
            {
                return $this->palsu;
            }
        };

        (new SendMessageJob($pesan->id))->handle(
            $manager,
            app(MessageDispatcher::class),
            app(WebhookDispatcher::class),
        );
    }
}
