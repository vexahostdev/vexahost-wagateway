<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tagihan baru terbit.
 *
 * Satu-satunya peristiwa penagihan yang **tidak** punya pasangan di WhatsApp,
 * dan itu disengaja: tagihan perpanjangan terbit tiga hari sebelum masa
 * berlaku habis, di hari yang sama pengingat H-3 dikirim. Dua pesan WhatsApp
 * beruntun tentang uang yang sama terbaca seperti penagihan ganda. Email
 * berbeda — di sanalah orang memang mencari lampiran dan nominal.
 */
class TagihanTerbit extends Mailable
{
    use Queueable, SerializesModels, \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(public readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return $this->buatEnvelope(
            subject: "Tagihan {$this->invoice->number} — ".$this->invoice->workspace?->name,
            tipePengirim: 'noreply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.tagihan-terbit', with: [
            'invoice' => $this->invoice,
            'workspace' => $this->invoice->workspace,
        ]);
    }
}
