import assert from 'node:assert/strict';
import { mkdtemp, readFile, utimes, writeFile } from 'node:fs/promises';
import os, { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { test } from 'node:test';

process.env.ENGINE_TOKEN = 'uji';
process.env.ENGINE_HMAC_SECRET = 'uji';
process.env.LARAVEL_URL = 'http://127.0.0.1:9';
process.env.ENGINE_LOG_LEVEL = 'silent';

const { KunciData } = await import('../src/kunci-data.js');

async function folderBaru() {
    return mkdtemp(path.join(tmpdir(), 'vexahost-wa-kunci-'));
}

function tunggu(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

test('engine kedua menunggu sampai engine pertama melepas data sesi', async () => {
    const folder = await folderBaru();
    const pertama = new KunciData({ folder, detakMs: 20, basiMs: 5_000, jedaMs: 10 });
    const kedua = new KunciData({ folder, detakMs: 20, basiMs: 5_000, jedaMs: 10 });

    await pertama.ambil();

    let keduaDapat = false;
    const menunggu = kedua.ambil().then(() => {
        keduaDapat = true;
    });

    await tunggu(150);
    assert.equal(keduaDapat, false, 'Selama pemegang pertama hidup, yang kedua tidak boleh mulai');

    await pertama.lepas();
    await menunggu;

    assert.equal(keduaDapat, true);
    await kedua.lepas();
});

test('kunci yang detaknya berhenti diambil alih', async () => {
    const folder = await folderBaru();
    const berkas = path.join(folder, '.engine.lock');

    // Pemegang di container lain yang mati kena SIGKILL tanpa sempat melepas.
    await writeFile(berkas, JSON.stringify({ id: 'lama', pid: 1, host: 'container-lama' }));
    const dulu = new Date(Date.now() - 60_000);
    await utimes(berkas, dulu, dulu);

    const kunci = new KunciData({ folder, basiMs: 1_000, jedaMs: 10 });
    await kunci.ambil();

    assert.notEqual(JSON.parse(await readFile(berkas, 'utf-8')).id, 'lama');
    await kunci.lepas();
});

test('pemegang di container yang sama yang prosesnya sudah mati diambil alih tanpa menunggu basi', async () => {
    const folder = await folderBaru();
    const berkas = path.join(folder, '.engine.lock');

    // PID setinggi ini praktis tidak pernah ada.
    await writeFile(berkas, JSON.stringify({ id: 'jatuh', pid: 2_000_000_000, host: os.hostname() }));

    const kunci = new KunciData({ folder, basiMs: 60_000, jedaMs: 10 });
    const mulai = Date.now();
    await kunci.ambil();

    assert.ok(Date.now() - mulai < 1_000, 'Engine yang dijalankan ulang start.sh tidak boleh menunggu detak basi');
    await kunci.lepas();
});

test('kunci dilepas paksa setelah menunggu terlalu lama', async () => {
    const folder = await folderBaru();
    const pertama = new KunciData({ folder, detakMs: 10, basiMs: 60_000 });
    const kedua = new KunciData({ folder, basiMs: 60_000, maksTungguMs: 100, jedaMs: 10 });

    await pertama.ambil();
    await kedua.ambil();

    await kedua.lepas();
    await pertama.lepas();
});

test('lepas() tidak membuang kunci milik engine lain', async () => {
    const folder = await folderBaru();
    const berkas = path.join(folder, '.engine.lock');
    const kunci = new KunciData({ folder });

    await writeFile(berkas, JSON.stringify({ id: 'orang-lain', pid: process.pid, host: os.hostname() }));
    await kunci.lepas();

    assert.equal(JSON.parse(await readFile(berkas, 'utf-8')).id, 'orang-lain');
});
