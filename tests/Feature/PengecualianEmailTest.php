<?php

namespace Tests\Feature;

use App\Mail\KabarTim;
use App\Models\PendingExemption;
use App\Models\SpecialNumber;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HelpdeskService;
use App\Services\Notifications\EmailNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email menyusul WhatsApp di seluruh pengecualian dan kabar tim.
 *
 * Satu alasan untuk ketiganya: WhatsApp adalah satu-satunya jalur yang dipakai,
 * dan satu-satunya jalur berarti satu titik yang kalau mati membuat semuanya
 * diam. Nomor VexaHost terputus — hal yang memang terjadi saat deploy, saat
 * WhatsApp memutus perangkat tertaut, atau saat ponselnya lama offline — dan
 * seluruh kabar ke tim hilang tanpa satu pun gejala.
 */
class PengecualianEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();

        // Suite berjalan dengan MAIL_MAILER=array; `EmailNotifier::ready()`
        // menolak itu, jadi tanpa baris ini seluruh tes di bawah menguji jalur
        // "email belum dikonfigurasi", bukan pengirimannya.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.from.address' => 'vexahostcloudtech@gmail.com',
            'mail.admin_addresses' => ['tim@vexahostcloud.my.id'],
            'billing.support_email' => 'tim@vexahostcloud.my.id',
        ]);
        \App\Support\MailIdentity::flushCache();

        $this->admin = User::create([
            'name' => 'VexaHost Finance',
            'email' => 'finance@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);
    }

    // ===================== Kabar tim lewat email =====================

    public function test_tiket_baru_mengabari_tim_lewat_email_juga(): void
    {
        $pelanggan = User::create([
            'name' => 'Ryan',
            'email' => 'ryan@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $workspace = Workspace::create([
            'name' => 'Toko Ryan',
            'slug' => 'toko-ryan',
            'owner_id' => $pelanggan->id,
            'owner_email' => $pelanggan->email,
            'max_sessions' => 1,
            'monthly_message_quota' => 1000,
        ]);

        app(HelpdeskService::class)->buatTiket($workspace, $pelanggan, [
            'subject' => 'Nomor terputus',
            'category' => 'nomor',
            'body' => 'Sudah scan tiga kali.',
        ]);

        Mail::assertSent(KabarTim::class, fn ($surat) => $surat->hasTo('tim@vexahostcloud.my.id'));
        $this->assertSame(1, Ticket::count());
    }

    /**
     * Penanda email berawalan sendiri, jadi email yang berhasil tidak pernah
     * membungkam pesan WhatsApp untuk peristiwa yang sama.
     */
    public function test_penanda_email_tim_terpisah_dari_penanda_whatsapp(): void
    {
        app(EmailNotifier::class)
            ->kabarTim('Uji', 'Isi kabar', 'peristiwa:9');

        $this->assertTrue(Cache::has('email-notif:peristiwa:9'));
        $this->assertFalse(Cache::has('wa-notif:peristiwa:9'));
    }

    /**
     * Teks kabar disusun untuk WhatsApp, jadi penanda tebalnya harus dibuang —
     * di email `*teks*` cuma terbaca sebagai tanda bintang nyasar.
     */
    public function test_penanda_tebal_whatsapp_dibuang_di_email(): void
    {
        $html = (new KabarTim('Judul', "*Bukti pembayaran baru*\n\nINV-000001"))->render();

        $this->assertStringContainsString('Bukti pembayaran baru', $html);
        $this->assertStringNotContainsString('*Bukti pembayaran baru*', $html);
    }

    // ===================== Nomor istimewa =====================

    public function test_nomor_istimewa_bisa_menyimpan_email_pemiliknya(): void
    {
        $this->actingAs($this->admin)->post(route('admin.exemptions.numbers.store'), [
            'phone' => '081234567890',
            'email' => 'operasional@vexahostcloud.my.id',
            'label' => 'Nomor notifikasi VexaHost',
        ])->assertRedirect();

        $this->assertSame('operasional@vexahostcloud.my.id', SpecialNumber::firstOrFail()->email);
    }

    /** Email opsional: nomor lama tanpa email tidak boleh jadi tidak bisa disimpan. */
    public function test_email_nomor_istimewa_boleh_dikosongkan(): void
    {
        $this->actingAs($this->admin)->post(route('admin.exemptions.numbers.store'), [
            'phone' => '081234567890',
            'label' => 'Tanpa email',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(SpecialNumber::firstOrFail()->email);
    }

    // ===================== Pembebasan lewat email =====================

    /**
     * Inti dari bagian ini.
     *
     * Tanpa pembebasan yang menunggu, membebaskan calon pelanggan berarti
     * mengingat untuk kembali menandainya setelah mereka mendaftar — dan yang
     * lupa ditandai akan tertagih seperti pelanggan biasa.
     */
    public function test_pembebasan_email_berlaku_otomatis_saat_pemiliknya_mendaftar(): void
    {
        $this->actingAs($this->admin)->post(route('admin.exemptions.email'), [
            'email' => 'Calon@Perusahaan.co.id',
            'note' => 'Mitra strategis',
        ])->assertRedirect();

        // Disimpan huruf kecil supaya pencocokannya tidak bergantung cara orang
        // mengetik alamatnya saat mendaftar.
        $this->assertDatabaseHas('pending_exemptions', ['email' => 'calon@perusahaan.co.id']);

        // Keluar dulu: /register ada di balik middleware `guest`, dan admin
        // yang masih login akan dipantulkan sebelum pendaftarannya terjadi.
        $this->post('/logout');
        $this->flushSession();

        $this->post('/register', [
            'name' => 'Calon Pelanggan',
            'email' => 'calon@perusahaan.co.id',
            'workspace' => 'PT Calon',
            'password' => 'rahasia12345',
            'password_confirmation' => 'rahasia12345',
            'terms' => '1',
        ])->assertRedirect();

        $user = User::where('email', 'calon@perusahaan.co.id')->firstOrFail();

        $this->assertTrue((bool) $user->is_exempt, 'Pembebasan yang menunggu tidak diterapkan saat mendaftar.');
        $this->assertTrue($user->workspaces()->first()->isExempt());

        $baris = PendingExemption::firstOrFail();
        $this->assertTrue($baris->sudahDipakai());
        $this->assertSame($user->id, $baris->claimed_by_user_id);
    }

    /** Alamat yang sudah punya akun dibebaskan langsung, tanpa menunggu apa pun. */
    public function test_alamat_yang_sudah_punya_akun_dibebaskan_seketika(): void
    {
        $ada = User::create([
            'name' => 'Sudah Ada',
            'email' => 'ada@contoh.id',
            'password' => Hash::make('rahasia12345'),
        ]);

        $this->actingAs($this->admin)->post(route('admin.exemptions.email'), [
            'email' => 'ada@contoh.id',
        ])->assertRedirect();

        $this->assertTrue((bool) $ada->fresh()->is_exempt);
        $this->assertSame(0, PendingExemption::count(), 'Tidak perlu menunggu untuk akun yang sudah ada.');
    }

    public function test_pembebasan_yang_menunggu_bisa_dibatalkan(): void
    {
        $this->actingAs($this->admin)->post(route('admin.exemptions.email'), ['email' => 'batal@contoh.id']);

        $baris = PendingExemption::firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.exemptions.email.cancel', $baris->id))
            ->assertRedirect();

        $this->assertSame(0, PendingExemption::count());
    }

    /**
     * Yang sudah dipakai TIDAK boleh dibatalkan dari sini.
     *
     * Menghapus barisnya tidak mencabut `is_exempt` pemiliknya, jadi yang
     * terjadi cuma jejaknya hilang sementara pembebasannya tetap berlaku — dan
     * tidak ada lagi cara menjawab siapa yang pernah memberikannya.
     */
    public function test_pembebasan_yang_sudah_dipakai_tidak_bisa_dibatalkan(): void
    {
        $baris = PendingExemption::create([
            'email' => 'sudah@contoh.id',
            'claimed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.exemptions.email.cancel', $baris->id))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, PendingExemption::count());
    }

    public function test_alamat_yang_sama_tidak_menghasilkan_dua_baris(): void
    {
        foreach (range(1, 3) as $ke) {
            $this->actingAs($this->admin)->post(route('admin.exemptions.email'), ['email' => 'sama@contoh.id']);
        }

        $this->assertSame(1, PendingExemption::count());
    }

    public function test_halaman_pengecualian_menampilkan_yang_menunggu(): void
    {
        $this->actingAs($this->admin)->post(route('admin.exemptions.email'), ['email' => 'nanti@contoh.id']);

        $this->actingAs($this->admin)->get(route('admin.exemptions'))
            ->assertOk()
            ->assertSee('Menunggu pemiliknya mendaftar')
            ->assertSee('nanti@contoh.id');
    }
}
