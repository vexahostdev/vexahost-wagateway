<?php

namespace App\Support;

/**
 * Arti tiap alasan putus yang dilaporkan engine — dan apa akibatnya.
 *
 * Satu-satunya tempat alasan putus diterjemahkan, untuk tiga pembaca
 * sekaligus: kartu sesi pelanggan (`last_error`), pemberitahuan WhatsApp, dan
 * riwayat koneksi di panel admin. Sebelum ini `last_error` diisi kode mentah
 * dari engine — `logged_out`, `403`, `440` — dan tampil merah di kartu sesi
 * tanpa memberi tahu apa yang terjadi pada nomornya atau apa yang harus
 * dilakukan.
 *
 * Nilainya ditetapkan engine (engine/src/session-manager.js, `golonganPutus`
 * dan `start()`); menambah alasan baru di sana berarti menambahkannya di sini.
 */
final class AlasanPutus
{
    /** 401 — WhatsApp mencabut perangkat tertaut ini. */
    public const LOGGED_OUT = 'logged_out';

    /** 403 — WhatsApp menolak akunnya: diblokir atau dibatasi. */
    public const BANNED = 'banned';

    /** 440 — klien lain tersambung dengan kredensial yang sama. */
    public const REPLACED = 'replaced';

    /** Jalur otomatis tidak menemukan kredensial yang bisa dipulihkan. */
    public const CREDENTIALS_MISSING = 'credentials_missing';

    /** Gangguan sementara yang tidak pulih setelah beberapa percobaan. */
    public const CONNECTION_LOST = 'connection_lost';

    /**
     * Nomor ini hanya bisa kembali lewat scan QR baru.
     *
     * Sesinya dikeluarkan dari pemulihan otomatis dengan mengosongkan
     * `connected_at`: penjadwal dan bootstrap yang mencoba lagi tanpa
     * kredensial hanya memunculkan QR yang tidak ditatap siapa pun.
     */
    public static function perluTautUlang(?string $alasan): bool
    {
        return in_array($alasan, [self::LOGGED_OUT, self::CREDENTIALS_MISSING], true);
    }

    /**
     * Cadangan kredensial sudah tidak berlaku dan wajib dibuang.
     *
     * Hanya untuk perangkat yang dicabut WhatsApp. Membiarkan cadangannya
     * berarti tombol Hubungkan memulihkan kunci mati, ditolak 401 lagi, dan QR
     * tidak pernah muncul. `credentials_missing` sengaja tidak termasuk: di sana
     * yang hilang kredensial di volume, dan cadangan yang ada (kalau ada) tidak
     * terbukti mati.
     */
    public static function cadanganMati(?string $alasan): bool
    {
        return $alasan === self::LOGGED_OUT;
    }

    /**
     * Penjadwal tidak boleh mencoba menyambung lagi sendiri.
     *
     * Akun yang ditolak WhatsApp tidak membaik dengan dicoba login tiap menit —
     * percobaannya justru menambah sinyal buruk. Perangkat yang diambil alih
     * akan direbut balik, lalu direbut lagi, tanpa akhir — kecuali satu
     * percobaan untuk kejadian pertama dalam sehari, lihat
     * SyncSessionStatusJob::cobaSekaliLagi(). Kredensialnya tetap disimpan;
     * tombol Hubungkan tetap bekerja.
     */
    public static function hentikanOtomatis(?string $alasan): bool
    {
        return in_array($alasan, [self::BANNED, self::REPLACED], true);
    }

    /**
     * Kalimat untuk pemilik nomor. Selalu menyebut apa yang terjadi pada
     * nomornya dan apa yang bisa dilakukan.
     */
    public static function kalimat(?string $alasan): string
    {
        return match ($alasan) {
            self::LOGGED_OUT => 'WhatsApp mengeluarkan perangkat ini dari nomor Anda — biasanya karena tautannya '
                .'dihapus dari menu Perangkat tertaut di ponsel, WhatsApp di ponsel dipasang ulang, atau ponselnya '
                .'lama tidak online. Tekan Hubungkan lalu scan kode QR untuk menautkannya lagi.',

            self::BANNED => 'WhatsApp menolak sambungan nomor ini — biasanya karena nomornya diblokir atau dibatasi. '
                .'Buka WhatsApp di ponsel untuk melihat keterangannya. Kami berhenti mencoba menyambung supaya '
                .'tidak memperburuk keadaan; tekan Hubungkan setelah nomornya pulih.',

            self::REPLACED => 'Sambungan nomor ini diambil alih oleh perangkat lain yang memakai kredensial yang sama. '
                .'Kami mencobanya sekali lagi otomatis dalam beberapa menit; kalau diambil alih lagi, kami berhenti '
                .'supaya keduanya tidak saling memutus — tekan Hubungkan untuk menyambungkannya kembali di sini.',

            self::CREDENTIALS_MISSING => 'Kredensial nomor ini tidak bisa dipulihkan, jadi nomornya perlu ditautkan '
                .'ulang. Tekan Hubungkan lalu scan kode QR. Riwayat pesan dan API key tidak terpengaruh.',

            default => 'Sambungan ke WhatsApp terputus berulang kali. Kami mencoba menyambungkannya lagi otomatis; '
                .'kalau tidak pulih dalam beberapa menit, tekan Hubungkan.',
        };
    }

    /** Label singkat untuk riwayat koneksi di panel admin. */
    public static function label(?string $alasan): string
    {
        return match ($alasan) {
            self::LOGGED_OUT => 'dikeluarkan WhatsApp',
            self::BANNED => 'ditolak WhatsApp (diblokir/dibatasi)',
            self::REPLACED => 'diambil alih perangkat lain',
            self::CREDENTIALS_MISSING => 'kredensial tidak bisa dipulihkan',
            self::CONNECTION_LOST => 'koneksi hilang berulang',
            null => 'alasan tidak dilaporkan',
            default => $alasan,
        };
    }
}
