<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HeaderKeamanan;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\WaitlistEntry;
use App\Models\WaSession;
use App\Services\Billing\BalanceService;
use App\Services\Billing\QrisManual;
use App\Services\Notifications\EmailNotifier;
use App\Services\Notifications\WhatsAppNotifier;
use App\Support\KapasitasPlatform;
use App\Support\KesehatanAntrean;
use App\Support\MailIdentity;
use App\Support\Plan;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Kesehatan sistem dan konfigurasi yang sedang berlaku.
 *
 * Halaman ini menjawab satu pertanyaan: **apa yang sebenarnya sedang menyala?**
 *
 * Hampir semua kegagalan mahal di sistem ini punya bentuk yang sama — sesuatu
 * berhenti bekerja tanpa satu pun gejala. Engine mati dan sesi tampak masih
 * hijau. `BILLING_NOTIFY_WORKSPACE_ID` kosong dan seluruh pemberitahuan diam.
 * QRIS salah salin dan halaman bayar diam-diam jatuh ke transfer bank. Ketiganya
 * tidak melempar galat ke siapa pun; satu-satunya cara menyadarinya adalah
 * dengan melihatnya tertulis.
 */
class SystemController extends Controller
{
    public function __construct(
        private readonly WhatsAppNotifier $notifier,
        private readonly EmailNotifier $email,
        private readonly QrisManual $qris,
        private readonly BalanceService $balances,
    ) {}

    public function __invoke(Request $request): View
    {
        return view('admin.system', [
            'engine' => $this->engine(),

            'keamanan' => $this->keamanan($request),

            // Saldo yang tidak cocok dengan buku besarnya adalah uang pelanggan
            // yang tidak bisa dipertanggungjawabkan — dan ia tidak menghasilkan
            // galat apa pun. Satu-satunya cara menyadarinya adalah melihatnya
            // tertulis di sini.
            'saldo' => $this->balances->workspaceTidakCocok(),

            'email' => [
                'siap' => $this->email->ready(),
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'dari' => MailIdentity::noreply()['address'],
            ],

            'identitasEmail' => [
                'noreply_address' => AppSetting::ambil('mail_noreply_address', ''),
                'noreply_name' => AppSetting::ambil('mail_noreply_name', ''),
                'support_address' => AppSetting::ambil('mail_support_address', ''),
                'support_name' => AppSetting::ambil('mail_support_name', ''),
                'reply_to_address' => AppSetting::ambil('mail_reply_to_address', ''),
                'admin_addresses' => AppSetting::ambil('mail_admin_addresses', ''),
                'active' => [
                    'noreply' => MailIdentity::noreply(),
                    'support' => MailIdentity::support(),
                    'reply_to' => MailIdentity::replyTo(),
                    'admin_recipients' => MailIdentity::adminRecipients(),
                    'contact' => MailIdentity::contactEmail(),
                ],
                'defaults' => [
                    'noreply_address' => config('mail.from.address'),
                    'noreply_name' => config('mail.from.name'),
                    'support_address' => config('mail.support.address'),
                    'support_name' => config('mail.support.name'),
                    'reply_to_address' => config('mail.reply_to.address'),
                    'admin_addresses' => implode(",\n", config('mail.admin_addresses', [])),
                ],
                'allowed_domains' => MailIdentity::allowedSenderDomains(),
            ],

            'notifikasi' => [
                'siap' => $this->notifier->ready(),
                'workspace' => config('billing.notify_workspace_id'),
                'nomorAdmin' => config('billing.admin_phone'),
            ],

            'pembayaran' => [
                'qris' => $this->qris->available(),
                'merchant' => $this->qris->merchantName(),
                'bank' => $this->qris->bankAccount(),
                'pajak' => (float) config('billing.tax_percent'),
            ],

            'antrean' => KesehatanAntrean::periksa()
                + ['ambang' => KesehatanAntrean::ambangMenit()],

            'kapasitas' => [
                'hidup' => WaSession::whereIn('status', ['connected', 'connecting', 'qr'])->count(),
                'batas' => (int) config('gateway.engine.max_sessions'),

                // Komitmen slot kepada langganan BERBAYAR — angka yang dipakai
                // gerbang checkout, dan berbeda dari jumlah sesi yang menyala.
                'dijanjikan' => KapasitasPlatform::terpakai(),

                /*
                 | Berapa permintaan yang TIDAK BISA kami layani.
                 |
                 | Tidak ada di tempat lain mana pun, dan tanpanya kapasitas
                 | penuh terlihat sebagai grafik pendaftaran yang datar — yang
                 | terbaca "tidak ada peminat" alih-alih "peminatnya ditolak di
                 | pintu". Keduanya menuntut keputusan yang berlawanan.
                */
                'menunggu' => WaitlistEntry::menunggu()->count(),
                'menunggu_terlama' => WaitlistEntry::menunggu()->value('created_at'),
            ],

            'siklus' => [
                'jeda_kirim' => config('gateway.throttle.min_delay_ms').'–'.config('gateway.throttle.max_delay_ms').' ms',
                'tenggat_tagihan' => config('billing.invoice_due_days'),
                'terbit_sebelum' => config('billing.issue_days_before'),
                'masa_tenggang' => config('billing.grace_days'),
                'pengingat' => implode(', ', config('billing.reminder_days')),
            ],

            'paket' => Plan::all(),

            'pesanGagal24Jam' => Message::where('status', 'failed')
                ->where('created_at', '>=', now()->subDay())
                ->count(),

            'lingkungan' => [
                'app_env' => app()->environment(),
                'app_debug' => (bool) config('app.debug'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'zona_waktu' => config('app.timezone'),
            ],
        ]);
    }

    /**
     * Empat hal yang membuat sambungan ke aplikasi ini aman — atau tidak.
     *
     * Ketiganya di luar `engine()` punya sifat yang sama dan itulah alasan
     * mereka ada di halaman ini: tidak satu pun menghasilkan galat saat salah.
     * Cookie yang bocor lewat http, halaman yang bisa dibingkai orang lain, dan
     * halaman galat yang memamerkan kata sandi database semuanya tampak persis
     * seperti aplikasi yang berjalan normal — sampai ada yang memanfaatkannya.
     *
     * `secure()` dibaca dari permintaan yang sedang berjalan, bukan dari
     * `APP_URL`: yang menentukan aman-tidaknya adalah sambungan yang benar-benar
     * dipakai admin saat ini, dan `APP_URL` boleh saja berbunyi https sementara
     * halamannya dibuka lewat http.
     *
     * @return array<string, bool|string>
     */
    private function keamanan(Request $request): array
    {
        return [
            // HTTPS dan cookie secure SENGAJA mati di lokal — dev berjalan di
            // http://127.0.0.1:8051. Menandainya merah tiap hari di mesin
            // pengembang melatih orang mengabaikan warna merah di halaman ini,
            // dan halaman ini cuma berguna selama merahnya masih berarti.
            'wajib_aman' => ! app()->environment(['local', 'testing']),
            'https' => $request->secure(),
            'cookie_secure' => (bool) config('session.secure'),
            'sesi_terenkripsi' => (bool) config('session.encrypt'),
            'debug_mati' => ! config('app.debug'),
            'header' => app(Kernel::class)->hasMiddleware(HeaderKeamanan::class),
        ];
    }

    /**
     * Keadaan engine, ditanyakan saat itu juga.
     *
     * Ditanyakan langsung dan bukan dibaca dari database dengan sengaja: baris
     * di database hanya seakurat callback terakhir yang berhasil sampai, dan
     * engine yang mati mendadak tidak sempat mengirim apa pun. Justru keadaan
     * itulah yang paling perlu terlihat di halaman ini.
     *
     * @return array{terjangkau: bool, pesan: string}
     */
    private function engine(): array
    {
        try {
            $jawaban = Http::timeout((int) config('gateway.engine.connect_timeout', 5))
                ->withToken(config('gateway.engine.token'))
                ->get(rtrim(config('gateway.engine.url'), '/').'/health');

            if (! $jawaban->successful()) {
                return ['terjangkau' => false, 'pesan' => 'Menjawab dengan status '.$jawaban->status().'.'];
            }

            $isi = $jawaban->json();

            return [
                'terjangkau' => true,
                'pesan' => 'Menjawab normal.',
                'engine' => $isi['engine'] ?? 'baileys',
                'sesi' => $isi['sessions'] ?? 0,
                'connected' => $isi['connected_sessions'] ?? 0,
                'connecting' => $isi['connecting_sessions'] ?? 0,
                'qr' => $isi['qr_sessions'] ?? 0,
                'max_sessions' => $isi['max_sessions'] ?? null,
                'memory' => $isi['memory'] ?? null,
                'uptime_seconds' => $isi['uptime_seconds'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'terjangkau' => false,
                'pesan' => $e->getMessage(),
                'engine' => null,
                'sesi' => null,
                'connected' => null,
                'connecting' => null,
                'qr' => null,
                'max_sessions' => null,
                'memory' => null,
                'uptime_seconds' => null,
            ];
        }
    }

    public function updateMail(Request $request): RedirectResponse
    {
        $allowedDomains = MailIdentity::allowedSenderDomains();
        $domainList = implode(', ', $allowedDomains);

        $validated = $request->validate([
            'mail_noreply_address' => ['nullable', 'email'],
            'mail_noreply_name' => ['nullable', 'string', 'max:100'],
            'mail_support_address' => ['nullable', 'email'],
            'mail_support_name' => ['nullable', 'string', 'max:100'],
            'mail_reply_to_address' => ['nullable', 'email'],
            'mail_admin_addresses' => ['nullable', 'string'],
        ]);

        // Validasi domain noreply
        if (!empty($validated['mail_noreply_address'])) {
            $host = substr(strrchr($validated['mail_noreply_address'], '@'), 1);
            if (!in_array(strtolower($host), array_map('strtolower', $allowedDomains), true)) {
                return back()->withErrors([
                    'mail_noreply_address' => "Alamat pengirim harus memakai domain terverifikasi: {$domainList}",
                ])->withInput();
            }
        }

        // Validasi domain support
        if (!empty($validated['mail_support_address'])) {
            $host = substr(strrchr($validated['mail_support_address'], '@'), 1);
            if (!in_array(strtolower($host), array_map('strtolower', $allowedDomains), true)) {
                return back()->withErrors([
                    'mail_support_address' => "Alamat pengirim harus memakai domain terverifikasi: {$domainList}",
                ])->withInput();
            }
        }

        // Validasi email admin jika diisi
        if (!empty($validated['mail_admin_addresses'])) {
            $rawAdmins = preg_split('/[\r\n,]+/', (string) $validated['mail_admin_addresses']);
            $cleanedAdmins = [];
            foreach ($rawAdmins as $item) {
                $addr = trim($item);
                if ($addr === '') continue;
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    return back()->withErrors([
                        'mail_admin_addresses' => "Alamat email admin tidak valid: {$addr}",
                    ])->withInput();
                }
                $cleanedAdmins[] = $addr;
            }
            if (empty($cleanedAdmins)) {
                return back()->withErrors([
                    'mail_admin_addresses' => 'Minimal harus ada satu alamat email admin yang valid.',
                ])->withInput();
            }
            $validated['mail_admin_addresses'] = implode(', ', array_unique($cleanedAdmins));
        }

        foreach ([
            'mail_noreply_address',
            'mail_noreply_name',
            'mail_support_address',
            'mail_support_name',
            'mail_reply_to_address',
            'mail_admin_addresses',
        ] as $key) {
            $val = $validated[$key] ?? null;
            AppSetting::simpan($key, filled($val) ? trim($val) : null);
        }

        MailIdentity::flushCache();

        AuditLog::record('settings.mail.updated', null, [
            'noreply' => $validated['mail_noreply_address'] ?? null,
            'support' => $validated['mail_support_address'] ?? null,
        ]);

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => 'Pengaturan email disimpan',
            'pesan' => 'Identitas pengirim dan penerima admin berhasil diperbarui.',
        ]);
    }

    public function testMail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'type' => ['required', 'in:noreply,support'],
        ]);

        $hasil = $this->email->kirimTes($data['email'], $data['type']);

        AuditLog::record('settings.mail.tested', null, [
            'tujuan' => $data['email'],
            'tipe' => $data['type'],
            'berhasil' => $hasil['berhasil'],
        ]);

        return back()->with('swal', [
            'tipe' => $hasil['berhasil'] ? 'success' : 'error',
            'judul' => $hasil['berhasil'] ? 'Email tes dikirim' : 'Email tes tidak bisa dikirim',
            'pesan' => $hasil['pesan'],
        ]);
    }
}
