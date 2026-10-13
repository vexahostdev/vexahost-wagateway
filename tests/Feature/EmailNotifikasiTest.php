<?php

namespace Tests\Feature;

use App\Jobs\BillingCycleJob;
use App\Mail\MasaBerlakuHabis;
use App\Mail\PembayaranDiterima;
use App\Mail\TagihanTerbit;
use App\Mail\TesEmail;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionService;
use App\Services\Notifications\EmailNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Pemberitahuan email penagihan.
 *
 * Yang paling penting di berkas ini bukan "email terkirim", melainkan **email
 * yang gagal tidak pernah menjatuhkan penagihan**. Itu aturan yang sama dengan
 * `WhatsAppNotifier`, dan alasannya sama: yang memanggil sedang menandai
 * tagihan lunas — uang yang sudah benar-benar masuk — dan itu tidak boleh batal
 * tercatat gara-gara Brevo sedang tersendat.
 */
class EmailNotifikasiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();

        // Suite berjalan dengan MAIL_MAILER=array; `ready()` menolak itu sama
        // seperti ia menolak `log`, jadi tanpa baris ini seluruh tes di bawah
        // menguji jalur "email belum dikonfigurasi", bukan pengirimannya.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.from.address' => 'vexahostcloudtech@gmail.com',
        ]);

        $pemilik = User::create([
            'name' => 'Ryan',
            'email' => 'ryan@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Toko Contoh',
            'slug' => 'toko-contoh',
            'owner_id' => $pemilik->id,
            'owner_email' => $pemilik->email,
            'billing_email' => 'keuangan@contoh.id',
            'billing_phone' => '6281234567890',
            'max_sessions' => 1,
            'monthly_message_quota' => 3000,
        ]);
    }

    public function test_tagihan_terbit_mengirim_email_ke_alamat_penagihan(): void
    {
        app(SubscriptionService::class)->issueInvoice($this->workspace, 'prime', 'monthly');

        Mail::assertSent(TagihanTerbit::class, fn ($surat) => $surat->hasTo('keuangan@contoh.id'));
    }

    public function test_pembayaran_lunas_mengirim_email(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueInvoice($this->workspace, 'prime', 'monthly');

        $subscriptions->markPaid($invoice);

        Mail::assertSent(PembayaranDiterima::class, fn ($surat) => $surat->hasTo('keuangan@contoh.id'));
    }

    public function test_pengingat_masa_berlaku_mengirim_email(): void
    {
        $this->berlangganan($this->workspace);

        // H-3 adalah salah satu tanggal pengingat di config/billing.php.
        $this->workspace->subscription->forceFill([
            'current_period_end' => now()->addDays(3),
        ])->save();

        app()->call([new BillingCycleJob, 'handle']);

        Mail::assertSent(MasaBerlakuHabis::class);
    }

    /**
     * Inti dari seluruh berkas ini.
     *
     * Pengiriman yang melempar galat harus berhenti di dalam notifier. Kalau ia
     * merambat keluar, `markPaid()` gagal setelah transaksinya commit —
     * pelanggan sudah membayar, tagihannya sudah lunas di database, dan
     * pemanggilnya menerima galat 500.
     */
    public function test_email_gagal_tidak_menjatuhkan_penagihan(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueInvoice($this->workspace, 'prime', 'monthly');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP menolak: 550 sender tidak diverifikasi'));

        $hasil = $subscriptions->markPaid($invoice);

        $this->assertSame('paid', $hasil->status);
        $this->assertSame('active', $this->workspace->fresh()->status);
    }

    public function test_workspace_bebas_tidak_menerima_surat_penagihan(): void
    {
        $this->workspace->forceFill(['is_internal' => true])->save();

        app(SubscriptionService::class)->issueInvoice($this->workspace->fresh(), 'prime', 'monthly');

        Mail::assertNotSent(TagihanTerbit::class);
    }

    /**
     * Penanda sekali-kirim, diuji langsung di notifier.
     *
     * Sengaja TIDAK lewat `issueInvoice()` dua kali: panggilan kedua
     * mengembalikan tagihan yang sama lebih awal dan tidak pernah sampai ke
     * baris pengirimannya, jadi tes seperti itu lulus tanpa menyentuh penanda
     * sama sekali. Yang dijaga di sini adalah `BillingCycleJob` yang berjalan
     * dua kali dalam sehari — dan email kembar soal uang membuat orang mengira
     * ia ditagih dua kali.
     */
    public function test_satu_peristiwa_satu_email(): void
    {
        $invoice = app(SubscriptionService::class)->issueInvoice($this->workspace, 'prime', 'monthly');
        $notifier = app(EmailNotifier::class);

        $pertama = $notifier->toWorkspace($this->workspace, new TagihanTerbit($invoice), 'peristiwa-yang-sama');
        $kedua = $notifier->toWorkspace($this->workspace, new TagihanTerbit($invoice), 'peristiwa-yang-sama');

        $this->assertTrue($pertama);
        $this->assertFalse($kedua, 'Penanda cache tidak menahan email kedua.');
    }

    /**
     * Penanda email terpisah dari penanda WhatsApp.
     *
     * Kalau keduanya berbagi kunci, email yang berhasil lebih dulu akan
     * membungkam pesan WhatsApp untuk peristiwa yang sama — dan jalur yang
     * paling mungkin dibaca pelanggan justru yang hilang.
     */
    public function test_penanda_email_tidak_membungkam_whatsapp(): void
    {
        $invoice = app(SubscriptionService::class)->issueInvoice($this->workspace, 'prime', 'monthly');

        app(EmailNotifier::class)->toWorkspace($this->workspace, new TagihanTerbit($invoice), 'peristiwa:1');

        $this->assertFalse(Cache::has('wa-notif:peristiwa:1'));
        $this->assertTrue(Cache::has('email-notif:peristiwa:1'));
    }

    public function test_mailer_log_dilaporkan_belum_siap(): void
    {
        config(['mail.default' => 'log']);

        $this->assertFalse(app(EmailNotifier::class)->ready());

        $hasil = app(EmailNotifier::class)->kirimTes('orang@contoh.id');

        $this->assertFalse($hasil['berhasil']);
        $this->assertStringContainsString('MAIL_MAILER', $hasil['pesan']);
    }

    public function test_smtp_tanpa_host_dilaporkan_belum_siap(): void
    {
        config(['mail.mailers.smtp.host' => null]);

        $this->assertFalse(app(EmailNotifier::class)->ready());
        $this->assertStringContainsString('MAIL_HOST', app(EmailNotifier::class)->kirimTes('orang@contoh.id')['pesan']);
    }

    public function test_tombol_kirim_email_tes_berhasil(): void
    {
        $admin = User::create([
            'name' => 'VexaHost Finance',
            'email' => 'finance@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/pengecualian/email/tes', ['email' => 'orang@contoh.id'])
            ->assertRedirect();

        Mail::assertSent(TesEmail::class, fn ($surat) => $surat->hasTo('orang@contoh.id'));
    }

    public function test_tombol_kirim_email_tes_bisa_ke_banyak_penerima(): void
    {
        $admin = User::create([
            'name' => 'VexaHost Finance',
            'email' => 'finance@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/pengecualian/email/tes', [
                'email' => 'support@vexahostcloud.my.id, vexahostcloudtech@gmail.com',
            ])
            ->assertRedirect();

        Mail::assertSent(TesEmail::class, fn ($surat) => $surat->hasTo('support@vexahostcloud.my.id'));
        Mail::assertSent(TesEmail::class, fn ($surat) => $surat->hasTo('vexahostcloudtech@gmail.com'));
    }

    public function test_tombol_kirim_email_tes_menolak_salah_satu_email_tidak_sah(): void
    {
        $admin = User::create([
            'name' => 'VexaHost Finance',
            'email' => 'finance@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/pengecualian/email/tes', [
                'email' => 'support@vexahostcloud.my.id, bukan-email',
            ])
            ->assertSessionHasErrors('email');
    }

    /**
     * Keempat template benar-benar bisa dirender.
     *
     * Blade di dalam email tidak pernah dilihat siapa pun sampai ia dikirim
     * sungguhan, dan `Mail::fake()` TIDAK merender isinya — jadi seluruh tes di
     * atas tetap hijau walau salah satu template memanggil method yang tidak
     * ada. Yang menemukan kesalahannya jadi pelanggan yang menerima surat
     * kosong, atau tagihan yang gagal terbit karena render-nya melempar galat.
     */
    public function test_seluruh_template_email_bisa_dirender(): void
    {
        $subscriptions = app(SubscriptionService::class);
        $invoice = $subscriptions->issueInvoice($this->workspace, 'prime', 'monthly');
        $subscription = $this->workspace->fresh()->subscription;

        $this->assertStringContainsString(
            $invoice->number,
            (new TagihanTerbit($invoice))->render(),
        );

        $this->assertStringContainsString(
            $invoice->number,
            (new PembayaranDiterima($invoice, $subscription))->render(),
        );

        // Ketiga cabang kalimatnya diuji sekaligus: "hari ini", "besok", dan
        // "n hari lagi" ditulis terpisah di template, jadi dua di antaranya
        // bisa rusak tanpa ketahuan kalau cuma satu yang pernah dirender.
        foreach ([0, 1, 7] as $sisaHari) {
            $this->assertNotEmpty((new MasaBerlakuHabis($subscription, $sisaHari))->render());
        }

        $this->assertStringContainsString('Tes email keluar', (new TesEmail)->render());
    }

    public function test_halaman_sistem_menyebut_keadaan_email(): void
    {
        $admin = User::create([
            'name' => 'VexaHost Finance',
            'email' => 'finance@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin)->get('/admin/sistem')
            ->assertOk()
            ->assertSee('Email keluar');
    }
}
