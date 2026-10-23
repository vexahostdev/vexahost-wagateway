<?php

namespace App\Models;

use App\Support\CadanganSesi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SessionBackup extends Model
{
    /**
     * Berapa cadangan terakhir yang disimpan per sesi.
     *
     * Dulu satu, ditimpa tiap lima menit. Satu cadangan rusak — berkas yang
     * terbaca saat sedang ditulis, proses yang mati di tengah unggahan — langsung
     * menggantikan satu-satunya cadangan sehat, dan pemulihan berikutnya
     * memaksa scan ulang. Tiga memberi ruang mundur tanpa menyimpan kunci lama
     * yang sudah tidak berguna.
     */
    public const SIMPAN = 3;

    protected $fillable = [
        'wa_session_id',
        'disk',
        'path',
        'size',
        'checksum',
        'backed_up_at',
    ];

    private ?string $isiTerbaca = null;

    protected function casts(): array
    {
        return [
            'backed_up_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WaSession::class, 'wa_session_id');
    }

    /**
     * Isi cadangan kalau layak dipakai memulihkan; null kalau berkasnya
     * hilang, berformat lama (.zip era Chromium), atau kredensialnya tidak layak.
     */
    public function isiLayak(): ?string
    {
        if ($this->isiTerbaca !== null) {
            return $this->isiTerbaca;
        }

        $disk = Storage::disk($this->disk);

        if (! str_ends_with((string) $this->path, '.json') || ! $disk->exists($this->path)) {
            return null;
        }

        $isi = $disk->get($this->path);

        return CadanganSesi::layak($isi) ? $this->isiTerbaca = $isi : null;
    }

    /**
     * Cadangan terbaru yang layak dipakai untuk sebuah sesi.
     *
     * Yang tidak layak dilewati, bukan menghentikan pencarian: cadangan
     * terbaru yang rusak tidak boleh menyembunyikan cadangan sehat di bawahnya.
     */
    public static function terbaikUntuk(string $sessionId): ?self
    {
        $cadangan = static::where('wa_session_id', $sessionId)
            ->orderByDesc('backed_up_at')
            ->orderByDesc('id')
            ->get();

        foreach ($cadangan as $backup) {
            if ($backup->isiLayak() !== null) {
                return $backup;
            }

            Log::warning('Cadangan sesi dilewati: berkas hilang, format lama, atau kredensialnya tidak layak', [
                'session_id' => $sessionId,
                'path' => $backup->path,
            ]);
        }

        return null;
    }

    /** Membuang berkas dan barisnya sekaligus — baris tanpa berkas menyesatkan, berkas tanpa baris menjadi sampah. */
    public function buang(): void
    {
        Storage::disk($this->disk)->delete($this->path);

        $this->delete();
    }
}
