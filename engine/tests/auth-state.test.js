import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { mkdtemp, readdir, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { test } from 'node:test';
import { BufferJSON, initAuthCreds } from '@whiskeysockets/baileys';

process.env.ENGINE_TOKEN = 'uji';
process.env.ENGINE_HMAC_SECRET = 'uji';
process.env.LARAVEL_URL = 'http://127.0.0.1:9';
process.env.ENGINE_LOG_LEVEL = 'silent';

const {
    bacaBerkas,
    namaBerkasSah,
    nilaiKredensial,
    pakaiAuthStateBerkas,
    periksaKredensial,
    tulisBerkasAtomik,
    tungguPenulisanSelesai,
} = await import('../src/auth-state.js');

function credsTertaut() {
    return JSON.stringify({ ...initAuthCreds(), me: { id: '628123456789:1@s.whatsapp.net' } }, BufferJSON.replacer);
}

async function folderBaru() {
    return mkdtemp(path.join(tmpdir(), 'vexahost-wa-auth-'));
}

test('creds.json terpotong dinilai rusak, bukan "tidak ada"', async () => {
    const folder = await folderBaru();
    const utuh = credsTertaut();

    await writeFile(path.join(folder, 'creds.json'), utuh.slice(0, utuh.length / 2));
    assert.equal(await periksaKredensial(folder), 'rusak');

    await writeFile(path.join(folder, 'creds.json'), '');
    assert.equal(await periksaKredensial(folder), 'rusak');

    await writeFile(path.join(folder, 'creds.json'), '{"bukan":"kredensial"}');
    assert.equal(await periksaKredensial(folder), 'rusak');
});

test('kredensial dibedakan antara tertaut, belum tertaut, dan tidak ada', async () => {
    const folder = await folderBaru();

    assert.equal(await periksaKredensial(folder), 'tidak_ada');

    await writeFile(path.join(folder, 'creds.json'), JSON.stringify(initAuthCreds(), BufferJSON.replacer));
    assert.equal(await periksaKredensial(folder), 'belum_tertaut');

    await writeFile(path.join(folder, 'creds.json'), credsTertaut());
    assert.equal(await periksaKredensial(folder), 'tertaut');

    assert.equal(nilaiKredensial(undefined), 'rusak');
});

test('auth state menolak creds.json rusak alih-alih diam-diam membuat identitas baru', async () => {
    const folder = await folderBaru();
    await writeFile(path.join(folder, 'creds.json'), '{"noiseKey":');

    await assert.rejects(() => pakaiAuthStateBerkas(folder), /creds\.json rusak/);
});

test('auth state tanpa creds.json membuat kredensial baru, dan menyimpannya utuh', async () => {
    const folder = await folderBaru();
    const { state, saveCreds } = await pakaiAuthStateBerkas(folder);

    assert.ok(state.creds.noiseKey, 'Kredensial baru harus dibuat untuk tautan pertama');

    state.creds.me = { id: '628111:2@s.whatsapp.net' };
    await saveCreds();

    assert.equal(await periksaKredensial(folder), 'tertaut');

    const muatUlang = await pakaiAuthStateBerkas(folder);
    assert.equal(muatUlang.state.creds.me.id, '628111:2@s.whatsapp.net');
    assert.ok(Buffer.isBuffer(muatUlang.state.creds.noiseKey.private), 'Buffer harus kembali utuh lewat BufferJSON');
});

test('kunci sesi tersimpan, terbaca, dan terhapus lewat keys.set/get', async () => {
    const folder = await folderBaru();
    const { state } = await pakaiAuthStateBerkas(folder);

    await state.keys.set({ 'pre-key': { 1: { public: Buffer.from('a'), private: Buffer.from('b') } } });
    await state.keys.set({ session: { '628123:1.0': Buffer.from('sesi') } });

    const preKey = await state.keys.get('pre-key', ['1']);
    assert.ok(Buffer.isBuffer(preKey['1'].public));

    // `:` di id diganti supaya aman sebagai nama berkas.
    assert.ok((await readdir(folder)).includes('session-628123-1.0.json'));

    await state.keys.set({ 'pre-key': { 1: null } });
    assert.equal((await state.keys.get('pre-key', ['1']))['1'], null);
});

test('berkas kunci yang rusak diperlakukan tidak ada, tidak menjatuhkan sesi', async () => {
    const folder = await folderBaru();
    const { state } = await pakaiAuthStateBerkas(folder);

    await writeFile(path.join(folder, 'pre-key-9.json'), '{"terpotong');

    assert.equal((await state.keys.get('pre-key', ['9']))['9'], null);
});

test('penulisan bersamaan ke satu berkas tidak pernah menghasilkan isi campuran', async () => {
    const folder = await folderBaru();
    const berkas = path.join(folder, 'creds.json');
    const versi = Array.from({ length: 25 }, (_, i) => JSON.stringify({ urutan: i, isi: 'x'.repeat(20_000) }));

    const pembacaan = [];
    await Promise.all(versi.map((isi, i) => {
        const tulis = tulisBerkasAtomik(berkas, isi);

        if (i % 5 === 0) {
            pembacaan.push(bacaBerkas(berkas));
        }

        return tulis;
    }));

    for (const isi of await Promise.all(pembacaan)) {
        if (isi !== null) {
            assert.doesNotThrow(() => JSON.parse(isi), 'Pembaca tidak boleh melihat berkas setengah tertulis');
        }
    }

    assert.equal(await readFile(berkas, 'utf-8'), versi.at(-1), 'Penulisan terakhir yang menang');
    assert.deepEqual((await readdir(folder)).filter((nama) => nama.endsWith('.tmp')), [], 'Tidak ada berkas sementara yang tertinggal');
});

test('tungguPenulisanSelesai menunggu penulisan yang sedang berjalan', async () => {
    const folder = await folderBaru();
    const berkas = path.join(folder, 'besar.json');

    tulisBerkasAtomik(berkas, JSON.stringify({ isi: 'y'.repeat(2_000_000) }));
    await tungguPenulisanSelesai(5000);

    assert.doesNotThrow(() => JSON.parse(readFileSync(berkas, 'utf-8')));
});

test('nama berkas cadangan yang keluar dari folder sesi ditolak', () => {
    assert.equal(namaBerkasSah('creds.json'), true);
    assert.equal(namaBerkasSah('sender-key-120363@g.us--628123-1.json'), true);
    assert.equal(namaBerkasSah('session-628123_1.0.json'), true);

    assert.equal(namaBerkasSah('../creds.json'), false);
    assert.equal(namaBerkasSah('..\\creds.json'), false);
    assert.equal(namaBerkasSah('sub/creds.json'), false);
    assert.equal(namaBerkasSah('.engine.lock'), false);
    assert.equal(namaBerkasSah('creds.json.123.ab.tmp'), false);
    assert.equal(namaBerkasSah(undefined), false);
});
