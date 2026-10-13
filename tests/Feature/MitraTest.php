<?php

namespace Tests\Feature;

use App\Mail\PayoutPaidMail;
use App\Mail\PayoutRequestedMail;
use App\Models\Invoice;
use App\Models\PayoutRequest;
use App\Models\ReferralCode;
use App\Models\ReferralRedemption;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MitraTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();

        $this->user = User::create([
            'name' => 'Mitra User',
            'email' => 'mitra@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Mitra Workspace',
            'slug' => 'mitra-workspace',
            'owner_id' => $this->user->id,
            'owner_email' => $this->user->email,
        ]);

        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_landing_page_mitra_bisa_diakses_oleh_publik(): void
    {
        $response = $this->get(route('mitra.landing'));

        $response->assertOk();
        $response->assertSee('Program Kemitraan');
        $response->assertSee('20%');
    }

    public function test_landing_page_mitra_menyimpan_kode_referal_di_sesi(): void
    {
        $kode = ReferralCode::create([
            'code' => 'XYZAB',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
        ]);

        $response = $this->get(route('mitra.landing', ['ref' => 'xyzab']));

        $response->assertOk();
        $response->assertSessionHas('referral_code', 'XYZAB');
    }

    public function test_halaman_register_menyimpan_kode_referal_dan_menampilkan_badge(): void
    {
        $kode = ReferralCode::create([
            'code' => 'KODEA',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
        ]);

        $response = $this->get(route('register', ['ref' => 'kodea']));

        $response->assertOk();
        $response->assertSessionHas('referral_code', 'KODEA');
        $response->assertSee('KODEA');
        $response->assertSee('Diskon 10%');
    }

    public function test_dashboard_mitra_memerlukan_login(): void
    {
        $response = $this->get(route('mitra.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_mitra_menampilkan_form_pendaftaran_jika_belum_punya_kode(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('mitra.index'));

        $response->assertOk();
        $response->assertSee('Pendaftaran Program Mitra VexaHost');
        $response->assertSee(route('mitra.apply'));
    }

    public function test_pengguna_bisa_mengajukan_permohonan_mitra_dengan_data_rekening(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('mitra.apply'), [
                'bank_name' => 'BCA',
                'bank_account_number' => '8880123456',
                'bank_account_name' => 'Mitra Sukses Mandiri',
                'whatsapp_number' => '081298765432',
                'notes' => 'Software agency dengan 20 klien aktif.',
                'terms' => '1',
            ]);

        $response->assertRedirect(route('mitra.index'));

        $this->assertDatabaseHas('referral_codes', [
            'owner_user_id' => $this->user->id,
            'is_active' => false,
            'approval_status' => 'pending',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra Sukses Mandiri',
            'whatsapp_number' => '081298765432',
        ]);

        $kode = ReferralCode::where('owner_user_id', $this->user->id)->first();
        $this->assertNotNull($kode);
        $this->assertEquals(5, strlen($kode->code));
        $this->assertTrue($kode->isPending());
    }

    public function test_pengguna_wajib_menyetujui_syarat_dan_ketentuan_kemitraan(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('mitra.apply'), [
                'bank_name' => 'BCA',
                'bank_account_number' => '8880123456',
                'bank_account_name' => 'Mitra Sukses Mandiri',
                'whatsapp_number' => '081298765432',
            ]);

        $response->assertSessionHasErrors('terms');
        $this->assertSame(0, ReferralCode::count());
    }

    public function test_dashboard_mitra_menampilkan_status_menunggu_persetujuan_saat_pending(): void
    {
        ReferralCode::create([
            'code' => 'MITRA',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => false,
            'approval_status' => 'pending',
            'bank_name' => 'Mandiri',
            'bank_account_number' => '123000987654',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('mitra.index'));

        $response->assertOk();
        $response->assertSee('Permohonan Sedang Ditinjau Admin');
        $response->assertSee('Mandiri');
    }

    public function test_admin_bisa_menyetujui_permohonan_mitra(): void
    {
        $admin = User::create([
            'name' => 'Admin VexaHost',
            'email' => 'admin@vexahostcloud.my.id',
            'password' => Hash::make('password123'),
            'is_super_admin' => true,
        ]);

        $kode = ReferralCode::create([
            'code' => 'ACCKD',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => false,
            'approval_status' => 'pending',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.referrals.approve', $kode->id), [
                'discount_percent' => 10,
                'commission_percent' => 20,
            ]);

        $response->assertRedirect();
        $kode->refresh();
        $this->assertTrue($kode->isApproved());
        $this->assertTrue($kode->is_active);
        $this->assertSame($admin->id, $kode->approved_by);
    }

    public function test_admin_bisa_menolak_permohonan_mitra_dengan_alasan(): void
    {
        $admin = User::create([
            'name' => 'Admin VexaHost',
            'email' => 'admin@vexahostcloud.my.id',
            'password' => Hash::make('password123'),
            'is_super_admin' => true,
        ]);

        $kode = ReferralCode::create([
            'code' => 'REJKD',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => false,
            'approval_status' => 'pending',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.referrals.reject', $kode->id), [
                'rejection_reason' => 'Nomor WhatsApp tidak dapat dihubungi.',
            ]);

        $response->assertRedirect();
        $kode->refresh();
        $this->assertTrue($kode->isRejected());
        $this->assertFalse($kode->is_active);
        $this->assertSame('Nomor WhatsApp tidak dapat dihubungi.', $kode->rejection_reason);
    }

    public function test_dashboard_mitra_menampilkan_statistik_dan_riwayat_komisi_saat_approved(): void
    {
        $kode = ReferralCode::create([
            'code' => 'MITRA',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $pembeli = User::create([
            'name' => 'Klien Mitra',
            'email' => 'klien@contoh.id',
            'password' => Hash::make('password123'),
        ]);

        $workspaceKlien = Workspace::create([
            'name' => 'Workspace Klien',
            'slug' => 'workspace-klien',
            'owner_id' => $pembeli->id,
            'owner_email' => $pembeli->email,
        ]);

        $invoice = Invoice::create([
            'external_id' => (string) Str::ulid(),
            'workspace_id' => $workspaceKlien->id,
            'number' => 'INV-2026-001',
            'plan_slug' => 'pro',
            'period' => 'monthly',
            'amount' => 299000,
            'discount_amount' => 29900,
            'total' => 269100,
            'status' => 'paid',
            'paid_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        ReferralRedemption::create([
            'referral_code_id' => $kode->id,
            'workspace_id' => $workspaceKlien->id,
            'invoice_id' => $invoice->id,
            'discount_amount' => 29900,
            'commission_amount' => 53820,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('mitra.index'));

        $response->assertOk();
        $response->assertSee('MITRA');
        $response->assertSee('Workspace Klien');
        $response->assertSee(number_format(53820, 0, ',', '.'));
        $response->assertSee('Disetujui');
        $response->assertSee('Minimal Tarik Rp 100.000');
    }

    public function test_mitra_tidak_bisa_mencairkan_komisi_bila_saldo_kurang_dari_100_ribu(): void
    {
        $kode = ReferralCode::create([
            'code' => 'SALDO',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $pembeli = User::create([
            'name' => 'Klien Mitra',
            'email' => 'klien2@contoh.id',
            'password' => Hash::make('password123'),
        ]);

        $workspaceKlien = Workspace::create([
            'name' => 'Workspace Klien',
            'slug' => 'workspace-klien-2',
            'owner_id' => $pembeli->id,
            'owner_email' => $pembeli->email,
        ]);

        $invoice = Invoice::create([
            'external_id' => (string) Str::ulid(),
            'workspace_id' => $workspaceKlien->id,
            'number' => 'INV-2026-002',
            'plan_slug' => 'starter',
            'period' => 'monthly',
            'amount' => 150000,
            'discount_amount' => 15000,
            'total' => 135000,
            'status' => 'paid',
            'paid_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        ReferralRedemption::create([
            'referral_code_id' => $kode->id,
            'workspace_id' => $workspaceKlien->id,
            'invoice_id' => $invoice->id,
            'discount_amount' => 15000,
            'commission_amount' => 27000, // < 100.000
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('mitra.payout'));

        $response->assertRedirect();
        $response->assertSessionHas('swal');
        $this->assertSame(0, PayoutRequest::count());
    }

    public function test_mitra_bisa_mengajukan_pencairan_bila_saldo_mencapai_100_ribu(): void
    {
        $kode = ReferralCode::create([
            'code' => 'CAIRR',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $pembeli = User::create([
            'name' => 'Klien Mitra',
            'email' => 'klien3@contoh.id',
            'password' => Hash::make('password123'),
        ]);

        $workspaceKlien = Workspace::create([
            'name' => 'Workspace Klien',
            'slug' => 'workspace-klien-3',
            'owner_id' => $pembeli->id,
            'owner_email' => $pembeli->email,
        ]);

        $invoice = Invoice::create([
            'external_id' => (string) Str::ulid(),
            'workspace_id' => $workspaceKlien->id,
            'number' => 'INV-2026-003',
            'plan_slug' => 'enterprise',
            'period' => 'yearly',
            'amount' => 1000000,
            'discount_amount' => 100000,
            'total' => 900000,
            'status' => 'paid',
            'paid_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        ReferralRedemption::create([
            'referral_code_id' => $kode->id,
            'workspace_id' => $workspaceKlien->id,
            'invoice_id' => $invoice->id,
            'discount_amount' => 100000,
            'commission_amount' => 180000, // >= 100.000
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('mitra.payout'), [
                'notes' => 'Mohon ditransfer ke BCA saya secepatnya.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('swal');

        $this->assertDatabaseHas('payout_requests', [
            'referral_code_id' => $kode->id,
            'user_id' => $this->user->id,
            'amount' => 180000,
            'fee_percent' => 5,
            'fee_amount' => 9000,
            'net_amount' => 171000,
            'status' => 'pending',
            'notes' => 'Mohon ditransfer ke BCA saya secepatnya.',
        ]);

        $createdPayout = PayoutRequest::where('referral_code_id', $kode->id)->first();
        $this->assertNotNull($createdPayout->payout_number);
        $this->assertStringStartsWith('PO-', $createdPayout->payout_number);

        Mail::assertSent(PayoutRequestedMail::class, 1 + count(\App\Support\MailIdentity::adminRecipients()));
    }

    public function test_admin_bisa_menandai_pencairan_komisi_selesai_dan_komisi_menjadi_paid(): void
    {
        $admin = User::create([
            'name' => 'Admin VexaHost',
            'email' => 'admin@vexahostcloud.my.id',
            'password' => Hash::make('password123'),
            'is_super_admin' => true,
        ]);

        $kode = ReferralCode::create([
            'code' => 'PAIDD',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'whatsapp_number' => '081234567890',
        ]);

        $payout = PayoutRequest::create([
            'referral_code_id' => $kode->id,
            'user_id' => $this->user->id,
            'amount' => 200000,
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra User',
            'status' => 'pending',
        ]);

        $redemption = ReferralRedemption::create([
            'referral_code_id' => $kode->id,
            'workspace_id' => $this->workspace->id,
            'discount_amount' => 50000,
            'commission_amount' => 200000,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.referrals.payout.paid', $payout->id), [
                'admin_notes' => 'Sudah ditransfer via BCA manual pada '.now()->format('d/m/Y H:i'),
            ]);

        $response->assertRedirect();
        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame($admin->id, $payout->fresh()->paid_by);
        $this->assertSame('paid', $redemption->fresh()->status);
        Mail::assertSent(PayoutPaidMail::class);
    }

    public function test_mitra_dan_admin_bisa_melihat_invoice_resmi_pencairan(): void
    {
        $admin = User::create([
            'name' => 'Admin VexaHost',
            'email' => 'admin-inv@vexahostcloud.my.id',
            'password' => Hash::make('password123'),
            'is_super_admin' => true,
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
        ]);

        $kode = ReferralCode::create([
            'code' => 'INVCD',
            'owner_user_id' => $this->user->id,
            'discount_percent' => 10,
            'commission_percent' => 20,
            'is_active' => true,
            'approval_status' => 'approved',
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra Invoice User',
            'whatsapp_number' => '081234567890',
        ]);

        $payout = PayoutRequest::create([
            'referral_code_id' => $kode->id,
            'user_id' => $this->user->id,
            'amount' => 300000,
            'bank_name' => 'BCA',
            'bank_account_number' => '8880123456',
            'bank_account_name' => 'Mitra Invoice User',
            'status' => 'pending',
            'notes' => 'Tolong proses invoice ini.',
        ]);

        // Mitra yang memiliki payout bisa melihat invoice
        $responseMitra = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('mitra.payout.invoice', $payout->id));

        $responseMitra->assertOk();
        $responseMitra->assertSee('INVOICE PENCAIRAN KOMISI');
        $responseMitra->assertSee($payout->payout_number);
        $responseMitra->assertSee('300.000');
        $responseMitra->assertSee('15.000'); // 5% fee
        $responseMitra->assertSee('285.000'); // net

        // Admin bisa melihat invoice yang sama
        $responseAdmin = $this->actingAs($admin)
            ->get(route('admin.referrals.payout.invoice', $payout->id));

        $responseAdmin->assertOk();
        $responseAdmin->assertSee($payout->payout_number);

        // Mitra bisa mengunduh berkas invoice PDF resmi
        $resMitraDownload = $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('mitra.payout.download', $payout->id));

        $resMitraDownload->assertOk();
        $this->assertStringContainsString('application/pdf', $resMitraDownload->headers->get('content-type'));

        // Admin bisa mengunduh berkas invoice PDF resmi
        $resAdminDownload = $this->actingAs($admin)
            ->get(route('admin.referrals.payout.download', $payout->id));

        $resAdminDownload->assertOk();
        $this->assertStringContainsString('application/pdf', $resAdminDownload->headers->get('content-type'));

        // Pengguna lain tidak diizinkan melihat invoice milik mitra lain (403 Forbidden)
        $otherWorkspace = Workspace::create([
            'name' => 'Other Workspace',
            'slug' => 'other-workspace',
            'owner_id' => $otherUser->id,
            'owner_email' => $otherUser->email,
        ]);
        $otherWorkspace->members()->attach($otherUser->id, ['role' => 'owner']);

        $responseOther = $this->actingAs($otherUser)
            ->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('mitra.payout.invoice', $payout->id));

        $responseOther->assertForbidden();
    }
}
