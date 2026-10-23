<?php

namespace App\Services\Providers;

use App\Models\Message;
use App\Models\WaSession;

/**
 * Kontrak satu provider WhatsApp.
 *
 * Hanya ada satu implementasi: WwebjsProvider — otomasi WhatsApp Web lewat
 * browser. Produk ini tidak menjual, menyalurkan, atau menawarkan layanan
 * WhatsApp milik pihak lain; nomor pelanggan tidak pernah melewati gateway
 * selain milik kita sendiri.
 *
 * Interface-nya dipertahankan sebagai batas yang jelas antara "apa yang
 * dilakukan sebuah sesi" dan "bagaimana caranya" — batas itulah yang membuat
 * SendMessageJob dan SessionService tidak perlu tahu detail implementasi engine sama sekali.
 */
interface WhatsAppProvider
{
    /**
     * Menyalakan sesi dan memicu registrasi QR atau koneksi ulang.
     *
     * `$hanyaPulihkan` untuk jalur tanpa manusia: sesi yang tidak punya
     * kredensial untuk dipulihkan tidak dijalankan sama sekali.
     */
    public function startSession(WaSession $session, bool $hanyaPulihkan = false): void;

    public function stopSession(WaSession $session): void;

    /**
     * Memutus tautan perangkat di sisi WhatsApp dan membuang kredensial sesi.
     * Berbeda dengan stopSession: setelah logout wajib scan QR ulang.
     */
    public function logoutSession(WaSession $session): void;

    /**
     * @return array{status: string, phone_number?: ?string, push_name?: ?string}
     */
    public function status(WaSession $session): array;

    /**
     * Mengirim pesan yang sudah tercatat di tabel messages.
     *
     * @return array{wa_message_id: ?string, raw: array<string, mixed>}
     */
    public function send(WaSession $session, Message $message): array;
}
