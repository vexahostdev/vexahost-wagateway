import { mkdir, rename, rm } from 'node:fs/promises';
import path from 'node:path';
import makeWASocket, {
    Browsers,
    DisconnectReason,
    getContentType,
    isJidBroadcast,
    isJidGroup,
    isJidNewsletter,
    isLidUser,
    isPnUser,
    jidNormalizedUser,
    WAMessageStatus,
} from '@whiskeysockets/baileys';
import pino from 'pino';
import qrcode from 'qrcode';
import { ArsipKirim } from './arsip-kirim.js';
import {
    nilaiKredensial,
    pakaiAuthStateBerkas,
    periksaKredensial,
    tulisUlangKredensial,
    tungguPenulisanSelesai,
} from './auth-state.js';
import { config } from './config.js';
import { laravel } from './laravel.js';
import { logger } from './logger.js';
import { SendQueue } from './queue.js';
import { LaravelStore } from './stores/laravel-store.js';
import { VersiWhatsApp } from './versi-wa.js';

/**
 * Berapa kali gangguan sementara disambung ulang sebelum sesi dilepas dan
 * keputusannya diserahkan ke penjadwal Laravel (yang punya batasnya sendiri).
 */
const BATAS_SAMBUNG_ULANG = 5;

/**
 * Hasil `onWhatsApp` disimpan sebentar. Tanpa ini setiap pesan didahului satu
 * pertanyaan "apakah nomor ini terdaftar" ke WhatsApp — broadcast ulang ke
 * daftar yang sama mengulang ratusan pemeriksaan, pola yang juga dipakai alat
 * pemindai nomor. Nomor yang tidak terdaftar disimpan lebih singkat karena
 * orang memang bisa mendaftar belakangan.
 */
const UMUR_NOMOR_TERDAFTAR_MS = 24 * 60 * 60 * 1000;
const UMUR_NOMOR_TIDAK_TERDAFTAR_MS = 60 * 60 * 1000;
const BATAS_CACHE_NOMOR = 10_000;

export class SessionManager {
    #sessions = new Map();
    #starting = new Set();
    #queue = new SendQueue();
    #store;

    /**
     * Hasil pengiriman per message_id, untuk menangkal kiriman ganda.
     *
     * Laravel mengulang job-nya kalau permintaan HTTP-nya habis waktu — padahal
     * habis waktu bukan berarti pesannya tidak terkirim. Antrean anti-ban di
     * engine menahan tiap pesan beberapa detik sebelum benar-benar dikirim, jadi
     * pesan berikutnya dalam satu giliran bisa menunggu lebih lama daripada batas
     * waktu HTTP Laravel.
     *
     * Kunci map ini message_id milik Laravel, jadi percobaan ulang menerima
     * hasil kiriman pertama alih-alih mengirim ulang. Nilainya Promise, bukan
     * hasil jadi — percobaan ulang yang datang saat kiriman pertama masih
     * berjalan ikut menunggu promise yang sama.
     */
    #kiriman = new Map();

    #nomorTerdaftar = new Map();

    #arsip = new ArsipKirim();

    /**
     * Pabrik socket, bisa diganti saat pengujian unit.
     */
    #buatSocket;

    #versi;

    #stabilMs;

    /**
     * Jeda sebelum sambung ulang ke-n. Bisa diganti saat pengujian — backoff
     * sungguhan sampai 15 detik per percobaan.
     */
    #jedaSambungUlang;

    constructor({ buatSocket, store, versi, stabilMs, jedaSambungUlang } = {}) {
        this.#store = store ?? new LaravelStore();
        this.#buatSocket = buatSocket ?? ((sessionId, options) => makeWASocket(options));
        this.#versi = versi ?? new VersiWhatsApp();
        this.#stabilMs = stabilMs ?? 60_000;
        this.#jedaSambungUlang = jedaSambungUlang ?? jedaSambungUlangBawaan;
    }

    /**
     * Menjalankan ulang seluruh sesi yang tercatat di Laravel.
     *
     * Inilah yang membuat redeploy tidak lagi memaksa scan QR: daftar sesi ada
     * di database, kredensialnya ada di persistent volume (dengan cadangan di
     * Laravel), jadi engine yang baru lahir bisa memulihkan keduanya sendiri.
     */
    async bootstrap() {
        await mkdir(config.dataPath, { recursive: true });

        let sessions;

        try {
            sessions = await this.#ambilDaftarSesi();
        } catch (error) {
            logger.error({ err: error.message }, 'Bootstrap gagal; engine tetap jalan tanpa sesi');

            return;
        }

        logger.info({ count: sessions.length }, 'Memulihkan sesi tersimpan');

        for (const { session_id: sessionId, name } of sessions) {
            try {
                const entry = await this.start(sessionId, { fromBootstrap: true });

                if (entry) {
                    logger.info({ sessionId, name }, 'Sesi dipulihkan');
                }
            } catch (error) {
                // Satu sesi rusak tidak boleh menahan pemulihan sesi lainnya.
                logger.error({ sessionId, err: error.message }, 'Gagal memulihkan sesi');
            }
        }
    }

    /**
     * Menanyakan daftar sesi ke Laravel, dengan percobaan ulang.
     */
    async #ambilDaftarSesi() {
        const percobaan = 5;

        for (let ke = 1; ke <= percobaan; ke++) {
            try {
                return await laravel.bootstrap();
            } catch (error) {
                if (ke === percobaan) throw error;

                logger.warn(
                    { percobaan: ke, err: error.message },
                    'Bootstrap belum berhasil, mencoba lagi dalam 5 detik',
                );

                await new Promise((resolve) => setTimeout(resolve, 5000));
            }
        }
    }

    /**
     * @param {string} sessionId
     * @param {{ backup_data?: string|object, fromBootstrap?: boolean, restore_only?: boolean }} opsi
     *   `fromBootstrap` dan `restore_only` sama-sama berarti "tidak ada manusia
     *   yang sedang menatap kode QR" — sesinya hanya boleh dipulihkan.
     * @returns {Promise<object|null>} entry sesi, atau null kalau sesi sengaja
     *   tidak dijalankan karena tidak ada kredensial untuk dipulihkan
     */
    async start(sessionId, { backup_data, fromBootstrap, restore_only } = {}) {
        if (this.#sessions.has(sessionId)) {
            return this.#sessions.get(sessionId);
        }

        if (this.#starting.has(sessionId)) {
            throw new Error('Sesi sedang dalam proses dijalankan.');
        }

        if (this.#sessions.size >= config.maxSessions) {
            throw new Error(
                `Engine sudah menjalankan ${config.maxSessions} sesi (batas WA_MAX_SESSIONS).`
            );
        }

        this.#starting.add(sessionId);

        try {
            const sessionDir = path.join(config.dataPath, sessionId);
            await mkdir(sessionDir, { recursive: true });

            let kondisi = await periksaKredensial(sessionDir);

            // Kredensial lokal yang tertaut selalu menang atas cadangan: berkas
            // di volume lebih baru, sedangkan cadangan bisa tertinggal sampai
            // satu interval pencadangan.
            if (kondisi !== 'tertaut'
                && await this.#pulihkanKredensial(sessionId, sessionDir, kondisi, { backup_data, fromBootstrap })) {
                kondisi = 'tertaut';
            }

            /*
             | Jalur otomatis hanya boleh MEMULIHKAN, tidak boleh menautkan.
             |
             | Tanpa kredensial, menjalankan sesi berarti memunculkan kode QR
             | yang tidak ditatap siapa pun — memakan satu slot engine, berganti
             | QR tiap 20 detik, lalu dicoba lagi tiap menit oleh penjadwal.
             | Laravel diberi tahu supaya kartu sesinya bilang "scan ulang",
             | bukan "terputus" yang seolah akan pulih sendiri.
            */
            if (kondisi !== 'tertaut' && (fromBootstrap === true || restore_only === true)) {
                logger.warn({ sessionId, kondisi }, 'Tidak ada kredensial yang bisa dipulihkan; sesi tidak dijalankan');

                await laravel.event(sessionId, 'disconnected', { reason: 'credentials_missing', status_code: null });

                return null;
            }

            if (kondisi === 'rusak') {
                await this.#singkirkanKredensialRusak(sessionId, sessionDir);
            } else if (kondisi === 'tidak_ada') {
                // Berkas kunci sisa tautan lama tanpa creds.json-nya akan
                // tercampur dengan identitas baru yang dibuat Baileys — sesi
                // Signal dari dua identitas berbeda di satu folder.
                await tungguPenulisanSelesai();
                await rm(sessionDir, { recursive: true, force: true, maxRetries: 3 });
                await mkdir(sessionDir, { recursive: true });
            }

            const entry = {
                sock: null,
                auth: await pakaiAuthStateBerkas(sessionDir),
                status: 'connecting',
                phoneNumber: null,
                pushName: null,
                loadingPercent: null,
                pengukurInit: null,
                pengukurStabil: null,
                backupTimer: null,
                reconnectAttempts: 0,
                reconnectTimer: null,
                sessionDir,
                stopped: false,
                qrImage: null,
            };

            this.#sessions.set(sessionId, entry);
            this.#pasangPengukurInit(sessionId, entry);

            await this.#sambungSocket(sessionId, entry);

            return entry;
        } catch (error) {
            const entry = this.#sessions.get(sessionId);
            if (entry) {
                entry.status = 'failed';
                this.#lepasPengukurInit(entry);
                this.#sessions.delete(sessionId);
            }
            throw error;
        } finally {
            this.#starting.delete(sessionId);
        }
    }

    /**
     * Mengisi folder sesi dari cadangan, kalau ada cadangan yang layak.
     *
     * @returns {Promise<boolean>} apakah folder sesi sekarang berisi kredensial tertaut
     */
    async #pulihkanKredensial(sessionId, sessionDir, kondisi, { backup_data, fromBootstrap }) {
        let berkas = null;

        if (backup_data) {
            berkas = berkasDariCadangan(backup_data);
        } else if (fromBootstrap === true) {
            // Hanya saat engine booting (bukan saat melayani request HTTP dari
            // Laravel) boleh bertanya ke store Laravel — permintaan HTTP dari
            // Laravel yang menunggu Laravel sendiri adalah deadlock.
            //
            // Galatnya sengaja TIDAK ditelan. "Laravel tidak terjangkau" bukan
            // "tidak ada cadangan": menelannya membuat sesi dilaporkan kehilangan
            // kredensial karena gangguan sesaat, dan pemiliknya disuruh scan ulang
            // nomor yang kredensialnya sebenarnya utuh.
            if (await this.#store.sessionExists({ session: sessionId })) {
                berkas = await this.#store.ambilBerkas({ session: sessionId });
            }
        }

        if (!berkas) {
            return false;
        }

        if (nilaiKredensial(berkas['creds.json']) !== 'tertaut') {
            logger.warn({ sessionId }, 'Cadangan diabaikan: creds.json di dalamnya rusak atau belum tertaut');

            return false;
        }

        if (kondisi === 'rusak') {
            await this.#singkirkanKredensialRusak(sessionId, sessionDir);
        }

        await tulisUlangKredensial(sessionDir, berkas);

        logger.info({ sessionId, kondisiLokal: kondisi }, 'Kredensial sesi dipulihkan dari cadangan');

        return true;
    }

    /**
     * Kredensial rusak dipindahkan, bukan dihapus dan bukan ditimpa.
     *
     * Ditimpa diam-diam adalah perilaku bawaan Baileys yang dulu membuat nomor
     * "tiba-tiba minta scan ulang" tanpa jejak apa pun. Salinannya disimpan
     * (hanya yang terakhir) supaya penyebab kerusakannya masih bisa diperiksa.
     */
    async #singkirkanKredensialRusak(sessionId, sessionDir) {
        const tujuan = `${sessionDir}.rusak`;

        await tungguPenulisanSelesai();
        await rm(tujuan, { recursive: true, force: true, maxRetries: 3 });
        await rename(sessionDir, tujuan);
        await mkdir(sessionDir, { recursive: true });

        logger.error({ sessionId, disimpanDi: tujuan }, 'creds.json rusak; disingkirkan supaya tidak diganti identitas baru diam-diam');
    }

    async #sambungSocket(sessionId, entry) {
        if (entry.stopped || !this.#sessions.has(sessionId)) return;

        // Auth state dibuat SEKALI per sesi dan dipakai bersama oleh setiap
        // socket sambung ulang. Baileys mengubah objek kredensialnya di tempat;
        // dua objek terpisah untuk satu folder berarti socket lama yang telat
        // menyimpan bisa menimpa kredensial yang lebih baru.
        const socketOptions = {
            auth: entry.auth.state,
            version: this.#versi.sekarang(),
            printQRInTerminal: false,
            logger: pino({ level: 'silent' }),
            browser: Browsers.ubuntu('Chrome'),
            syncFullHistory: false,
            markOnlineOnConnect: false,
            generateHighQualityLinkPreview: false,
            getMessage: async (key) => this.#arsip.ambil(sessionId, key?.id),
        };

        const sock = this.#buatSocket(sessionId, socketOptions);
        entry.sock = sock;

        this.#bindEvents(sessionId, entry, sock);
    }

    #bindEvents(sessionId, entry, sock) {
        if (typeof sock.ev?.on !== 'function') return;

        const sesiUsang = () => entry.stopped || this.#sessions.get(sessionId) !== entry;

        sock.ev.on('creds.update', async () => {
            // Tidak memandang socket mana yang memicunya: auth state-nya satu
            // untuk seluruh socket sesi ini, jadi yang disimpan selalu keadaan
            // terbaru. Yang dicegah hanya sesi yang sudah dihentikan atau diganti
            // — mereka tidak boleh lagi menulis ke folder yang mungkin sudah
            // dipakai tautan baru.
            if (sesiUsang()) return;

            try {
                await entry.auth.saveCreds();
            } catch (err) {
                logger.error({ sessionId, err: err.message }, 'Gagal menyimpan pembaruan creds ke disk');
            }
        });

        sock.ev.on('connection.update', async (update) => {
            // Socket yang sudah diganti oleh sambung ulang tidak boleh lagi
            // mengubah keadaan: `close` yang telat darinya akan menjadwalkan
            // sambung ulang yang mematikan socket penggantinya.
            if (sesiUsang() || entry.sock !== sock) return;

            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                entry.status = 'qr';
                this.#lepasPengukurInit(entry);

                try {
                    const image = await qrcode.toDataURL(qr, { width: 320, margin: 1 });
                    entry.qrImage = image;
                    laravel.event(sessionId, 'qr', { qr_image: image }).catch((err) => {
                        logger.warn({ sessionId, err: err.message }, 'Gagal memancarkan event QR ke Laravel');
                    });
                } catch (err) {
                    logger.error({ sessionId, err: err.message }, 'Gagal mengonversi QR ke data URL');
                }
            }

            if (connection === 'open') {
                const rawId = sock.user?.id || '';
                const phoneNumber = rawId.split(':')[0].replace(/\D+/g, '') || null;
                const pushName = sock.user?.name || null;

                entry.status = 'connected';
                entry.phoneNumber = phoneNumber;
                entry.pushName = pushName;
                entry.loadingPercent = null;
                entry.qrImage = null;

                this.#lepasPengukurInit(entry);
                this.#pasangPengukurStabil(entry);
                this.#pasangBackupInterval(sessionId, entry);

                logger.info({ sessionId, phoneNumber, pushName }, 'Sesi siap');

                await laravel.event(sessionId, 'ready', {
                    phone_number: phoneNumber,
                    push_name: pushName,
                });

                // Simpan backup awal ke Laravel saat berhasil terkoneksi
                this.persist(sessionId).catch((err) => {
                    logger.warn({ sessionId, err: err.message }, 'Gagal menyimpan backup awal ke Laravel');
                });
            }

            if (connection === 'close') {
                await this.#tanganiPutus(sessionId, entry, lastDisconnect);
            }
        });

        sock.ev.on('messages.upsert', async ({ messages, type: jenis }) => {
            if (!Array.isArray(messages)) return;

            for (const msg of messages) {
                if (!msg?.message || msg.key?.fromMe) continue;

                const remoteJid = msg.key?.remoteJid;

                // Status, siaran, dan Channel bukan percakapan: tidak ada yang
                // bisa dibalas di sana, dan meneruskannya ke integrasi yang
                // membalas otomatis berarti balasan ke alamat yang tidak ada.
                if (!remoteJid || isJidBroadcast(remoteJid) || isJidNewsletter(remoteJid)) {
                    continue;
                }

                // Unwrap pesan berulang / ephemeral / view once
                let message = msg.message;
                if (message?.ephemeralMessage?.message) message = message.ephemeralMessage.message;
                if (message?.viewOnceMessage?.message) message = message.viewOnceMessage.message;
                if (message?.viewOnceMessageV2?.message) message = message.viewOnceMessageV2.message;
                if (message?.viewOnceMessageV2Extension?.message) message = message.viewOnceMessageV2Extension.message;
                if (message?.documentWithCaptionMessage?.message) message = message.documentWithCaptionMessage.message;

                const contentType = getContentType(message);
                if (!contentType) continue;

                const grup = isJidGroup(remoteJid);
                const chatJid = grup ? remoteJid : await nomorAsli(sock, remoteJid, msg.key.remoteJidAlt);
                const pengirim = grup
                    ? await nomorAsli(sock, msg.key.participant || remoteJid, msg.key.participantAlt)
                    : chatJid;

                await laravel.event(sessionId, 'message', {
                    wa_message_id: msg.key.id ?? null,
                    chat_id: toContractChatId(chatJid),
                    from: toContractChatId(jidNormalizedUser(pengirim)),
                    type: mapType(contentType),
                    body: extractBody(contentType, message[contentType]),
                    // Pesan yang menumpuk selama sesi offline datang sekaligus
                    // saat tersambung lagi — setiap deploy. Tanpa dua penanda ini
                    // integrasi yang membalas otomatis tidak bisa membedakan
                    // "halo?" dari tiga jam lalu dari pesan barusan, lalu
                    // menjawab seluruh tumpukan dalam satu ledakan.
                    timestamp: detikPesan(msg.messageTimestamp),
                    is_backlog: jenis === 'append',
                });
            }
        });

        sock.ev.on('messages.update', async (updates) => {
            if (!Array.isArray(updates)) return;

            for (const update of updates) {
                if (!update.key?.id || update.update?.status == null) continue;

                const ack = mapAck(update.update.status);
                if (ack !== null) {
                    await laravel.event(sessionId, 'message_ack', {
                        wa_message_id: update.key.id,
                        ack,
                    });
                }
            }
        });
    }

    /**
     * Apa yang dilakukan setelah koneksi tertutup ditentukan oleh KENAPA ia
     * tertutup. Dulu semua kode selain 401 dan 515 disambung ulang seperti
     * gangguan jaringan — termasuk akun yang diblokir (dicoba login terus) dan
     * perangkat yang diambil alih engine lain (keduanya saling tendang).
     */
    async #tanganiPutus(sessionId, entry, lastDisconnect) {
        entry.qrImage = null;
        this.#lepasBackupInterval(entry);
        this.#lepasPengukurStabil(entry);

        const statusCode = lastDisconnect?.error?.output?.statusCode;
        const golongan = golonganPutus(statusCode);

        logger.warn({ sessionId, statusCode, golongan }, 'Koneksi websocket terputus');

        switch (golongan) {
            case 'logged_out':
                // Kredensialnya sudah dicabut WhatsApp. Menyimpannya berarti setiap
                // Hubungkan, setiap giliran penjadwal, dan setiap deploy login
                // dengan kunci mati — ditolak 401 lagi, dan QR tidak pernah muncul.
                logger.warn({ sessionId }, 'Sesi dikeluarkan oleh WhatsApp (logged out)');
                await this.#akhiri(sessionId, entry, { reason: 'logged_out', statusCode, buangKredensial: true });

                return;

            case 'banned':
            case 'replaced':
                // Kredensialnya tetap disimpan — nomor yang dipulihkan WhatsApp,
                // atau perangkat yang direbut kembali, tersambung lagi tanpa scan.
                // Yang dihentikan cuma percobaan otomatisnya.
                await this.#akhiri(sessionId, entry, { reason: golongan, statusCode, buangKredensial: false });

                return;

            case 'restart':
                logger.info({ sessionId }, 'Restart diperlukan oleh WhatsApp (515), menyambung ulang segera');
                this.#jadwalkanReconnect(sessionId, entry, 0);

                return;

            case 'version':
                // Versi yang dipakai ditolak. Ambil yang terbaru lalu perlakukan
                // seperti gangguan sementara — kalau versinya memang belum
                // berganti, batas sambung ulang di bawah tetap berlaku.
                await this.#versi.segarkan({ paksa: true });

                break;

            default:
                break;
        }

        if (entry.reconnectAttempts < BATAS_SAMBUNG_ULANG) {
            entry.reconnectAttempts++;
            const jeda = this.#jedaSambungUlang(entry.reconnectAttempts, entry.status);
            logger.info(
                { sessionId, attempt: entry.reconnectAttempts, jedaMs: Math.round(jeda) },
                'Mencoba menyambung kembali websocket',
            );
            this.#jadwalkanReconnect(sessionId, entry, Math.round(jeda));
        } else {
            logger.warn({ sessionId, attempts: entry.reconnectAttempts }, 'Batas percobaan reconnect habis, sesi dihentikan');
            await this.#akhiri(sessionId, entry, { reason: 'connection_lost', statusCode, buangKredensial: false });
        }
    }

    /**
     * Menghentikan sesi karena keputusan di sisi WhatsApp, lalu melapor.
     *
     * Urutannya disengaja: kredensial dibuang SELAGI entry masih terdaftar.
     * Selama itu `start()` untuk sesi yang sama mengembalikan entry ini alih-alih
     * membuat folder baru — kalau dibalik, penghapusan bisa mengenai folder
     * tautan baru yang dibuat penjadwal di antaranya.
     */
    async #akhiri(sessionId, entry, { reason, statusCode, buangKredensial }) {
        entry.status = 'disconnected';
        entry.stopped = true;
        this.#lepasPengukurInit(entry);

        if (buangKredensial) {
            this.#arsip.lupakan(sessionId);

            try {
                await tungguPenulisanSelesai();
                await rm(entry.sessionDir, { recursive: true, force: true, maxRetries: 3 });
            } catch (err) {
                logger.warn({ sessionId, err: err.message }, 'Gagal membuang kredensial lokal yang sudah dicabut');
            }
        }

        await this.stop(sessionId);

        await laravel.event(sessionId, 'disconnected', { reason, status_code: statusCode ?? null });
    }

    #jadwalkanReconnect(sessionId, entry, delayMs) {
        if (entry.reconnectTimer) {
            clearTimeout(entry.reconnectTimer);
            entry.reconnectTimer = null;
        }

        if (delayMs === 0) {
            this.#lakukanReconnect(sessionId, entry);
            return;
        }

        entry.reconnectTimer = setTimeout(() => {
            this.#lakukanReconnect(sessionId, entry);
        }, delayMs);
        entry.reconnectTimer.unref?.();
    }

    async #lakukanReconnect(sessionId, entry) {
        if (entry.stopped || this.#sessions.get(sessionId) !== entry) return;

        try {
            if (entry.sock) {
                try {
                    entry.sock.end?.();
                } catch {}
                entry.sock = null;
            }

            await this.#sambungSocket(sessionId, entry);
        } catch (error) {
            logger.error({ sessionId, err: error.message }, 'Gagal saat mencoba menyambung ulang socket');
        }
    }

    #pasangPengukurInit(sessionId, entry) {
        entry.pengukurInit = setTimeout(async () => {
            if (this.#sessions.get(sessionId) !== entry) return;

            logger.error(
                { sessionId, batasMs: config.initTimeoutMs },
                'Sesi tidak selesai menginisialisasi dalam batas waktu; dihentikan',
            );

            entry.status = 'failed';

            await laravel.event(sessionId, 'auth_failure', {
                message: 'Nomor tidak selesai tersambung dalam batas waktu. Coba hubungkan lagi.',
            });

            await this.stop(sessionId);
        }, config.initTimeoutMs);

        entry.pengukurInit.unref?.();
    }

    #lepasPengukurInit(entry) {
        if (!entry?.pengukurInit) return;

        clearTimeout(entry.pengukurInit);
        entry.pengukurInit = null;
    }

    /**
     * Penghitung sambung ulang baru kembali nol setelah koneksinya BERTAHAN,
     * bukan begitu terbuka.
     *
     * Dulu nol tiap `open`. Koneksi yang terbuka lalu langsung ditutup —
     * persis yang terjadi saat dua pemegang memperebutkan satu perangkat —
     * mendapat jatah percobaan penuh tiap kali, jadi rentetannya tidak pernah
     * berhenti.
     */
    #pasangPengukurStabil(entry) {
        this.#lepasPengukurStabil(entry);

        entry.pengukurStabil = setTimeout(() => {
            entry.reconnectAttempts = 0;
            entry.pengukurStabil = null;
        }, this.#stabilMs);

        entry.pengukurStabil.unref?.();
    }

    #lepasPengukurStabil(entry) {
        if (!entry?.pengukurStabil) return;

        clearTimeout(entry.pengukurStabil);
        entry.pengukurStabil = null;
    }

    #pasangBackupInterval(sessionId, entry) {
        this.#lepasBackupInterval(entry);

        if (!config.backupIntervalMs || config.backupIntervalMs <= 0) return;

        entry.backupTimer = setInterval(async () => {
            if (entry.stopped || entry.status !== 'connected') return;

            try {
                await this.persist(sessionId);
            } catch (error) {
                logger.warn({ sessionId, err: error.message }, 'Gagal sinkronisasi backup berkala ke Laravel');
            }
        }, config.backupIntervalMs);

        entry.backupTimer.unref?.();
    }

    #lepasBackupInterval(entry) {
        if (!entry?.backupTimer) return;

        clearInterval(entry.backupTimer);
        entry.backupTimer = null;
    }

    /**
     * Menyimpan kredensial sesi ke store Laravel.
     *
     * Kredensial di memori ditulis ke disk lebih dulu: pembaruan yang datang
     * dari socket lama di tengah sambung ulang hanya mengubah objek di memori,
     * dan cadangan yang membaca disk akan tertinggal satu langkah.
     */
    async persist(sessionId) {
        const entry = this.#sessions.get(sessionId);

        if (!entry || entry.status !== 'connected') return;

        await entry.auth.saveCreds();

        if (await this.#store.save({ session: sessionId }) !== false) {
            logger.info({ sessionId }, 'Kredensial sesi disimpan ke Laravel');
        }
    }

    async stop(sessionId) {
        const entry = this.#sessions.get(sessionId);

        if (!entry) return;

        entry.stopped = true;
        this.#lepasPengukurInit(entry);
        this.#lepasPengukurStabil(entry);
        this.#lepasBackupInterval(entry);

        if (entry.reconnectTimer) {
            clearTimeout(entry.reconnectTimer);
            entry.reconnectTimer = null;
        }

        this.#sessions.delete(sessionId);
        this.#queue.forget(sessionId);

        if (entry.sock) {
            try {
                entry.sock.end?.();
            } catch (error) {
                logger.warn({ sessionId, err: error.message }, 'Galat saat menutup socket');
            }
            entry.sock = null;
        }
    }

    /**
     * Memutus tautan perangkat di sisi WhatsApp lalu membuang kredensialnya.
     * Operasi ini bersifat lokal dan merespons segera:
     * - sock.logout() dijalankan di latar belakang (fire-and-forget)
     * - Sesi lokal segera dihentikan dan dibersihkan
     * - Laravel tidak pernah menunggu WhatsApp
     */
    async logout(sessionId) {
        const entry = this.#sessions.get(sessionId);
        const sock = entry?.sock;

        // Lepas referensi socket dari entry agar stop() dan operasi lain tidak tumpang tindih
        if (entry) {
            entry.sock = null;
            entry.qrImage = null;
        }

        // Jalankan sock.logout() di latar belakang (fire-and-forget).
        // Jangan pernah menahan siklus respon HTTP ke Laravel.
        if (sock) {
            Promise.race([
                Promise.resolve().then(() => sock.logout?.()),
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('sock.logout() timeout')), 4_000),
                ),
            ])
                .catch((error) => {
                    logger.warn({ sessionId, err: error.message }, 'Logout WhatsApp di latar belakang gagal atau timeout');
                })
                .finally(() => {
                    try {
                        sock.end?.(new Error('Session logged out'));
                    } catch {}
                });
        }

        await this.stop(sessionId);
        this.#arsip.lupakan(sessionId);

        // Hapus folder sesi lokal jika ada
        try {
            const sessionDir = entry?.sessionDir ?? path.join(config.dataPath, sessionId);
            await tungguPenulisanSelesai();
            await rm(sessionDir, { recursive: true, force: true, maxRetries: 3 });
        } catch {}

        // Store remote: Laravel sudah membersihkan backup miliknya di SessionService::logout.
        // Jika perlu memanggil delete() ke store, jalankan setelah logout selesai (di macrotask berikutnya)
        // supaya tidak pernah menahan siklus respon HTTP ke Laravel.
        setImmediate(() => {
            this.#store?.delete?.({ session: sessionId })?.catch?.((error) => {
                logger.warn({ sessionId, err: error.message }, 'Gagal menghapus backup sesi di latar belakang');
            });
        });
    }

    status(sessionId) {
        const entry = this.#sessions.get(sessionId);

        if (!entry) {
            return null;
        }

        return {
            status: entry.status,
            phone_number: entry.phoneNumber,
            push_name: entry.pushName,
            loading_percent: entry.loadingPercent,
            qr: entry.qrImage ?? null,
        };
    }

    listSessionIds() {
        return [...this.#sessions.keys()];
    }

    stats() {
        let connected = 0;
        let connecting = 0;
        let qr = 0;

        for (const entry of this.#sessions.values()) {
            if (entry.status === 'connected') connected++;
            else if (entry.status === 'connecting') connecting++;
            else if (entry.status === 'qr') qr++;
        }

        return {
            total: this.#sessions.size,
            connected,
            connecting,
            qr,
        };
    }

    /**
     * Mengantre satu pesan keluar.
     */
    async send(sessionId, { to, type, body, media, messageId }) {
        const sebelumnya = messageId ? this.#kiriman.get(messageId) : undefined;

        if (sebelumnya) {
            logger.info({ sessionId, messageId }, 'Permintaan kirim diulang, memakai hasil kiriman pertama');

            return sebelumnya.promise;
        }

        const entry = this.#sessions.get(sessionId);

        if (!entry) {
            const error = new Error('Sesi tidak sedang berjalan di engine.');
            error.permanent = false;
            throw error;
        }

        if (entry.status !== 'connected' || !entry.sock) {
            const error = new Error(`Sesi berstatus ${entry.status}, belum siap mengirim.`);
            error.permanent = false;
            throw error;
        }

        const promise = this.#queue.enqueue(sessionId, async () => {
            const chatId = await this.#resolveChatId(entry.sock, to);

            let content;

            if (media) {
                const buffer = Buffer.from(media.data, 'base64');
                const mime = media.mimetype || '';

                if (type === 'image' || mime.startsWith('image/')) {
                    content = { image: buffer, caption: body ?? undefined, mimetype: mime };
                } else if (type === 'video' || mime.startsWith('video/')) {
                    content = { video: buffer, caption: body ?? undefined, mimetype: mime };
                } else if (type === 'audio' || mime.startsWith('audio/')) {
                    content = { audio: buffer, mimetype: mime, ptt: type === 'audio' && mime.includes('ogg') };
                } else {
                    content = {
                        document: buffer,
                        mimetype: mime,
                        fileName: media.filename || 'document',
                        caption: body ?? undefined,
                    };
                }
            } else {
                content = { text: body ?? '' };
            }

            const sent = await entry.sock.sendMessage(chatId, content);

            this.#arsip.simpan(sessionId, sent?.key?.id, sent?.message);

            return { wa_message_id: sent?.key?.id ?? null, chat_id: toContractChatId(chatId) };
        });

        if (messageId) {
            this.#catatKiriman(messageId, promise);
        }

        return promise;
    }

    #catatKiriman(messageId, promise) {
        this.#kiriman.set(messageId, { promise, waktu: Date.now() });

        promise.catch(() => this.#kiriman.delete(messageId));

        const batas = Date.now() - 15 * 60 * 1000;

        for (const [kunci, nilai] of this.#kiriman) {
            if (nilai.waktu < batas) {
                this.#kiriman.delete(kunci);
            }
        }
    }

    async #resolveChatId(sock, to) {
        if (to.endsWith('@g.us') || to.endsWith('@s.whatsapp.net')) {
            return to;
        }

        if (to.endsWith('@c.us')) {
            return to.replace(/@c\.us$/, '@s.whatsapp.net');
        }

        const digits = to.replace(/\D+/g, '');

        if (typeof sock.onWhatsApp !== 'function') {
            return `${digits}@s.whatsapp.net`;
        }

        let jid = this.#nomorDariCache(digits);

        if (jid === undefined) {
            const results = await sock.onWhatsApp(digits);
            const match = Array.isArray(results) ? results.find((r) => r.exists) : null;

            jid = match?.jid ?? null;
            this.#simpanNomor(digits, jid);
        }

        if (!jid) {
            const error = new Error(`Nomor ${digits} tidak terdaftar di WhatsApp.`);
            error.permanent = true;
            throw error;
        }

        return jid;
    }

    /**
     * @returns {string|null|undefined} jid, `null` kalau tercatat tidak
     *   terdaftar, `undefined` kalau belum pernah ditanyakan atau sudah basi
     */
    #nomorDariCache(digits) {
        const isi = this.#nomorTerdaftar.get(digits);

        if (!isi) {
            return undefined;
        }

        const umur = isi.jid ? UMUR_NOMOR_TERDAFTAR_MS : UMUR_NOMOR_TIDAK_TERDAFTAR_MS;

        if (Date.now() - isi.waktu > umur) {
            this.#nomorTerdaftar.delete(digits);

            return undefined;
        }

        return isi.jid;
    }

    #simpanNomor(digits, jid) {
        this.#nomorTerdaftar.delete(digits);
        this.#nomorTerdaftar.set(digits, { jid, waktu: Date.now() });

        while (this.#nomorTerdaftar.size > BATAS_CACHE_NOMOR) {
            this.#nomorTerdaftar.delete(this.#nomorTerdaftar.keys().next().value);
        }
    }
}

/**
 * Golongan kode putus — dan karena itu, apa yang boleh dilakukan sesudahnya.
 *
 *  - `logged_out` (401) — kredensial dicabut; mustahil pulih tanpa scan ulang.
 *  - `banned` (403) — WhatsApp menolak akunnya; mencoba terus menambah buruk.
 *  - `replaced` (440) — klien lain memakai kredensial yang sama; menyambung
 *    ulang berarti merebutnya kembali, lalu direbut lagi, tanpa akhir.
 *  - `restart` (515) — diminta WhatsApp, terutama sesaat setelah scan QR.
 *  - `version` (405) — versi WhatsApp Web yang dipakai ditolak.
 *  - `transient` — sisanya, termasuk 500. Kode 500 (`badSession`) adalah
 *    bawaan Baileys untuk galat stream apa pun yang tidak dikenalinya, jadi
 *    TIDAK boleh diartikan "kredensial rusak" — membuang kredensial karenanya
 *    berarti memaksa scan ulang untuk gangguan sesaat.
 */
export function golonganPutus(statusCode) {
    switch (statusCode) {
        case DisconnectReason.loggedOut:
            return 'logged_out';
        case DisconnectReason.forbidden:
            return 'banned';
        case DisconnectReason.connectionReplaced:
            return 'replaced';
        case DisconnectReason.restartRequired:
            return 'restart';
        case 405:
            return 'version';
        default:
            return 'transient';
    }
}

function jedaSambungUlangBawaan(percobaan, status) {
    // Saat sesi masih dalam tahap connecting awal (belum pernah QR atau connected),
    // lakukan reconnect cepat (250ms) tanpa jeda panjang, supaya pengguna tidak
    // melihat penundaan atau galat saat scan pertama.
    return status === 'connecting'
        ? 250
        : Math.min(2000 * Math.pow(1.5, percobaan - 1), 15000);
}

/**
 * Nomor telepon di balik alamat LID, kalau bisa diketahui.
 *
 * Sebagian chat di WhatsApp sekarang beralamat `@lid` — pengenal yang tidak
 * berisi nomor telepon. Diteruskan apa adanya, `from` di webhook kosong dan
 * balasan ke `chat_id` itu ditolak karena bukan nomor: sebagian pelanggan
 * tidak pernah terbalas tanpa satu pun galat yang menjelaskan kenapa.
 */
async function nomorAsli(sock, jid, alternatif) {
    if (!jid || !isLidUser(jid)) {
        return jid;
    }

    if (alternatif && isPnUser(alternatif)) {
        return jidNormalizedUser(alternatif);
    }

    try {
        const pn = await sock.signalRepository?.lidMapping?.getPNForLID?.(jid);

        if (pn) {
            return jidNormalizedUser(pn);
        }
    } catch (error) {
        logger.debug({ err: error.message }, 'Nomor di balik LID tidak bisa dipetakan');
    }

    return jid;
}

function berkasDariCadangan(backupData) {
    try {
        const payload = typeof backupData === 'string' ? JSON.parse(backupData) : backupData;
        const files = payload?.files;

        return files && typeof files === 'object' ? files : null;
    } catch {
        return null;
    }
}

/**
 * `messageTimestamp` dari Baileys bisa berupa angka, string, atau objek Long.
 */
export function detikPesan(stempel) {
    const angka = typeof stempel?.toNumber === 'function' ? stempel.toNumber() : Number(stempel);

    return Number.isFinite(angka) && angka > 0 ? Math.floor(angka) : null;
}

export function toContractChatId(jid) {
    if (!jid || typeof jid !== 'string') return jid;

    if (jid.endsWith('@s.whatsapp.net')) {
        return jid.replace(/@s\.whatsapp\.net$/, '@c.us');
    }

    return jid;
}

export function mapType(contentType) {
    if (contentType === 'conversation' || contentType === 'extendedTextMessage') return 'text';
    if (contentType === 'imageMessage') return 'image';
    if (contentType === 'documentMessage') return 'document';
    if (contentType === 'videoMessage') return 'video';
    if (contentType === 'audioMessage') return 'audio';
    if (contentType === 'locationMessage' || contentType === 'liveLocationMessage') return 'location';

    return 'other';
}

export function extractBody(contentType, content) {
    if (!content) return null;
    if (typeof content === 'string') return content;
    if (contentType === 'conversation') return content;
    if (contentType === 'extendedTextMessage') return content.text ?? null;
    if (contentType === 'imageMessage' || contentType === 'videoMessage' || contentType === 'documentMessage') {
        return content.caption ?? null;
    }
    if (contentType === 'locationMessage') {
        return (
            content.name ||
            content.address ||
            (content.degreesLatitude != null ? `${content.degreesLatitude},${content.degreesLongitude}` : null)
        );
    }

    return null;
}

export function mapAck(status) {
    if (status === WAMessageStatus.SERVER_ACK || status === 'SERVER_ACK' || status === 2) return 1;
    if (status === WAMessageStatus.DELIVERY_ACK || status === 'DELIVERY_ACK' || status === 3) return 2;
    if (status === WAMessageStatus.READ || status === 'READ' || status === 4) return 3;
    if (status === WAMessageStatus.PLAYED || status === 'PLAYED' || status === 5) return 3;

    return null;
}
