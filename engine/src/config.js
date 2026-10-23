import process from 'node:process';

function required(name) {
    const value = process.env[name];

    if (!value) {
        // Sengaja gagal saat boot, bukan saat request pertama: engine yang
        // berjalan tanpa token/secret akan menerima callback tak bertanda
        // tangan dan menolak semua permintaan Laravel tanpa penjelasan.
        throw new Error(`Environment variable ${name} wajib diisi.`);
    }

    return value;
}

/**
 * Angka opsional. String kosong diperlakukan sama dengan tidak diisi — di
 * Coolify variabel yang "dikosongkan" tetap terkirim ke container sebagai
 * string kosong, dan Number('') menghasilkan 0.
 */
function angka(name, bawaan) {
    const value = process.env[name];

    if (value === undefined || value === '') return bawaan;

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : bawaan;
}

/**
 * Versi WhatsApp Web untuk handshake Baileys. Nilai dapat berupa string dipisahkan titik atau koma,
 * misalnya '2.3000.1043857760' atau '2,3000,1043857760'.
 */
export function parseWaWebVersion(raw, bawaan = [2, 3000, 1043857760]) {
    if (!raw) return bawaan;
    if (Array.isArray(raw) && raw.length === 3 && raw.every((n) => Number.isFinite(Number(n)))) {
        return raw.map(Number);
    }
    const parts = String(raw).split(/[.,]/).map((p) => parseInt(p.trim(), 10));
    if (parts.length === 3 && parts.every((n) => Number.isFinite(n))) {
        return parts;
    }
    return bawaan;
}

/**
 * Sejak engine dan Laravel berbagi satu container (lihat ../../start.sh),
 * seluruh variabel env-nya berada di satu ruang nama yang sama. Tiga nama
 * bertabrakan dan harus dibedakan:
 *
 *   PORT / HOST  → dipakai Nixpacks & Coolify untuk proses web
 *   LOG_LEVEL    → Laravel memakainya dengan skala nilai yang berbeda
 *                  (`error` di produksi; di engine itu berarti kehilangan baris
 *                  "Backup sesi terkirim" yang jadi patokan uji regresi)
 *
 * Nama lama tetap diterima sebagai cadangan supaya engine masih bisa
 * dijalankan sendiri saat pengembangan lokal (`npm --prefix engine run dev`
 * membaca engine/.env yang masih memakai PORT/HOST/LOG_LEVEL).
 */
export const config = {
    port: angka('ENGINE_PORT', angka('PORT', 3100)),

    // Bawaannya tetap 0.0.0.0 supaya engine yang dijalankan sendiri tetap bisa
    // dijangkau. Dalam satu container, env-nya menyetel 127.0.0.1 — tidak ada
    // yang perlu menjangkau engine dari luar container, dan port yang tidak
    // mendengarkan di jaringan internal adalah port yang tidak bisa disalahgunakan.
    host: process.env.ENGINE_HOST || process.env.HOST || '0.0.0.0',

    // Token statis yang harus disertakan Laravel di header X-Engine-Token.
    engineToken: required('ENGINE_TOKEN'),

    // Secret HMAC untuk menandatangani callback engine -> Laravel.
    hmacSecret: required('ENGINE_HMAC_SECRET'),

    laravelUrl: required('LARAVEL_URL').replace(/\/+$/, ''),

    // Persistent volume Coolify. Inilah yang membuat sesi selamat dari redeploy.
    dataPath: process.env.WA_DATA_PATH ?? '/data/sessions',

    // Interval backup kredensial sesi ke Laravel.
    backupIntervalMs: Math.max(60_000, angka('WA_BACKUP_INTERVAL_MS', 300_000)),

    // Jeda acak antar pesan keluar dalam satu sesi (anti-ban).
    minDelayMs: angka('WA_MIN_DELAY_MS', 3000),
    maxDelayMs: angka('WA_MAX_DELAY_MS', 8000),

    /**
     * Batas sesi aktif per container.
     *
     * Bawaannya 3, sesuai kapasitas teruji. Dengan migrasi Baileys, setiap sesi
     * hanya memakan websocket dan state memori tanpa Chromium.
     */
    maxSessions: angka('WA_MAX_SESSIONS', 3),

    /**
     * Batas waktu satu sesi boleh berada di tahap inisialisasi.
     * Melepas pengukur saat QR diterbitkan atau sesi berhasil tersambung.
     */
    initTimeoutMs: angka('WA_INIT_TIMEOUT_MS', 180_000),

    /**
     * Versi protokol WhatsApp Web untuk handshake Baileys — titik awal saja.
     * Engine menyegarkannya sendiri dari WhatsApp Web (lihat versi-wa.js),
     * KECUALI `WA_WEB_VERSION` diisi (format '2.3000.1043857760'): nilai itu
     * dipatok dan tidak pernah diganti.
     */
    waWebVersion: parseWaWebVersion(process.env.WA_WEB_VERSION),

    // Nilai yang tidak bisa dibaca tidak dihitung sebagai patokan. Kalau
    // dihitung, satu salah ketik di env diam-diam mematikan penyegaran versi.
    waWebVersionDipatok: parseWaWebVersion(process.env.WA_WEB_VERSION, null) !== null,

    logLevel: process.env.ENGINE_LOG_LEVEL || process.env.LOG_LEVEL || 'info',
};

