<?php

namespace Tests\Feature;

use App\Mail\KabarTim;
use App\Mail\ResetPasswordMail;
use App\Mail\TagihanTerbit;
use App\Mail\TesEmail;
use App\Mail\TiketDibalas;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\EmailNotifier;
use App\Support\MailIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $pelanggan;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.from.address' => 'noreply@vexahostcloud.my.id',
            'mail.from.name' => 'VexaHost WA Gateway',
            'mail.support.address' => 'support@vexahostcloud.my.id',
            'mail.support.name' => 'VexaHost WA Gateway Support',
            'mail.reply_to.address' => 'support@vexahostcloud.my.id',
            'mail.reply_to.name' => 'VexaHost WA Gateway Support',
            'mail.admin_addresses' => ['support@vexahostcloud.my.id', 'vexahostcloudtech@gmail.com'],
            'mail.allowed_sender_domains' => ['vexahostcloud.my.id'],
        ]);

        $this->admin = User::create([
            'name' => 'VexaHost Admin',
            'email' => 'admin@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        $this->pelanggan = User::create([
            'name' => 'Ryan Customer',
            'email' => 'customer@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Workspace Uji',
            'slug' => 'workspace-uji',
            'owner_id' => $this->pelanggan->id,
        ]);

        MailIdentity::flushCache();
    }

    public function test_support_emails_have_support_from_and_reply_to(): void
    {
        $ticket = Ticket::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->pelanggan->id,
            'subject' => 'Kendala Gateway',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        $mail = new TiketDibalas($ticket);
        $envelope = $mail->envelope();

        $this->assertSame('support@vexahostcloud.my.id', $envelope->from->address);
        $this->assertSame('VexaHost WA Gateway Support', $envelope->from->name);
        $this->assertNotEmpty($envelope->replyTo);
        $this->assertSame('support@vexahostcloud.my.id', $envelope->replyTo[0]->address);
    }

    public function test_noreply_emails_have_noreply_from_and_support_reply_to(): void
    {
        $invoice = Invoice::create([
            'workspace_id' => $this->workspace->id,
            'number' => 'INV-TEST-001',
            'external_id' => 'EXT-TEST-001',
            'plan_slug' => 'pro',
            'period' => 'monthly',
            'amount' => 50000,
            'total' => 50000,
            'status' => 'unpaid',
            'due_at' => now()->addDays(3),
        ]);

        // 1. ResetPasswordMail
        $mail1 = new ResetPasswordMail($this->pelanggan, 'dummy-token', 'https://example.com/reset');
        $env1 = $mail1->envelope();
        $this->assertSame('noreply@vexahostcloud.my.id', $env1->from->address);
        $this->assertSame('VexaHost WA Gateway', $env1->from->name);
        $this->assertSame('support@vexahostcloud.my.id', $env1->replyTo[0]->address);

        // 2. TagihanTerbit
        $mail2 = new TagihanTerbit($invoice);
        $env2 = $mail2->envelope();
        $this->assertSame('noreply@vexahostcloud.my.id', $env2->from->address);
        $this->assertSame('VexaHost WA Gateway', $env2->from->name);
        $this->assertSame('support@vexahostcloud.my.id', $env2->replyTo[0]->address);

        // 3. TesEmail (tipe noreply)
        $mail3 = new TesEmail('noreply');
        $env3 = $mail3->envelope();
        $this->assertSame('noreply@vexahostcloud.my.id', $env3->from->address);
        $this->assertSame('VexaHost WA Gateway', $env3->from->name);
        $this->assertSame('support@vexahostcloud.my.id', $env3->replyTo[0]->address);

        // 4. TesEmail (tipe support)
        $mail4 = new TesEmail('support');
        $env4 = $mail4->envelope();
        $this->assertSame('support@vexahostcloud.my.id', $env4->from->address);
        $this->assertSame('VexaHost WA Gateway Support', $env4->from->name);
        $this->assertSame('support@vexahostcloud.my.id', $env4->replyTo[0]->address);
    }

    public function test_admin_alerts_sent_to_all_admin_recipients(): void
    {
        Mail::fake();

        $notifier = app(EmailNotifier::class);
        $notifier->toAdmin(new KabarTim('Alert Uji', 'Isi pesan'));

        Mail::assertSent(KabarTim::class, 2);
        Mail::assertSent(KabarTim::class, fn ($m) => $m->hasTo('support@vexahostcloud.my.id'));
        Mail::assertSent(KabarTim::class, fn ($m) => $m->hasTo('vexahostcloudtech@gmail.com'));
    }

    public function test_db_override_used_when_set_and_fallback_to_env_when_empty(): void
    {
        AppSetting::simpan('mail_noreply_name', 'VexaHost Custom Noreply');
        MailIdentity::flushCache();

        $this->assertSame('VexaHost Custom Noreply', MailIdentity::noreply()['name']);
        $this->assertSame('db', MailIdentity::noreply()['source']);

        AppSetting::simpan('mail_noreply_name', null);
        MailIdentity::flushCache();

        $this->assertSame('VexaHost WA Gateway', MailIdentity::noreply()['name']);
        $this->assertSame('config', MailIdentity::noreply()['source']);
    }

    public function test_db_override_with_external_domain_is_ignored(): void
    {
        AppSetting::simpan('mail_noreply_address', 'phishing@externaldomain.com');
        MailIdentity::flushCache();

        $this->assertSame('noreply@vexahostcloud.my.id', MailIdentity::noreply()['address']);
        $this->assertSame('config', MailIdentity::noreply()['source']);
    }

    public function test_admin_form_rejects_external_sender_domain(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.system.mail.update'), [
            'mail_noreply_address' => 'bad@unauthorizeddomain.com',
            'mail_support_address' => 'support@vexahostcloud.my.id',
        ]);

        $response->assertSessionHasErrors(['mail_noreply_address']);
    }

    public function test_cache_is_flushed_after_admin_saves_mail_settings(): void
    {
        // Pre-warm cache
        MailIdentity::noreply();
        $this->assertTrue(Cache::has(MailIdentity::CACHE_KEY));

        $response = $this->actingAs($this->admin)->post(route('admin.system.mail.update'), [
            'mail_noreply_name' => 'Brand Baru Gateway',
            'mail_noreply_address' => 'noreply@vexahostcloud.my.id',
            'mail_support_name' => 'Support Baru Gateway',
            'mail_support_address' => 'support@vexahostcloud.my.id',
            'mail_reply_to_address' => 'support@vexahostcloud.my.id',
            'mail_admin_addresses' => "admin1@contoh.id,\nadmin2@contoh.id",
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertFalse(Cache::has(MailIdentity::CACHE_KEY));

        $this->assertSame('Brand Baru Gateway', MailIdentity::noreply()['name']);
        $this->assertContains('admin1@contoh.id', MailIdentity::adminRecipients());
        $this->assertContains('admin2@contoh.id', MailIdentity::adminRecipients());
    }
}
