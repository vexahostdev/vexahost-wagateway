import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { test } from 'node:test';
import { BufferJSON, DisconnectReason, initAuthCreds } from '@whiskeysockets/baileys';

process.env.ENGINE_TOKEN = 'uji';
process.env.ENGINE_HMAC_SECRET = 'uji';
process.env.LARAVEL_URL = 'http://127.0.0.1:9';
process.env.ENGINE_LOG_LEVEL = 'silent';
process.env.WA_INIT_TIMEOUT_MS = '60000';
process.env.WA_MIN_DELAY_MS = '0';
process.env.WA_MAX_DELAY_MS = '1';

const dataPath = await mkdtemp(path.join(tmpdir(), 'vexahost-wa-sesi-'));
process.env.WA_DATA_PATH = dataPath;

const { SessionManager, golonganPutus, detikPesan } = await import('../src/session-manager.js');
const { laravel } = await import('../src/laravel.js');

/**
 * Seluruh event yang dikirim engine ke Laravel, untuk diperiksa.
 */
const kejadian = [];
laravel.event = async (sessionId, event, payload = {}) => {
    kejadian.push({ sessionId, event, payload });

    return null;
};

function kejadianUntuk(sessionId, event) {
    return kejadian.filter((k) => k.sessionId === sessionId && k.event === event);
}

function socketTiruan(overrides = {}) {
    const sock = {
        ev: new EventEmitter(),
        user: { id: '628123456789:1@s.whatsapp.net', name: 'Uji' },
        ditutup: false,
        end: () => {
            sock.ditutup = true;
        },
        logout: async () => {},
        sendMessage: async (chatId, content) => ({
            key: { id: `WA-${Math.random().toString(36).slice(2)}`, remoteJid: chatId },
            message: { conversation: content.text },
        }),
        onWhatsApp: async (digits) => [{ jid: `${digits}@s.whatsapp.net`, exists: true }],
        ...overrides,
    };

    return sock;
}

/**
 * Manager dengan pabrik socket yang mencatat setiap socket yang dibuat —
 * jumlahnya adalah jumlah percobaan menyambung.
 */
function managerUji(opsi = {}) {
    const sockets = [];
    const opsiSocket = [];
    const manager = new SessionManager({
        buatSocket: (id, options) => {
            const sock = socketTiruan(opsi.socket);
            sockets.push(sock);
            opsiSocket.push(options);

            return sock;
        },
        store: opsi.store ?? {
            sessionExists: async () => false,
            ambilBerkas: async () => ({}),
            save: async () => true,
            delete: async () => {},
        },
        versi: opsi.versi,
        stabilMs: opsi.stabilMs ?? 60_000,
        jedaSambungUlang: () => 1,
    });

    return { manager, sockets, opsiSocket, terakhir: () => sockets.at(-1) };
}

function credsTertaut() {
    return JSON.stringify({ ...initAuthCreds(), me: { id: '628123456789:1@s.whatsapp.net' } }, BufferJSON.replacer);
}

async function siapkanKredensial(sessionId, isi = credsTertaut()) {
    const folder = path.join(dataPath, sessionId);
    await mkdir(folder, { recursive: true });
    await writeFile(path.join(folder, 'creds.json'), isi);

    return folder;
}

function tutup(sock, statusCode) {
    sock.ev.emit('connection.update', {
        connection: 'close',
        lastDisconnect: { error: { output: { statusCode } } },
    });
}

function tunggu(ms = 30) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

test('401: kredensial yang dicabut WhatsApp dibuang, tidak dicoba lagi, dan Laravel diberi alasannya', async () => {
    const { manager, sockets, terakhir } = managerUji();
    const folder = await siapkanKredensial('s-401');

    await manager.start('s-401');
    terakhir().ev.emit('connection.update', { connection: 'open' });
    tutup(terakhir(), DisconnectReason.loggedOut);
    await tunggu();

    assert.equal(sockets.length, 1, 'Tidak boleh ada percobaan sambung ulang dengan kunci mati');
    assert.equal(existsSync(folder), false, 'Kredensial yang dicabut harus dibuang dari volume');
    assert.deepEqual(manager.listSessionIds(), []);
    assert.deepEqual(kejadianUntuk('s-401', 'disconnected').at(-1).payload, { reason: 'logged_out', status_code: 401 });
});

test('403: akun yang ditolak WhatsApp tidak dicoba login terus, kredensialnya tetap disimpan', async () => {
    const { manager, sockets, terakhir } = managerUji();
    const folder = await siapkanKredensial('s-403');

    await manager.start('s-403');
    tutup(terakhir(), DisconnectReason.forbidden);
    await tunggu();

    assert.equal(sockets.length, 1);
    assert.equal(existsSync(path.join(folder, 'creds.json')), true);
    assert.equal(kejadianUntuk('s-403', 'disconnected').at(-1).payload.reason, 'banned');
});

test('440: perangkat yang diambil alih klien lain tidak direbut balik', async () => {
    const { manager, sockets, terakhir } = managerUji();
    const folder = await siapkanKredensial('s-440');

    await manager.start('s-440');
    terakhir().ev.emit('connection.update', { connection: 'open' });
    tutup(terakhir(), DisconnectReason.connectionReplaced);
    await tunggu();

    assert.equal(sockets.length, 1, 'Merebut balik berarti saling tendang tanpa akhir');
    assert.equal(existsSync(path.join(folder, 'creds.json')), true);
    assert.equal(kejadianUntuk('s-440', 'disconnected').at(-1).payload.reason, 'replaced');
});

test('500 adalah galat stream umum, bukan kredensial rusak: disambung ulang, kredensial utuh', async () => {
    const { manager, sockets, terakhir } = managerUji();
    const folder = await siapkanKredensial('s-500');

    await manager.start('s-500');
    tutup(terakhir(), DisconnectReason.badSession);
    await tunggu();

    assert.equal(sockets.length, 2);
    assert.equal(existsSync(path.join(folder, 'creds.json')), true);
    assert.equal(kejadianUntuk('s-500', 'disconnected').length, 0);

    await manager.stop('s-500');
});

test('koneksi yang terbuka lalu langsung putus tidak mendapat jatah percobaan baru tiap kali', async () => {
    const { manager, sockets, terakhir } = managerUji({ stabilMs: 60_000 });
    await siapkanKredensial('s-kedip');

    await manager.start('s-kedip');

    for (let i = 0; i < 10 && manager.listSessionIds().length > 0; i++) {
        terakhir().ev.emit('connection.update', { connection: 'open' });
        tutup(terakhir(), DisconnectReason.connectionClosed);
        await tunggu(15);
    }

    assert.deepEqual(manager.listSessionIds(), [], 'Rentetan buka-putus harus berhenti');
    assert.equal(sockets.length, 6, 'Satu socket awal + lima percobaan, bukan tanpa batas');
    assert.equal(kejadianUntuk('s-kedip', 'disconnected').at(-1).payload.reason, 'connection_lost');
});

test('koneksi yang bertahan mengembalikan jatah percobaan sambung ulang', async () => {
    const { manager, sockets, terakhir } = managerUji({ stabilMs: 20 });
    await siapkanKredensial('s-stabil');

    await manager.start('s-stabil');

    for (let i = 0; i < 8; i++) {
        terakhir().ev.emit('connection.update', { connection: 'open' });
        await tunggu(40);
        tutup(terakhir(), DisconnectReason.connectionLost);
        await tunggu(15);
    }

    assert.deepEqual(manager.listSessionIds(), ['s-stabil'], 'Putus sesekali setelah stabil bukan alasan menyerah');
    assert.equal(sockets.length, 9);

    await manager.stop('s-stabil');
});

test('405 menyegarkan versi WhatsApp Web sebelum menyambung ulang', async () => {
    let dipaksa = 0;
    const versi = {
        sekarang: () => [2, 3000, dipaksa === 0 ? 1 : 2],
        segarkan: async ({ paksa } = {}) => {
            if (paksa) dipaksa++;
        },
    };
    const { manager, sockets, opsiSocket, terakhir } = managerUji({ versi });
    await siapkanKredensial('s-405');

    await manager.start('s-405');
    tutup(terakhir(), 405);
    await tunggu();

    assert.equal(dipaksa, 1);
    assert.equal(sockets.length, 2);
    assert.deepEqual(opsiSocket.at(-1).version, [2, 3000, 2], 'Socket baru harus memakai versi yang sudah disegarkan');

    await manager.stop('s-405');
});

test('creds.json rusak dipulihkan dari cadangan, dan salinan rusaknya disimpan', async () => {
    const { manager, sockets } = managerUji();
    const folder = await siapkanKredensial('s-rusak-pulih', '{"noiseKey":');
    const cadangan = credsTertaut();

    await manager.start('s-rusak-pulih', {
        backup_data: JSON.stringify({ version: 1, files: { 'creds.json': cadangan, 'pre-key-1.json': '{"a":1}' } }),
    });

    assert.equal(sockets.length, 1);
    assert.equal(await readFile(path.join(folder, 'creds.json'), 'utf-8'), cadangan);
    assert.equal(existsSync(path.join(folder, 'pre-key-1.json')), true);
    assert.equal(await readFile(path.join(`${folder}.rusak`, 'creds.json'), 'utf-8'), '{"noiseKey":');

    await manager.stop('s-rusak-pulih');
});

test('kredensial lokal yang sehat tidak ditimpa cadangan yang lebih tua', async () => {
    const { manager } = managerUji();
    const lokal = credsTertaut();
    const folder = await siapkanKredensial('s-lokal-menang', lokal);

    await manager.start('s-lokal-menang', {
        backup_data: JSON.stringify({ version: 1, files: { 'creds.json': credsTertaut() } }),
    });

    assert.equal(await readFile(path.join(folder, 'creds.json'), 'utf-8'), lokal);

    await manager.stop('s-lokal-menang');
});

test('jalur otomatis tanpa kredensial tidak memunculkan QR yang tidak ditatap siapa pun', async () => {
    const { manager, sockets } = managerUji();
    await siapkanKredensial('s-otomatis-rusak', '');

    const entry = await manager.start('s-otomatis-rusak', { restore_only: true });

    assert.equal(entry, null);
    assert.equal(sockets.length, 0, 'Tidak boleh ada socket — socket tanpa kredensial berarti kode QR');
    assert.deepEqual(manager.listSessionIds(), []);
    assert.equal(kejadianUntuk('s-otomatis-rusak', 'disconnected').at(-1).payload.reason, 'credentials_missing');

    const kosong = await manager.start('s-otomatis-kosong', { fromBootstrap: true });
    assert.equal(kosong, null);
    assert.equal(sockets.length, 0);
});

test('cadangan berisi creds.json yang rusak tidak dipakai untuk memulihkan', async () => {
    const { manager, sockets } = managerUji();

    const entry = await manager.start('s-cadangan-rusak', {
        restore_only: true,
        backup_data: JSON.stringify({ version: 1, files: { 'creds.json': '{"me":{"id":"628"}}' } }),
    });

    assert.equal(entry, null);
    assert.equal(sockets.length, 0);
});

test('saat boot, Laravel yang tidak terjangkau TIDAK dilaporkan sebagai kredensial hilang', async () => {
    const { manager } = managerUji({
        store: {
            sessionExists: async () => {
                throw new Error('Laravel tidak terjangkau');
            },
            ambilBerkas: async () => ({}),
            save: async () => true,
            delete: async () => {},
        },
    });

    await assert.rejects(() => manager.start('s-boot-gangguan', { fromBootstrap: true }), /tidak terjangkau/);
    assert.equal(kejadianUntuk('s-boot-gangguan', 'disconnected').length, 0,
        'Gangguan sesaat tidak boleh membuat Laravel menyuruh pelanggan scan ulang');
});

test('saat boot, cadangan dari Laravel dipakai kalau volume kosong', async () => {
    const cadangan = credsTertaut();
    const { manager, sockets } = managerUji({
        store: {
            sessionExists: async () => true,
            ambilBerkas: async () => ({ 'creds.json': cadangan }),
            save: async () => true,
            delete: async () => {},
        },
    });

    await manager.start('s-boot-pulih', { fromBootstrap: true });

    assert.equal(sockets.length, 1);
    assert.equal(await readFile(path.join(dataPath, 's-boot-pulih', 'creds.json'), 'utf-8'), cadangan);

    await manager.stop('s-boot-pulih');
});

test('tautan pertama oleh manusia tetap memunculkan QR', async () => {
    const { manager, sockets, terakhir } = managerUji();

    await manager.start('s-baru');
    terakhir().ev.emit('connection.update', { qr: 'kode-qr' });
    await tunggu();

    assert.equal(sockets.length, 1);
    assert.equal(manager.status('s-baru').status, 'qr');

    await manager.stop('s-baru');
});

test('pesan masuk membawa waktu aslinya dan penanda tumpukan offline', async () => {
    const { manager, terakhir } = managerUji();
    await siapkanKredensial('s-masuk');
    await manager.start('s-masuk');

    terakhir().ev.emit('messages.upsert', {
        type: 'append',
        messages: [{
            key: { id: 'LAMA', remoteJid: '628111@s.whatsapp.net', fromMe: false },
            message: { conversation: 'halo?' },
            messageTimestamp: 1_700_000_000,
        }],
    });
    terakhir().ev.emit('messages.upsert', {
        type: 'notify',
        messages: [{
            key: { id: 'BARU', remoteJid: '628111@s.whatsapp.net', fromMe: false },
            message: { conversation: 'masih ada?' },
            messageTimestamp: { toNumber: () => 1_700_000_500 },
        }],
    });
    await tunggu();

    const [lama, baru] = kejadianUntuk('s-masuk', 'message').map((k) => k.payload);

    assert.equal(lama.is_backlog, true);
    assert.equal(lama.timestamp, 1_700_000_000);
    assert.equal(baru.is_backlog, false);
    assert.equal(baru.timestamp, 1_700_000_500);
    assert.equal(baru.chat_id, '628111@c.us');

    await manager.stop('s-masuk');
});

test('pesan dari Channel dan siaran tidak diteruskan sebagai percakapan', async () => {
    const { manager, terakhir } = managerUji();
    await siapkanKredensial('s-channel');
    await manager.start('s-channel');

    terakhir().ev.emit('messages.upsert', {
        type: 'notify',
        messages: [
            { key: { id: 'N1', remoteJid: '120363@newsletter', fromMe: false }, message: { conversation: 'kabar' } },
            { key: { id: 'S1', remoteJid: 'status@broadcast', fromMe: false }, message: { conversation: 'status' } },
        ],
    });
    await tunggu();

    assert.equal(kejadianUntuk('s-channel', 'message').length, 0);

    await manager.stop('s-channel');
});

test('chat beralamat LID diteruskan dengan nomor telepon aslinya', async () => {
    const { manager, terakhir } = managerUji({
        socket: {
            signalRepository: {
                lidMapping: { getPNForLID: async () => '628333:5@s.whatsapp.net' },
            },
        },
    });
    await siapkanKredensial('s-lid');
    await manager.start('s-lid');

    terakhir().ev.emit('messages.upsert', {
        type: 'notify',
        messages: [
            {
                key: { id: 'L1', remoteJid: '1234567890@lid', remoteJidAlt: '628222@s.whatsapp.net', fromMe: false },
                message: { conversation: 'dari alt' },
            },
            {
                key: { id: 'L2', remoteJid: '999@lid', fromMe: false },
                message: { conversation: 'dari pemetaan' },
            },
            {
                key: { id: 'G1', remoteJid: '120363@g.us', participant: '555@lid', participantAlt: '628444@s.whatsapp.net', fromMe: false },
                message: { conversation: 'di grup' },
            },
        ],
    });
    await tunggu();

    const [alt, peta, grup] = kejadianUntuk('s-lid', 'message').map((k) => k.payload);

    assert.equal(alt.chat_id, '628222@c.us');
    assert.equal(alt.from, '628222@c.us');
    assert.equal(peta.chat_id, '628333@c.us', 'Nomor perangkat (:5) harus dibuang');
    assert.equal(grup.chat_id, '120363@g.us');
    assert.equal(grup.from, '628444@c.us');

    await manager.stop('s-lid');
});

test('pesan yang baru dikirim bisa diambil lagi untuk permintaan kirim ulang', async () => {
    const { manager, opsiSocket, terakhir } = managerUji();
    await siapkanKredensial('s-ulang');
    await manager.start('s-ulang');
    terakhir().ev.emit('connection.update', { connection: 'open' });

    const hasil = await manager.send('s-ulang', { to: '628555', type: 'text', body: 'tagihan' });

    assert.equal((await opsiSocket[0].getMessage({ id: hasil.wa_message_id }))?.conversation, 'tagihan');
    assert.equal(await opsiSocket[0].getMessage({ id: 'tidak-pernah-dikirim' }), undefined);

    await manager.stop('s-ulang');
});

test('pemeriksaan nomor ke WhatsApp tidak diulang untuk tiap pesan ke nomor yang sama', async () => {
    let ditanyakan = 0;
    const { manager, terakhir } = managerUji({
        socket: {
            onWhatsApp: async (digits) => {
                ditanyakan++;

                return [{ jid: `${digits}@s.whatsapp.net`, exists: true }];
            },
        },
    });
    await siapkanKredensial('s-cek');
    await manager.start('s-cek');
    terakhir().ev.emit('connection.update', { connection: 'open' });

    for (let i = 0; i < 3; i++) {
        await manager.send('s-cek', { to: '628666', type: 'text', body: `pesan ${i}` });
    }

    assert.equal(ditanyakan, 1);

    await manager.stop('s-cek');
});

test('golonganPutus dan detikPesan', () => {
    assert.equal(golonganPutus(401), 'logged_out');
    assert.equal(golonganPutus(403), 'banned');
    assert.equal(golonganPutus(440), 'replaced');
    assert.equal(golonganPutus(515), 'restart');
    assert.equal(golonganPutus(405), 'version');
    assert.equal(golonganPutus(500), 'transient');
    assert.equal(golonganPutus(undefined), 'transient');

    assert.equal(detikPesan('1700000000'), 1_700_000_000);
    assert.equal(detikPesan(null), null);
    assert.equal(detikPesan('bukan angka'), null);
});
