<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureWorkspaceSelected;
use App\Models\AuditLog;
use App\Models\WaitlistEntry;
use App\Services\Billing\QrisManual;
use App\Services\Billing\ReferralService;
use App\Services\Billing\SubscriptionService;
use App\Services\Notifications\BillingMessages;
use App\Services\Notifications\EmailNotifier;
use App\Services\Notifications\PeringatanSistem;
use App\Services\Notifications\WhatsAppNotifier;
use App\Services\Payment\MayarService;
use App\Support\DaftarBank;
use App\Support\KapasitasPlatform;
use App\Support\KontakWhatsApp;
use App\Support\PhoneNumber;
use App\Support\Plan;
use App\Support\WilayahIndonesia;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly QrisManual $qris,
        private readonly WhatsAppNotifier $notifier,
        private readonly ReferralService $referrals,
        private readonly EmailNotifier $email,
        private readonly MayarService $mayar,
        private readonly \App\Services\Payment\XenditService $xendit,
    ) {}


    /**
     * Ringkasan langganan.
     *
     * Tiga hal yang dulu menumpuk di satu halaman — keadaan langganan, daftar
     * paket, dan riwayat tagihan — sekarang punya alamatnya masing-masing.
     * Bukan sekadar demi kerapian: yang paling sering dibuka adalah "berapa
     * lama lagi langganan saya berlaku", dan sebelumnya jawaban itu terkubur
     * di atas dua blok panjang yang jarang dibutuhkan. Halaman terpisah juga
     * bisa ditautkan langsung — dari spanduk, dari pengingat WhatsApp, dari
     * panel admin — tanpa mengandalkan pengguna menggulir ke bagian yang benar.
     */
    public function index(Request $request): View
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        return view('dashboard.billing.index', [
            'subscription' => $this->subscriptions->ensureFor($workspace),
            'usage' => $workspace->currentUsage(),

            // Hanya tagihan terbuka yang ditampilkan di sini; sisanya di halaman
            // riwayat. Yang butuh tindakan sekarang tidak boleh berbagi tempat
            // dengan yang sudah selesai setahun lalu.
            'tagihanTerbuka' => $workspace->invoices()
                ->where('status', 'pending')
                ->where('due_at', '>', now())
                ->latest()
                ->first(),

            // Anggota biasa boleh melihat status langganan — mereka perlu tahu
            // kenapa pengiriman berhenti — tapi tidak boleh mengeluarkan uang
            // atas nama workspace.
            'bolehBayar' => $request->user()->canManage($workspace),
        ]);
    }

    /** Daftar paket dan tombol pilihnya. */
    public function plans(Request $request): View
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        return view('dashboard.billing.plans', [
            'subscription' => $this->subscriptions->ensureFor($workspace),
            'plans' => Plan::all(),
            'bolehBayar' => $request->user()->canManage($workspace),

            // Harga perkenalan ditawarkan HANYA kalau workspace ini memang
            // masih berhak. Menampilkannya ke yang sudah pernah membayar adalah
            // janji harga yang akan dibatalkan sendiri saat tagihannya terbit —
            // dan yang membacanya baru tahu setelah melihat nominal transfernya.
            'berhakPromo' => $workspace->belumPernahBayar(),
        ]);
    }

    /** Riwayat tagihan, berhalaman. */
    public function history(Request $request): View
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        return view('dashboard.billing.history', [
            'subscription' => $this->subscriptions->ensureFor($workspace),
            'invoices' => $workspace->invoices()->latest()->paginate(20),
        ]);
    }

    /**
     * Memilih paket dan menerbitkan tagihannya.
     *
     * Tidak ada perubahan paket yang terjadi di sini. Paket berpindah saat
     * tagihannya lunas, bukan saat pelanggan mengklik — kalau tidak, menaikkan
     * paket lalu tidak membayar akan menaikkan batasnya secara cuma-cuma.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus langganan.');

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('plans.catalog')))],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'referral' => ['nullable', 'string', 'max:16'],
        ]);

        /*
         | Kapasitas platform diperiksa DI SINI, sebelum satu rupiah pun
         | ditagihkan.
         |
         | Satu-satunya tempat yang tahu soal `WA_MAX_SESSIONS` dulu adalah
         | engine, dan ia baru menjawabnya saat pelanggan menekan Hubungkan —
         | yaitu setelah mendaftar, memilih paket, mentransfer, dan menunggu
         | buktinya diperiksa manusia. Seluruh langkah itu berhasil, lalu
         | langkah terakhir gagal dengan alasan yang sepenuhnya urusan kami.
         |
         | Menolak uang terdengar salah sampai dibandingkan dengan
         | alternatifnya: menerimanya untuk layanan yang tidak bisa kami berikan.
         | Yang dihitung komitmen paket, bukan sesi yang sedang menyala — sesi
         | yang kebetulan mati tetap milik orang yang membayarnya.
         |
         | Workspace yang sudah punya komitmen (perpanjangan, atau pindah paket
         | dengan jatah sesi yang sama atau lebih kecil) tidak ikut dihalangi:
         | slotnya sudah terhitung sebagai miliknya.
        */
        $butuh = (int) (config("plans.catalog.{$data['plan']}.max_sessions") ?? 1);
        $sudahDimiliki = KapasitasPlatform::komitmenWorkspace($workspace);
        $tambahan = max(0, $butuh - $sudahDimiliki);

        if ($tambahan > 0 && ! $workspace->isExempt() && ! KapasitasPlatform::sanggup($tambahan)) {
            /*
             | Ditolak, TAPI dicatat. Bedanya besar.
             |
             | Pelanggan yang ditolak tanpa jalan lain tidak kembali besok — ia
             | mencari gateway lain hari itu juga. Dan dari sisi kami, penolakan
             | yang tidak meninggalkan jejak membuat kapasitas penuh terlihat
             | sebagai grafik pendaftaran yang datar; grafik datar terbaca
             | "tidak ada peminat", bukan "peminatnya ditolak di pintu", dan
             | keduanya menuntut keputusan yang berlawanan.
             |
             | `updateOrCreate`: menekan tombolnya lima kali adalah satu
             | permintaan, bukan lima, dan urutan antreannya tidak berubah
             | karena mencoba lagi (`created_at` tidak ikut ditulis ulang).
            */
            $sudahAda = WaitlistEntry::where('workspace_id', $workspace->id)
                ->where('plan_slug', $data['plan'])
                ->where('period', $data['period'])
                ->first();

            $antrean = $sudahAda ?? WaitlistEntry::create([
                'workspace_id' => $workspace->id,
                'plan_slug' => $data['plan'],
                'period' => $data['period'],
                'slots' => $butuh,
            ]);

            /*
             | Tim WAJIB tahu, dan tahu sekarang.
             |
             | Ini satu-satunya kabar di seluruh sistem yang berarti "ada orang
             | yang mau membayar dan kita menolaknya". Kalimat penolakan di
             | layar pelanggan menjanjikan bahwa kami tahu — jadi kabar ini
             | bukan pelengkap, ia yang membuat janji itu tidak bohong.
             |
             | Penandanya memuat jumlah antrean, jadi tiap permintaan BARU
             | menghasilkan kabar baru sementara orang yang mencoba lagi tidak.
            */
            app(PeringatanSistem::class)->kabari(
                type: 'kapasitas.penuh',
                title: 'Kapasitas nomor penuh, ada yang masuk daftar tunggu',
                body: 'Workspace "'.$workspace->name.'" ingin mengambil paket '.$data['plan']
                    .' dan ditolak karena seluruh '.KapasitasPlatform::batas()
                    .' slot nomor sudah dijanjikan. Sekarang ada '
                    .WaitlistEntry::menunggu()->count().' permintaan di daftar tunggu. '
                    .'Ini pelanggan yang mau membayar dan kita tolak.',
                url: route('admin.system'),
                level: 'danger',
                dedupe: 'kapasitas-penuh:'.WaitlistEntry::menunggu()->count(),
            );

            return back()->withErrors([
                'plan' => $sudahAda
                    ? KapasitasPlatform::kalimatSudahMenunggu($antrean->created_at)
                    : KapasitasPlatform::kalimat(),
            ]);
        }

        $referral = null;

        if (filled($data['referral'] ?? null)) {
            /*
             | Tagihan yang sudah terbit tidak diberi diskon belakangan.
             |
             | `issueInvoice()` mengembalikan tagihan pending yang sama untuk
             | paket dan periode yang sama, jadi kodenya akan diterima lalu
             | diabaikan diam-diam — pelanggan membaca "kode diterima" dan
             | mentransfer nominal yang tidak pernah berubah. Menambal diskon ke
             | tagihan lama juga bukan jawabannya: nominalnya ikut berubah,
             | sementara pelanggan bisa saja sudah mentransfer angka yang lama.
             | Membatalkan lalu menerbitkan ulang adalah satu-satunya jalan yang
             | tidak menghasilkan dua angka untuk satu tagihan.
            */
            $adaTagihan = $workspace->invoices()
                ->where('status', 'pending')
                ->where('plan_slug', $data['plan'])
                ->where('period', $data['period'])
                ->where('due_at', '>', now())
                ->first();

            if ($adaTagihan) {
                return back()->withErrors(['referral' => "Tagihan {$adaTagihan->number} untuk paket ini "
                    .'sudah terbit tanpa kode referal. Batalkan tagihan itu dulu, lalu pilih paketnya lagi '
                    .'dengan kodenya — supaya nominal yang Anda transfer tidak berubah di tengah jalan.']);
            }

            try {
                $referral = $this->referrals->periksa($data['referral'], $workspace);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['referral' => $e->getMessage()])->withInput();
            }
        }

        $invoice = $this->subscriptions->issueInvoice($workspace, $data['plan'], $data['period'], $referral);

        return redirect()->route('billing.invoice', $invoice->id);
    }

    /**
     * Meninjau kode referal sebelum tagihan terbit.
     *
     * Dipisah dari checkout dengan sengaja: pelanggan harus bisa melihat
     * potongannya **sebelum** memutuskan, dan satu-satunya cara lain adalah
     * menerbitkan tagihan dulu lalu membatalkannya kalau kodenya ternyata
     * ditolak — memaksa orang membuat tagihan untuk sesuatu yang belum mereka
     * putuskan.
     */
    public function reviewReferral(Request $request): JsonResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'plan' => ['required', Rule::in(array_keys(config('plans.catalog')))],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        $harga = Plan::get($data['plan'])->price($data['period']);
        $nominal = $harga + (int) round($harga * config('billing.tax_percent') / 100);

        return response()->json($this->referrals->tinjau($data['code'], $workspace, $nominal));
    }

    public function invoice(Request $request, int $id): View
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        $invoice = $workspace->invoices()->findOrFail($id);

        if ($invoice->isPending() && $invoice->payment_gateway === 'xendit' && filled($invoice->payment_reference)) {
            if ($this->xendit->checkAndSyncStatus($invoice, $this->subscriptions)) {
                $invoice->refresh();
            }
        }

        $selectedProvince = old('billing_province', $workspace->billing_province);
        $selectedCity = old('billing_city', $workspace->billing_city);

        $daftarKota = $selectedProvince ? WilayahIndonesia::kota($selectedProvince) : [];
        $daftarKecamatan = ($selectedProvince && $selectedCity) ? WilayahIndonesia::kecamatan($selectedProvince, $selectedCity) : [];

        return view('dashboard.billing.invoice', [
            'invoice' => $invoice,
            'plan' => $invoice->plan(),

            // Payload disusun ulang setiap kali halaman dibuka, tidak disimpan
            // di kolom. Isinya sepenuhnya turunan dari nominal tagihan dan
            // payload merchant di env; menyimpannya berarti tagihan lama
            // membawa kode QR yang menunjuk ke merchant yang mungkin sudah
            // diganti, dan tidak ada yang akan menyadarinya sampai ada yang
            // memindainya.
            'qrisPayload' => $invoice->isPending() && ! $invoice->isOverdue()
                ? $this->qris->payload($invoice)
                : null,

            'merchant' => $this->qris->merchantName(),
            'bank' => $this->qris->bankAccount(),
            'bankAccounts' => $this->qris->bankAccounts(),
            'bolehBayar' => $request->user()->canManage($workspace),

            // Data penagihan dipakai di form kolom kiri dan mencetak data di ringkasan.
            'penagihan' => [
                'type' => $workspace->billing_type ?: 'individu',
                'company' => $workspace->billing_company,
                'nama' => $workspace->billing_name ?: $workspace->name,
                'email' => $workspace->billing_email ?: ($workspace->owner_email ?: $request->user()->email),
                'telepon' => $workspace->billing_phone,
                'bank_name' => $workspace->billing_bank_name,
                'bank_account' => $workspace->billing_bank_account,
                'bank_holder' => $workspace->billing_bank_holder,
                'province' => $workspace->billing_province,
                'city' => $workspace->billing_city,
                'district' => $workspace->billing_district,
                'address' => $workspace->billing_address,
                'postal_code' => $workspace->billing_postal_code,
                'lengkap' => $workspace->isBillingComplete(),
            ],

            'daftarBank' => DaftarBank::all(),
            'daftarProvinsi' => WilayahIndonesia::provinsi(),
            'daftarKota' => $daftarKota,
            'daftarKecamatan' => $daftarKecamatan,
            'mayarAvailable' => $this->mayar->isConfigured(),
            'mayarPaymentUrl' => ($invoice->payment_gateway === 'mayar' && filled($invoice->payment_url)) ? $invoice->payment_url : null,
            'xenditAvailable' => $this->xendit->isConfigured(),
            'xenditPaymentUrl' => ($invoice->payment_gateway === 'xendit' && filled($invoice->payment_url) && str_contains((string) $invoice->payment_url, 'xendit')) ? $invoice->payment_url : null,
        ]);
    }

    /**
     * Memulai sesi pembayaran via gateway online resmi Xendit.
     */
    public function payWithXendit(Request $request, int $id): JsonResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus pembayaran.');

        $invoice = $workspace->invoices()->findOrFail($id);

        if (! $workspace->isBillingComplete()) {
            return response()->json([
                'status' => 'incomplete_billing',
                'message' => 'Lengkapi Data Pelanggan dan rekening terlebih dahulu sebelum melanjutkan pembayaran.',
            ], 422);
        }

        if ($invoice->isPaid()) {
            return response()->json([
                'status' => 'already_paid',
                'message' => 'Tagihan sudah lunas.',
                'redirect' => route('billing.invoice', $id),
            ]);
        }

        if ($invoice->isOverdue()) {
            return response()->json([
                'status' => 'expired',
                'message' => 'Batas waktu pembayaran sudah lewat.',
            ], 422);
        }

        // Jika tagihan memiliki payment_url dari gateway lama (seperti Mayar), reset terlebih dahulu
        if ($invoice->payment_gateway !== 'xendit' || !str_contains((string) $invoice->payment_url, 'xendit')) {
            $invoice->update([
                'payment_gateway' => null,
                'payment_url' => null,
                'payment_reference' => null,
            ]);
            $invoice->refresh();
        }

        // XENDIT PAYMENT GATEWAY
        if ($this->xendit->isConfigured()) {
            try {
                $session = $this->xendit->createInvoice($invoice, $workspace);

                return response()->json([
                    'status' => 'ok',
                    'payment_url' => $session['link'],
                    'xendit_id' => $session['id'],
                ]);
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal membuat sesi pembayaran Xendit: '.$e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'status' => 'gateway_disabled',
            'message' => 'Metode pembayaran online Xendit sedang tidak aktif.',
        ], 422);
    }

    /**
     * Alias untuk backwards compatibility route billing.invoice.mayar.
     */
    public function payWithMayar(Request $request, int $id): JsonResponse
    {
        if ($this->mayar->isConfigured()) {
            $workspace = EnsureWorkspaceSelected::from($request);

            abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus pembayaran.');

            $invoice = $workspace->invoices()->findOrFail($id);

            if (! $workspace->isBillingComplete()) {
                return response()->json([
                    'status' => 'incomplete_billing',
                    'message' => 'Lengkapi Data Pelanggan dan rekening terlebih dahulu sebelum melanjutkan pembayaran.',
                ], 422);
            }

            if ($invoice->isPaid()) {
                return response()->json([
                    'status' => 'already_paid',
                    'message' => 'Tagihan sudah lunas.',
                    'redirect' => route('billing.invoice', $id),
                ]);
            }

            if ($invoice->isOverdue()) {
                return response()->json([
                    'status' => 'expired',
                    'message' => 'Batas waktu pembayaran sudah lewat.',
                ], 422);
            }

            try {
                $session = $this->mayar->createInvoice($invoice, $workspace);

                return response()->json([
                    'status' => 'ok',
                    'payment_url' => $session['link'],
                    'mayar_id' => $session['id'],
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        return $this->payWithXendit($request, $id);
    }

    /**
     * Menyimpan data penagihan dari halaman checkout.
     *
     * Tidak menyentuh tagihannya sama sekali — data ini melekat pada workspace,
     * jadi mengisinya sekali berlaku untuk seluruh tagihan berikutnya. Pengguna
     * dikembalikan ke halaman yang sama supaya ia bisa langsung lanjut membayar
     * tanpa kehilangan tempatnya.
     */
    public function saveBillingDetails(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus langganan.');

        $workspace->invoices()->findOrFail($id);

        $data = $request->validate([
            'billing_type' => ['required', 'in:individu,badan'],
            'billing_name' => ['required', 'string', 'max:120'],
            'billing_company' => ['nullable', 'string', 'max:150'],
            'billing_email' => ['required', 'email', 'max:180'],
            'billing_phone' => ['required', 'string', 'max:20'],
            'billing_bank_name' => ['required', 'string', 'max:80'],
            'billing_bank_account' => ['required', 'string', 'max:50'],
            'billing_bank_holder' => ['required', 'string', 'max:120'],
            'billing_province' => ['required', 'string', 'max:100'],
            'billing_city' => ['required', 'string', 'max:100'],
            'billing_district' => ['required', 'string', 'max:100'],
            'billing_address' => ['required', 'string', 'max:500'],
            'billing_postal_code' => ['nullable', 'string', 'max:10'],
        ], [
            'billing_type.required' => 'Pilih jenis pelanggan (Individu atau Badan).',
            'billing_name.required' => 'Nama lengkap penanggung jawab wajib diisi.',
            'billing_email.required' => 'Email penagihan wajib diisi.',
            'billing_phone.required' => 'Nomor WhatsApp wajib diisi.',
            'billing_bank_name.required' => 'Nama bank wajib dipilih.',
            'billing_bank_account.required' => 'Nomor rekening wajib diisi.',
            'billing_bank_holder.required' => 'Nama pemilik rekening wajib diisi.',
            'billing_province.required' => 'Provinsi wajib dipilih.',
            'billing_city.required' => 'Kota atau kabupaten wajib dipilih.',
            'billing_district.required' => 'Kecamatan wajib dipilih.',
            'billing_address.required' => 'Alamat jalan wajib diisi.',
        ]);

        if ($data['billing_type'] === 'badan' && empty(trim((string) ($data['billing_company'] ?? '')))) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Nama perusahaan / badan usaha wajib diisi.',
                    'errors' => ['billing_company' => ['Nama perusahaan / badan usaha wajib diisi.']],
                ], 422);
            }

            return back()->withErrors(['billing_company' => 'Nama perusahaan / badan usaha wajib diisi.'])->withInput();
        }

        // Nomor dinormalkan seperti nomor tujuan pesan mana pun; pengingat yang
        // dikirim ke `08...` gagal diam-diam, dan gagalnya baru ketahuan sebagai
        // pelanggan yang tidak pernah tahu langganannya habis.
        $nomor = PhoneNumber::normalize($data['billing_phone']);

        if ($nomor === null) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Nomor WhatsApp tidak valid. Masukkan format nomor yang benar (contoh: 08123456789).',
                    'errors' => ['billing_phone' => ['Nomor WhatsApp tidak valid. Masukkan format nomor yang benar.']],
                ], 422);
            }

            return back()->withErrors(['billing_phone' => 'Nomor WhatsApp tidak valid. Masukkan format nomor yang benar.'])->withInput();
        }

        $workspace->forceFill([
            'billing_type' => $data['billing_type'],
            'billing_name' => $data['billing_name'],
            'billing_company' => $data['billing_company'] ?? null,
            'billing_email' => $data['billing_email'],
            'billing_phone' => $nomor,
            'billing_bank_name' => $data['billing_bank_name'],
            'billing_bank_account' => preg_replace('/[^0-9]/', '', (string) $data['billing_bank_account']),
            'billing_bank_holder' => $data['billing_bank_holder'],
            'billing_province' => $data['billing_province'],
            'billing_city' => $data['billing_city'],
            'billing_district' => $data['billing_district'] ?? null,
            'billing_address' => $data['billing_address'],
            'billing_postal_code' => $data['billing_postal_code'] ?? null,
        ])->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Data penagihan dan rekening pelanggan berhasil disimpan.',
            ]);
        }

        return back()->with('swal', [
            'icon' => 'success',
            'title' => 'Data pelanggan disimpan',
            'text' => 'Data penagihan dan rekening Anda berhasil diperbarui. Anda sekarang dapat melanjutkan pembayaran.',
        ]);
    }

    /**
     * Mengunggah bukti transfer.
     *
     * Bukti tidak mengaktifkan apa pun dengan sendirinya — ia hanya memindahkan
     * tagihan ke antrean pemeriksaan admin. Menganggap unggahan sebagai
     * pembayaran berarti siapa pun bisa menyalakan layanannya sendiri dengan
     * memilih gambar apa saja dari galeri.
     */
    public function uploadProof(Request $request, int $id): RedirectResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus langganan.');

        $invoice = $workspace->invoices()->findOrFail($id);

        if (! $invoice->isPending()) {
            return back()->with('swal', [
                'icon' => 'error',
                'title' => 'Bukti tidak bisa dikirim',
                'text' => 'Tagihan ini sudah tidak menunggu pembayaran.',
            ]);
        }

        $request->validate([
            'bukti' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [], ['bukti' => 'bukti transfer']);

        // Disk privat, bukan publik: bukti transfer memuat nama, nomor rekening,
        // dan saldo orang. Admin membukanya lewat rute yang memeriksa izin.
        $path = $request->file('bukti')->store('bukti-bayar', 'media');

        $invoice->forceFill(['proof_path' => $path])->save();

        AuditLog::record('invoice.proof_uploaded', $invoice, [
            'number' => $invoice->number,
        ], $workspace->id);

        // Tanda terima ke pelanggan, dan panggilan ke kami sendiri. Yang kedua
        // yang paling menentukan: selama pencocokan masih manual, tagihan hanya
        // menjadi lunas kalau ada orang yang membukanya di panel — dan tanpa
        // pesan ini tidak ada apa pun yang memberi tahu ada yang perlu dibuka.
        $this->notifier->toWorkspace(
            $workspace,
            BillingMessages::proofReceived($invoice),
            "proof-received:{$invoice->id}",
        );

        $this->notifier->toAdmin(
            BillingMessages::adminProofWaiting($invoice),
            "admin-proof:{$invoice->id}",
        );

        // Email menyusul. WhatsApp saja berarti satu titik yang kalau mati
        // membuat seluruh kabar ke tim diam tanpa gejala — dan tagihan hanya
        // menjadi lunas kalau ada orang yang membukanya di panel.
        $this->email->kabarTim(
            'Bukti pembayaran baru — '.$invoice->number,
            BillingMessages::adminProofWaiting($invoice),
            "admin-proof:{$invoice->id}",
            route('admin.invoices'),
        );

        /*
         | Dialihkan ke halaman tersendiri, bukan kembali ke form.
         |
         | Kembali ke halaman yang sama dengan spanduk hijau tipis ternyata tidak
         | cukup meyakinkan: pelanggan pernah mengunggah bukti, tidak merasa ada
         | yang berubah, mengira gagal, lalu membatalkan tagihannya sendiri 24
         | detik kemudian. Halaman yang seluruhnya berbicara tentang "bukti Anda
         | sudah kami terima" tidak menyisakan ruang untuk keraguan itu.
         */
        return redirect()
            ->route('billing.verifying', $invoice->id)
            ->with('swal', [
                'icon' => 'success',
                'title' => 'Bukti pembayaran terkirim',
                'text' => 'Kami sudah menerimanya. Tim kami memeriksa dan mengaktifkan langganan Anda, biasanya dalam beberapa jam pada jam kerja.',
                'confirmButtonText' => 'Baik',
            ]);
    }

    /**
     * Konfirmasi pembayaran langsung oleh pelanggan (alur tanpa upload bukti transfer).
     *
     * Memberikan pengalaman seperti payment gateway profesional: pelanggan menekan
     * tombol setelah transfer/scan QRIS, tagihan masuk antrean verifikasi dengan
     * countdown timer 10 menit, dan admin langsung dikabari untuk cek mutasi DANA/Bank.
     */
    public function confirmPayment(Request $request, int $id): RedirectResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus langganan.');

        $invoice = $workspace->invoices()->findOrFail($id);

        if (! $invoice->isPending()) {
            return back()->with('swal', [
                'icon' => 'info',
                'title' => 'Tagihan tidak menunggu pembayaran',
                'text' => 'Tagihan ini sudah diproses atau tidak lagi berstatus pending.',
            ]);
        }

        $channel = $request->input('metode_bayar');
        $payload = [
            'payment_confirmed_at' => now(),
        ];
        if (filled($channel) && in_array($channel, ['qris', 'bank', 'va', 'qris_manual', 'bank_transfer'], true)) {
            $payload['channel'] = $channel === 'qris' ? 'qris_manual' : ($channel === 'bank' ? 'bank_transfer' : $channel);
        }

        $invoice->forceFill($payload)->save();

        AuditLog::record('invoice.payment_confirmed', $invoice, [
            'number' => $invoice->number,
            'channel' => $invoice->channel,
        ], $workspace->id);

        // Beri tahu admin seketika via WhatsApp dan Email agar membuka DANA/Bank
        $this->notifier->toAdmin(
            BillingMessages::adminPaymentWaiting($invoice),
            "admin-confirm:{$invoice->id}",
        );

        $this->email->kabarTim(
            'Konfirmasi pembayaran baru — '.$invoice->number,
            BillingMessages::adminPaymentWaiting($invoice),
            "admin-confirm:{$invoice->id}",
            route('admin.invoices'),
        );

        return redirect()->route('billing.verifying', $invoice->id);
    }

    /**
     * Halaman menunggu verifikasi pembayaran otomatis (dengan timer 10 menit).
     */
    public function verifying(Request $request, int $id): View|RedirectResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        $invoice = $workspace->invoices()->findOrFail($id);

        // Tagihan yang sudah lunas tidak lagi menunggu verifikasi
        if ($invoice->isPaid()) {
            return redirect()->route('billing.invoice', $invoice->id);
        }

        // Kalau belum pernah menekan tombol konfirmasi bayar dan belum ada bukti
        if (blank($invoice->payment_confirmed_at) && blank($invoice->proof_path)) {
            return redirect()->route('billing.invoice', $invoice->id);
        }

        // Hitung sisa detik dari batas 10 menit (600 detik) sejak payment_confirmed_at
        $waktuKonfirmasi = $invoice->payment_confirmed_at ?? $invoice->updated_at;
        $detikBerlalu = $waktuKonfirmasi ? abs((int) now()->diffInSeconds($waktuKonfirmasi, false)) : 0;
        $sisaDetik = max(0, 600 - $detikBerlalu);

        return view('dashboard.billing.verifying', [
            'invoice' => $invoice,
            'plan' => $invoice->plan(),
            'subscription' => $this->subscriptions->ensureFor($workspace),
            'sisaDetik' => $sisaDetik,
            'adminWa' => KontakWhatsApp::nomor(),
        ]);
    }

    /**
     * Keadaan satu tagihan sebagai JSON, untuk ditanyakan berkala oleh halaman
     * menunggu verifikasi.
     *
     * Ada karena halaman itu menjanjikan "berubah sendiri begitu selesai", dan
     * janji yang tidak ditepati di halaman pembayaran adalah cara tercepat
     * membuat orang menekan tombol yang tidak seharusnya — persis yang dulu
     * terjadi saat pelanggan membatalkan tagihannya sendiri.
     *
     * Sengaja sekecil mungkin: hanya status dan ke mana harus pergi. Tidak ada
     * nominal, nama, atau apa pun yang tidak dibutuhkan pemanggilnya.
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        $invoice = $workspace->invoices()->findOrFail($id);

        if ($invoice->isPending() && $invoice->payment_gateway === 'xendit' && filled($invoice->payment_reference)) {
            if ($this->xendit->checkAndSyncStatus($invoice, $this->subscriptions)) {
                $invoice->refresh();
            }
        }

        return response()->json([
            'status' => $invoice->status,
            'lunas' => $invoice->isPaid(),
            'lanjut' => route('billing.invoice', $invoice->id),
        ]);
    }

    public function cancelInvoice(Request $request, int $id): RedirectResponse
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        abort_unless($request->user()->canManage($workspace), 403, 'Hanya owner atau admin yang bisa mengurus langganan.');

        $invoice = $workspace->invoices()->findOrFail($id);

        if (! $invoice->isPending()) {
            return back()->withErrors(['tagihan' => 'Hanya tagihan yang menunggu pembayaran yang bisa dibatalkan.']);
        }

        /*
         | Tagihan yang sedang diverifikasi tidak boleh dibatalkan sendiri oleh pelanggan
         | agar tidak terjadi kasus dana sudah masuk namun tagihannya lenyap dari antrean.
         */
        if (filled($invoice->proof_path) || filled($invoice->payment_confirmed_at)) {
            return back()->withErrors([
                'tagihan' => 'Pembayaran untuk tagihan ini sedang dalam proses verifikasi, jadi tagihannya tidak bisa dibatalkan sendiri. Hubungi admin melalui WhatsApp jika ada kekeliruan.',
            ]);
        }

        $invoice->forceFill(['status' => 'canceled'])->save();

        // Kodenya batal ditukar, tapi barisnya TIDAK dihapus: workspace ini
        // tetap terhitung sudah pernah memakai kode referal. Kalau barisnya
        // dibuang, pelanggan yang sama bisa membatalkan tagihannya berulang
        // kali sambil mencoba kode lain sampai menemukan diskon terbesar.
        $this->referrals->batalkan($invoice);

        AuditLog::record('invoice.canceled', $invoice, [
            'number' => $invoice->number,
        ], $workspace->id);

        return redirect()->route('billing.index')->with('swal', [
            'icon' => 'success',
            'title' => 'Tagihan dibatalkan',
            'text' => "{$invoice->number} tidak akan ditagihkan lagi.",
        ]);
    }

    /**
     * Menyajikan bukti transfer yang tersimpan di disk privat.
     *
     * Dijaga oleh kepemilikan workspace, bukan sekadar oleh sulitnya menebak
     * nama berkas: `findOrFail` di atas berangkat dari workspace yang sedang
     * dibuka, jadi id tagihan milik orang lain berakhir 404 bukan gambar.
     */
    public function proof(Request $request, int $id)
    {
        $workspace = EnsureWorkspaceSelected::from($request);

        $invoice = $workspace->invoices()->findOrFail($id);

        abort_if(blank($invoice->proof_path), 404);

        return response()->file(
            Storage::disk('media')->path($invoice->proof_path)
        );
    }
}
