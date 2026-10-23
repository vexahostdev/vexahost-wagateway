<?php

namespace App\Support;

/**
 * Menilai isi cadangan kredensial sesi.
 *
 * Aturannya harus sama persis dengan `nilaiKredensial()` di
 * engine/src/auth-state.js: engine menolak memakai cadangan yang tidak lolos
 * aturan itu, jadi Laravel yang lebih longgar akan menyimpan, memilih, dan
 * mengirim cadangan yang pasti ditolak — sementara cadangan yang layak di
 * baris lain terlewat.
 */
final class CadanganSesi
{
    /**
     * Apakah payload cadangan memuat creds.json yang bisa dipakai menyambung
     * tanpa scan QR: kunci perangkat lengkap DAN sudah tertaut ke sebuah nomor.
     */
    public static function layak(?string $payload): bool
    {
        if ($payload === null || $payload === '' || ! mb_check_encoding($payload, 'UTF-8')) {
            return false;
        }

        $isi = json_decode($payload, true);
        $creds = is_array($isi) && is_array($isi['files'] ?? null) ? ($isi['files']['creds.json'] ?? null) : null;

        if (! is_string($creds)) {
            return false;
        }

        $creds = json_decode($creds, true);

        return is_array($creds)
            && ! empty($creds['noiseKey'])
            && ! empty($creds['signedIdentityKey'])
            && is_string($creds['me']['id'] ?? null)
            && $creds['me']['id'] !== '';
    }
}
