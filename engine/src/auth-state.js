import { randomBytes } from 'node:crypto';
import { mkdir, readFile, rename, rm, unlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';
import { logger } from './logger.js';

/**
 * Auth state Baileys berbasis berkas — pengganti `useMultiFileAuthState`.
 *
 * Bawaan Baileys punya tiga sifat yang di produksi berujung pada nomor yang
 * "tiba-tiba minta scan ulang" tanpa satu pun galat di log:
 *
 * 1. `writeFile` langsung menimpa berkasnya. Proses yang mati di tengah
 *    penulisan — SIGTERM saat deploy, OOM killer, dua container yang sempat
 *    hidup bersamaan di volume yang sama — meninggalkan `creds.json` terpotong.
 *
 * 2. Pembacanya menelan galat APA PUN dan menjawab `null`, lalu Baileys
 *    membuat identitas baru lewat `initAuthCreds()`. Kredensial yang rusak
 *    berubah diam-diam menjadi kode QR, perangkat lamanya tetap tercatat di
 *    ponsel pelanggan (batasnya empat), dan engine tidak memulihkan dari
 *    cadangan karena berkasnya memang "ada".
 *
 * 3. Kunci per berkasnya tertutup di dalam modul Baileys, sehingga pencadangan
 *    ke Laravel membaca berkas tanpa kunci dan bisa menyalin berkas yang sedang
 *    setengah ditulis — lalu cadangan rusak itu menimpa cadangan yang sehat.
 *
 * Di sini penulisan selalu lewat berkas sementara lalu `rename` (atomik di
 * satu sistem berkas), pembacaan membedakan "tidak ada" dari "rusak", dan
 * kuncinya dipakai bersama oleh `LaravelStore`.
 */

const kunci = new Map();
const penulisanBerjalan = new Set();

/**
 * Menjalankan `kerja` setelah giliran sebelumnya untuk berkas yang sama
 * selesai. Antrean dibuang begitu kosong supaya Map ini tidak tumbuh
 * sepanjang umur proses — tiap pesan bisa menyentuh berkas kunci baru.
 */
function denganKunci(berkas, kerja) {
    const sebelumnya = kunci.get(berkas) ?? Promise.resolve();
    const giliran = sebelumnya.then(kerja);
    const ekor = giliran.catch(() => {});

    kunci.set(berkas, ekor);
    ekor.then(() => {
        if (kunci.get(berkas) === ekor) {
            kunci.delete(berkas);
        }
    });

    return giliran;
}

/**
 * `rename` di Windows sesekali ditolak EPERM/EBUSY saat berkas tujuannya
 * sedang dibuka proses lain (antivirus, pengindeks). Produksi berjalan di
 * Linux; ini supaya tes dan pengembangan lokal tidak gagal acak.
 */
async function gantiNama(asal, tujuan) {
    for (let percobaan = 1; ; percobaan++) {
        try {
            await rename(asal, tujuan);

            return;
        } catch (error) {
            if (percobaan >= 5 || !['EPERM', 'EBUSY', 'EACCES'].includes(error.code)) {
                throw error;
            }

            await new Promise((resolve) => setTimeout(resolve, 20 * percobaan));
        }
    }
}

export function tulisBerkasAtomik(berkas, isi) {
    const tugas = denganKunci(berkas, async () => {
        const sementara = `${berkas}.${process.pid}.${randomBytes(4).toString('hex')}.tmp`;

        try {
            await writeFile(sementara, isi);
            await gantiNama(sementara, berkas);
        } catch (error) {
            await rm(sementara, { force: true }).catch(() => {});

            throw error;
        }
    });

    penulisanBerjalan.add(tugas);
    tugas.then(
        () => penulisanBerjalan.delete(tugas),
        () => penulisanBerjalan.delete(tugas),
    );

    return tugas;
}

/**
 * Isi berkas sebagai teks, atau `null` kalau berkasnya memang tidak ada.
 * Galat lain dilempar: "tidak bisa dibaca" bukan "tidak ada", dan
 * menyamakan keduanya adalah persis kesalahan bawaan Baileys.
 */
export function bacaBerkas(berkas) {
    return denganKunci(berkas, async () => {
        try {
            return await readFile(berkas, 'utf-8');
        } catch (error) {
            if (error.code === 'ENOENT') {
                return null;
            }

            throw error;
        }
    });
}

function hapusBerkas(berkas) {
    return denganKunci(berkas, () => unlink(berkas).catch((error) => {
        if (error.code !== 'ENOENT') {
            throw error;
        }
    }));
}

/**
 * Menunggu seluruh penulisan yang sedang berjalan, dengan batas waktu.
 *
 * Dipanggil sebelum proses keluar dan sebelum folder sesi dihapus.
 * `process.exit()` tidak menunggu penulisan asinkron apa pun — tanpa ini,
 * pembaruan kunci terakhir sebelum deploy bisa hilang begitu saja.
 */
export async function tungguPenulisanSelesai(batasMs = 3000) {
    if (penulisanBerjalan.size === 0) {
        return;
    }

    let pengukur;

    await Promise.race([
        Promise.allSettled([...penulisanBerjalan]),
        new Promise((resolve) => {
            pengukur = setTimeout(resolve, batasMs);
        }),
    ]);

    clearTimeout(pengukur);
}

/**
 * Nama berkas yang boleh berada di folder sesi.
 *
 * Dipakai untuk dua hal: menyaring berkas sementara `.tmp` dari pencadangan,
 * dan menolak nama yang keluar dari folder sesi saat memulihkan cadangan.
 * Nama dari Baileys selalu satu segmen (`/` diganti `__`, `:` diganti `-`),
 * jadi apa pun yang memuat pemisah path bukan berasal dari Baileys.
 *
 * Sengaja tidak membatasi karakternya: id kunci Baileys memuat JID, nomor
 * perangkat, dan akhiran yang berubah antar versi. Daftar karakter yang
 * terlalu ketat akan diam-diam membuang berkas kunci yang sah dari cadangan.
 */
export function namaBerkasSah(nama) {
    return typeof nama === 'string'
        && nama.endsWith('.json')
        && !nama.startsWith('.')
        && !/[\\/\0]/.test(nama)
        && path.basename(nama) === nama;
}

function namaBerkasAman(nama) {
    return nama.replace(/\//g, '__').replace(/:/g, '-');
}

/**
 * Keadaan sebuah `creds.json`, dari isinya.
 *
 * `belum_tertaut` = kunci perangkat sudah dibuat tapi QR-nya belum pernah
 * di-scan. Dibedakan dari `tertaut` karena hanya yang terakhir yang layak
 * dipulihkan atau dicadangkan.
 *
 * @returns {'tertaut'|'belum_tertaut'|'rusak'}
 */
export function nilaiKredensial(teks) {
    if (typeof teks !== 'string' || teks.trim() === '') {
        return 'rusak';
    }

    let creds;

    try {
        creds = JSON.parse(teks, BufferJSON.reviver);
    } catch {
        return 'rusak';
    }

    if (!creds || typeof creds !== 'object' || !creds.noiseKey || !creds.signedIdentityKey) {
        return 'rusak';
    }

    return creds.me?.id ? 'tertaut' : 'belum_tertaut';
}

/**
 * @returns {Promise<'tidak_ada'|'tertaut'|'belum_tertaut'|'rusak'>}
 */
export async function periksaKredensial(folder) {
    const teks = await bacaBerkas(path.join(folder, 'creds.json'));

    return teks === null ? 'tidak_ada' : nilaiKredensial(teks);
}

/**
 * Mengganti seluruh isi folder sesi dengan berkas dari cadangan.
 *
 * Folder dikosongkan lebih dulu, bukan ditimpa: berkas kunci sisa dari
 * keadaan lain yang tercampur dengan `creds.json` dari cadangan menghasilkan
 * sesi Signal yang tidak cocok satu sama lain — pesan gagal didekripsi tanpa
 * penjelasan yang bisa dilacak.
 */
export async function tulisUlangKredensial(folder, berkas) {
    await tungguPenulisanSelesai();
    await rm(folder, { recursive: true, force: true, maxRetries: 3 });
    await mkdir(folder, { recursive: true });

    for (const [nama, isi] of Object.entries(berkas)) {
        if (!namaBerkasSah(nama) || typeof isi !== 'string') {
            logger.warn({ nama }, 'Berkas cadangan dengan nama tidak sah dilewati');

            continue;
        }

        await tulisBerkasAtomik(path.join(folder, nama), isi);
    }
}

/**
 * Auth state untuk `makeWASocket`, setara dengan `useMultiFileAuthState`.
 *
 * Melempar galat kalau `creds.json` ada tapi rusak. Pemanggil wajib sudah
 * memeriksanya lewat `periksaKredensial()` dan memutuskan sendiri — yang
 * tidak boleh terjadi adalah identitas baru lahir tanpa sepengetahuan siapa pun.
 */
export async function pakaiAuthStateBerkas(folder) {
    await mkdir(folder, { recursive: true });

    const lokasi = (nama) => path.join(folder, namaBerkasAman(nama));

    const tulis = (data, nama) => tulisBerkasAtomik(lokasi(nama), JSON.stringify(data, BufferJSON.replacer));

    const baca = async (nama) => {
        const teks = await bacaBerkas(lokasi(nama));

        if (teks === null) {
            return null;
        }

        try {
            return JSON.parse(teks, BufferJSON.reviver);
        } catch {
            // Satu berkas kunci yang rusak (satu kontak, satu pre-key) tidak
            // sebanding dengan menjatuhkan seluruh sesi: Baileys memperlakukan
            // kunci yang hilang dengan menegosiasikannya ulang.
            logger.warn({ folder, nama }, 'Berkas kunci sesi rusak, diperlakukan sebagai tidak ada');

            return null;
        }
    };

    const teksCreds = await bacaBerkas(lokasi('creds.json'));
    let creds;

    if (teksCreds === null) {
        creds = initAuthCreds();
    } else if (nilaiKredensial(teksCreds) === 'rusak') {
        throw new Error('creds.json rusak dan tidak bisa dipakai.');
    } else {
        creds = JSON.parse(teksCreds, BufferJSON.reviver);
    }

    return {
        state: {
            creds,
            keys: {
                get: async (type, ids) => {
                    const data = {};

                    await Promise.all(ids.map(async (id) => {
                        let value = await baca(`${type}-${id}.json`);

                        if (type === 'app-state-sync-key' && value) {
                            value = proto.Message.AppStateSyncKeyData.fromObject(value);
                        }

                        data[id] = value;
                    }));

                    return data;
                },
                set: async (data) => {
                    const tugas = [];

                    for (const category in data) {
                        for (const id in data[category]) {
                            const value = data[category][id];
                            const nama = `${category}-${id}.json`;

                            tugas.push(value ? tulis(value, nama) : hapusBerkas(lokasi(nama)));
                        }
                    }

                    await Promise.all(tugas);
                },
            },
        },
        saveCreds: () => tulis(creds, 'creds.json'),
    };
}
