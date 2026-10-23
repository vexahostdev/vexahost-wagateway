# Engine: Seluk-beluk Sisi Node.js (Baileys)

Engine adalah bagian yang bertanggung jawab berkomunikasi langsung dengan jaringan WhatsApp. Dokumen ini menjelaskan cara kerjanya secara rinci — untuk siapa pun yang akan mengubah, men-debug, atau mengoperasikannya.

Sisi Laravel dibahas di [REFERENSI_KODE.md](REFERENSI_KODE.md).

---

## 1. Apa yang sebenarnya terjadi

Engine menggunakan **Baileys** (`@whiskeysockets/baileys`), yaitu pustaka murni JavaScript yang mengimplementasikan protokol WebSocket WhatsApp (Noise protocol handshake, enkripsi end-to-end Signal, dan serialisasi Protobuf).

Engine **TIDAK** lagi menjalankan browser Chromium atau Puppeteer.

Konsekuensi arsitektur ini:

- **Satu sesi = satu koneksi WebSocket murni.** Tanpa overhead peramban (Chromium/Puppeteer). Biaya RAM per sesi Baileys di server produksi: **BELUM DIUKUR SAMA SEKALI**.
- **Kapasitas platform.** Produksi berjalan dengan `WA_MAX_SESSIONS=20` sejak migrasi Baileys (dikonfirmasi aman 23 Okt 2026). Biaya RAM per sesi belum diukur resmi; `/health` melaporkan `rss_mb` engine untuk memantaunya saat sesi tersambung bertambah.
- **Koneksi instan.** Opsi `syncFullHistory: false` disetel secara eksplisit sehingga engine tidak menarik riwayat chat besar saat baru terhubung, menghindari lonjakan memori dan waktu tunggu yang lama.
- **Kredensial berbasis multi-file auth.** Kunci enkripsi sesi disimpan sebagai berkas-berkas JSON (`creds.json`, `app-state-sync-key-*.json`, `pre-key-*.json`, dll.) di folder sesi.

---

## 2. Prinsip perancangan

### Engine tidak menyimpan state bisnis
Engine tidak memiliki database relasional dan tidak menyimpan daftar sesi secara permanen. Saat booting, engine menanyakan daftar sesi aktif ke Laravel (`/internal/engine/bootstrap`). State kredensial disimpan lokal di volume `/data/sessions` dengan cadangan otomatis di Laravel (`session.json`).

### Kegagalan satu sesi terisolasi
Setiap sesi memiliki socket Baileys dan antrean pesan keluar masing-masing. Diskoneksi atau kegagalan pada satu nomor tidak memengaruhi nomor lain.

### Isolasi kesalahan antara Laravel dan Engine
- Jika Laravel sedang restart, pengiriman event webhook dari engine ditelan secara aman (`laravel.event()` menelan error jaringan tanpa crash).
- Jika engine mati atau sedang menghubungkan ulang, Laravel menangani balasan 503 sebagai galat sementara (*temporary*), sehingga antrean pengiriman pesan diulang kembali (*retry*) oleh worker queue.

### Kontrak eksternal tetap terkunci
Baileys secara internal menggunakan format JID `@s.whatsapp.net` untuk direct chat dan enum status `WAMessageStatus`. Engine dan Laravel **mengunci kontrak API eksternal**:
- `chat_id` selalu dikembalikan dalam format `<nomor>@c.us` untuk chat personal dan `<id>@g.us` untuk grup.
- `wa_message_id` adalah ID stanza WhatsApp yang unik dan stabil (string buram).
- `ack` selalu dipetakan ke integer `1` (sent), `2` (delivered), atau `3` (read).

---

## 3. Struktur Berkas

```
engine/src/
├─ server.js            Express, penjaga token, endpoint HTTP & metrik kesehatan
├─ session-manager.js   Inti: Map sesi, siklus socket Baileys, normalisasi event & kontrak
├─ auth-state.js        Auth state berbasis berkas: tulis atomik, kunci per berkas, penilaian creds.json
├─ kunci-data.js        Hak tunggal atas folder data sesi (.engine.lock) saat deploy bertumpuk
├─ versi-wa.js          Versi WhatsApp Web: diambil dari web.whatsapp.com, disegarkan berkala
├─ arsip-kirim.js       Isi pesan yang baru dikirim, untuk menjawab permintaan kirim ulang
├─ queue.js             Antrean per sesi + jeda anti-ban (3–8 detik)
├─ laravel.js           Klien HTTP bertanda tangan HMAC ke Laravel (/internal/*)
├─ config.js            Pembacaan env + validasi saat boot
├─ logger.js            Pino logger dengan penyamaran nomor telepon
└─ stores/
   └─ laravel-store.js  Sinkronisasi backup kredensial multi-file auth ke Laravel
```

### `auth-state.js`
Pengganti `useMultiFileAuthState` bawaan Baileys, yang menimpa berkas langsung (berkas terpotong kalau proses mati di tengah penulisan) dan menjawab `null` untuk galat apa pun (Baileys lalu diam-diam membuat identitas baru — nomor "tiba-tiba minta scan ulang"). Di sini setiap penulisan lewat berkas sementara lalu `rename`, `creds.json` dinilai `tertaut` / `belum_tertaut` / `rusak` / `tidak_ada`, dan kunci per berkasnya dipakai bersama oleh `LaravelStore` saat mencadangkan.

### `kunci-data.js`
Coolify menyalakan container baru sebelum menghentikan yang lama, dan keduanya memasang volume `/data` yang sama. Engine mengambil `.engine.lock` sebelum membuka port; engine kedua menunggu sampai yang pertama melepasnya (saat SIGTERM), sampai detaknya berhenti 30 detik, atau — di container yang sama — sampai prosesnya terbukti mati.

### `versi-wa.js`
Versi WhatsApp Web diambil saat boot dan disegarkan berkala (paling cepat tiap 6 jam), serta dipaksa disegarkan saat WhatsApp menolak dengan kode 405. Gagal mengambil tidak menghentikan apa pun. `WA_WEB_VERSION` yang diisi mematok versinya.

### `config.js`
Membaca environment dan memvalidasi variabel wajib saat booting:
- `ENGINE_TOKEN`: Token statis untuk menjaga endpoint HTTP engine.
- `ENGINE_HMAC_SECRET`: Kunci rahasia untuk menandatangani panggilan callback ke Laravel.
- `LARAVEL_URL`: URL internal aplikasi Laravel.
- `dataPath`: Direktori lokal penyimpanan kredensial (bawaan: `/data/sessions`).
- `maxSessions`: Batas maksimal sesi aktif (`WA_MAX_SESSIONS`; bawaan kode 3 kalau env kosong, produksi 20).
- `initTimeoutMs`: Batas waktu inisialisasi koneksi (bawaan 180.000 ms).

### `logger.js`
Menggunakan Pino dengan konfigurasi `redact` untuk menyamarkan nomor telepon (`to`, `from`, `*.phone_number`) guna mematuhi privasi data pelanggan.

### `laravel.js`
Klien HTTP ke endpoint internal Laravel (`/internal/*`). Setiap permintaan ditandatangani dengan HMAC-SHA256:
```
signature = HMAC-SHA256(timestamp + "." + body, ENGINE_HMAC_SECRET)
```
Untuk upload backup kredensial, body dikirim sebagai JSON murni dengan header checksum SHA-256 (`X-Engine-Body-Sha256`).

### `server.js`
Express HTTP server (port 3100) dengan rute:

| Method | Path | Keterangan |
|---|---|---|
| GET | `/health` | Status engine, metrik sesi websocket, memori RSS/heap, dan kompatibilitas pemantau |
| POST | `/sessions/:id/start` | Menyalakan sesi Baileys. `restore_only: true` (penjadwal) hanya memulihkan; tanpa kredensial dijawab `credentials_missing` |
| POST | `/sessions/:id/stop` | Menghentikan socket sesi |
| POST | `/sessions/:id/logout` | Memutus tautan di WhatsApp dan menghapus backup |
| GET | `/sessions/:id/status` | Status sesi (`connecting`, `qr`, `connected`, `disconnected`) |
| POST | `/sessions/:id/messages` | Mengirim pesan (teks / media) |

Balasan kegagalan pengiriman pesan:
- `200`: Berhasil dikirim ke antrean WhatsApp.
- `422`: Gagal permanen (nomor tujuan tidak terdaftar di WhatsApp, payload tidak valid).
- `503`: Gagal sementara (sesi belum siap/reconnecting, server sibuk).

---

## 4. `session-manager.js`

Komponen inti yang mengelola seluruh instans socket Baileys.

### Siklus Koneksi & Pemetaan Event

1. **`start(sessionId, { backup_data, fromBootstrap, restore_only })`**:
   - Memastikan tidak melebihi `WA_MAX_SESSIONS`.
   - Menilai `creds.json` di `/data/.../{sessionId}`. Yang `tertaut` selalu dipakai — lebih baru dari cadangan mana pun.
   - Kalau tidak tertaut: memulihkan dari `backup_data` (dikirim Laravel), atau saat boot dari `LaravelStore`. Cadangan yang `creds.json`-nya tidak layak diabaikan. `creds.json` yang rusak dipindah ke `{folder}.rusak`, tidak ditimpa diam-diam.
   - **Jalur otomatis hanya boleh memulihkan.** `fromBootstrap` dan `restore_only` (penjadwal Laravel) tanpa kredensial yang bisa dipulihkan TIDAK menjalankan sesi — mengirim event `disconnected` alasan `credentials_missing` dan menjawab `{ status: 'credentials_missing' }`. Tanpa ini sesi tanpa kredensial memunculkan QR yang tidak ditatap siapa pun.
   - Laravel yang tidak terjangkau saat boot dilempar sebagai galat, BUKAN dilaporkan sebagai kredensial hilang.
   - Auth state dibuat sekali per sesi (`pakaiAuthStateBerkas`) dan dipakai bersama oleh setiap socket sambung ulang.
2. **`connection.update`** (diabaikan kalau datang dari socket yang sudah diganti):
   - Jika menerima `qr`: Mengonversi string QR menjadi format Data URL PNG via `qrcode.toDataURL()`, melepas pengukur inisialisasi, dan mengirim event `qr` ke Laravel.
   - Jika `connection === 'open'`: status `connected`, event `ready`, sinkronisasi backup berkala. Penghitung sambung ulang baru kembali nol setelah koneksi **bertahan 60 detik** — koneksi yang terbuka lalu langsung putus tidak mendapat jatah percobaan baru.
   - Jika `connection === 'close'`, tindakannya ditentukan `golonganPutus(statusCode)`:

     | Kode | Golongan | Tindakan | Event ke Laravel |
     |---|---|---|---|
     | 401 | `logged_out` | Berhenti, **buang kredensial lokal** | `disconnected` / `logged_out` |
     | 403 | `banned` | Berhenti, kredensial disimpan | `disconnected` / `banned` |
     | 440 | `replaced` | Berhenti, **tidak** merebut balik | `disconnected` / `replaced` |
     | 515 | `restart` | Sambung ulang seketika | — |
     | 405 | `version` | Segarkan versi WhatsApp Web, lalu seperti sementara | — |
     | lainnya, termasuk 500 | `transient` | Backoff (2s, 3s, 4.5s… maks 15s), 5 kali | `disconnected` / `connection_lost` kalau habis |

     Kode 500 (`badSession`) adalah bawaan Baileys untuk galat stream apa pun yang tidak dikenalinya — bukan bukti kredensial rusak, jadi tidak pernah dijadikan alasan membuang kredensial.
3. **`creds.update`**:
   - Menyimpan kredensial ke disk lewat penulisan atomik. Diabaikan untuk sesi yang sudah dihentikan, supaya tidak menulis ke folder yang mungkin sudah dipakai tautan baru.
4. **`messages.upsert`**:
   - Mengabaikan pesan keluar sendiri (`key.fromMe`), status, siaran, dan Channel (`@newsletter`).
   - Membuka pembungkus pesan ephemeral atau view-once.
   - Chat beralamat `@lid` dipetakan ke nomor teleponnya (`remoteJidAlt`/`participantAlt`, lalu `lidMapping`), supaya `from` tidak kosong dan `chat_id` bisa dibalas.
   - Menormalkan JID ke format kontrak (`@s.whatsapp.net` → `@c.us`).
   - Mengirim event `message` ke Laravel, ditambah `timestamp` (waktu asli, detik Unix) dan `is_backlog` (`true` untuk pesan yang menumpuk selama offline — tipe upsert `append`).
5. **`messages.update`**:
   - Memetakan pembaruan status pengiriman (`WAMessageStatus`):
     - `SERVER_ACK` (2) → ack `1` (sent)
     - `DELIVERY_ACK` (3) → ack `2` (delivered)
     - `READ` (4) atau `PLAYED` (5) → ack `3` (read)
   - Mengirim event `message_ack` ke Laravel.

### Pengiriman Pesan (`send`)

- Memverifikasi keberadaan nomor tujuan di WhatsApp via `sock.onWhatsApp(digits)`, dengan hasil disimpan 24 jam (nomor tidak terdaftar: 1 jam) supaya broadcast ulang tidak mengulang ratusan pemeriksaan. Jika nomor tidak terdaftar, melempar error dengan tanda `permanent = true` (menghasilkan status HTTP 422).
- Isi pesan yang terkirim disimpan 24 jam di `ArsipKirim` untuk `getMessage` — permintaan kirim ulang dari ponsel penerima yang gagal mendekripsi. Cache bawaan Baileys hanya 5 menit dan hilang tiap sambung ulang.
- Mendukung pesan teks dan media (`image`, `video`, `audio`, `document`) dari buffer base64.
- Mengembalikan ID WhatsApp buram dan `chat_id` yang dinormalkan ke `@c.us` atau `@g.us`:
  ```json
  {
    "wa_message_id": "3EB0ABCDEF1234567890",
    "chat_id": "6281234567890@c.us"
  }
  ```
- Mencegah duplikasi pengiriman berulang dengan pencatatan promise berdasarkan `messageId`.

---

## 5. `queue.js`

Mengantre pesan keluar per nomor WhatsApp menggunakan antrean Promise terisolasi, diselingi jeda acak 3–8 detik antar pesan. Isolasi per sesi memastikan broadcast satu workspace tidak memperlambat pengiriman pesan workspace lain.

---

## 6. `stores/laravel-store.js`

Menyimpan cadangan kredensial multi-file auth Baileys ke storage Laravel (`session-backups` disk).

- `save({ session: sessionId })`: Membaca seluruh berkas JSON kredensial pada folder sesi **di bawah kunci yang sama dengan penulisnya** (berkas `.tmp` dilewati), membundelnya ke dalam `{ version: 1, sessionId, files: { [nama]: konten } }`, menghitung digest SHA-256, dan mengunggahnya ke `/internal/engine/session-backup/{sessionId}`. Dibatalkan kalau `creds.json` rusak atau belum tertaut — cadangan sehat yang lama tidak boleh terdorong keluar oleh cadangan rusak.
- `ambilBerkas({ session: sessionId })`: Mengunduh cadangan dan mengembalikan isinya tanpa menulis apa pun — pemanggil yang menilai layak tidaknya.
- `extract({ session: sessionId })`: Mengganti isi folder sesi dengan cadangan. Nama berkas yang keluar dari folder sesi ditolak.
- `delete({ session: sessionId })`: Menghapus cadangan di Laravel. Folder lokal **tidak** ikut dihapus di sini: pemanggilnya (`logout()`) berjalan di latar belakang dan bisa mengenai folder tautan baru.
- `sessionExists({ session: sessionId })`: Menanyakan ke Laravel apakah ada cadangan **yang layak**. Melempar galat kalau Laravel tidak terjangkau.

Laravel menyimpan **tiga** cadangan terakhir per sesi (`SessionBackup::SIMPAN`), menolak unggahan yang kredensialnya tidak layak (`App\Support\CadanganSesi`, aturannya sama persis dengan `nilaiKredensial()` di engine), dan selalu menyajikan cadangan terbaru yang layak.

---

## 7. Menjalankan dan Debugging

```bash
# Menjalankan engine lokal untuk pengembangan
npm --prefix engine run dev

# Menjalankan pengujian unit engine
npm --prefix engine test

# Memeriksa kesehatan engine lokal
curl http://127.0.0.1:3100/health
```

### Log Penting
- `Engine WhatsApp berjalan (Baileys)`: Engine siap menerima koneksi.
- `Memulihkan sesi tersimpan`: Proses bootstrap pemulihan sesi dimulai.
- `Sesi siap`: Sesi berhasil tersambung ke WhatsApp.
- `Backup sesi terkirim`: Kredensial berhasil dicadangkan ke Laravel.
- `Mencoba menyambung kembali websocket`: Percobaan reconnect otomatis akibat gangguan jaringan.
- `Koneksi websocket terputus` (dengan `statusCode` dan `golongan`): titik awal setiap penyelidikan "kenapa nomor ini putus". Riwayat yang sama tersimpan di panel admin (Sesi WhatsApp → nama sesi).
- `Menunggu engine lain melepas data sesi` → `Kunci data sesi diperoleh`: deploy bertumpuk yang ditangani dengan benar.
- `Versi WhatsApp Web diperbarui`: versi handshake berganti.
- `creds.json rusak; disingkirkan`: berkas kredensial terpotong. Salinannya di `{folder sesi}.rusak`.
- `Backup dibatalkan: creds.json rusak atau belum tertaut`: pencadangan yang sengaja dilewati.
- `Tidak ada kredensial yang bisa dipulihkan; sesi tidak dijalankan`: jalur otomatis menolak memunculkan QR tanpa penonton.

`npm --prefix engine run dev` menjalankan engine **tanpa** `--watch`: restart di tengah scan QR membuang hasil scan-nya. Yang butuh muat-ulang otomatis memakai `dev:watch`, dan tidak sambil menautkan nomor.
