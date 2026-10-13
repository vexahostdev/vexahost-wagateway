<?php

namespace App\Mail\Concerns;

use App\Support\MailIdentity;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

trait PakaiIdentitasEmail
{
    /**
     * Buat instance Envelope dengan pengirim eksplisit dan Reply-To seragam.
     *
     * @param string $subject
     * @param string $tipePengirim 'noreply' atau 'support'
     * @param array $tags
     * @param string|null $using
     * @return Envelope
     */
    protected function buatEnvelope(
        string $subject,
        string $tipePengirim = 'noreply',
        array $tags = [],
        ?string $using = null
    ): Envelope {
        $pengirim = $tipePengirim === 'support' ? MailIdentity::support() : MailIdentity::noreply();
        $replyTo = MailIdentity::replyTo();

        return new Envelope(
            from: new Address($pengirim['address'], $pengirim['name']),
            replyTo: [new Address($replyTo['address'], $replyTo['name'])],
            subject: $subject,
            tags: $tags,
            using: $using ? [$using] : [],
        );
    }
}
