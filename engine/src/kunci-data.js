import { randomUUID } from 'node:crypto';
import { mkdir, readFile, rm, stat, utimes, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { logger } from './logger.js';

/**
 * Hak tunggal atas folder data sesi.
 *
 * Coolify menjalankan container baru SEBELUM menghentikan yang lama (rolling
 * update, karena aplikasi ini tanpa port mapping dan healthcheck-nya mati),
 * dan keduanya memasang volume `/data` yang sama. Tanpa kunci ini, selama
 * tumpang tindih itu dua engine memegang kredensial yang sama:
 *
 *  - WhatsApp hanya mengizinkan satu sambungan per perangkat tertaut. Engine
 *    yang menyambung belakangan menendang yang lain (kode 440), dan sambung
 *    ulang otomatis membuat keduanya saling tendang — pola login beruntun dari
 *    dua alamat yang paling cepat membuat WhatsApp mencabut perangkatnya.
 *  - Dua proses menulis berkas kunci yang sama tanpa saling tahu. Kunci per
 *    berkas di auth-state.js hanya berlaku di dalam satu proses.
 *
 * Kuncinya berkas berisi identitas pemegang, dengan detak berupa waktu ubah
 * yang diperbarui berkala. Pemegang yang mati tanpa sempat melepas (SIGKILL,
 * OOM) dikenali dari detaknya yang berhenti.
 */
export class KunciData {
    #berkas;
    #identitas;
    #detak = null;
    #basiMs;
    #detakMs;
    #maksTungguMs;
    #jedaMs;

    constructor({
        folder,
        basiMs = 30_000,
        detakMs = 5_000,
        maksTungguMs = 180_000,
        jedaMs = 1_000,
    } = {}) {
        this.#berkas = path.join(folder, '.engine.lock');
        this.#identitas = {
            id: randomUUID(),
            pid: process.pid,
            host: os.hostname(),
            sejak: new Date().toISOString(),
        };
        this.#basiMs = basiMs;
        this.#detakMs = detakMs;
        this.#maksTungguMs = maksTungguMs;
        this.#jedaMs = jedaMs;
    }

    get berkas() {
        return this.#berkas;
    }

    async ambil() {
        await mkdir(path.dirname(this.#berkas), { recursive: true });

        const mulai = Date.now();
        let sudahDilaporkan = false;

        for (;;) {
            try {
                await writeFile(this.#berkas, JSON.stringify(this.#identitas), { flag: 'wx' });
                this.#pasangDetak();

                if (sudahDilaporkan) {
                    logger.info({ menungguMs: Date.now() - mulai }, 'Kunci data sesi diperoleh');
                }

                return;
            } catch (error) {
                if (error.code !== 'EEXIST') {
                    throw error;
                }
            }

            const pemegang = await this.#bacaPemegang();

            // Dilepas di antara dua langkah di atas — langsung coba lagi.
            if (pemegang === null) {
                continue;
            }

            const alasan = this.#alasanAmbilAlih(pemegang, Date.now() - mulai);

            if (alasan !== null) {
                logger.warn({ alasan, pemegang: pemegang.data }, 'Mengambil alih kunci data sesi dari pemegang sebelumnya');
                await rm(this.#berkas, { force: true });

                continue;
            }

            if (!sudahDilaporkan) {
                logger.info(
                    { pemegang: pemegang.data },
                    'Menunggu engine lain melepas data sesi sebelum menjalankan sesi apa pun',
                );
                sudahDilaporkan = true;
            }

            await new Promise((resolve) => setTimeout(resolve, this.#jedaMs));
        }
    }

    /**
     * Hanya melepas kunci milik sendiri. Engine yang sudah diambil alih
     * kuncinya (karena dianggap mati) tidak boleh membuang kunci penggantinya.
     */
    async lepas() {
        if (this.#detak) {
            clearInterval(this.#detak);
            this.#detak = null;
        }

        const pemegang = await this.#bacaPemegang();

        if (pemegang?.data?.id === this.#identitas.id) {
            await rm(this.#berkas, { force: true });
        }
    }

    #pasangDetak() {
        let sudahDiperingatkan = false;

        this.#detak = setInterval(async () => {
            const sekarang = new Date();

            try {
                await utimes(this.#berkas, sekarang, sekarang);
            } catch (error) {
                if (!sudahDiperingatkan) {
                    logger.error({ err: error.message }, 'Kunci data sesi hilang; engine lain mungkin sedang memegang folder yang sama');
                    sudahDiperingatkan = true;
                }
            }
        }, this.#detakMs);

        this.#detak.unref?.();
    }

    async #bacaPemegang() {
        try {
            const [isi, info] = await Promise.all([readFile(this.#berkas, 'utf-8'), stat(this.#berkas)]);
            let data = null;

            try {
                data = JSON.parse(isi);
            } catch {
                // Berkas kunci yang setengah tertulis tetap kunci; umurnya yang
                // menentukan kapan boleh diambil alih.
            }

            return { data, umurMs: Date.now() - info.mtimeMs };
        } catch (error) {
            if (error.code === 'ENOENT') {
                return null;
            }

            throw error;
        }
    }

    #alasanAmbilAlih({ data, umurMs }, sudahMenungguMs) {
        if (umurMs > this.#basiMs) {
            return 'detak_berhenti';
        }

        // Di dalam container yang sama, proses engine sebelumnya bisa
        // dipastikan mati tanpa menunggu detaknya basi — `start.sh`
        // menjalankan ulang engine yang jatuh dalam tiga detik.
        if (data?.host === os.hostname() && !prosesHidup(data.pid)) {
            return 'proses_mati';
        }

        if (sudahMenungguMs > this.#maksTungguMs) {
            return 'menunggu_terlalu_lama';
        }

        return null;
    }
}

function prosesHidup(pid) {
    if (!Number.isInteger(pid) || pid <= 0) {
        return false;
    }

    if (pid === process.pid) {
        return true;
    }

    try {
        process.kill(pid, 0);

        return true;
    } catch (error) {
        return error.code === 'EPERM';
    }
}
