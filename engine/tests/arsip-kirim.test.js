import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ArsipKirim } from '../src/arsip-kirim.js';

/**
 * Arsip ini hidup di memori engine, yang RAM-nya dibagi dengan PHP, MySQL,
 * dan aplikasi lain di VPS yang sama. Versi pertamanya hanya dibatasi jumlah
 * dan terukur 62 MB per sesi untuk pesan teks terpanjang — tes di sini
 * memastikan batas ukurannya tidak pernah dilonggarkan tanpa sengaja.
 */

function teks(panjang, isi = 'x') {
    return { extendedTextMessage: { text: isi.repeat(panjang) } };
}

test('pesan yang disimpan bisa diambil lagi dengan isi yang sama', () => {
    const arsip = new ArsipKirim();

    arsip.simpan('s1', 'A', { conversation: 'tagihan Oktober' });

    assert.equal(arsip.ambil('s1', 'A').conversation, 'tagihan Oktober');
    assert.equal(arsip.ambil('s1', 'B'), undefined);
    assert.equal(arsip.ambil('s2', 'A'), undefined, 'Arsip tiap sesi terpisah');
});

test('total isi per sesi tidak pernah melewati batas ukuran; yang tertua dibuang lebih dulu', () => {
    const arsip = new ArsipKirim({ batasByte: 10_000 });

    for (let i = 0; i < 50; i++) {
        arsip.simpan('s1', `P${i}`, teks(1000, String(i % 10)));
    }

    assert.ok(arsip.byteTerpakai('s1') <= 10_000, `Terpakai ${arsip.byteTerpakai('s1')} byte`);
    assert.equal(arsip.ambil('s1', 'P0'), undefined, 'Pesan tertua harus sudah dibuang');
    assert.ok(arsip.ambil('s1', 'P49'), 'Pesan terbaru harus tetap ada');
});

test('pesan yang lebih besar dari seluruh jatah tidak disimpan sama sekali', () => {
    const arsip = new ArsipKirim({ batasByte: 5_000 });

    arsip.simpan('s1', 'kecil', teks(100));
    arsip.simpan('s1', 'raksasa', teks(65_000));

    assert.equal(arsip.ambil('s1', 'raksasa'), undefined);
    assert.ok(arsip.ambil('s1', 'kecil'), 'Satu pesan raksasa tidak boleh mengosongkan arsip');
});

test('pesan yang sangat pendek tetap dibatasi jumlahnya', () => {
    const arsip = new ArsipKirim({ maks: 100 });

    for (let i = 0; i < 1000; i++) {
        arsip.simpan('s1', `OK${i}`, { conversation: 'OK' });
    }

    assert.equal(arsip.ambil('s1', 'OK899'), undefined);
    assert.ok(arsip.ambil('s1', 'OK900'));
    assert.ok(arsip.ambil('s1', 'OK999'));
});

test('menyimpan ulang id yang sama tidak menghitung ukurannya dua kali', () => {
    const arsip = new ArsipKirim();

    arsip.simpan('s1', 'A', teks(500));
    const sekali = arsip.byteTerpakai('s1');
    arsip.simpan('s1', 'A', teks(500));

    assert.equal(arsip.byteTerpakai('s1'), sekali);
});

test('pesan yang kedaluwarsa tidak dikembalikan dan ukurannya dilepas', async () => {
    const arsip = new ArsipKirim({ umurMs: 10 });

    arsip.simpan('s1', 'A', teks(100));
    await new Promise((resolve) => setTimeout(resolve, 25));

    assert.equal(arsip.ambil('s1', 'A'), undefined);
    assert.equal(arsip.byteTerpakai('s1'), 0);
});

test('isi yang tidak bisa dikodekan dilewati tanpa melempar galat', () => {
    const arsip = new ArsipKirim();

    assert.doesNotThrow(() => arsip.simpan('s1', 'A', { conversation: { bukan: 'teks' } }));
    assert.equal(arsip.byteTerpakai('s1'), 0);

    arsip.lupakan('s1');
    assert.equal(arsip.byteTerpakai('s1'), 0);
});
