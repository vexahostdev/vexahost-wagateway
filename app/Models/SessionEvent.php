<?php

namespace App\Models;

use App\Support\AlasanPutus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Satu kejadian di riwayat koneksi sebuah sesi.
 *
 * Nilai `event`:
 *  - `connected`          — WhatsApp menerima sambungannya
 *  - `disconnected`       — engine melepas sesi; `reason` dari AlasanPutus
 *  - `auth_failure`       — tidak selesai tersambung dalam batas waktu
 *  - `connect_requested`  — Hubungkan ditekan (`manual`) atau penjadwal (`otomatis`)
 *  - `restore_requested`  — engine menyala ulang dan memulihkannya
 *  - `stopped`            — dihentikan dari dashboard, panel, atau penagihan
 *  - `unlinked`           — tautan diputus dari dashboard
 *  - `delivery_low`       — peringatan dini: banyak pesan tidak sampai
 *  - `warmup_skipped`     — pemilik melewati masa pemanasan nomor
 */
class SessionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'wa_session_id',
        'event',
        'reason',
        'status_code',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WaSession::class, 'wa_session_id');
    }

    /**
     * Mencatat kejadian yang sama untuk banyak sesi sekaligus.
     *
     * Riwayat itu pelengkap: kegagalan menulisnya tidak boleh menggagalkan
     * pekerjaan yang sedang dicatat — terutama bootstrap engine, yang tanpa
     * jawaban berarti engine menyala dengan nol sesi.
     *
     * @param  iterable<string>  $sessionIds
     */
    public static function catatBanyak(iterable $sessionIds, string $event, ?string $reason = null): void
    {
        $sekarang = now();
        $baris = [];

        foreach ($sessionIds as $id) {
            $baris[] = [
                'wa_session_id' => $id,
                'event' => $event,
                'reason' => $reason,
                'created_at' => $sekarang,
            ];
        }

        if ($baris === []) {
            return;
        }

        try {
            static::insert($baris);
        } catch (\Throwable $e) {
            Log::warning('Riwayat koneksi sesi gagal dicatat', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    public function label(): string
    {
        return match ($this->event) {
            'connected' => 'Tersambung',
            'disconnected' => 'Terputus — '.AlasanPutus::label($this->reason),
            'auth_failure' => 'Gagal tersambung dalam batas waktu',
            'connect_requested' => $this->reason === 'otomatis' ? 'Disambungkan ulang otomatis' : 'Hubungkan ditekan',
            'restore_requested' => 'Dipulihkan saat engine menyala',
            'stopped' => 'Dihentikan',
            'unlinked' => 'Tautan diputus pemilik',
            'delivery_low' => 'Banyak pesan tidak sampai — tanda awal dibatasi WhatsApp',
            'warmup_skipped' => 'Masa pemanasan dilewati pemilik',
            default => $this->event,
        };
    }

    /** Kejadian yang perlu ditindaklanjuti, untuk diberi warna di riwayat. */
    public function buruk(): bool
    {
        return in_array($this->event, ['disconnected', 'auth_failure', 'delivery_low'], true);
    }
}
