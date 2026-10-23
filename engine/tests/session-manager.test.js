import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { test } from 'node:test';
import { DisconnectReason, WAMessageStatus } from '@whiskeysockets/baileys';

process.env.ENGINE_TOKEN = 'uji';
process.env.ENGINE_HMAC_SECRET = 'uji';
process.env.LARAVEL_URL = 'http://127.0.0.1:9';
process.env.ENGINE_LOG_LEVEL = 'silent';
process.env.WA_INIT_TIMEOUT_MS = '150';
process.env.WA_DATA_PATH = await mkdtemp(path.join(tmpdir(), 'vexahost-wa-manager-'));

const { SessionManager, mapType, mapAck, extractBody, toContractChatId } = await import(
    '../src/session-manager.js'
);
const { config, parseWaWebVersion } = await import('../src/config.js');

function socketTiruan(overrides = {}) {
    const ev = new EventEmitter();
    const sock = {
        ev,
        user: { id: '628123456789:1@s.whatsapp.net', name: 'Uji VexaHost' },
        ditutup: false,
        end: () => {
            sock.ditutup = true;
        },
        logout: async () => {},
        sendMessage: async (chatId, content) => ({
            key: { id: 'WA_TEST_MSG_1', remoteJid: chatId },
            message: content,
        }),
        onWhatsApp: async (digits) => [{ jid: `${digits}@s.whatsapp.net`, exists: true }],
        ...overrides,
    };

    return sock;
}

const storeTiruan = {
    sessionExists: async () => false,
    extract: async () => {},
    save: async () => {},
    delete: async () => {},
};

function tunggu(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

test('sesi yang menggantung tanpa koneksi dihentikan setelah batas waktu init', async () => {
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-gantung');

    assert.deepEqual(manager.listSessionIds(), ['sesi-gantung']);
    assert.equal(manager.status('sesi-gantung').status, 'connecting');

    await tunggu(350);

    assert.deepEqual(
        manager.listSessionIds(),
        [],
        'Sesi yang menggantung harus dilepas setelah batas waktu inisialisasi',
    );
    assert.equal(sock.ditutup, true);
    assert.equal(manager.status('sesi-gantung'), null);
});

test('event qr melepas pengukur init dan mengubah status menjadi qr', async () => {
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-qr');

    sock.ev.emit('connection.update', { qr: 'qr-dummy-code' });
    await tunggu(50);

    assert.equal(manager.status('sesi-qr').status, 'qr');

    await tunggu(350);
    // Masih harus hidup karena QR melepas pengukur init
    assert.deepEqual(manager.listSessionIds(), ['sesi-qr']);
    assert.equal(sock.ditutup, false);
});

test('connection open mengubah status menjadi connected dan mengisi data nomor', async () => {
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-open');

    sock.ev.emit('connection.update', { connection: 'open' });
    await tunggu(50);

    const st = manager.status('sesi-open');
    assert.equal(st.status, 'connected');
    assert.equal(st.phone_number, '628123456789');
    assert.equal(st.push_name, 'Uji VexaHost');

    await tunggu(350);
    assert.deepEqual(manager.listSessionIds(), ['sesi-open']);
    assert.equal(sock.ditutup, false);
});

test('disconnect loggedOut (401) mematikan sesi tanpa mencoba reconnect', async () => {
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-logout');
    sock.ev.emit('connection.update', { connection: 'open' });

    sock.ev.emit('connection.update', {
        connection: 'close',
        lastDisconnect: { error: { output: { statusCode: DisconnectReason.loggedOut } } },
    });

    await tunggu(50);

    assert.deepEqual(manager.listSessionIds(), []);
    assert.equal(sock.ditutup, true);
});

test('stop() menutup socket dan membersihkan entry', async () => {
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-stop');
    await manager.stop('sesi-stop');

    assert.equal(sock.ditutup, true);
    assert.deepEqual(manager.listSessionIds(), []);
    assert.equal(manager.status('sesi-stop'), null);
});

test('send() mengirimkan pesan dan menangkal duplikasi pengiriman', async () => {
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-kirim');
    sock.ev.emit('connection.update', { connection: 'open' });

    const hasil1 = await manager.send('sesi-kirim', {
        to: '628123456789',
        type: 'text',
        body: 'Halo pesan uji',
        messageId: 'laravel-msg-001',
    });

    assert.equal(hasil1.wa_message_id, 'WA_TEST_MSG_1');
    assert.equal(hasil1.chat_id, '628123456789@c.us');

    // Pengiriman ulang dengan messageId yang sama harus mengembalikan hasil yang sama
    const hasil2 = await manager.send('sesi-kirim', {
        to: '628123456789',
        type: 'text',
        body: 'Halo pesan uji',
        messageId: 'laravel-msg-001',
    });

    assert.equal(hasil2.wa_message_id, 'WA_TEST_MSG_1');
    assert.equal(hasil2.chat_id, '628123456789@c.us');
});

test('send() melempar galat permanent jika nomor tidak terdaftar di WhatsApp', async () => {
    const sock = socketTiruan({
        onWhatsApp: async () => [{ exists: false }],
    });
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-invalid-no');
    sock.ev.emit('connection.update', { connection: 'open' });

    await assert.rejects(
        async () => {
            await manager.send('sesi-invalid-no', {
                to: '628999999999',
                type: 'text',
                body: 'Pesan',
            });
        },
        (err) => {
            assert.equal(err.permanent, true);
            assert.match(err.message, /tidak terdaftar di WhatsApp/);
            return true;
        },
    );
});

test('stats() menghitung total sesi berdasarkan statusnya', async () => {
    const sock1 = socketTiruan();
    const sock2 = socketTiruan();
    let buatCount = 0;

    const manager = new SessionManager({
        buatSocket: () => {
            buatCount++;
            return buatCount === 1 ? sock1 : sock2;
        },
        store: storeTiruan,
    });

    await manager.start('s1');
    await manager.start('s2');

    sock1.ev.emit('connection.update', { connection: 'open' });
    sock2.ev.emit('connection.update', { qr: 'dummy-qr' });

    const st = manager.stats();
    assert.equal(st.total, 2);
    assert.equal(st.connected, 1);
    assert.equal(st.qr, 1);
    assert.equal(st.connecting, 0);
});

test('toContractChatId() mengubah @s.whatsapp.net menjadi @c.us dan mempertahankan @g.us', () => {
    assert.equal(toContractChatId('628123456789@s.whatsapp.net'), '628123456789@c.us');
    assert.equal(toContractChatId('123456789-987654@g.us'), '123456789-987654@g.us');
    assert.equal(toContractChatId('628123456789@c.us'), '628123456789@c.us');
    assert.equal(toContractChatId(null), null);
});

test('mapType() memetakan tipe konten Baileys ke tipe Laravel yang diharapkan', () => {
    assert.equal(mapType('conversation'), 'text');
    assert.equal(mapType('extendedTextMessage'), 'text');
    assert.equal(mapType('imageMessage'), 'image');
    assert.equal(mapType('documentMessage'), 'document');
    assert.equal(mapType('videoMessage'), 'video');
    assert.equal(mapType('audioMessage'), 'audio');
    assert.equal(mapType('locationMessage'), 'location');
    assert.equal(mapType('unknownType'), 'other');
});

test('mapAck() memetakan status pesan Baileys ke nomor ack Laravel (1/2/3)', () => {
    assert.equal(mapAck(WAMessageStatus.SERVER_ACK), 1);
    assert.equal(mapAck(WAMessageStatus.DELIVERY_ACK), 2);
    assert.equal(mapAck(WAMessageStatus.READ), 3);
    assert.equal(mapAck(WAMessageStatus.PLAYED), 3);
    assert.equal(mapAck(WAMessageStatus.PENDING), null);
    assert.equal(mapAck(WAMessageStatus.ERROR), null);
});

test('extractBody() mengekstrak teks isi dari berbagai tipe konten', () => {
    assert.equal(extractBody('conversation', 'Halo Baileys'), 'Halo Baileys');
    assert.equal(extractBody('extendedTextMessage', { text: 'Teks panjang' }), 'Teks panjang');
    assert.equal(extractBody('imageMessage', { caption: 'Foto profil' }), 'Foto profil');
    assert.equal(extractBody('locationMessage', { name: 'Kantor Pusat' }), 'Kantor Pusat');
    assert.equal(extractBody('unknown', {}), null);
});

test('start() dengan has_backup: false tidak memanggil store.sessionExists (mencegah deadlock loopback)', async () => {
    let sessionExistsCalled = false;
    const store = {
        sessionExists: async () => {
            sessionExistsCalled = true;
            return false;
        },
        extract: async () => {},
        save: async () => {},
        delete: async () => {},
    };
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store });

    await manager.start('sesi-baru-tanpa-backup', { has_backup: false });

    assert.equal(sessionExistsCalled, false, 'store.sessionExists tidak boleh dipanggil saat has_backup: false');
    assert.equal(manager.status('sesi-baru-tanpa-backup').status, 'connecting');
});

test('start() dengan backup_data mengekstrak berkas langsung tanpa HTTP request ke store', async () => {
    let sessionExistsCalled = false;
    let extractCalled = false;
    const store = {
        sessionExists: async () => {
            sessionExistsCalled = true;
            return true;
        },
        extract: async () => {
            extractCalled = true;
        },
        save: async () => {},
        delete: async () => {},
    };
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store });

    const backupPayload = JSON.stringify({
        version: 1,
        sessionId: 'sesi-dengan-payload-backup',
        files: { 'creds.json': JSON.stringify({ test: 123 }) },
    });

    await manager.start('sesi-dengan-payload-backup', { has_backup: true, backup_data: backupPayload });

    assert.equal(sessionExistsCalled, false, 'store.sessionExists tidak dipanggil jika backup_data disertakan');
    assert.equal(extractCalled, false, 'store.extract tidak dipanggil jika backup_data disertakan');
    assert.equal(manager.status('sesi-dengan-payload-backup').status, 'connecting');
});

test('transient disconnect (515 restartRequired atau 428) sebelum QR memicu reconnect transparan tanpa menjatuhkan sesi', async () => {
    const sock1 = socketTiruan();
    const sock2 = socketTiruan();
    let socketCount = 0;

    const manager = new SessionManager({
        buatSocket: () => {
            socketCount++;
            return socketCount === 1 ? sock1 : sock2;
        },
        store: storeTiruan,
    });

    await manager.start('sesi-transient-close', { has_backup: false });
    assert.equal(manager.status('sesi-transient-close').status, 'connecting');

    // WhatsApp menutup koneksi dengan restartRequired (515) pada koneksi awal sebelum QR
    sock1.ev.emit('connection.update', {
        connection: 'close',
        lastDisconnect: { error: { output: { statusCode: DisconnectReason.restartRequired } } },
    });

    // Reconnect berlangsung segera (0ms)
    await tunggu(50);
    assert.equal(socketCount, 2, 'Socket kedua harus dibuat otomatis untuk reconnect');
    assert.equal(manager.status('sesi-transient-close').status, 'connecting');

    // Socket kedua memancarkan QR
    sock2.ev.emit('connection.update', { qr: 'qr-reconnected' });
    await tunggu(50);

    assert.equal(manager.status('sesi-transient-close').status, 'qr');
    assert.deepEqual(manager.listSessionIds(), ['sesi-transient-close']);
});

test('socketOptions menyertakan versi WhatsApp Web yang dikonfigurasi', async () => {
    let capturedOptions = null;
    const sock = socketTiruan();
    const manager = new SessionManager({
        buatSocket: (id, options) => {
            capturedOptions = options;
            return sock;
        },
        store: storeTiruan,
    });

    await manager.start('sesi-versi', { has_backup: false });

    assert.ok(capturedOptions, 'socketOptions harus diterima oleh buatSocket');
    assert.deepEqual(capturedOptions.version, config.waWebVersion);
});

test('parseWaWebVersion mem-parse string titik, koma, atau array dengan benar', () => {
    assert.deepEqual(parseWaWebVersion('2.3000.1043857760'), [2, 3000, 1043857760]);
    assert.deepEqual(parseWaWebVersion('2,3000,1043857760'), [2, 3000, 1043857760]);
    assert.deepEqual(parseWaWebVersion([2, 3000, 1043857760]), [2, 3000, 1043857760]);
    assert.deepEqual(parseWaWebVersion(null), [2, 3000, 1043857760]);
    assert.deepEqual(parseWaWebVersion('invalid'), [2, 3000, 1043857760]);
});

test('start() tanpa has_backup (default aman) tidak memanggil store.sessionExists', async () => {
    let sessionExistsCalled = false;
    const store = {
        sessionExists: async () => {
            sessionExistsCalled = true;
            return false;
        },
        extract: async () => {},
        save: async () => {},
        delete: async () => {},
    };
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store });

    // Dipanggil tanpa opsi sama sekali (mis. panggilan lama atau field hilang)
    await manager.start('sesi-default-aman');

    assert.equal(sessionExistsCalled, false, 'store.sessionExists tidak boleh dipanggil jika has_backup tidak diisi true');
    assert.equal(manager.status('sesi-default-aman').status, 'connecting');
});

test('logout() tidak memanggil store.delete secara sinkron sebelum merespons (mencegah deadlock loopback)', async () => {
    let deleteCalledBeforeReturn = false;
    let logoutResolved = false;
    const store = {
        sessionExists: async () => false,
        extract: async () => {},
        save: async () => {},
        delete: async () => {
            if (!logoutResolved) {
                deleteCalledBeforeReturn = true;
            }
        },
    };
    const sock = socketTiruan();
    const manager = new SessionManager({ buatSocket: () => sock, store });

    await manager.start('sesi-logout-async', { has_backup: false });

    const logoutPromise = manager.logout('sesi-logout-async');
    await logoutPromise;
    logoutResolved = true;

    assert.equal(deleteCalledBeforeReturn, false, 'store.delete tidak boleh dipanggil secara sinkron menahan logout');
});

test('logout() merespons dalam hitungan milidetik bahkan saat sock.logout() menggantung selamanya', async () => {
    let sockEndCalled = false;
    const sock = socketTiruan({
        logout: () => new Promise(() => {}), // menggantung selamanya tanpa resolve/reject
        end: () => {
            sockEndCalled = true;
        },
    });
    const manager = new SessionManager({ buatSocket: () => sock, store: storeTiruan });

    await manager.start('sesi-logout-gantung', { has_backup: false });

    const awal = Date.now();
    await manager.logout('sesi-logout-gantung');
    const durasi = Date.now() - awal;

    // Harus selesai segera (jauh di bawah ENGINE_TIMEOUT 15 detik), idealnya < 200ms
    assert.ok(durasi < 500, `logout() harus segera selesai tanpa menunggu WhatsApp (durasi ${durasi}ms >= 500ms)`);
    assert.equal(manager.status('sesi-logout-gantung'), null, 'Sesi harus sudah dibersihkan dari manager');
});



