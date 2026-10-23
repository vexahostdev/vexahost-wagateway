import { createHash } from 'node:crypto';
import { readdir } from 'node:fs/promises';
import path from 'node:path';
import { bacaBerkas, namaBerkasSah, nilaiKredensial, tulisUlangKredensial } from '../auth-state.js';
import { config } from '../config.js';
import { laravel } from '../laravel.js';
import { logger } from '../logger.js';

/**
 * Store kredensial Baileys yang menyinkronkan berkas autentikasi ke Laravel.
 *
 * Baileys menyimpan kredensial sebagai kumpulan berkas JSON kecil (creds.json
 * dan serangkaian berkas kunci seperti pre-key-*.json, session-*.json, dll).
 * Store ini membundel seluruh berkas di folder sesi menjadi satu payload JSON
 * terstruktur yang ditandatangani digest SHA-256 untuk disimpan di Laravel.
 */
export class LaravelStore {
    /**
     * Memeriksa apakah backup ada di Laravel.
     * Galat dilempar jika Laravel tidak terjangkau (jangan mengembalikan false
     * karena false memicu start tanpa memulihkan kredensial).
     */
    async sessionExists(sessionOrId) {
        const sessionId = toSessionId(sessionOrId);
        return laravel.backupExists(sessionId);
    }

    /**
     * Menyimpan seluruh berkas state Baileys dari folder sesi ke Laravel.
     *
     * Berkas dibaca di bawah kunci yang sama dengan penulisnya, jadi tidak ada
     * berkas setengah tertulis yang ikut tercadang. Dan kalau creds.json-nya
     * sendiri tidak layak, pencadangan dibatalkan: Laravel hanya menyimpan
     * beberapa cadangan terakhir, dan satu cadangan rusak yang masuk berarti
     * satu cadangan sehat yang terdorong keluar.
     *
     * @returns {Promise<boolean>} apakah cadangan benar-benar terkirim
     */
    async save(sessionOrId) {
        const sessionId = toSessionId(sessionOrId);
        const sessionDir = path.join(config.dataPath, sessionId);

        let entries;
        try {
            entries = await readdir(sessionDir);
        } catch (err) {
            if (err.code === 'ENOENT') {
                logger.warn({ sessionId }, 'Folder sesi tidak ditemukan saat akan menyimpan backup');
                return false;
            }
            throw err;
        }

        const files = {};
        for (const filename of entries) {
            // Berkas sementara dari penulisan atomik yang sedang berjalan
            // bukan bagian dari state — menyalinnya berarti memulihkan sampah.
            if (!namaBerkasSah(filename)) {
                continue;
            }

            const isi = await bacaBerkas(path.join(sessionDir, filename));

            if (isi !== null) {
                files[filename] = isi;
            }
        }

        if (nilaiKredensial(files['creds.json']) !== 'tertaut') {
            logger.warn({ sessionId }, 'Backup dibatalkan: creds.json rusak atau belum tertaut, cadangan lama dibiarkan');

            return false;
        }

        const payload = JSON.stringify({
            version: 1,
            sessionId,
            files,
        });

        const buffer = Buffer.from(payload, 'utf-8');
        const digest = createHash('sha256').update(buffer).digest('hex');

        await laravel.uploadBackup(sessionId, buffer, digest);

        logger.info({ sessionId, bytes: buffer.length, fileCount: Object.keys(files).length }, 'Backup sesi terkirim');

        return true;
    }

    /**
     * Isi cadangan dari Laravel sebagai `{ namaBerkas: isi }`, tanpa menulis
     * apa pun — pemanggil yang memutuskan apakah cadangan itu layak dipakai.
     */
    async ambilBerkas(sessionOrId) {
        const sessionId = toSessionId(sessionOrId);
        const buffer = await laravel.downloadBackup(sessionId);
        const payload = JSON.parse(buffer.toString('utf-8'));

        return payload?.files && typeof payload.files === 'object' ? payload.files : {};
    }

    /**
     * Memulihkan berkas state Baileys dari backup Laravel ke folder sesi.
     */
    async extract(sessionOrId, targetPath) {
        const sessionId = toSessionId(sessionOrId);
        const destDir = targetPath ?? path.join(config.dataPath, sessionId);
        const files = await this.ambilBerkas(sessionId);

        await tulisUlangKredensial(destDir, files);

        logger.info({ sessionId, fileCount: Object.keys(files).length }, 'Backup sesi dipulihkan');
    }

    /**
     * Menghapus backup di Laravel.
     *
     * Folder lokal sengaja TIDAK ikut dihapus di sini. `logout()` memanggil ini
     * di latar belakang sesudah merespons; kalau pelanggan langsung menautkan
     * ulang, penghapusan yang telat akan mengenai folder tautan barunya.
     * `logout()` sudah membuang folder lokalnya sendiri.
     */
    async delete(sessionOrId) {
        const sessionId = toSessionId(sessionOrId);
        await laravel.deleteBackup(sessionId);
    }
}

function toSessionId(session) {
    if (!session) return '';
    if (typeof session === 'object') {
        const raw = session.sessionId ?? session.session ?? '';
        return String(raw).replace(/^RemoteAuth-/, '');
    }
    return String(session).replace(/^RemoteAuth-/, '');
}
