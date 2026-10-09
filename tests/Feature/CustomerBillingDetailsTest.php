<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionService;
use App\Support\WilayahIndonesia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerBillingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.mayar.api_key', 'test_api_key_123');
        Config::set('services.mayar.api_url', 'https://api.mayar.id/hl/v2');
        Config::set('services.mayar.webhook_token', 'test_webhook_token_secret');
        Config::set('services.xendit.secret_key', 'xnd_development_test_key_123');
        Config::set('services.xendit.webhook_token', 'test_xendit_webhook_token');

        $this->owner = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@perusahaan.id',
            'phone' => '081234567890',
            'password' => Hash::make('password123'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Workspace Budi',
            'slug' => 'workspace-budi',
            'owner_id' => $this->owner->id,
            'owner_email' => $this->owner->email,
        ]);

        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);
    }

    private function terbitkanTagihan(): Invoice
    {
        return app(SubscriptionService::class)->issueInvoice(
            $this->workspace,
            'essentials',
            'monthly'
        );
    }

    public function test_customer_can_save_valid_individual_billing_details(): void
    {
        $invoice = $this->terbitkanTagihan();

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('billing.details', $invoice->id), [
                'billing_type' => 'individu',
                'billing_name' => 'Budi Santoso',
                'billing_email' => 'budi@perusahaan.id',
                'billing_phone' => '081234567890',
                'billing_bank_name' => 'Bank Central Asia (BCA)',
                'billing_bank_account' => '1234567890',
                'billing_bank_holder' => 'Budi Santoso',
                'billing_province' => 'DKI Jakarta',
                'billing_city' => 'Jakarta Selatan',
                'billing_district' => 'Kebayoran Baru',
                'billing_address' => 'Jl. Sudirman No. 45',
                'billing_postal_code' => '12190',
            ]);

        $response->assertRedirect();

        $ws = $this->workspace->fresh();
        $this->assertEquals('individu', $ws->billing_type);
        $this->assertEquals('Budi Santoso', $ws->billing_name);
        $this->assertEquals('budi@perusahaan.id', $ws->billing_email);
        $this->assertEquals('6281234567890', $ws->billing_phone);
        $this->assertEquals('Bank Central Asia (BCA)', $ws->billing_bank_name);
        $this->assertEquals('1234567890', $ws->billing_bank_account);
        $this->assertEquals('Budi Santoso', $ws->billing_bank_holder);
        $this->assertEquals('DKI Jakarta', $ws->billing_province);
        $this->assertEquals('Jakarta Selatan', $ws->billing_city);
        $this->assertEquals('Kebayoran Baru', $ws->billing_district);
        $this->assertEquals('Jl. Sudirman No. 45', $ws->billing_address);
        $this->assertEquals('12190', $ws->billing_postal_code);
        $this->assertTrue($ws->isBillingComplete());
    }

    public function test_customer_type_badan_requires_company_name(): void
    {
        $invoice = $this->terbitkanTagihan();

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('billing.details', $invoice->id), [
                'billing_type' => 'badan',
                'billing_company' => '',
                'billing_name' => 'Budi Santoso',
                'billing_email' => 'budi@perusahaan.id',
                'billing_phone' => '081234567890',
                'billing_bank_name' => 'Bank Mandiri',
                'billing_bank_account' => '1234567890',
                'billing_bank_holder' => 'PT Sukses Mandiri',
                'billing_province' => 'Jawa Barat',
                'billing_city' => 'Kota Bandung',
                'billing_district' => 'Coblong',
                'billing_address' => 'Jl. Asia Afrika No. 10',
            ]);

        $response->assertSessionHasErrors('billing_company');
    }

    public function test_invoice_page_renders_all_banks_and_provinces(): void
    {
        $invoice = $this->terbitkanTagihan();

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('billing.invoice', $invoice->id));

        $response->assertOk();
        $response->assertSee('Bank Central Asia (BCA)');
        $response->assertSee('Bank Mandiri');
        $response->assertSee('Bank Rakyat Indonesia (BRI)');
        $response->assertSee('DKI Jakarta');
        $response->assertSee('Jawa Barat');
        $response->assertSee('Banten');
        $response->assertSee('Pilih kota...');
        $response->assertSee('Pilih kecamatan...');
    }

    public function test_wilayah_indonesia_cascading_data(): void
    {
        $provinces = WilayahIndonesia::provinsi();
        $this->assertContains('DKI Jakarta', $provinces);
        $this->assertContains('Jawa Barat', $provinces);

        $citiesJakarta = WilayahIndonesia::kota('DKI Jakarta');
        $this->assertContains('Kota Jakarta Selatan', $citiesJakarta);
        $this->assertContains('Kota Jakarta Pusat', $citiesJakarta);

        $districtsJaksel = WilayahIndonesia::kecamatan('DKI Jakarta', 'Kota Jakarta Selatan');
        $this->assertContains('Tebet', $districtsJaksel);
        $this->assertContains('Cilandak', $districtsJaksel);
    }

    public function test_pay_with_mayar_blocked_when_billing_incomplete(): void
    {
        $invoice = $this->terbitkanTagihan();

        $this->assertFalse($this->workspace->isBillingComplete());

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->postJson(route('billing.invoice.mayar', $invoice->id));

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'incomplete_billing',
            ]);
    }

    public function test_pay_with_mayar_allowed_when_billing_complete(): void
    {
        $invoice = $this->terbitkanTagihan();

        $this->workspace->forceFill([
            'billing_type' => 'individu',
            'billing_name' => 'Budi Santoso',
            'billing_email' => 'budi@perusahaan.id',
            'billing_phone' => '6281234567890',
            'billing_bank_name' => 'Bank Central Asia (BCA)',
            'billing_bank_account' => '1234567890',
            'billing_bank_holder' => 'Budi Santoso',
            'billing_province' => 'DKI Jakarta',
            'billing_city' => 'Jakarta Selatan',
            'billing_district' => 'Kebayoran Baru',
            'billing_address' => 'Jl. Sudirman No. 45',
        ])->save();

        $this->assertTrue($this->workspace->fresh()->isBillingComplete());

        Http::fake([
            'https://api.xendit.co/v2/invoices' => Http::response([
                'id' => 'xnd-inv-comp123',
                'invoice_url' => 'https://checkout.xendit.co/web/comp123',
                'status' => 'PENDING',
            ], 200),
            '*/invoices/create' => Http::response([
                'statusCode' => 200,
                'messages' => 'success',
                'data' => [
                    'id' => 'mayar-inv-complete-1',
                    'transactionId' => 'tx-abc-complete',
                    'link' => 'https://mayar.link/invoices/comp123',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->postJson(route('billing.invoice.mayar', $invoice->id));

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
        $this->assertNotEmpty($response->json('payment_url'));
    }

    public function test_checkout_page_renders_clean_button_without_warning_alert(): void
    {
        $invoice = $this->terbitkanTagihan();

        $this->assertFalse($this->workspace->isBillingComplete());

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('billing.invoice', $invoice->id));

        $response->assertOk();
        $response->assertSee('Pilih Pembayaran');
        $response->assertDontSee('Lengkapi Data Pelanggan Dulu');
        $response->assertDontSee('Isi dan simpan data pelanggan di sebelah kiri untuk mengaktifkan pembayaran');
    }

    public function test_admin_invoices_and_workspace_detail_displays_full_customer_billing_data(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@vexahostcloud.my.id',
            'password' => Hash::make('secret'),
            'is_super_admin' => true,
        ]);

        $this->workspace->forceFill([
            'billing_type' => 'badan',
            'billing_company' => 'PT VexaHost Digital Solusi',
            'billing_name' => 'Rian PIC',
            'billing_email' => 'finance@vexahostdigital.id',
            'billing_phone' => '628999888777',
            'billing_bank_name' => 'Bank Mandiri',
            'billing_bank_account' => '9876543210',
            'billing_bank_holder' => 'PT VexaHost Digital Solusi',
            'billing_province' => 'DKI Jakarta',
            'billing_city' => 'Kota Jakarta Selatan',
            'billing_district' => 'Kebayoran Baru',
            'billing_address' => 'Gedung Menara VexaHost Lt. 12',
            'billing_postal_code' => '12190',
        ])->save();

        $invoice = $this->terbitkanTagihan();

        // 1. Cek di halaman admin invoices
        $responseInvoices = $this->actingAs($superAdmin)
            ->get(route('admin.invoices'));

        $responseInvoices->assertOk();
        $responseInvoices->assertSee('PT VexaHost Digital Solusi');
        $responseInvoices->assertSee('Rian PIC');
        $responseInvoices->assertSee('628999888777');
        $responseInvoices->assertSee('Bank Mandiri');
        $responseInvoices->assertSee('9876543210');

        // 2. Cek di halaman admin workspace detail
        $responseDetail = $this->actingAs($superAdmin)
            ->get(route('admin.workspaces.show', $this->workspace->id));

        $responseDetail->assertOk();
        $responseDetail->assertSee('Data Pelanggan');
        $responseDetail->assertSee('PT VexaHost Digital Solusi');
        $responseDetail->assertSee('Rian PIC');
        $responseDetail->assertSee('finance@vexahostdigital.id');
        $responseDetail->assertSee('628999888777');
        $responseDetail->assertSee('Bank Mandiri');
        $responseDetail->assertSee('Gedung Menara VexaHost Lt. 12');
    }

    public function test_checkout_page_does_not_leak_raw_javascript_outside_script_tags(): void
    {
        $invoice = $this->terbitkanTagihan();

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('billing.invoice', $invoice->id));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('x-data="checkoutInvoice(window.checkoutInvoiceData)"', $content);
        $this->assertStringContainsString('x-data="formDataPelanggan(window.formDataPelangganData)"', $content);
    }

    public function test_customer_can_save_billing_details_via_ajax_json_response(): void
    {
        $invoice = $this->terbitkanTagihan();

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->postJson(route('billing.details', $invoice->id), [
                'billing_type' => 'individu',
                'billing_name' => 'Budi Santoso',
                'billing_email' => 'budi@perusahaan.id',
                'billing_phone' => '081234567890',
                'billing_bank_name' => 'Bank Central Asia (BCA)',
                'billing_bank_account' => '1234567890',
                'billing_bank_holder' => 'Budi Santoso',
                'billing_province' => 'Banten',
                'billing_city' => 'Kota Tangerang Selatan',
                'billing_district' => 'Pondok Aren',
                'billing_address' => 'Puri Bintaro Hijau',
                'billing_postal_code' => '15224',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);

        $this->assertTrue($this->workspace->fresh()->isBillingComplete());
    }

    public function test_ajax_returns_validation_error_json_when_phone_invalid(): void
    {
        $invoice = $this->terbitkanTagihan();

        $response = $this->actingAs($this->owner)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->postJson(route('billing.details', $invoice->id), [
                'billing_type' => 'individu',
                'billing_name' => 'Budi Santoso',
                'billing_email' => 'budi@perusahaan.id',
                'billing_phone' => '123', // Nomor tidak valid
                'billing_bank_name' => 'Bank Central Asia (BCA)',
                'billing_bank_account' => '1234567890',
                'billing_bank_holder' => 'Budi Santoso',
                'billing_province' => 'Banten',
                'billing_city' => 'Kota Tangerang Selatan',
                'billing_district' => 'Pondok Aren',
                'billing_address' => 'Puri Bintaro Hijau',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'status',
                'message',
                'errors' => ['billing_phone'],
            ]);
    }
}
