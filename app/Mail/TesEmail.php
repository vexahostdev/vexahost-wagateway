<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Surat percobaan dari halaman Pemberitahuan & Pengecualian.
 *
 * Sengaja TIDAK memakai `Queueable`: yang menekan tombolnya sedang menunggu
 * jawaban, dan email yang masuk antrean akan menjawab "berhasil" sebelum ada
 * satu pun sambungan SMTP dibuka. Itu persis kebalikan dari gunanya tombol
 * ini — kegagalan Brevo (pengirim belum diverifikasi, kredensial salah) hanya
 * terlihat kalau pengirimannya benar-benar terjadi saat itu juga.
 */
class TesEmail extends Mailable
{
    use \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(public readonly string $tipePengirim = 'noreply') {}

    public function envelope(): Envelope
    {
        return $this->buatEnvelope(
            subject: 'Tes email keluar — ' . config('app.name', 'VexaHost WA Gateway'),
            tipePengirim: $this->tipePengirim,
        );
    }

    public function content(): Content
    {
        $sender = $this->tipePengirim === 'support' ? \App\Support\MailIdentity::support() : \App\Support\MailIdentity::noreply();

        return new Content(markdown: 'emails.tes', with: [
            'dikirimPada' => now()->translatedFormat('j F Y, H:i'),
            'pengirim' => $sender['address'],
            'mailer' => config('mail.default'),
        ]);
    }
}
