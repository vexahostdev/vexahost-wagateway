<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Pengingat sebelum masa berlaku habis.
 *
 * Sepenuhnya pelengkap: yang benar-benar memberitahu adalah tagihan yang sudah
 * terbit dan spanduk di dashboard. Tapi pelanggan yang gateway-nya berjalan
 * lancar justru yang paling jarang membuka dashboard — jadi di praktiknya,
 * surat inilah yang sampai.
 *
 * Kalimat tentang nomor yang tetap tertaut wajib ikut. Tanpanya, "layanan
 * berhenti" terbaca seperti nomornya akan diputus dan harus discan ulang, dan
 * itu ketakutan yang sepenuhnya keliru.
 */
class MasaBerlakuHabis extends Mailable
{
    use Queueable, SerializesModels, \App\Mail\Concerns\PakaiIdentitasEmail;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $sisaHari,
    ) {}

    public function envelope(): Envelope
    {
        $nama = $this->subscription->workspace?->name;

        return $this->buatEnvelope(
            subject: match (true) {
                $this->sisaHari <= 0 => "Langganan {$nama} berakhir hari ini",
                $this->sisaHari === 1 => "Langganan {$nama} berakhir besok",
                default => "Langganan {$nama} berakhir {$this->sisaHari} hari lagi",
            },
            tipePengirim: 'noreply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.masa-berlaku-habis', with: [
            'subscription' => $this->subscription,
            'workspace' => $this->subscription->workspace,
            'sisaHari' => $this->sisaHari,
        ]);
    }
}
