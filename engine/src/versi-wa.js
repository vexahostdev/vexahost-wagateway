import { fetchLatestWaWebVersion } from '@whiskeysockets/baileys';
import { config } from './config.js';
import { logger } from './logger.js';

const ENAM_JAM = 6 * 60 * 60 * 1000;

function versiSah(versi) {
    return Array.isArray(versi) && versi.length === 3 && versi.every((n) => Number.isInteger(n) && n >= 0);
}

/**
 * Versi WhatsApp Web yang dipakai saat handshake.
 *
 * Dulu dipatok di kode (sama dengan bawaan Baileys yang terpasang). Versi
 * yang dipatok tidak pernah bergerak, sementara WhatsApp menaikkan versi
 * minimumnya kapan saja — dan saat itu terjadi, SELURUH sesi seluruh
 * pelanggan gagal menyambung bersamaan dengan "Connection Failure" (405),
 * tanpa ada yang berubah di sisi kita.
 *
 * Sekarang versinya diambil dari WhatsApp Web saat engine menyala dan
 * disegarkan berkala. Kegagalan mengambil tidak pernah menghentikan apa pun:
 * versi terakhir yang diketahui tetap dipakai. `WA_WEB_VERSION` yang diisi
 * mematok versinya — jalan keluar kalau versi terbaru justru bermasalah.
 */
export class VersiWhatsApp {
    #versi;
    #dipatok;
    #ambil;
    #segarSetiapMs;
    #terakhirDisegarkan = 0;
    #berjalan = null;

    constructor({
        awal = config.waWebVersion,
        dipatok = config.waWebVersionDipatok,
        ambil = () => fetchLatestWaWebVersion({ signal: AbortSignal.timeout(10_000) }),
        segarSetiapMs = ENAM_JAM,
    } = {}) {
        this.#versi = awal;
        this.#dipatok = dipatok;
        this.#ambil = ambil;
        this.#segarSetiapMs = segarSetiapMs;
    }

    sekarang() {
        return this.#versi;
    }

    /**
     * @param {{ paksa?: boolean }} opsi  `paksa` mengabaikan jeda penyegaran —
     *   dipakai saat WhatsApp baru saja menolak versi yang sedang dipakai.
     */
    async segarkan({ paksa = false } = {}) {
        if (this.#dipatok) {
            return this.#versi;
        }

        if (!paksa && Date.now() - this.#terakhirDisegarkan < this.#segarSetiapMs) {
            return this.#versi;
        }

        // Beberapa sesi yang ditolak bersamaan cukup memicu satu permintaan.
        this.#berjalan ??= this.#ambilTerbaru().finally(() => {
            this.#terakhirDisegarkan = Date.now();
            this.#berjalan = null;
        });

        return this.#berjalan;
    }

    async #ambilTerbaru() {
        try {
            const hasil = await this.#ambil();

            if (hasil?.isLatest && versiSah(hasil.version)) {
                if (hasil.version.join('.') !== this.#versi.join('.')) {
                    logger.info(
                        { sebelumnya: this.#versi.join('.'), sekarang: hasil.version.join('.') },
                        'Versi WhatsApp Web diperbarui',
                    );
                }

                this.#versi = hasil.version;
            } else {
                logger.warn(
                    { err: hasil?.error?.message, dipakai: this.#versi.join('.') },
                    'Versi WhatsApp Web terbaru tidak terbaca; versi terakhir tetap dipakai',
                );
            }
        } catch (error) {
            logger.warn(
                { err: error.message, dipakai: this.#versi.join('.') },
                'Gagal mengambil versi WhatsApp Web terbaru; versi terakhir tetap dipakai',
            );
        }

        return this.#versi;
    }
}
