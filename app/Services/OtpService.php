<?php

namespace App\Services;

use App\Exceptions\OtpRateLimited;
use App\Models\OtpCode;
use App\Models\WaSession;
use App\Models\Workspace;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * OTP lewat WhatsApp: kirim kode, lalu cocokkan kodenya.
 *
 * Dikirim dari nomor workspace pemanggil, dan menyebut nama workspace itu.
 * Sebelumnya seluruh OTP keluar dari satu sesi global (OTP_SESSION_ID) dengan
 * teks yang menyebut merek kami sendiri — artinya pelanggan yang memakai endpoint ini
 * mengirim kode dari nomor kami, atas nama kami, dan memakannya dari kuota
 * kami. Pelanggan yang menerimanya pun melihat merek yang bukan merek yang
 * mereka daftarkan, dan laporan spam atas kiriman itu jatuh ke nomor kami.
 */
class OtpService
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function send(string $phone, string $purpose, ?string $ip, Workspace $workspace): OtpCode
    {
        $normalized = PhoneNumber::normalize($phone);

        if ($normalized === null) {
            throw new RuntimeException("Nomor tidak valid: {$phone}");
        }

        $this->guardRateLimit($normalized, $purpose);

        $code = str_pad((string) random_int(0, 999999), config('gateway.otp.length'), '0', STR_PAD_LEFT);

        // Kode lama untuk tujuan yang sama dimatikan supaya hanya kode terbaru
        // yang berlaku — mencegah kode dari SMS lama masih bisa dipakai.
        OtpCode::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()]);

        $otp = OtpCode::create([
            'phone' => $normalized,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(config('gateway.otp.ttl_seconds')),
            'requested_by_ip' => $ip,
            'workspace_id' => $workspace->id,
        ]);

        $minutes = (int) ceil(config('gateway.otp.ttl_seconds') / 60);
        $pengirim = $workspace->name;

        $this->dispatcher->queue($this->senderSession($workspace), $normalized, [
            // OTP yang ditunda sampai besok sama dengan OTP yang gagal, dan
            // orang yang memintanya baru saja menekan tombol — ia menunggu.
            'is_otp' => true,
            'type' => 'text',
            'body' => "*{$code}* adalah kode verifikasi {$pengirim} Anda.\n\n"
                ."Kode berlaku {$minutes} menit. Jangan bagikan kode ini kepada siapa pun, "
                ."termasuk yang mengaku dari {$pengirim}.",
        ]);

        return $otp;
    }

    public function verify(string $phone, string $purpose, string $code): bool
    {
        $normalized = PhoneNumber::normalize($phone);

        $otp = OtpCode::where('phone', $normalized)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['verified_at' => now()]);

        return true;
    }

    private function guardRateLimit(string $phone, string $purpose): void
    {
        $recent = OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds(config('gateway.otp.resend_cooldown_seconds')))
            ->exists();

        if ($recent) {
            $seconds = config('gateway.otp.resend_cooldown_seconds');

            throw new OtpRateLimited("Kode baru bisa diminta lagi setelah {$seconds} detik.");
        }

        $today = OtpCode::where('phone', $phone)
            ->where('created_at', '>', now()->subDay())
            ->count();

        // Batas harian menahan penyalahgunaan endpoint OTP untuk membombardir
        // nomor orang lain — pola yang cepat membuat nomor pengirim diblokir.
        if ($today >= config('gateway.otp.max_per_phone_per_day')) {
            throw new OtpRateLimited('Batas permintaan kode untuk nomor ini sudah tercapai hari ini.');
        }
    }

    /**
     * Nomor pengirim diambil dari workspace pemanggil, dengan aturan yang sama
     * persis dengan pesan biasa: sesi terhubung tertua. Tidak ada nomor khusus
     * dan tidak ada konfigurasi tambahan — OTP hanyalah pesan teks biasa yang
     * kebetulan berisi kode.
     */
    private function senderSession(Workspace $workspace): WaSession
    {
        $session = $workspace->sessions()
            ->where('status', 'connected')
            ->orderBy('created_at')
            ->first();

        if (! $session) {
            throw new RuntimeException(
                'Belum ada nomor WhatsApp yang terhubung di workspace ini. '
                .'Hubungkan satu nomor di dashboard lebih dulu.'
            );
        }

        return $session;
    }
}
