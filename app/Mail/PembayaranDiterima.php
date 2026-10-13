<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Pembayaran dikonfirmasi — surat yang paling ditunggu di seluruh alur.
 *
 * Selama pencocokan pembayaran masih dikerjakan manusia, jarak antara
 * "pelanggan mengirim bukti" dan "layanan menyala lagi" diisi ketidakpastian
 * penuh. Surat ini yang menutupnya, dan karena itu ia harus menyebutkan sampai
 * kapan langganannya berlaku — bukan sekadar "terima kasih".
 */
class PembayaranDiterima extends Mailable
{
    use Queueable, SerializesModels, \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Subscription $subscription,
    ) {}

    public function envelope(): Envelope
    {
        return $this->buatEnvelope(
            subject: "Pembayaran diterima — {$this->invoice->number}",
            tipePengirim: 'noreply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.pembayaran-diterima', with: [
            'invoice' => $this->invoice,
            'subscription' => $this->subscription,
            'workspace' => $this->invoice->workspace,
        ]);
    }
}
