import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { BufferJSON, initAuthCreds } from '@whiskeysockets/baileys';

process.env.ENGINE_TOKEN = 'uji';
process.env.ENGINE_HMAC_SECRET = 'uji';
process.env.LARAVEL_URL = 'http://localhost:8051';
process.env.ENGINE_LOG_LEVEL = 'silent';

const dataPath = await mkdtemp(path.join(tmpdir(), 'vexahost-wa-store-'));
process.env.WA_DATA_PATH = dataPath;

const { LaravelStore } = await import('../src/stores/laravel-store.js');
const { laravel } = await import('../src/laravel.js');

/**
 * creds.json berbentuk asli: dibuat Baileys sendiri, lalu ditandai tertaut.
 * Store menolak mencadangkan apa pun yang lain, jadi tiruan `{ me: '628' }`
 * tidak lagi mewakili keadaan yang mungkin terjadi.
 */
function credsTertaut(nomor = '628123456789') {
    return JSON.stringify(
        { ...initAuthCreds(), me: { id: `${nomor}:1@s.whatsapp.net`, name: 'Uji' } },
        BufferJSON.replacer,
    );
}

async function denganUnggahTiruan(kerja) {
    const terkirim = [];
    const asli = laravel.uploadBackup;
    laravel.uploadBackup = async (id, buffer, digest) => {
        terkirim.push({ id, buffer, digest });
    };

    try {
        await kerja();
    } finally {
        laravel.uploadBackup = asli;
    }

    return terkirim;
}

test('save membundel berkas dari folder sesi ke dalam JSON dan mengunggahnya', async () => {
    const store = new LaravelStore();
    const sessionId = 'uji123';
    const sessionDir = path.join(dataPath, sessionId);
    await mkdir(sessionDir, { recursive: true });

    const creds = credsTertaut();
    await writeFile(path.join(sessionDir, 'creds.json'), creds);
    await writeFile(path.join(sessionDir, 'pre-key-1.json'), JSON.stringify({ keyId: 1 }));

    let hasil;
    const terkirim = await denganUnggahTiruan(async () => {
        hasil = await store.save(sessionId);
    });

    assert.equal(hasil, true);
    assert.equal(terkirim.length, 1, 'uploadBackup seharusnya dipanggil');
    assert.equal(terkirim[0].id, 'uji123');

    const parsed = JSON.parse(terkirim[0].buffer.toString('utf-8'));
    assert.equal(parsed.sessionId, 'uji123');
    assert.equal(parsed.version, 1);
    assert.equal(parsed.files['creds.json'], creds);
    assert.deepEqual(JSON.parse(parsed.files['pre-key-1.json']), { keyId: 1 });
});

test('save TIDAK mengunggah creds.json yang rusak, supaya cadangan sehat tidak tertimpa', async () => {
    const store = new LaravelStore();
    const sessionId = 'uji-rusak';
    const sessionDir = path.join(dataPath, sessionId);
    await mkdir(sessionDir, { recursive: true });

    // Persis yang ditinggalkan proses yang mati di tengah penulisan.
    await writeFile(path.join(sessionDir, 'creds.json'), credsTertaut().slice(0, 40));

    let hasil;
    const terkirim = await denganUnggahTiruan(async () => {
        hasil = await store.save(sessionId);
    });

    assert.equal(hasil, false);
    assert.equal(terkirim.length, 0);
});

test('save TIDAK mengunggah sesi yang QR-nya belum pernah di-scan', async () => {
    const store = new LaravelStore();
    const sessionId = 'uji-belum-tertaut';
    const sessionDir = path.join(dataPath, sessionId);
    await mkdir(sessionDir, { recursive: true });

    await writeFile(path.join(sessionDir, 'creds.json'), JSON.stringify(initAuthCreds(), BufferJSON.replacer));

    const terkirim = await denganUnggahTiruan(() => store.save(sessionId));

    assert.equal(terkirim.length, 0);
});

test('save melewati berkas sementara dari penulisan yang sedang berjalan', async () => {
    const store = new LaravelStore();
    const sessionId = 'uji-tmp';
    const sessionDir = path.join(dataPath, sessionId);
    await mkdir(sessionDir, { recursive: true });

    await writeFile(path.join(sessionDir, 'creds.json'), credsTertaut());
    await writeFile(path.join(sessionDir, 'creds.json.123.abcd.tmp'), '{"setengah');

    const terkirim = await denganUnggahTiruan(() => store.save(sessionId));
    const parsed = JSON.parse(terkirim[0].buffer.toString('utf-8'));

    assert.deepEqual(Object.keys(parsed.files), ['creds.json']);
});

test('sessionExists melempar galat, bukan menjawab false, saat Laravel bermasalah', async () => {
    const store = new LaravelStore();
    const asli = laravel.backupExists;
    laravel.backupExists = async () => {
        throw new Error('Laravel tidak terjangkau');
    };

    try {
        await assert.rejects(
            () => store.sessionExists('uji123'),
            /tidak terjangkau/,
        );
    } finally {
        laravel.backupExists = asli;
    }
});

test('simpan lalu pulihkan menghasilkan state yang sama persis', async () => {
    const store = new LaravelStore();
    const sessionId = 'uji-pulih';
    const sessionDir = path.join(dataPath, sessionId);
    await mkdir(sessionDir, { recursive: true });

    const berkasUji = {
        'creds.json': credsTertaut(),
        'app-state-sync-key-1.json': JSON.stringify({ keyData: 'abc123xyz' }),
        'pre-key-42.json': JSON.stringify({ keyPair: { public: 'pubkey', private: 'privkey' } }),
    };

    for (const [nama, isi] of Object.entries(berkasUji)) {
        await writeFile(path.join(sessionDir, nama), isi);
    }

    let storageBuffer = null;
    const asliUpload = laravel.uploadBackup;
    const asliDownload = laravel.downloadBackup;

    laravel.uploadBackup = async (id, buffer) => {
        storageBuffer = buffer;
    };
    laravel.downloadBackup = async () => storageBuffer;

    try {
        // Simpan
        await store.save(sessionId);

        // Hapus folder lokal seolah container dibuat ulang
        await rm(sessionDir, { recursive: true, force: true });
        assert.equal((await readdir(dataPath)).includes(sessionId), false);

        // Pulihkan
        await store.extract(sessionId);

        // Verifikasi seluruh berkas ada dan isinya identik
        const pulihEntries = await readdir(sessionDir);
        assert.deepEqual(pulihEntries.sort(), Object.keys(berkasUji).sort());

        for (const [nama, isi] of Object.entries(berkasUji)) {
            const terbaca = await readFile(path.join(sessionDir, nama), 'utf-8');
            assert.equal(terbaca, isi, `Isi berkas ${nama} harus sama persis`);
        }
    } finally {
        laravel.uploadBackup = asliUpload;
        laravel.downloadBackup = asliDownload;
    }
});

test('extract menolak nama berkas yang keluar dari folder sesi', async () => {
    const store = new LaravelStore();
    const sessionId = 'uji-traversal';
    const asliDownload = laravel.downloadBackup;

    laravel.downloadBackup = async () => Buffer.from(JSON.stringify({
        version: 1,
        sessionId,
        files: {
            'creds.json': credsTertaut(),
            '../di-luar.json': '{}',
            '..\\di-luar-juga.json': '{}',
        },
    }));

    try {
        await store.extract(sessionId);
    } finally {
        laravel.downloadBackup = asliDownload;
    }

    assert.deepEqual(await readdir(path.join(dataPath, sessionId)), ['creds.json']);
    assert.equal((await readdir(dataPath)).includes('di-luar.json'), false);
    assert.equal((await readdir(dataPath)).includes('di-luar-juga.json'), false);
});
