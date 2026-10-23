<?php

namespace App\Jobs;

use App\Models\WaSession;
use App\Services\Notifications\BillingMessages;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\WhatsAppNotifier;
use App\Services\RemPengiriman;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Peringatan dini untuk nomor yang mulai dibatasi WhatsApp.
 *
 * Pembatasan hampir tidak pernah memutus sambungan: nomornya tetap
 * `connected`, pesannya tetap diterima server WhatsApp (centang satu), tapi
 * tidak diteruskan ke penerima. Tanpa pemantauan ini, pemiliknya baru tahu
 * dari pelanggannya sendiri — atau saat nomornya sudah diblokir penuh.
 *
 * Hanya MENGABARI, tidak menghentikan apa pun. Penerima yang ponselnya mati
 * berhari-hari menghasilkan centang satu yang sah, dan rem yang memutus
 * notifikasi pelanggan karena salah baca jauh lebih merugikan daripada kabar
 * yang ternyata tidak perlu.
 */
class PantauNomorJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(RemPengiriman $rem): void
    {
        $ambang = (float) config('gateway.rem.kesehatan_ambang_tertahan');

        WaSession::query()
            ->with('workspace')
            ->where('status', 'connected')
            ->each(function (WaSession $session) use ($rem, $ambang): void {
                try {
                    $hasil = $rem->periksaKesehatan($session);

                    if ($hasil === null || $ambang > $hasil['tertahan'] / $hasil['total']) {
                        return;
                    }

                    $this->kabari($session, $hasil['total'], $hasil['tertahan']);
                } catch (\Throwable $e) {
                    // Satu nomor yang gagal diperiksa tidak boleh menahan yang lain.
                    Log::warning('Pemeriksaan kesehatan nomor gagal', [
                        'session_id' => $session->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function kabari(WaSession $session, int $total, int $tertahan): void
    {
        if (! $session->workspace) {
            return;
        }

        // Satu kabar per nomor per hari: job ini berjalan tiap jam, dan keadaan
        // seperti ini tidak pulih dalam satu jam.
        $penanda = "nomor-tertahan:{$session->id}:".now()->toDateString();
        $persen = (int) round($tertahan / $total * 100);
        $jam = (int) config('gateway.rem.kesehatan_jam_tunggu');

        $dibuat = app(Notifier::class)->keWorkspace(
            workspace: $session->workspace,
            type: 'session.delivery_low',
            title: "Pesan dari nomor {$session->name} banyak yang tidak sampai",
            body: "{$tertahan} dari {$total} pesan ({$persen}%) belum sampai ke ponsel penerima setelah {$jam} jam. "
                .'Ini tanda paling awal nomor sedang dibatasi WhatsApp. Kurangi pengiriman ke nomor yang belum '
                .'pernah menghubungi Anda, dan buka WhatsApp di ponsel untuk melihat apakah ada peringatan.',
            url: route('sessions.index', absolute: false),
            level: 'warning',
            dedupe: $penanda,
        );

        app(WhatsAppNotifier::class)->toWorkspace(
            $session->workspace,
            BillingMessages::deliveryLow($session, $total, $tertahan, $jam),
            $penanda,
            24,
        );

        if ($dibuat > 0) {
            $session->catatKejadian('delivery_low', null, null, "{$tertahan} dari {$total} pesan belum sampai setelah {$jam} jam");
        }
    }
}
