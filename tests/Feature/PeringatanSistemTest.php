<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\PeringatanSistem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Aturan eskalasi temuan pengawas kesehatan.
 *
 * Yang diuji di sini bukan isi pemeriksaannya melainkan **ke mana kabarnya
 * pergi** — satu-satunya bagian yang kalau salah membuat kegagalan paling parah
 * berhenti di lonceng notifikasi yang tidak dibuka siapa pun sampai besok pagi.
 */
class PeringatanSistemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Mail::fake();

        User::create([
            'name' => 'Admin',
            'email' => 'admin-peringatan@vexahostcloud.my.id',
            'password' => Hash::make('rahasia12345'),
            'is_super_admin' => true,
        ]);

        /*
         | phpunit.xml menyetel MAIL_MAILER=array, dan `EmailNotifier::ready()`
         | menolak mailer itu — sengaja, karena "array" berarti tidak ada email
         | yang benar-benar keluar. Di sini yang diuji justru KEPUTUSAN untuk
         | mengirim, jadi email harus terlihat siap. `Mail::fake()` yang menahan
         | kirimannya, bukan mailer palsu.
        */
        config([
            'billing.support_email' => 'tim@vexahostcloud.my.id',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.contoh',
            'mail.from.address' => 'noreply@vexahostcloud.my.id',
        ]);
    }

    private function kabari(string $level, string $dedupe = 'uji.sesuatu'): void
    {
        app(PeringatanSistem::class)->kabari(
            type: 'sistem.uji',
            title: 'Judul uji',
            body: 'Isi uji.',
            url: 'https://contoh/admin/sistem',
            level: $level,
            dedupe: $dedupe,
        );
    }

    public function test_temuan_ringan_hanya_masuk_lonceng(): void
    {
        $this->kabari('warning');

        $this->assertSame(1, Notification::where('type', 'sistem.uji')->count());

        Mail::assertNothingSent();
    }

    /**
     * Temuan berat keluar lewat email juga.
     *
     * Email penting justru karena ia satu-satunya jalur yang selamat dari
     * matinya engine WhatsApp — dan engine mati adalah salah satu temuan berat
     * yang paling sering terjadi. Peringatan "engine mati" yang cuma dikirim
     * lewat WhatsApp adalah peringatan yang tidak akan pernah sampai.
     */
    public function test_temuan_berat_ikut_dikirim_lewat_email(): void
    {
        $this->kabari('danger');

        $this->assertSame(1, Notification::where('type', 'sistem.uji')->count());

        Mail::assertSentCount(count(\App\Support\MailIdentity::adminRecipients()));
    }

    /**
     * Satu keadaan menghasilkan satu kabar per hari.
     *
     * Pengawasnya berjalan tiap jam. Tanpa penanda, engine yang mati semalaman
     * menghasilkan dua belas kabar yang sama — dan orang berhenti membacanya.
     * Yang terabaikan berikutnya adalah yang sungguhan.
     */
    public function test_keadaan_yang_sama_tidak_dikabarkan_dua_kali_sehari(): void
    {
        $this->kabari('danger');
        $this->kabari('danger');
        $this->kabari('danger');

        $this->assertSame(1, Notification::where('type', 'sistem.uji')->count());
        Mail::assertSentCount(count(\App\Support\MailIdentity::adminRecipients()));
    }

    /** Keadaan yang berbeda tetap dikabarkan sendiri-sendiri. */
    public function test_keadaan_berbeda_tetap_dikabarkan_masing_masing(): void
    {
        $this->kabari('danger', 'uji.satu');
        $this->kabari('danger', 'uji.dua');

        $this->assertSame(2, Notification::where('type', 'sistem.uji')->count());
    }

    /** Peringatan yang gagal terkirim tidak boleh menjatuhkan pengawasnya. */
    public function test_jalur_kirim_yang_mati_tidak_melempar_galat(): void
    {
        config(['billing.admin_phone' => '628123456789']);

        Http::fake(fn () => throw new \RuntimeException('gateway mati'));

        $this->kabari('danger');

        // Loncengnya tetap terisi meski jalur luar gagal seluruhnya — itu yang
        // menjaga temuan tidak hilang total saat engine sedang bermasalah.
        $this->assertSame(1, Notification::where('type', 'sistem.uji')->count());
    }
}
