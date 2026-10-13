<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Mail\PayoutRequestedMail;
use App\Models\AuditLog;
use App\Models\PayoutRequest;
use App\Models\ReferralCode;
use App\Models\ReferralRedemption;
use App\Services\Billing\InvoicePdfService;
use App\Services\Notifications\EmailNotifier;
use App\Services\Notifications\WhatsAppNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Program Mitra (Reseller / Afiliasi) VexaHost.
 *
 * Mengelola pendaftaran kemitraan dengan verifikasi rekening oleh admin,
 * pelacakan downline, pengajuan pencairan komisi (min. Rp 100.000) dengan
 * notifikasi otomatis ke WhatsApp dan Gmail admin, serta tutorial alur pencairan.
 */
class MitraController extends Controller
{
    public function __construct(
        private readonly WhatsAppNotifier $notifier,
        private readonly InvoicePdfService $pdfService,
        private readonly EmailNotifier $email,
    ) {}

    /**
     * Halaman publik Program Kemitraan (Mitra / Reseller).
     */
    public function landing(Request $request): View
    {
        if ($request->filled('ref')) {
            $kode = ReferralCode::normalkan((string) $request->query('ref'));
            if (ReferralCode::where('code', $kode)->where('approval_status', 'approved')->where('is_active', true)->exists()) {
                session(['referral_code' => $kode]);
            }
        }

        return view('mitra.index');
    }

    /**
     * Dashboard Mitra / Reseller untuk pengguna yang login.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $referralCode = ReferralCode::where('owner_user_id', $user->id)->first();

        if (! $referralCode || ! $referralCode->isApproved()) {
            return view('dashboard.mitra.index', [
                'referralCode' => $referralCode,
            ]);
        }

        $redemptions = ReferralRedemption::with(['workspace', 'invoice'])
            ->where('referral_code_id', $referralCode->id)
            ->latest()
            ->paginate(15);

        $payoutRequests = PayoutRequest::where('referral_code_id', $referralCode->id)
            ->latest()
            ->get();

        $totalReferral = ReferralRedemption::where('referral_code_id', $referralCode->id)->count();
        $totalSukses = ReferralRedemption::where('referral_code_id', $referralCode->id)
            ->whereIn('status', ['approved', 'paid'])
            ->count();

        // Komisi approved yang belum ditarik (bukan yang pending payout request)
        $komisiMenunggu = (int) ReferralRedemption::where('referral_code_id', $referralCode->id)
            ->where('status', 'approved')
            ->sum('commission_amount');

        $komisiCair = (int) ReferralRedemption::where('referral_code_id', $referralCode->id)
            ->where('status', 'paid')
            ->sum('commission_amount');

        $totalKomisi = $komisiMenunggu + $komisiCair;
        $minPayout = 100_000;
        $bisaCair = $komisiMenunggu >= $minPayout;
        $hasPendingPayout = PayoutRequest::where('referral_code_id', $referralCode->id)
            ->where('status', 'pending')
            ->exists();

        $tautanReferal = route('register').'?ref='.$referralCode->code;

        return view('dashboard.mitra.index', [
            'referralCode' => $referralCode,
            'redemptions' => $redemptions,
            'payoutRequests' => $payoutRequests,
            'totalReferral' => $totalReferral,
            'totalSukses' => $totalSukses,
            'komisiMenunggu' => $komisiMenunggu,
            'komisiCair' => $komisiCair,
            'totalKomisi' => $totalKomisi,
            'minPayout' => $minPayout,
            'bisaCair' => $bisaCair,
            'hasPendingPayout' => $hasPendingPayout,
            'tautanReferal' => $tautanReferal,
        ]);
    }

    /**
     * Pendaftaran / Pengajuan Menjadi Mitra (Wajib ACC Admin).
     */
    public function apply(Request $request): RedirectResponse
    {
        $user = $request->user();
        $existing = ReferralCode::where('owner_user_id', $user->id)->first();

        if ($existing && $existing->isApproved()) {
            return redirect()->route('mitra.index')->with('swal', [
                'tipe' => 'info',
                'judul' => 'Kode Referal Sudah Aktif',
                'pesan' => 'Kode referal Anda adalah '.$existing->code.' dan sudah aktif digunakan.',
            ]);
        }

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:50'],
            'bank_account_number' => ['required', 'string', 'max:50'],
            'bank_account_name' => ['required', 'string', 'max:100'],
            'whatsapp_number' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'terms' => ['accepted'],
        ], [
            'bank_name.required' => 'Nama bank atau e-wallet wajib diisi.',
            'bank_account_number.required' => 'Nomor rekening atau nomor e-wallet wajib diisi.',
            'bank_account_name.required' => 'Nama pemilik rekening wajib diisi sesuai buku tabungan.',
            'whatsapp_number.required' => 'Nomor WhatsApp aktif wajib diisi untuk koordinasi pencairan komisi.',
            'terms.accepted' => 'Anda wajib membaca dan menyetujui Syarat & Ketentuan Program Kemitraan VexaHost.',
        ]);

        if ($existing) {
            // Update permohonan yang ditolak atau sedang pending
            $existing->update([
                'bank_name' => $data['bank_name'],
                'bank_account_number' => $data['bank_account_number'],
                'bank_account_name' => $data['bank_account_name'],
                'whatsapp_number' => $data['whatsapp_number'],
                'notes' => $data['notes'] ?? null,
                'approval_status' => 'pending',
                'rejection_reason' => null,
            ]);
            $kode = $existing;
        } else {
            // Satu akun satu kode selamanya: buat kode acak 5 huruf permanen
            $kode = ReferralCode::create([
                'code' => ReferralCode::buatKode(),
                'owner_user_id' => $user->id,
                'discount_percent' => 10,
                'commission_percent' => 20,
                'is_active' => false, // Belum aktif sampai di-ACC admin
                'approval_status' => 'pending',
                'bank_name' => $data['bank_name'],
                'bank_account_number' => $data['bank_account_number'],
                'bank_account_name' => $data['bank_account_name'],
                'whatsapp_number' => $data['whatsapp_number'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
        }

        AuditLog::record('mitra.application.submitted', $kode, [
            'owner' => $user->email,
            'bank' => $kode->bank_name,
            'rekening' => $kode->bank_account_number,
        ]);

        // Beri tahu admin lewat WhatsApp bahwa ada pendaftar reseller baru
        $pesanAdmin = "Halo Admin VexaHost, ada pendaftaran mitra/reseller baru yang menunggu konfirmasi (ACC):\n\n"
            ."Nama: {$user->name} ({$user->email})\n"
            ."WhatsApp: {$kode->whatsapp_number}\n"
            ."Rekening: {$kode->bank_name} - {$kode->bank_account_number} a.n {$kode->bank_account_name}\n"
            ."Kode Disiapkan: {$kode->code}\n\n"
            .'Buka panel admin untuk konfirmasi: '.route('admin.referrals');
        $this->notifier->toAdmin($pesanAdmin);

        // Email menyusul: pendaftaran mitra yang tidak pernah dikonfirmasi
        // adalah orang yang sudah menyerahkan data rekeningnya lalu didiamkan.
        $this->email->kabarTim(
            'Pendaftaran mitra baru — '.$user->name,
            $pesanAdmin,
            "mitra-daftar:{$kode->id}",
            route('admin.referrals'),
        );

        return redirect()->route('mitra.index')->with('swal', [
            'tipe' => 'success',
            'judul' => 'Permohonan Berhasil Dikirim',
            'pesan' => 'Data rekening dan permohonan kemitraan Anda telah diterima. Tim admin akan memverifikasi dan mengaktifkan kode Anda dalam 1x24 jam.',
        ]);
    }

    /**
     * Pengajuan Pencairan Dana Komisi (Minimal Rp 100.000).
     */
    public function requestPayout(Request $request): RedirectResponse
    {
        $user = $request->user();
        $referralCode = ReferralCode::where('owner_user_id', $user->id)->first();

        if (! $referralCode || ! $referralCode->isApproved()) {
            return back()->withErrors(['payout' => 'Akun kemitraan Anda belum aktif atau belum disetujui.']);
        }

        // Hitung komisi disetujui yang siap ditarik
        $komisiTersedia = (int) ReferralRedemption::where('referral_code_id', $referralCode->id)
            ->where('status', 'approved')
            ->sum('commission_amount');

        if ($komisiTersedia < 100_000) {
            return back()->with('swal', [
                'tipe' => 'warning',
                'judul' => 'Saldo Belum Mencukupi',
                'pesan' => 'Minimal pencairan dana adalah Rp 100.000. Saldo komisi Anda saat ini adalah Rp '
                    .number_format($komisiTersedia, 0, ',', '.').' (kurang Rp '
                    .number_format(100_000 - $komisiTersedia, 0, ',', '.').' lagi).',
            ]);
        }

        // Cek jika sudah ada permohonan pending
        $adaPending = PayoutRequest::where('referral_code_id', $referralCode->id)
            ->where('status', 'pending')
            ->first();

        if ($adaPending) {
            return back()->with('swal', [
                'tipe' => 'info',
                'judul' => 'Permintaan Sedang Diproses',
                'pesan' => 'Anda masih memiliki permintaan pencairan dana sebesar Rp '
                    .number_format($adaPending->amount, 0, ',', '.').' yang sedang menunggu transfer admin.',
            ]);
        }

        $feePercent = config('billing.payout_fee_percent', 5);
        $feeAmount = (int) round($komisiTersedia * $feePercent / 100);
        $netAmount = max(0, $komisiTersedia - $feeAmount);

        $payout = PayoutRequest::create([
            'referral_code_id' => $referralCode->id,
            'user_id' => $user->id,
            'amount' => $komisiTersedia,
            'fee_percent' => $feePercent,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'bank_name' => $referralCode->bank_name ?? '—',
            'bank_account_number' => $referralCode->bank_account_number ?? '—',
            'bank_account_name' => $referralCode->bank_account_name ?? '—',
            'status' => 'pending',
            'notes' => $request->input('notes'),
        ]);

        AuditLog::record('mitra.payout.requested', $payout, [
            'amount' => $payout->amount,
            'fee' => $payout->fee_amount,
            'net' => $payout->net_amount,
            'owner' => $user->email,
        ]);

        // Terbitkan berkas dokumen invoice PDF resmi
        $pdfPath = null;
        try {
            $pdfPath = $this->pdfService->generatePayoutInvoice($payout);
        } catch (\Throwable $e) {
            Log::warning('Gagal menerbitkan PDF invoice pencairan dana', ['error' => $e->getMessage()]);
        }

        $pdfMedia = $pdfPath ? [
            'type' => 'document',
            'media_path' => $pdfPath,
            'media_mime' => 'application/pdf',
            'media_filename' => "Invoice-{$payout->payout_number}.pdf",
        ] : [];

        // 1. Notifikasi WhatsApp ke Admin (disertai dokumen invoice PDF)
        $pesanWaAdmin = "Halo Admin VexaHost, ada permintaan pencairan dana komisi mitra baru!\n\n"
            ."No. Invoice: {$payout->payout_number}\n"
            ."Mitra: {$user->name} ({$user->email})\n"
            ."Nomor WA: {$referralCode->whatsapp_number}\n"
            ."Kode Referal: {$referralCode->code}\n"
            .'Komisi Bruto: Rp '.number_format($payout->amount, 0, ',', '.')."\n"
            ."Potongan Admin ({$payout->fee_percent}%): Rp ".number_format($payout->fee_amount, 0, ',', '.')."\n"
            .'Nominal Transfer Bersih: Rp '.number_format($payout->net_amount, 0, ',', '.')."\n"
            ."Tujuan Transfer: {$payout->bank_name} - {$payout->bank_account_number} a.n {$payout->bank_account_name}\n\n"
            ."Dokumen invoice PDF resmi terlampir.\n"
            .'Buka invoice online: '.route('admin.referrals.payout.invoice', $payout->id)."\n"
            .'Konfirmasi di panel admin: '.route('admin.referrals');
        $this->notifier->toAdmin($pesanWaAdmin, media: $pdfMedia);

        // Email menyusul, tanpa lampiran PDF-nya: yang perlu sampai adalah
        // kabar bahwa ada pencairan menunggu, dan invoice-nya selalu bisa
        // dibuka lagi dari panel.
        $this->email->kabarTim(
            'Pengajuan pencairan komisi — '.$user->name,
            $pesanWaAdmin,
            "mitra-cair:{$payout->id}",
            route('admin.referrals'),
        );

        // 2. Notifikasi WhatsApp ke User / Mitra (disertai dokumen invoice PDF)
        $targetWaUser = $referralCode->whatsapp_number ?: $user->phone;
        if (! blank($targetWaUser)) {
            $pesanWaUser = "Halo {$user->name}, permohonan pencairan komisi kemitraan Anda telah berhasil kami terima!\n\n"
                ."No. Invoice: {$payout->payout_number}\n"
                .'Komisi Bruto: Rp '.number_format($payout->amount, 0, ',', '.')."\n"
                ."Potongan Administrasi ({$payout->fee_percent}%): Rp ".number_format($payout->fee_amount, 0, ',', '.')."\n"
                .'Nominal Transfer Bersih: Rp '.number_format($payout->net_amount, 0, ',', '.')."\n"
                ."Rekening Tujuan: {$payout->bank_name} - {$payout->bank_account_number} a.n {$payout->bank_account_name}\n\n"
                ."Terlampir dokumen invoice PDF resmi penarikan dana ini. Tim Finance VexaHost akan memproses transfer manual dalam 1x24 jam kerja.\n\n"
                .'Cek status invoice online: '.route('mitra.payout.invoice', $payout->id);
            $this->notifier->toPhone($targetWaUser, $pesanWaUser, media: $pdfMedia);
        }

        // 3. Notifikasi Email ke Admin (disertakan lampiran PDF)
        try {
            foreach (\App\Support\MailIdentity::adminRecipients() as $adminEmail) {
                Mail::to($adminEmail)->send(new PayoutRequestedMail($payout, isForAdmin: true));
            }
        } catch (\Throwable $e) {
            // Kegagalan email tidak membatalkan pengajuan pencairan
        }

        // 4. Notifikasi Email ke User / Mitra (disertakan lampiran PDF)
        try {
            Mail::to($user->email)->send(new PayoutRequestedMail($payout, isForAdmin: false));
        } catch (\Throwable $e) {
            // Kegagalan email tidak membatalkan pengajuan pencairan
        }

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => 'Pencairan Dana Diajukan',
            'pesan' => 'Permintaan pencairan komisi '.$payout->payout_number.' sebesar Rp '.number_format($payout->amount, 0, ',', '.')
                .' (Bersih: Rp '.number_format($payout->net_amount, 0, ',', '.').') berhasil dikirim. Dokumen invoice PDF telah diteruskan ke WhatsApp & Email Anda dan Admin VexaHost.',
        ]);
    }

    /**
     * Halaman Invoice Resmi Pencairan Komisi Mitra (Standar Enterprise).
     */
    public function payoutInvoice(Request $request, int $id): View
    {
        $user = $request->user();
        $payout = PayoutRequest::with(['user', 'referralCode', 'payer'])->findOrFail($id);

        abort_if($payout->user_id !== $user->id, 403, 'Anda tidak memiliki hak akses ke dokumen ini.');

        return view('dashboard.mitra.payout-invoice', [
            'payout' => $payout,
            'isAdmin' => false,
        ]);
    }

    /**
     * Unduh Berkas Dokumen Invoice PDF Resmi Pencairan Dana.
     */
    public function downloadInvoice(Request $request, int $id)
    {
        $user = $request->user();
        $payout = PayoutRequest::with(['user', 'referralCode', 'payer'])->findOrFail($id);

        abort_if($payout->user_id !== $user->id, 403, 'Anda tidak memiliki hak akses ke dokumen ini.');

        return $this->pdfService->streamPayoutInvoice($payout)
            ->download("Invoice-{$payout->payout_number}.pdf");
    }
}
