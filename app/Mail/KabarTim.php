<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Kabar untuk tim kami sendiri, mengiringi pemberitahuan WhatsApp.
 *
 * Satu kelas untuk seluruh jenis kabar, bukan satu kelas per peristiwa, dan
 * itu pilihan sadar: isinya sudah disusun rapi oleh `BillingMessages` dan
 * `HelpdeskMessages` yang menjadi sumber tunggal nada seluruh produk. Membuat
 * mailable terpisah untuk masing-masing berarti teks yang sama ditulis dua
 * kali, lalu menyimpang.
 *
 * Yang dilakukan kelas ini cuma memindahkan teks itu ke email — termasuk
 * membuang penanda tebal WhatsApp (`*teks*`) yang di email cuma terbaca
 * sebagai tanda bintang nyasar.
 */
class KabarTim extends Mailable
{
    use Queueable, SerializesModels, \App\Mail\Concerns\PakaiIdentitasEmail;

    public readonly string $isi;

    public function __construct(
        public readonly string $judul,
        string $isi,
        public readonly ?string $tautan = null,
        public readonly ?string $labelTautan = null,
    ) {
        /*
         | Penanda tebal WhatsApp dibuang SEKALI di sini, bukan saat merender.
         |
         | Properti publik sebuah Mailable ikut dibagikan ke view-nya, jadi
         | membersihkannya di `content()` menghasilkan dua nilai untuk nama yang
         | sama — dan yang menang bukan yang dikira. Dibersihkan di constructor,
         | tidak ada versi kedua yang bisa bocor.
         |
         | Dibuang, bukan diterjemahkan ke markdown: teks ini disusun untuk
         | dibaca di WhatsApp, dan menebalkan separuh kalimatnya di email
         | membuat penekanannya jatuh di tempat yang salah.
        */
        $this->isi = preg_replace('/\*(.+?)\*/u', '$1', $isi) ?? $isi;
    }

    public function envelope(): Envelope
    {
        return $this->buatEnvelope(
            subject: '['.config('app.name').'] '.$this->judul,
            tipePengirim: 'noreply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.kabar-tim', with: [
            'labelTautan' => $this->labelTautan ?: 'Buka panel admin',
        ]);
    }
}
