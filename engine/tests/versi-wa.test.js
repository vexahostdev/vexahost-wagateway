import assert from 'node:assert/strict';
import process from 'node:process';
import { test } from 'node:test';

process.env.ENGINE_TOKEN = 'uji';
process.env.ENGINE_HMAC_SECRET = 'uji';
process.env.LARAVEL_URL = 'http://127.0.0.1:9';
process.env.ENGINE_LOG_LEVEL = 'silent';

const { VersiWhatsApp } = await import('../src/versi-wa.js');
const { parseWaWebVersion } = await import('../src/config.js');

const AWAL = [2, 3000, 1];

test('versi terbaru dari WhatsApp Web menggantikan versi awal', async () => {
    const versi = new VersiWhatsApp({
        awal: AWAL,
        dipatok: false,
        ambil: async () => ({ version: [2, 3000, 99], isLatest: true }),
    });

    assert.deepEqual(versi.sekarang(), AWAL);
    assert.deepEqual(await versi.segarkan(), [2, 3000, 99]);
    assert.deepEqual(versi.sekarang(), [2, 3000, 99]);
});

test('gagal mengambil versi tidak menghentikan apa pun: versi terakhir tetap dipakai', async () => {
    const versi = new VersiWhatsApp({
        awal: AWAL,
        dipatok: false,
        ambil: async () => {
            throw new Error('web.whatsapp.com tidak terjangkau');
        },
    });

    assert.deepEqual(await versi.segarkan(), AWAL);

    const tanpaRevisi = new VersiWhatsApp({
        awal: AWAL,
        dipatok: false,
        ambil: async () => ({ version: [9, 9, 9], isLatest: false, error: { message: 'tidak ketemu' } }),
    });

    assert.deepEqual(await tanpaRevisi.segarkan(), AWAL, 'Versi cadangan Baileys bukan versi terbaru');
});

test('WA_WEB_VERSION yang diisi mematok versinya', async () => {
    let dipanggil = 0;
    const versi = new VersiWhatsApp({
        awal: AWAL,
        dipatok: true,
        ambil: async () => {
            dipanggil++;

            return { version: [2, 3000, 99], isLatest: true };
        },
    });

    assert.deepEqual(await versi.segarkan({ paksa: true }), AWAL);
    assert.equal(dipanggil, 0);
});

test('penyegaran berjeda, kecuali dipaksa; permintaan bersamaan digabung', async () => {
    let dipanggil = 0;
    const versi = new VersiWhatsApp({
        awal: AWAL,
        dipatok: false,
        ambil: async () => {
            dipanggil++;
            await new Promise((resolve) => setTimeout(resolve, 20));

            return { version: [2, 3000, 100 + dipanggil], isLatest: true };
        },
    });

    await Promise.all([versi.segarkan(), versi.segarkan(), versi.segarkan()]);
    assert.equal(dipanggil, 1, 'Sesi yang ditolak bersamaan cukup memicu satu permintaan');

    await versi.segarkan();
    assert.equal(dipanggil, 1, 'Belum waktunya disegarkan lagi');

    await versi.segarkan({ paksa: true });
    assert.equal(dipanggil, 2);
});

test('nilai WA_WEB_VERSION yang salah ketik tidak dihitung sebagai patokan', () => {
    assert.equal(parseWaWebVersion('2.3000', null), null);
    assert.equal(parseWaWebVersion('', null), null);
    assert.deepEqual(parseWaWebVersion('2.3000.5', null), [2, 3000, 5]);
});
