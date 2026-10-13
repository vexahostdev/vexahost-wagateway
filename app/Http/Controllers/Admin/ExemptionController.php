<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\PendingExemption;
use App\Models\SpecialNumber;
use App\Models\User;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Services\Notifications\EmailNotifier;
use App\Services\Notifications\WhatsAppNotifier;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pemberitahuan WhatsApp dan seluruh pengecualian, di satu halaman.
 *
 * Digabung bukan karena kebetulan sama-sama "pengaturan", melainkan karena
 * ketiganya menjawab satu pertanyaan yang sama: **siapa yang tidak tunduk pada
 * aturan biasa, dan kenapa.** Nomor perusahaan yang tidak menghitung kuota,
 * akun yang tidak ditagih, dan nomor yang dipakai mengirim tagihan orang lain —
 * semuanya pengecualian, dan pengecualian yang tersebar di beberapa halaman
 * adalah pengecualian yang lupa dicabut.
 *
 * Sebelum halaman ini ada, satu-satunya cara membuat pengecualian adalah
 * mengubah kolom `is_internal` lewat tinker. Tidak ada catatan siapa yang
 * memberi, kapan, dan untuk apa — dan tidak ada satu pun tempat yang menjawab
 * "siapa saja yang sekarang memakai produk ini gratis".
 */
class ExemptionController extends Controller
{
    public function index(WhatsAppNotifier $notifier, EmailNotifier $email): View
    {
        $workspaceId = AppSetting::ambil('notify_workspace_id', config('billing.notify_workspace_id'));

        return view('admin.exemptions', [
            'nomorIstimewa' => SpecialNumber::with('pembuat')->latest()->get(),

            'akunBebas' => User::where('is_exempt', true)
                ->withCount('workspaces')
                ->orderBy('email')
                ->get(),

            'workspaceBebas' => Workspace::where('is_internal', true)->orderBy('name')->get(),

            'pembebasanMenunggu' => PendingExemption::with('pembuat')
                ->whereNull('claimed_at')
                ->orderBy('email')
                ->get(),

            // Hanya workspace yang punya sesi tersambung yang layak jadi
            // pengirim — menawarkan yang lain berarti menawarkan pilihan yang
            // pasti gagal.
            'kandidatPengirim' => Workspace::withCount([
                'sessions as sesi_tersambung' => fn ($q) => $q->where('status', 'connected'),
            ])->orderBy('name')->get(),

            'pengirimTerpilih' => $workspaceId,
            'notifikasiSiap' => $notifier->ready(),
            'nomorAdmin' => config('billing.admin_phone'),

            'emailSiap' => $email->ready(),
            'emailPengirim' => config('mail.from.address'),
            'emailMailer' => config('mail.default'),
            'emailAdmin' => implode(', ', \App\Support\MailIdentity::adminRecipients()),

            // Untuk menjelaskan akibatnya secara konkret, bukan abstrak.
            'sesiIstimewaHidup' => WaSession::whereIn('phone_number', SpecialNumber::daftar())
                ->where('status', 'connected')
                ->count(),
        ]);
    }

    public function storeNumber(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            // Kontak pemilik nomor perusahaan. Nomor yang bermasalah tanpa
            // orang yang bisa dihubungi berarti menebak siapa pemegangnya.
            'email' => ['nullable', 'email', 'max:180'],
            'label' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $nomor = PhoneNumber::normalize($data['phone']);

        if ($nomor === null) {
            return back()->withErrors(['phone' => 'Nomor tidak dikenali. Pakai format 08xx atau 62xx.'])->withInput();
        }

        if (SpecialNumber::where('phone', $nomor)->exists()) {
            return back()->withErrors(['phone' => 'Nomor itu sudah terdaftar sebagai nomor istimewa.'])->withInput();
        }

        $baris = SpecialNumber::create([
            'phone' => $nomor,
            'email' => $data['email'] ?? null,
            'label' => $data['label'],
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('exemption.number.added', $baris, [
            'nomor' => PhoneNumber::mask($nomor),
            'label' => $baris->label,
        ]);

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => 'Nomor istimewa ditambahkan',
            'pesan' => 'Nomor ini tidak lagi menghitung kuota sesi workspace mana pun.',
        ]);
    }

    public function destroyNumber(Request $request, int $id): RedirectResponse
    {
        $baris = SpecialNumber::findOrFail($id);

        AuditLog::record('exemption.number.removed', $baris, [
            'nomor' => PhoneNumber::mask($baris->phone),
            'label' => $baris->label,
        ]);

        $baris->delete();

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => 'Nomor dikeluarkan dari daftar',
            'pesan' => 'Mulai sekarang nomor itu menghitung kuota sesi seperti nomor pelanggan biasa.',
        ]);
    }

    /**
     * Membebaskan sebuah ALAMAT EMAIL, termasuk yang belum pernah mendaftar.
     *
     * Tanpa ini, membebaskan calon pelanggan berarti menunggu mereka mendaftar
     * lebih dulu lalu mengingat untuk kembali menandainya — dan yang lupa
     * ditandai akan tertagih seperti pelanggan biasa, lalu mengeluh tentang
     * janji yang sudah diberikan seseorang.
     */
    public function exemptEmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $email = PendingExemption::normalkan($data['email']);

        // Sudah punya akun? Bebaskan langsung — tidak ada gunanya menunggu
        // sesuatu yang sudah terjadi.
        if ($user = User::where('email', $email)->first()) {
            $user->forceFill(['is_exempt' => true])->save();

            AuditLog::record('exemption.user.granted', $user, [
                'email' => $user->email,
                'lewat' => 'email',
            ]);

            return back()->with('swal', [
                'tipe' => 'success',
                'judul' => 'Akun dibebaskan',
                'pesan' => 'Akun '.$user->email.' sudah terdaftar, jadi pembebasannya berlaku sekarang juga — '
                    .'termasuk untuk workspace yang dibuatnya nanti.',
            ]);
        }

        $baris = PendingExemption::firstOrCreate(
            ['email' => $email],
            ['note' => $data['note'] ?? null, 'created_by' => $request->user()->id],
        );

        AuditLog::record('exemption.email.pending', $baris, ['email' => $email]);

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => 'Pembebasan menunggu',
            'pesan' => $email.' belum punya akun. Pembebasannya berlaku otomatis '
                .'begitu alamat itu mendaftar — tidak perlu diingat lagi.',
        ]);
    }

    /** Membatalkan pembebasan yang belum sempat dipakai. */
    public function cancelPendingExemption(Request $request, int $id): RedirectResponse
    {
        $baris = PendingExemption::findOrFail($id);

        if ($baris->sudahDipakai()) {
            return back()->withErrors([
                'email' => 'Pembebasan ini sudah dipakai. Cabut lewat daftar akun bebas di bawah.',
            ]);
        }

        AuditLog::record('exemption.email.canceled', $baris, ['email' => $baris->email]);
        $baris->delete();

        return back()->with('status', 'Pembebasan yang menunggu dibatalkan.');
    }

    public function toggleUser(Request $request, int $id): RedirectResponse
    {
        $user = User::findOrFail($id);

        $user->forceFill(['is_exempt' => ! $user->is_exempt])->save();

        AuditLog::record($user->is_exempt ? 'exemption.user.granted' : 'exemption.user.revoked', $user, [
            'email' => $user->email,
        ]);

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => $user->is_exempt ? 'Akun dibebaskan' : 'Pembebasan dicabut',
            'pesan' => $user->is_exempt
                ? 'Seluruh workspace milik '.$user->email.' bebas berlangganan, termasuk yang dibuat nanti.'
                : 'Workspace milik '.$user->email.' kembali tunduk pada penagihan biasa.',
        ]);
    }

    /**
     * Mengirim pesan percobaan ke nomor mana pun.
     *
     * Tanpa ini, satu-satunya cara memastikan jalur pemberitahuan benar adalah
     * menunggu peristiwa penagihan sungguhan terjadi — dan kalau ternyata
     * salah, yang menemukan kesalahannya adalah pelanggan yang tidak dikabari.
     */
    public function testNotifier(Request $request, WhatsAppNotifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $hasil = $notifier->kirimTes($data['phone']);

        AuditLog::record('settings.notifier.tested', null, [
            'tujuan' => PhoneNumber::mask(PhoneNumber::normalize($data['phone']) ?? $data['phone']),
            'berhasil' => $hasil['berhasil'],
        ]);

        return back()->with('swal', [
            'tipe' => $hasil['berhasil'] ? 'success' : 'error',
            'judul' => $hasil['berhasil'] ? 'Pesan tes dikirim' : 'Pesan tes tidak bisa dikirim',
            'pesan' => $hasil['pesan'],
        ]);
    }

    /**
     * Mengirim email percobaan ke alamat mana pun.
     *
     * Bertetangga dengan tombol tes WhatsApp karena keduanya menjawab
     * pertanyaan yang sama — "apakah jalurnya benar-benar sampai" — dan punya
     * bentuk kegagalan yang sama: diam. Brevo menolak pengirim yang belum
     * diverifikasi dengan 550, dan penolakan itu tidak muncul di antarmuka
     * mana pun. Tanpa tombol ini, yang menemukan kesalahannya adalah pelanggan
     * yang tidak dikabari.
     */
    public function testEmail(Request $request, EmailNotifier $email): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
        ]);

        $hasil = $email->kirimTes($data['email']);

        AuditLog::record('settings.email.tested', null, [
            'tujuan' => $data['email'],
            'berhasil' => $hasil['berhasil'],
        ]);

        return back()->with('swal', [
            'tipe' => $hasil['berhasil'] ? 'success' : 'error',
            'judul' => $hasil['berhasil'] ? 'Email tes dikirim' : 'Email tes tidak bisa dikirim',
            'pesan' => $hasil['pesan'],
        ]);
    }

    public function saveNotifier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        AppSetting::simpan('notify_workspace_id', $data['workspace_id'] ?? null);

        AuditLog::record('settings.notifier.changed', null, [
            'workspace_id' => $data['workspace_id'] ?? null,
        ]);

        return back()->with('swal', [
            'tipe' => 'success',
            'judul' => 'Pengirim pemberitahuan disimpan',
            'pesan' => blank($data['workspace_id'] ?? null)
                ? 'Dikosongkan — seluruh pemberitahuan WhatsApp berhenti dikirim.'
                : 'Pemberitahuan dikirim dari nomor yang tertaut di workspace itu.',
        ]);
    }
}
