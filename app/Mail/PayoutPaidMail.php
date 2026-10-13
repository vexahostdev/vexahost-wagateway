<?php

namespace App\Mail;

use App\Models\PayoutRequest;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

class PayoutPaidMail extends Mailable
{
    use \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(
        public readonly PayoutRequest $payout,
    ) {}

    public function envelope(): Envelope
    {
        return $this->buatEnvelope(
            subject: 'Pencairan Komisi Telah Ditransfer: '.$this->payout->payout_number.' — '.config('app.name'),
            tipePengirim: 'noreply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.payout-paid', with: [
            'payout' => $this->payout,
            'user' => $this->payout->user,
            'mitraUrl' => route('mitra.payout.invoice', $this->payout->id),
        ]);
    }

    public function attachments(): array
    {
        $filePath = "invoices/payout-{$this->payout->payout_number}.pdf";
        if (Storage::disk('media')->exists($filePath)) {
            return [
                Attachment::fromStorageDisk('media', $filePath)
                    ->as("Invoice-Lunas-{$this->payout->payout_number}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
