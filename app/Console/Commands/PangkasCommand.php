<?php

namespace App\Console\Commands;

use App\Support\Pemangkas;
use Illuminate\Console\Command;

/**
 * Menjalankan pemangkasan retensi dari tangan, bukan dari penjadwal.
 *
 * Ada dua alasan perintah ini wajib ada, dan yang kedua yang sebenarnya:
 *
 * 1. Memangkas di luar jadwal saat tabel telanjur besar.
 *
 * 2. **`--dry-run`.** Menghapus data pelanggan berdasarkan kueri yang belum
 *    pernah dilihat siapa pun adalah cara termahal untuk menemukan kueri yang
 *    salah — dan penghapusan tidak bisa dibatalkan. Jalankan ini dulu, baca
 *    angkanya, baru hapus.
 */
class PangkasCommand extends Command
{
    protected $signature = 'vexahost:pangkas
        {--dry-run : Hanya melaporkan berapa baris yang akan terhapus, tidak menghapus apa pun}
        {--tabel= : Batasi ke satu tabel saja}';

    protected $description = 'Memangkas data yang sudah lewat masa simpannya, bertahap dan bisa dijalankan kering.';

    public function handle(Pemangkas $pemangkas): int
    {
        $kering = (bool) $this->option('dry-run');
        $tabel = $this->option('tabel');

        if ($tabel !== null && ! in_array($tabel, Pemangkas::tabel(), true)) {
            $this->error("Tabel '{$tabel}' tidak dikelola pemangkas. Yang ada: ".implode(', ', Pemangkas::tabel()));

            return self::FAILURE;
        }

        $this->line($kering
            ? '<comment>Mode kering.</comment> Tidak ada satu baris pun yang akan dihapus.'
            : '<comment>Menghapus sungguhan.</comment> Bertahap '
                .config('gateway.pemangkasan.potongan').' baris, jeda '
                .config('gateway.pemangkasan.jeda_ms').' ms, maksimum '
                .number_format((int) config('gateway.pemangkasan.batas_per_tabel')).' baris per tabel.');

        $this->newLine();

        $mulai = microtime(true);
        $hasil = $pemangkas->jalankan($kering, $tabel);
        $lama = microtime(true) - $mulai;

        $this->table(
            ['Tabel', $kering ? 'Akan terhapus' : 'Terhapus', 'Masa simpan'],
            collect($hasil)->map(fn (int $jumlah, string $nama) => [
                $nama,
                number_format($jumlah),
                $this->masaSimpan($nama),
            ])->values()->all()
        );

        $total = array_sum($hasil);

        $this->line(sprintf(
            '%s %s baris dalam %.1f detik.',
            $kering ? 'Akan terhapus:' : 'Terhapus:',
            number_format($total),
            $lama
        ));

        if ($kering && $total > 0) {
            $this->newLine();
            $this->line('Jalankan tanpa <comment>--dry-run</comment> untuk benar-benar menghapus.');
        }

        return self::SUCCESS;
    }

    /**
     * Ditampilkan berdampingan dengan jumlahnya supaya angka yang mengejutkan
     * bisa langsung dicocokkan dengan aturannya, tanpa membuka config.
     */
    private function masaSimpan(string $tabel): string
    {
        $r = config('gateway.retention');

        return match ($tabel) {
            'messages' => 'mengikuti paket tiap workspace',
            'webhook_deliveries' => "berhasil {$r['webhook_ok_hours']} jam, gagal {$r['webhook_deliveries_days']} hari",
            'audit_logs' => "{$r['audit_days']} hari",
            'notifications' => "{$r['notifications_days']} hari setelah dibaca; belum dibaca tidak pernah dibuang",
            'otp_codes' => '1 hari setelah kedaluwarsa',
            'failed_jobs' => "{$r['failed_jobs_days']} hari",
            'job_batches' => "{$r['job_batches_days']} hari setelah selesai",
            'status_daily' => "{$r['status_daily_days']} hari",
            'session_events' => "{$r['session_events_days']} hari",
            'warmup_counters' => '7 hari setelah harinya lewat',
            'cache' => 'setelah kedaluwarsa',
            'password_reset_tokens' => '1 hari',
            default => '-',
        };
    }
}
