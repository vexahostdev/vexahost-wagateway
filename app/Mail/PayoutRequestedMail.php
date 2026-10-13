<?php

namespace App\Mail;

use App\Models\PayoutRequest;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

class PayoutRequestedMail extends Mailable
{
    use \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(
        public readonly PayoutRequest $payout,
        public readonly bool $isForAdmin = true,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isForAdmin
            ? 'Permintaan Pencairan Komisi Reseller: '.$this->payout->payout_number.' (Rp '.number_format($this->payout->amount, 0, ',', '.').') — '.config('app.name')
            : 'Bukti Pengajuan Pencairan Komisi: '.$this->payout->payout_number.' — '.config('app.name');

        return $this->buatEnvelope(
            subject: $subject,
            tipePengirim: 'noreply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.payout-requested', with: [
            'payout' => $this->payout,
            'user' => $this->payout->user,
            'isForAdmin' => $this->isForAdmin,
            'adminUrl' => route('admin.referrals'),
            'mitraUrl' => route('mitra.payout.invoice', $this->payout->id),
        ]);
    }

    public function attachments(): array
    {
        $filePath = "invoices/payout-{$this->payout->payout_number}.pdf";
        if (Storage::disk('media')->exists($filePath)) {
            return [
                Attachment::fromStorageDisk('media', $filePath)
                    ->as("Invoice-{$this->payout->payout_number}.pdf")
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
