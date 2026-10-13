<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tiket pelanggan sudah dibalas.
 *
 * Isi balasannya sengaja TIDAK ikut, sama seperti di pemberitahuan WhatsApp:
 * tiket bisa memuat apa saja yang ditempelkan pelanggan saat melaporkan masalah,
 * termasuk kunci API mereka sendiri. Email diteruskan dan diarsipkan di tempat
 * yang tidak kami kendalikan; yang perlu disampaikan cuma bahwa ada jawaban.
 */
class TiketDibalas extends Mailable
{
    use Queueable, SerializesModels, \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(public readonly Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return $this->buatEnvelope(
            subject: "Tiket #{$this->ticket->id} sudah dibalas — {$this->ticket->subject}",
            tipePengirim: 'support',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.tiket-dibalas', with: [
            'ticket' => $this->ticket,
            'workspace' => $this->ticket->workspace,
        ]);
    }
}
