<?php

namespace App\Support;

use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pemangkas retensi. Satu tempat untuk seluruh penghapusan berjadwal.
 *
 * Tiga aturan yang berlaku untuk SETIAP tabel di sini, dan ketiganya lahir dari
 * satu hal yang sama — MySQL ini dipakai bersama aplikasi vexahost:
 *
 * 1. **Tidak ada DELETE tanpa batas.** Satu DELETE atas ratusan ribu baris
 *    menahan kunci dan menggelembungkan undo log untuk SELURUH aplikasi di
 *    server itu, dan gejalanya muncul di tempat yang tidak ada hubungannya
 *    dengan gateway ini. Dipotong 1.000 baris, dengan jeda di antaranya.
 *
 * 2. **Ada batas total per jalan.** Sisanya diambil jalan berikutnya.
 *    Pemangkasan yang berjalan berjam-jam adalah pemangkasan yang bertabrakan
 *    dengan jam sibuk.
 *
 * 3. **Bisa dijalankan tanpa menghapus apa pun.** `--dry-run` melaporkan berapa
 *    baris yang AKAN terhapus. Menghapus data pelanggan berdasarkan kueri yang
 *    belum pernah dilihat siapa pun adalah cara termahal menemukan kueri yang
 *    salah.
 *
 * Yang TIDAK pernah disentuh, dan alasannya bukan teknis: invoices,
 * subscriptions, balance_transactions, usage_counters, referral_*, payout_*.
 * Itu catatan keuangan. `usage_counters` punya alasan tambahan — ia satu-satunya
 * sumber `Workspace::freeMessagesUsed()`, jadi memangkasnya mengubah paket coba
 * gratis menjadi gratis selamanya.
 */
class Pemangkas
{
    private bool $dryRun = false;

    /**
     * @param  string|null  $hanya  nama tabel tunggal, atau null untuk semuanya
     * @return array<string, int> jumlah baris terhapus (atau akan terhapus) per tabel
     */
    public function jalankan(bool $dryRun = false, ?string $hanya = null): array
    {
        $this->dryRun = $dryRun;

        $tugas = [
            'messages' => fn () => $this->pesan(),

            'webhook_deliveries' => fn () => $this->kirimanWebhook(),

            'audit_logs' => fn () => $this->potong(
                'audit_logs',
                'id',
                fn (Builder $q) => $q->where('created_at', '<', now()->subDays($this->hari('audit_days')))
            ),

            // Yang BELUM dibaca tidak pernah dibuang, berapa pun umurnya: kabar
            // yang hilang sebelum sempat dilihat adalah persis kegagalan yang
            // lonceng ini dibuat untuk mencegahnya.
            'notifications' => fn () => $this->potong(
                'notifications',
                'id',
                fn (Builder $q) => $q->whereNotNull('read_at')
                    ->where('read_at', '<', now()->subDays($this->hari('notifications_days')))
            ),

            'otp_codes' => fn () => $this->potong(
                'otp_codes',
                'id',
                fn (Builder $q) => $q->where('expires_at', '<', now()->subDay())
            ),

            'failed_jobs' => fn () => $this->potong(
                'failed_jobs',
                'id',
                fn (Builder $q) => $q->where('failed_at', '<', now()->subDays($this->hari('failed_jobs_days')))
            ),

            'job_batches' => fn () => $this->potong(
                'job_batches',
                'id',
                fn (Builder $q) => $q->whereNotNull('finished_at')
                    ->where('finished_at', '<', now()->subDays($this->hari('job_batches_days'))->getTimestamp())
            ),

            'status_daily' => fn () => $this->potong(
                'status_daily',
                'id',
                fn (Builder $q) => $q->where('day', '<', now()->subDays($this->hari('status_daily_days'))->toDateString())
            ),

            'session_events' => fn () => $this->potong(
                'session_events',
                'id',
                fn (Builder $q) => $q->where('created_at', '<', now()->subDays($this->hari('session_events_days')))
            ),

            // Penghitung harian masa pemanasan hanya dibaca untuk hari ini dan
            // hari-hari ke depan; yang sudah lewat tidak berarti apa-apa lagi.
            'warmup_counters' => fn () => $this->potong(
                'warmup_counters',
                'id',
                fn (Builder $q) => $q->where('day', '<', now()->subDays(7)->toDateString())
            ),

            // Driver cache database TIDAK pernah membuang kunci kedaluwarsa yang
            // kebetulan tidak pernah dibaca lagi — dan sebagian besar kunci di
            // sini memang begitu: penanda dedupe notifikasi, penanda
            // `apikey:*:touched`, kunci `withoutOverlapping` milik penjadwal.
            'cache' => fn () => $this->potong(
                'cache',
                'key',
                fn (Builder $q) => $q->where('expiration', '<', now()->getTimestamp())
            ),

            'password_reset_tokens' => fn () => $this->potong(
                'password_reset_tokens',
                'email',
                fn (Builder $q) => $q->where('created_at', '<', now()->subDay())
            ),
        ];

        $hasil = [];

        foreach ($tugas as $tabel => $kerja) {
            if ($hanya !== null && $hanya !== $tabel) {
                continue;
            }

            $hasil[$tabel] = $kerja();
        }

        return $hasil;
    }

    /** @return array<int, string> */
    public static function tabel(): array
    {
        return [
            'messages', 'webhook_deliveries', 'audit_logs', 'notifications', 'otp_codes',
            'failed_jobs', 'job_batches', 'status_daily', 'session_events', 'warmup_counters', 'cache',
            'password_reset_tokens',
        ];
    }

    private function hari(string $kunci): int
    {
        return (int) config("gateway.retention.{$kunci}");
    }

    /**
     * Riwayat pesan — satu-satunya data BISNIS di daftar ini.
     *
     * Disapu per workspace, bukan sekali dengan satu batas untuk semua: retensi
     * adalah salah satu yang dibeli pelanggan saat naik paket, dan satu angka
     * global akan menghapus riwayat 12 bulan milik pelanggan Elite.
     *
     * Berkas media dihapus lebih dulu, per potongan, baru barisnya. Menghapus
     * baris duluan berarti kehilangan satu-satunya petunjuk berkas mana yang
     * harus dibuang — dan disk terisi lampiran yang tidak ditunjuk apa pun,
     * tanpa gejala sampai disk penuh.
     */
    private function pesan(): int
    {
        $total = 0;

        Workspace::withTrashed()->select(['id', 'is_internal', 'plan_slug'])
            ->with('subscription:id,workspace_id,plan_slug')
            ->chunkById(100, function ($workspaces) use (&$total): void {
                foreach ($workspaces as $workspace) {
                    $batas = now()->subDays($workspace->messageRetentionDays());

                    $total += $this->potong(
                        'messages',
                        'id',
                        fn (Builder $q) => $q->where('workspace_id', $workspace->id)
                            ->where('created_at', '<', $batas),
                        function (array $ids): void {
                            Message::whereIn('id', $ids)
                                ->whereNotNull('media_path')
                                ->pluck('media_path')
                                ->each(fn ($jalur) => Storage::disk('media')->delete($jalur));
                        }
                    );
                }
            });

        return $total;
    }

    /**
     * Kiriman webhook, dua aturan dalam satu tabel.
     *
     * Yang BERHASIL dibuang setelah 48 jam: tidak pernah ada yang membukanya,
     * dan sebagian besar baris di tabel ini memang berhasil. Yang GAGAL bertahan
     * lebih lama karena justru itu yang dicari orang saat integrasinya rusak —
     * tapi tetap dalam hitungan hari, bukan bulan.
     *
     * Tabel ini yang tumbuh paling cepat di seluruh sistem: satu pesan keluar
     * menghasilkan sampai empat kejadian (`sent` ditambah tiga tingkat ack), dan
     * `DeliverWebhookJob` membuat satu baris per PERCOBAAN, sampai tiga kali.
     */
    private function kirimanWebhook(): int
    {
        $sukses = $this->potong(
            'webhook_deliveries',
            'id',
            fn (Builder $q) => $q->whereNotNull('delivered_at')
                ->where('created_at', '<', now()->subHours((int) config('gateway.retention.webhook_ok_hours')))
        );

        $gagal = $this->potong(
            'webhook_deliveries',
            'id',
            fn (Builder $q) => $q->whereNull('delivered_at')
                ->where('created_at', '<', now()->subDays($this->hari('webhook_deliveries_days')))
        );

        return $sukses + $gagal;
    }

    /**
     * Penghapusan bertahap. Inti dari seluruh kelas ini.
     *
     * Kunci baris dikumpulkan lebih dulu lalu dihapus dengan `whereIn`, bukan
     * `DELETE ... LIMIT`. Alasannya bukan gaya: `DELETE ... LIMIT` tidak
     * didukung SQLite, dan seluruh uji berjalan di SQLite — mekanisme
     * penghapusan yang tidak bisa diuji adalah mekanisme yang salahnya baru
     * ketahuan di produksi.
     *
     * @param  \Closure(array<int, mixed>): void|null  $sebelumHapus  dijalankan per potongan, sebelum barisnya hilang
     */
    private function potong(string $tabel, string $kunci, \Closure $saring, ?\Closure $sebelumHapus = null): int
    {
        $kueri = fn (): Builder => $saring(DB::table($tabel));

        if ($this->dryRun) {
            return $kueri()->count();
        }

        $potongan = (int) config('gateway.pemangkasan.potongan');
        $jeda = (int) config('gateway.pemangkasan.jeda_ms') * 1000;
        $batas = (int) config('gateway.pemangkasan.batas_per_tabel');

        $total = 0;

        while ($total < $batas) {
            $ids = $kueri()->limit(min($potongan, $batas - $total))->pluck($kunci)->all();

            if ($ids === []) {
                break;
            }

            if ($sebelumHapus !== null) {
                $sebelumHapus($ids);
            }

            $terhapus = DB::table($tabel)->whereIn($kunci, $ids)->delete();

            $total += $terhapus;

            // Kalau tidak ada yang benar-benar terhapus, saringannya tidak cocok
            // dengan kunci yang dikumpulkan. Berhenti, daripada berputar selamanya.
            if ($terhapus === 0) {
                break;
            }

            if ($jeda > 0) {
                usleep($jeda);
            }
        }

        return $total;
    }
}
