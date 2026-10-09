<?php

use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\EnterpriseController as AdminEnterpriseController;
use App\Http\Controllers\Admin\ExemptionController as AdminExemptionController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\MessageController as AdminMessageController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\PaymentSettingController as AdminPaymentSettingController;
use App\Http\Controllers\Admin\ReferralController as AdminReferralController;
use App\Http\Controllers\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Admin\StatusController as AdminStatusController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\AiIntegrationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Dashboard\ApiKeyController;
use App\Http\Controllers\Dashboard\BalanceController;
use App\Http\Controllers\Dashboard\BillingController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DataRightsController;
use App\Http\Controllers\Dashboard\MessageController;
use App\Http\Controllers\Dashboard\MitraController;
use App\Http\Controllers\Dashboard\ProfileController;
use App\Http\Controllers\Dashboard\SessionController;
use App\Http\Controllers\Dashboard\TemplateController;
use App\Http\Controllers\Dashboard\TicketController;
use App\Http\Controllers\Dashboard\WebhookController;
use App\Http\Controllers\Dashboard\WorkspaceController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\EnterpriseLeadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingGuideController;
use App\Http\Controllers\StatusController;
use App\Support\DocsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('welcome');

/*
| Public XML Sitemap (Google Search Console & Mesin Pencari)
*/
Route::get('sitemap.xml', function () {
    $baseUrl = rtrim(config('app.url', url('/')), '/');

    // lastmod dibaca dari berkas view/markdown-nya, bukan now(). Sitemap yang
    // menyebut setiap URL "baru saja berubah" pada setiap permintaan diabaikan
    // Google — tanggalnya harus benar-benar menandai perubahan.
    $diubah = function (string $view): ?string {
        $berkas = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');

        return is_file($berkas)
            ? Carbon::createFromTimestamp(filemtime($berkas))->toAtomString()
            : null;
    };

    $urls = [
        ['loc' => $baseUrl.'/', 'changefreq' => 'daily', 'priority' => '1.0', 'lastmod' => $diubah('welcome')],
        ['loc' => $baseUrl.'/docs', 'changefreq' => 'weekly', 'priority' => '0.9', 'lastmod' => $diubah('docs.index')],
        ['loc' => $baseUrl.'/mitra', 'changefreq' => 'weekly', 'priority' => '0.8', 'lastmod' => $diubah('mitra.index')],
        ['loc' => $baseUrl.'/ai', 'changefreq' => 'weekly', 'priority' => '0.8', 'lastmod' => $diubah('ai.index')],
        ['loc' => $baseUrl.'/enterprise', 'changefreq' => 'weekly', 'priority' => '0.8', 'lastmod' => $diubah('enterprise')],
        ['loc' => $baseUrl.'/status', 'changefreq' => 'hourly', 'priority' => '0.7', 'lastmod' => $diubah('status')],
    ];

    try {
        foreach (array_keys(DocsRepository::flat()) as $docSlug) {
            $urls[] = [
                'loc' => $baseUrl.'/docs/'.$docSlug,
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'lastmod' => DocsRepository::lastModified($docSlug),
            ];
        }
    } catch (Throwable $e) {
        // Fallback gracefully jika ada kendala pembacaan berkas docs
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

    foreach ($urls as $u) {
        $xml .= "    <url>\n";
        $xml .= '        <loc>'.htmlspecialchars($u['loc'], ENT_XML1)."</loc>\n";
        if (! empty($u['lastmod'])) {
            $xml .= '        <lastmod>'.$u['lastmod']."</lastmod>\n";
        }
        $xml .= '        <changefreq>'.$u['changefreq']."</changefreq>\n";
        $xml .= '        <priority>'.$u['priority']."</priority>\n";
        $xml .= "    </url>\n";
    }

    $xml .= '</urlset>';

    return response($xml, 200, [
        'Content-Type' => 'application/xml; charset=utf-8',
    ]);
})->name('sitemap');
Route::get('mitra', [MitraController::class, 'landing'])->name('mitra.landing');
Route::get('ai', [AiIntegrationController::class, 'index'])->name('ai.index');
Route::redirect('integrasi-ai', 'ai');
Route::get('ai/panduan.md', [AiIntegrationController::class, 'downloadMasterMd'])->name('ai.markdown');
Route::get('ai/agent/{agent}/unduh', [AiIntegrationController::class, 'downloadAgentFile'])->name('ai.agent.download');

/*
| Dokumentasi. Terbuka untuk publik: isinya penjelasan cara kerja dan cara
| memakai, bukan data pelanggan. Slug dibatasi daftar putih di DocsRepository,
| jadi nilainya tidak pernah dipakai menyusun path berkas.
*/
Route::get('docs', [DocsController::class, 'index'])->name('docs.index');
Route::get('docs/{slug}.md', [DocsController::class, 'raw'])
    ->whereIn('slug', array_keys(DocsRepository::flat()))
    ->name('docs.raw');
Route::get('docs/{slug}', [DocsController::class, 'show'])
    ->whereIn('slug', array_keys(DocsRepository::flat()))
    ->name('docs.show');

/*
| Halaman status. Terbuka tanpa login, dan itu keputusan sadar: yang paling
| butuh halaman ini justru orang yang sedang tidak bisa masuk. Halaman status
| di balik login adalah halaman status yang mati persis saat diperlukan.
|
| Sengaja TIDAK memakai middleware langganan atau workspace mana pun — keduanya
| membaca database, dan halaman ini harus tetap terbuka saat database itulah
| yang sedang bermasalah.
*/
Route::get('status', StatusController::class)->name('status');
Route::get('status.json', [StatusController::class, 'json'])->name('status.json');

/*
| Autentikasi lokal: gateway memegang form login dan register-nya sendiri,
| tidak menumpang pintu masuk aplikasi lain.
*/
Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register']);

    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

    Route::get('forgot-password', [PasswordResetController::class, 'showForgotForm'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

/*
| Form "Hubungi kami" paket Enterprise — terbuka untuk tamu.
|
| Yang paling sering butuh lebih dari Elite adalah orang yang sedang menimbang
| apakah produk ini sanggup, bukan pelanggan yang sudah masuk. Memaksa mereka
| mendaftar lebih dulu berarti kehilangan mereka di langkah yang tidak perlu ada.
| Rate limit-nya wajib: form publik tanpa batas adalah undangan bagi bot untuk
| mengisi tabelnya sampai permintaan sungguhan tidak bisa ditemukan lagi.
*/
/*
| Halaman Enterprise dengan kalkulator perkiraannya.
|
| Alamat sendiri, bukan jangkar di halaman harga: yang menimbang Enterprise
| butuh memasukkan angkanya lalu melihat hasilnya berubah, dan tim perlu bisa
| mengirimkan alamatnya langsung ke calon pelanggan.
*/
Route::get('enterprise', [EnterpriseLeadController::class, 'show'])->name('enterprise');

Route::post('enterprise/hubungi', [EnterpriseLeadController::class, 'store'])
    ->middleware('throttle:enterprise')
    ->name('enterprise.contact');

Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

/*
| Autentikasi dua faktor.
|
| Di luar setiap grup lain dengan sengaja. Rute-rute ini harus tetap terbuka
| justru bagi sesi yang BELUM lolos faktor kedua dan bagi admin yang belum
| memasangnya sama sekali — keduanya keadaan yang ditolak middleware `2fa`.
| Memasukkannya ke grup mana pun yang dijaga middleware itu menghasilkan
| pengalihan ke halaman yang menjaga dirinya sendiri, dan peramban berputar
| sampai menyerah.
|
| `workspace` juga tidak dipakai: pendaftar baru belum punya workspace, dan
| kode tetap harus bisa dimasukkan sebelum apa pun yang lain.
*/
Route::middleware('auth')->group(function (): void {
    Route::get('2fa', [TwoFactorController::class, 'pasang'])->name('two-factor.setup');
    Route::post('2fa', [TwoFactorController::class, 'nyalakan'])->name('two-factor.enable');
    Route::delete('2fa', [TwoFactorController::class, 'matikan'])->name('two-factor.disable');

    Route::get('2fa/kode-pemulihan/csv', [TwoFactorController::class, 'unduhKodePemulihanCsv'])->name('two-factor.recovery-codes.csv');
    Route::post('2fa/kode-pemulihan/tampilkan', [TwoFactorController::class, 'tampilkanKodePemulihan'])->name('two-factor.recovery-codes.show');
    Route::post('2fa/kode-pemulihan/buat-ulang', [TwoFactorController::class, 'buatUlangKodePemulihan'])->name('two-factor.recovery-codes.regenerate');

    Route::get('2fa/kode', [TwoFactorController::class, 'tantangan'])->name('two-factor.challenge');

    // Dibatasi lajunya: enam digit adalah satu juta kemungkinan, dan tanpa batas
    // ini kode bisa ditebak habis oleh skrip dalam hitungan jam.
    Route::post('2fa/kode', [TwoFactorController::class, 'verifikasi'])
        ->middleware('throttle:login')
        ->name('two-factor.verify');
});

Route::middleware('auth')->group(function (): void {
    // Pembuatan workspace ada di luar middleware `workspace`, karena middleware
    // itulah yang mengarahkan ke sini saat pengguna belum punya workspace.
    Route::get('onboarding', [WorkspaceController::class, 'createForm'])->name('onboarding.create');
    Route::post('onboarding', [WorkspaceController::class, 'create'])->name('onboarding.store');
    Route::post('workspaces/{id}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    /*
    | Mencatat bahwa sebuah tur pengenalan sudah selesai atau dilewati.
    |
    | Di luar grup `workspace` dengan sengaja: turnya juga muncul di halaman
    | onboarding, tempat pengguna belum punya workspace sama sekali.
    */
    /*
    | Lonceng notifikasi. Di luar grup `workspace` dengan sengaja: notifikasi
    | juga dibaca admin yang tidak sedang memilih workspace mana pun, dan
    | pelanggan yang workspace-nya baru saja dihapus tetap berhak membaca
    | riwayat kabarnya.
    */
    /*
    | Hak atas data pribadi. Di luar grup `workspace` DAN `subscription` dengan
    | sengaja, dan masing-masing punya alasannya sendiri:
    |
    | - `workspace` memantulkan siapa pun yang belum memilih workspace ke
    |   onboarding. Pengguna yang belum pernah membuat workspace — atau yang
    |   baru saja menghapus satu-satunya — tetap berhak menutup akunnya.
    | - `subscription` menolak semua POST saat langganan tidak berlaku, jadi
    |   pelanggan yang layanannya sudah mati justru tidak bisa pergi. Merekalah
    |   yang paling sering ingin, dan hak menurut UU PDP tidak berhenti karena
    |   tagihan.
    */
    Route::post('profil/hapus-akun', [DataRightsController::class, 'mintaHapus'])->name('profile.delete.request');
    Route::post('profil/hapus-akun/batal', [DataRightsController::class, 'batalHapus'])->name('profile.delete.cancel');

    Route::get('notifikasi', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifikasi/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifikasi/baca-semua', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::post('onboarding/tur/{guideKey}', [OnboardingGuideController::class, 'ack'])
        ->where('guideKey', '[a-z0-9\-\.]+')
        ->name('onboarding.guides.ack');

    /*
    | Bantuan sengaja HANYA memakai `workspace`, tanpa `subscription`.
    |
    | Grup di bawah menolak semua selain GET saat langganan tidak berlaku — dan
    | itu berarti pelanggan yang layanannya mati tidak bisa membuat tiket sama
    | sekali. Justru merekalah yang paling butuh menghubungi kami; satu-satunya
    | jalur yang tersisa jadi mencari nomor kami sendiri, dan yang tidak
    | menemukannya berhenti jadi pelanggan tanpa pernah bilang kenapa.
    |
    | Kalau suatu saat rute bantuan dipindahkan ke dalam grup di bawah, seluruh
    | maksud fitur ini hilang tanpa satu pun galat yang terlihat. `HelpdeskTest`
    | menjaganya.
    */
    Route::middleware('workspace')->group(function (): void {
        Route::get('bantuan', [TicketController::class, 'index'])->name('tickets.index');
        Route::post('bantuan', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('bantuan/{id}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('bantuan/{id}/balas', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::post('bantuan/{id}/tutup', [TicketController::class, 'close'])->name('tickets.close');
        Route::get('bantuan/{id}/lampiran/{messageId}', [TicketController::class, 'attachment'])->name('tickets.attachment');

        Route::get('profil', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('profil', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profil/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        Route::get('mitra/dashboard', [MitraController::class, 'index'])->name('mitra.index');
        Route::post('mitra/daftar', [MitraController::class, 'apply'])->name('mitra.apply');
        Route::post('mitra/tarik-dana', [MitraController::class, 'requestPayout'])->name('mitra.payout');
        Route::get('mitra/payout/{id}/invoice', [MitraController::class, 'payoutInvoice'])->name('mitra.payout.invoice');
        Route::get('mitra/payout/{id}/download', [MitraController::class, 'downloadInvoice'])->name('mitra.payout.download');
    });

    /*
    | `subscription` menempel di dalam `workspace`, bukan menggantikannya: ia
    | butuh workspace yang sudah terpilih untuk tahu langganan siapa yang
    | diperiksa. Aturannya satu — GET selalu lewat, selain GET ditolak saat
    | langganan tidak berlaku — jadi rute baru otomatis ikut terjaga tanpa
    | perlu didaftarkan di mana pun.
    */
    Route::middleware(['workspace', 'subscription'])->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        /*
        | Empat alamat terpisah, bukan satu halaman panjang. Yang terpenting
        | dari pemisahan ini bukan kerapian, tapi bahwa tiap langkah bisa
        | ditautkan langsung — spanduk "langganan habis" menuju pilih paket,
        | pengingat WhatsApp menuju halaman bayar — tanpa berharap pengguna
        | menggulir ke bagian yang benar.
        */
        /*
        | Saldo pay as you go. Isi saldo lewat jalur tagihan yang sudah ada —
        | tidak ada alur pembayaran kedua di produk ini.
        */
        Route::get('saldo', [BalanceController::class, 'index'])->name('balance.index');
        Route::post('saldo/isi', [BalanceController::class, 'topUp'])->name('balance.topup');

        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::get('billing/paket', [BillingController::class, 'plans'])->name('billing.plans');
        Route::get('billing/riwayat', [BillingController::class, 'history'])->name('billing.history');
        Route::post('billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('billing/referal/tinjau', [BillingController::class, 'reviewReferral'])->name('billing.referral.review');
        Route::get('billing/invoices/{id}', [BillingController::class, 'invoice'])->name('billing.invoice');
        Route::post('billing/invoices/{id}/penagihan', [BillingController::class, 'saveBillingDetails'])->name('billing.details');
        Route::post('billing/invoices/{id}/konfirmasi', [BillingController::class, 'confirmPayment'])->name('billing.invoice.confirm');
        Route::post('billing/invoices/{id}/xendit', [BillingController::class, 'payWithXendit'])->name('billing.invoice.xendit');
        Route::post('billing/invoices/{id}/mayar', [BillingController::class, 'payWithMayar'])->name('billing.invoice.mayar');
        Route::post('billing/invoices/{id}/bukti', [BillingController::class, 'uploadProof'])->name('billing.proof.upload');
        Route::get('billing/invoices/{id}/bukti', [BillingController::class, 'proof'])->name('billing.proof');
        Route::get('billing/invoices/{id}/menunggu-verifikasi', [BillingController::class, 'verifying'])->name('billing.verifying');
        Route::get('billing/invoices/{id}/status', [BillingController::class, 'status'])->name('billing.status');
        Route::post('billing/invoices/{id}/batal', [BillingController::class, 'cancelInvoice'])->name('billing.invoice.cancel');

        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::post('sessions', [SessionController::class, 'store'])->name('sessions.store');
        Route::post('sessions/{id}/connect', [SessionController::class, 'connect'])->name('sessions.connect');
        Route::post('sessions/{id}/disconnect', [SessionController::class, 'disconnect'])->name('sessions.disconnect');
        Route::post('sessions/{id}/logout', [SessionController::class, 'logout'])->name('sessions.logout');
        Route::delete('sessions/{id}', [SessionController::class, 'destroy'])->name('sessions.destroy');
        Route::get('sessions/{id}/status', [SessionController::class, 'status'])->name('sessions.status');

        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::get('messages/compose', [MessageController::class, 'compose'])->name('messages.compose');
        Route::post('messages/compose', [MessageController::class, 'send'])->name('messages.send');
        Route::get('messages/{id}', [MessageController::class, 'show'])->name('messages.show');

        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::post('templates/bawaan/{slug}', [TemplateController::class, 'copyBuiltin'])->name('templates.copy');
        Route::put('templates/{id}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('templates/{id}', [TemplateController::class, 'destroy'])->name('templates.destroy');

        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

        Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
        Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::post('webhooks/{id}/test', [WebhookController::class, 'test'])->name('webhooks.test');
        Route::post('webhooks/{id}/toggle', [WebhookController::class, 'toggle'])->name('webhooks.toggle');
        Route::delete('webhooks/{id}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');

        Route::get('settings', [WorkspaceController::class, 'settings'])->name('settings');
        Route::put('settings', [WorkspaceController::class, 'update'])->name('settings.update');
        Route::delete('settings', [WorkspaceController::class, 'destroy'])->name('settings.destroy');

        // Ekspor tetap di dalam grup workspace: isinya milik satu workspace, dan
        // tanpa workspace terpilih tidak ada yang bisa diekspor.
        Route::post('settings/ekspor', [DataRightsController::class, 'ekspor'])->name('settings.export');
        Route::get('settings/ekspor/{id}', [DataRightsController::class, 'unduh'])->name('settings.export.download');
        Route::post('settings/members', [WorkspaceController::class, 'addMember'])->name('settings.members.add');
        Route::delete('settings/members/{userId}', [WorkspaceController::class, 'removeMember'])->name('settings.members.remove');
    });
});

/*
| Panel admin. Di luar middleware `workspace` dengan sengaja: halaman-halaman
| ini justru bertugas melihat seluruh workspace sekaligus, dan berangkat dari
| satu workspace yang sedang dipilih hanya akan menghalangi.
|
| `EnsureSuperAdmin` menjawab 404, bukan 403 — bagi siapa pun yang bukan super
| admin, panel ini sebaiknya tidak tampak pernah ada.
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', AdminOverviewController::class)->name('overview');

    Route::get('workspaces', [AdminWorkspaceController::class, 'index'])->name('workspaces');
    Route::get('workspaces/{id}', [AdminWorkspaceController::class, 'show'])->name('workspaces.show');
    Route::post('workspaces/{id}/paket', [AdminWorkspaceController::class, 'changePlan'])->name('workspaces.plan');
    Route::post('workspaces/{id}/perpanjang', [AdminWorkspaceController::class, 'extend'])->name('workspaces.extend');
    Route::post('workspaces/{id}/tangguhkan', [AdminWorkspaceController::class, 'toggleSuspend'])->name('workspaces.suspend');
    Route::post('workspaces/{id}/batas', [AdminWorkspaceController::class, 'overrideLimits'])->name('workspaces.limits');
    // Rekanan: paket berbayar tanpa pembayaran. Tunduk pada batas paket dan
    // tanggal berakhirnya — berbeda dari akun bebas tagihan yang tanpa batas.
    Route::post('workspaces/{id}/rekanan', [AdminWorkspaceController::class, 'grantPartner'])->name('workspaces.partner');
    Route::delete('workspaces/{id}/rekanan', [AdminWorkspaceController::class, 'revokePartner'])->name('workspaces.partner.revoke');

    Route::get('tagihan', [AdminInvoiceController::class, 'index'])->name('invoices');
    Route::post('tagihan/{id}/lunas', [AdminInvoiceController::class, 'markPaid'])->name('invoices.paid');
    Route::post('tagihan/{id}/status', [AdminInvoiceController::class, 'updateStatus'])->name('invoices.status');
    Route::post('tagihan/{id}/tolak-bukti', [AdminInvoiceController::class, 'requestNewProof'])->name('invoices.reject-proof');
    Route::get('tagihan/{id}/bukti', [AdminInvoiceController::class, 'proof'])->name('invoices.proof');

    Route::get('pembayaran-paket', [AdminPaymentSettingController::class, 'index'])->name('payment-settings');
    Route::post('pembayaran-paket/qris', [AdminPaymentSettingController::class, 'saveQris'])->name('payment-settings.qris');
    Route::post('pembayaran-paket/bank', [AdminPaymentSettingController::class, 'storeBank'])->name('payment-settings.bank.store');
    Route::post('pembayaran-paket/bank/{id}', [AdminPaymentSettingController::class, 'updateBank'])->name('payment-settings.bank.update');
    Route::delete('pembayaran-paket/bank/{id}', [AdminPaymentSettingController::class, 'destroyBank'])->name('payment-settings.bank.destroy');
    Route::post('pembayaran-paket/bank/{id}/toggle', [AdminPaymentSettingController::class, 'toggleBank'])->name('payment-settings.bank.toggle');
    Route::post('pembayaran-paket/gateways', [AdminPaymentSettingController::class, 'saveGateways'])->name('payment-settings.gateways');
    Route::post('pembayaran-paket/harga', [AdminPaymentSettingController::class, 'savePrices'])->name('payment-settings.prices');

    Route::get('sesi', [AdminSessionController::class, 'index'])->name('sessions');
    Route::post('sesi/{id}/putus', [AdminSessionController::class, 'disconnect'])->name('sessions.disconnect');

    Route::get('pesan', AdminMessageController::class)->name('messages');
    Route::get('audit', AdminAuditController::class)->name('audit');
    Route::get('sistem', AdminSystemController::class)->name('system');

    /*
    | Insiden yang tampil di halaman status publik.
    |
    | Terpisah dari /admin/sistem dengan sengaja: yang di sana untuk mendiagnosis
    | ke dalam, yang di sini untuk berbicara ke luar. Menyatukannya membuat
    | tombol "umumkan ke seluruh pelanggan" berdampingan dengan tombol
    | pemeriksaan biasa, dan pengumuman publik tidak boleh berjarak satu salah
    | klik dari tindakan sehari-hari.
    */
    Route::get('status', [AdminStatusController::class, 'index'])->name('status');
    Route::post('status', [AdminStatusController::class, 'store'])->name('status.store');
    Route::post('status/{id}/kabar', [AdminStatusController::class, 'update'])->name('status.update');

    /*
    | Pemberitahuan dan pengecualian di satu halaman: ketiganya menjawab
    | pertanyaan yang sama — siapa yang tidak tunduk pada aturan biasa.
    */
    Route::get('pengecualian', [AdminExemptionController::class, 'index'])->name('exemptions');
    Route::post('pengecualian/nomor', [AdminExemptionController::class, 'storeNumber'])->name('exemptions.numbers.store');
    Route::delete('pengecualian/nomor/{id}', [AdminExemptionController::class, 'destroyNumber'])->name('exemptions.numbers.destroy');
    Route::post('pengecualian/akun/{id}', [AdminExemptionController::class, 'toggleUser'])->name('exemptions.users.toggle');
    Route::post('pengecualian/email', [AdminExemptionController::class, 'exemptEmail'])->name('exemptions.email');
    Route::delete('pengecualian/email/{id}', [AdminExemptionController::class, 'cancelPendingExemption'])->name('exemptions.email.cancel');
    Route::post('pengecualian/pengirim', [AdminExemptionController::class, 'saveNotifier'])->name('exemptions.notifier');
    Route::post('pengecualian/pengirim/tes', [AdminExemptionController::class, 'testNotifier'])->name('exemptions.notifier.test');
    Route::post('pengecualian/email/tes', [AdminExemptionController::class, 'testEmail'])->name('exemptions.email.test');

    /*
    | Reseller: kode referal dan komisi yang terutang.
    |
    | Pembayaran komisinya manual, sama seperti pembayaran masuk. Yang ada di
    | sini cuma catatan berapa terutang ke siapa dan tombol menandainya sudah
    | ditransfer — tidak ada uang yang bergerak sendiri.
    */
    /*
    | Tiket. Saringan bawaannya "perlu dijawab", pola yang sama dengan halaman
    | Tagihan: daftar yang bawaannya menampilkan segalanya membuat yang menunggu
    | tenggelam di antara yang sudah selesai.
    */
    /*
    | Enterprise: permintaan penawaran dan kesepakatan yang menjawabnya.
    | Saringan bawaannya "baru" — permintaan yang tenggelam adalah calon
    | pelanggan terbesar yang pergi tanpa pernah dijawab.
    */
    Route::get('enterprise', [AdminEnterpriseController::class, 'index'])->name('enterprise');
    Route::get('enterprise/{id}', [AdminEnterpriseController::class, 'show'])->name('enterprise.show');
    Route::post('enterprise/{id}/status', [AdminEnterpriseController::class, 'updateStatus'])->name('enterprise.status');
    Route::post('enterprise/{id}/kesepakatan', [AdminEnterpriseController::class, 'storePlan'])->name('enterprise.plan.store');
    Route::post('enterprise/{id}/kesepakatan/{planId}/tagihan', [AdminEnterpriseController::class, 'issueInvoice'])->name('enterprise.plan.invoice');
    Route::post('enterprise/{id}/kesepakatan/{planId}/matikan', [AdminEnterpriseController::class, 'deactivatePlan'])->name('enterprise.plan.deactivate');

    Route::get('tiket', [AdminTicketController::class, 'index'])->name('tickets');
    Route::get('tiket/{id}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::post('tiket/{id}/balas', [AdminTicketController::class, 'reply'])->name('tickets.reply');
    Route::post('tiket/{id}/status', [AdminTicketController::class, 'status'])->name('tickets.status');
    Route::get('tiket/{id}/lampiran/{messageId}', [AdminTicketController::class, 'attachment'])->name('tickets.attachment');

    Route::get('reseller', [AdminReferralController::class, 'index'])->name('referrals');
    Route::post('reseller', [AdminReferralController::class, 'store'])->name('referrals.store');
    Route::post('reseller/{id}/aktif', [AdminReferralController::class, 'toggle'])->name('referrals.toggle');
    Route::post('reseller/{id}/setujui', [AdminReferralController::class, 'approveApplication'])->name('referrals.approve');
    Route::post('reseller/{id}/tolak', [AdminReferralController::class, 'rejectApplication'])->name('referrals.reject');
    Route::post('reseller/komisi/{id}/bayar', [AdminReferralController::class, 'markPaid'])->name('referrals.commission.paid');
    Route::post('reseller/pencairan/{id}/bayar', [AdminReferralController::class, 'markPayoutPaid'])->name('referrals.payout.paid');
    Route::get('reseller/pencairan/{id}/invoice', [AdminReferralController::class, 'payoutInvoice'])->name('referrals.payout.invoice');
    Route::get('reseller/pencairan/{id}/download', [AdminReferralController::class, 'downloadInvoice'])->name('referrals.payout.download');

    Route::get('pengguna', [AdminUserController::class, 'index'])->name('users');
    Route::post('pengguna/{id}/super-admin', [AdminUserController::class, 'toggleSuperAdmin'])->name('users.super');
    Route::post('pengguna/{id}/atur-ulang-sandi', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');
});
