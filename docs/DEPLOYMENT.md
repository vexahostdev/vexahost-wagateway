# Deployment di Coolify

Mengikuti konvensi yang sudah dipakai ekosistem Flustra (`PANDUAN_SETUP_COOLIFY_LENGKAP_FLUSTRA.md` di repo utama): Nixpacks, perintah start di-override lewat UI, healthcheck dimatikan untuk aplikasi Laravel.

Baca [ENVIRONMENT.md](ENVIRONMENT.md) dulu untuk memahami pembagian tiga tahapnya.

---

## Ringkasan

**Satu resource per tahap.** Sejak 17 Agustus 2026, satu container menjalankan empat proses lewat [`start.sh`](../start.sh): web, engine WhatsApp (Node + Chromium), worker antrean, dan penjadwal.

| Resource | Base directory | Domain | Volume |
|---|---|---|---|
| `vexahost-wa` | `/` | `wa.vexahostcloud.my.id` | `wa-storage` → `/app/storage/app/private`, `wa-sessions` → `/data` |

Domain per tahap:

| Tahap | Branch | Domain |
|---|---|---|
| Development | `dev` | `wa-dev.vexahostcloud.my.id` |
| Staging | `staging` | `wa-staging.vexahostcloud.my.id` |
| Production | `main` | `wa.vexahostcloud.my.id` |

Total **3 resource** untuk tiga tahap, turun dari 9.

### Kenapa satu resource, bukan tiga

Bukan karena proses latarnya berat — worker yang menganggur cuma puluhan MB, dan engine yang belum memegang sesi sekitar 80 MB. Alasannya ada di sisi build: **setiap resource Coolify membangun ulang aplikasinya sendiri.** Tiga resource berarti tiga kali `composer install` + `npm ci` pada setiap deploy, tiga image tersimpan di disk, dan tiga lonjakan CPU berbarengan di VPS 2 vCPU yang juga menampung tujuh aplikasi Flustra lain. Pola yang sama sudah dipakai `flustra-erp` dan `flustra-clientportal`.

[ARSITEKTUR.md §2](ARSITEKTUR.md) dulu menuliskan alasan sebaliknya; jawaban atas ketiga alasan itu ada di sana dan di komentar `start.sh`.

Kalau suatu saat trafik menuntutnya, yang dipisahkan lagi cukup **engine**-nya — bukan worker. Worker hampir tidak punya biaya; engine yang punya profil sumber daya benar-benar berbeda.

---

## Persiapan sekali saja

### DNS

Ketiga tahap memakai subdomain `vexahostcloud.my.id` dan mengarah ke IP VPS Coolify yang menjalankannya:

| Tipe | Nama | Tahap |
|---|---|---|
| A | `wa` | produksi — `wa.vexahostcloud.my.id` |
| A | `wa-staging` | staging — `wa-staging.vexahostcloud.my.id` |
| A | `wa-dev` | dev — `wa-dev.vexahostcloud.my.id` |

Domain merek lama dilepas tanpa pengalihan; urutan aman pelepasannya ada di [PINDAH_KE_VEXAHOST.md](PINDAH_KE_VEXAHOST.md).

### Database

Di MySQL milik vexahost (host yang sama dengan `DB_HOST` aplikasi vexahost), buat tiga database:

```sql
CREATE DATABASE `db_vexahost-wa-production`         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE `db_vexahost-wa-staging` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE `db_vexahost-wa-dev`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Akses repo

Repo `rztechdev/vexahost-wagateway` harus terlihat oleh GitHub App Coolify yang punya akses ke akun `rztechdev`. Kalau App-nya diset ke repo tertentu, tambahkan repo ini ke daftarnya.

---

## 1. Resource aplikasi

- **Build pack**: Nixpacks — `nixpacks.toml` di root menyediakan library sistem untuk Chromium
- **Base directory**: `/`
- **Branch**: sesuai tahap
- **Domain**: sesuai tahap
- **Healthcheck**: **dimatikan** (sama seperti aplikasi Flustra lain)
- **Restart policy**: `always`

**Install command**

```
composer install --no-dev --optimize-autoloader && npm ci && PUPPETEER_CACHE_DIR=/app/engine/.puppeteer npm --prefix engine ci --omit=dev
```

> Bagian `npm --prefix engine ci` inilah yang dulu dikerjakan resource engine. Di sinilah Puppeteer mengunduh Chromium-nya (±170 MB), jadi build pertama setelah perubahan ini memang lebih lama dari biasanya.
>
> **`PUPPETEER_CACHE_DIR` di depannya bukan hiasan.** Tanpa itu Puppeteer mengunduh Chromium ke `$HOME/.cache/puppeteer` = `/root/.cache/puppeteer`. Selama engine punya resource sendiri, folder itu ikut ke image akhir. Sejak base directory-nya `/`, yang dibawa Nixpacks ke image akhir hanya `/app` — unduhannya hilang, dan baru ketahuan saat sesi pertama dijalankan, sebagai `Could not find Chrome` yang muncul apa adanya di kartu sesi pelanggan. Nilai yang sama harus ada juga di daftar environment variable, karena yang membaca variabel ini dua kali: saat mengunduh (build) dan saat mencari (runtime).

**Build command**

```
npm run build
```

**Start command**

```
bash ./start.sh
```

> **Bukan** `php artisan serve` langsung. Perintah itu hanya menjalankan web; engine, worker, dan penjadwal tidak ikut hidup, dan gejalanya menyesatkan — dashboard terbuka normal, tapi tidak ada sesi yang pulih dan setiap pesan mentok di status `queued`.

**Post-deployment command**

```
php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Persistent storage — dua volume, dua-duanya wajib**

| Nama volume | Mount path | Kalau terlewat |
|---|---|---|
| `wa-storage` | `/app/storage/app/private` | Cadangan sesi & media terhapus setiap deploy |
| `wa-sessions` | `/data` | Kredensial terbaru di volume hilang tiap deploy; sesi pulih dari cadangan di Laravel, yang bisa tertinggal sampai satu interval pencadangan (5 menit) |

Nama volume per tahap: `wa-storage-staging` / `wa-sessions-staging`, `wa-storage-dev` / `wa-sessions-dev`.

> Sejak Baileys, `/data` adalah sumber utama: kredensial di volume selalu dipakai lebih dulu karena paling baru. Cadangan di Laravel (`wa-storage`) hanya dipakai kalau `creds.json` di volume hilang atau rusak — dan engine memilih cadangan terbaru yang **layak**, bukan sekadar yang terbaru. (Catatan lama tentang `RemoteAuth.extractRemoteSession()` yang mengosongkan `/data` hanya berlaku di era whatsapp-web.js.)
>
> Volume ini juga memuat `.engine.lock`. Coolify menyalakan container baru sebelum menghentikan yang lama, dan keduanya memasang volume yang sama; engine baru menunggu kunci itu dilepas sebelum membuka port, supaya dua engine tidak pernah memegang kredensial yang sama. Log yang menandainya: `Menunggu engine lain melepas data sesi` lalu `Kunci data sesi diperoleh`.

**Scheduled Task: tidak perlu lagi.** Penjadwal berjalan sebagai proses di dalam `start.sh`. Kalau resource lama punya Scheduled Task `php artisan schedule:run`, hapus — kalau tidak, penjadwalnya berjalan dua kali.

**Connect To Predefined Network**: masih perlu, tapi bukan lagi untuk menghubungi engine (itu sudah lewat `127.0.0.1`) — melainkan untuk menjangkau container MySQL.

Environment variables: salin dari berkas `.env.<tahap>` di root repo ini (gitignored). Isinya sekarang memuat variabel Laravel **dan** engine sekaligus.

---

## 2. Berapa sesi yang muat

Tiap sesi WhatsApp = satu Chromium, ±300-500 MB. Ini pos pengeluaran RAM terbesar di seluruh gateway, jauh melampaui PHP dan Node-nya sendiri.

Perkiraan isi container saat tenang:

| Proses | RAM |
|---|---|
| `php artisan serve` (+ worker PHP-nya) | ±120 MB |
| `queue:work` | ±80 MB |
| `schedule:work` | ±60 MB |
| Engine Node tanpa sesi | ±80 MB |
| **Tiap sesi WhatsApp** | **±300-500 MB** |

Cara menyetel `WA_MAX_SESSIONS`: sisakan minimal 1,5 GB untuk sistem, Coolify, MySQL, dan aplikasi Flustra lain, lalu bagi sisanya dengan 500 MB.

| RAM VPS | `WA_MAX_SESSIONS` yang aman |
|---|---|
| 4 GB | 1 |
| 8 GB | 3 |
| 16 GB | 8 |

Angka bawaannya sempat **3**, turun dari 10. Sepuluh sesi berarti sampai 5 GB hanya untuk Chromium — dan yang dibunuh OOM killer belum tentu Chromium-nya, bisa saja MySQL.

> **Tabel dan rumus di atas berlaku untuk era Chromium.** Sejak engine pindah ke Baileys, satu sesi hanya satu koneksi websocket tanpa peramban, dan produksi dinaikkan ke `WA_MAX_SESSIONS=20` — berjalan aman (23 Okt 2026). Biaya per sesi Baileys belum diukur resmi, jadi belum ada rumus penggantinya. Patokannya sekarang pengamatan: `rss_mb` di `/health` engine dan baris `available` di `free -h` saat sesi yang tersambung bertambah. Arsip pesan untuk kirim ulang dibatasi ±1,5 MB per sesi (`engine/src/arsip-kirim.js`), jadi tidak ikut membengkak.

Dev dan staging disetel `WA_MAX_SESSIONS=1`: keduanya hanya perlu membuktikan satu sesi berfungsi, dan tiap sesi tambahan di sana memakan RAM yang seharusnya melayani produksi.

---

## 3. Penyiapan setelah deploy pertama

Penyiapan dilakukan lewat dashboard, sama persis seperti pelanggan mana pun — tidak ada jalur CLI istimewa. Perintah `gateway:setup-tenant` dulu ada dan sudah dihapus: ia membuat workspace **tanpa anggota**, sehingga tidak bisa dibuka dari dashboard oleh siapa pun, dan sesi bertipe `platform` yang tidak pernah terpilih otomatis saat pemanggil API mengosongkan `session_id`. Dua sifat itu tidak terlihat di antarmuka mana pun dan menghabiskan berjam-jam penelusuran.

1. Buka `https://wa.vexahostcloud.my.id`, daftar akun, isi nama workspace
2. **Sesi WhatsApp** → Buat sesi → **Hubungkan** → scan QR dengan nomor resmi VexaHost
3. **API Keys** → buat satu kunci untuk tiap aplikasi konsumen (`flustra-erp produksi`, `flustra-web produksi`, dan seterusnya). Halaman itu langsung menampilkan cuplikan `.env` siap salin
4. Untuk **flustra-auth**, buat kunci tersendiri dengan scope `otp` dicentang — scope ini tidak boleh diberikan ke kunci integrasi biasa. Tidak ada langkah lanjutan: OTP dikirim dari sesi terhubung milik workspace pemegang kunci, sama seperti pesan biasa

Kunci bisa dibuka lagi kapan saja dari halaman API Keys, jadi tidak perlu dicatat di tempat lain. Bebas kuota untuk workspace internal disetel dari terminal bila perlu:

```bash
php artisan tinker --execute='App\Models\Workspace::find(1)->update(["is_internal" => true]);'
```

Masukkan tiap kunci ke `WA_GATEWAY_KEY` pada aplikasi yang bersangkutan. Lihat [INTEGRASI_APP.md](INTEGRASI_APP.md).

---

## 4. Uji regresi wajib

Ini pemeriksaan terpenting. Lakukan di development lebih dulu, lalu staging, sebelum menyentuh produksi.

**Uji 0 — keempat proses benar-benar hidup**

Baru berlaku sejak penyatuan; kalau uji ini gagal, sisanya tidak perlu dijalankan.

```bash
sudo docker exec -it <container-vexahost-wa> ps -eo comm,rss --sort=-rss | head -20
```

Harus terlihat: `php` (serve), `php` (queue:work), `php` (schedule:work), dan `node`. Log Coolify harus memuat baris `[start.sh] Menyalakan web, engine WhatsApp, worker, dan penjadwal dalam satu container.`

**Uji 1 — sesi selamat dari deploy ulang**

1. Pastikan satu sesi berstatus **Terhubung**.
2. Tunggu ±5 menit sampai cadangan pertama terkirim. Cek log: `Backup sesi terkirim`.
3. Redeploy resource dari Coolify.
4. Setelah container hidup lagi, sesi harus kembali **Terhubung sendiri, tanpa scan QR**.

> Uji ini lebih berarti daripada sebelumnya. Dulu redeploy engine tidak menyentuh Laravel; sekarang keduanya restart bersamaan, dan yang dibuktikan sekaligus adalah penghentian rapi (`matikan()` di `start.sh` sempat mengirim SIGTERM ke Node) **dan** penantian boot (engine menunggu `/up` sebelum menanyakan daftar sesi).

**Uji 2 — sesi selamat meski volume hilang**

Ulangi uji 1, tapi kosongkan isi `/data` sebelum langkah 3. Sesi tetap harus pulih, kali ini dari cadangan di database.

**Uji 3 — nomor tetap bisa diganti**

Klik **Putus tautan** pada sesi, lalu **Hubungkan** dan scan dengan nomor berbeda. Sesi harus tersambung dengan nomor baru, sementara riwayat pesan dan API key tetap utuh.

**Uji 4 — engine mati sendiri tidak menjatuhkan dashboard**

Ini yang menggantikan jaminan lama "Chromium crash tidak menyentuh aplikasi":

```bash
sudo docker exec -it <container-vexahost-wa> pkill -f 'node engine/src/server.js'
```

Dashboard harus tetap terbuka. Dalam ±3 detik log memuat `[start.sh] engine berhenti, dijalankan ulang dalam 3 detik.`, dan sesi kembali terhubung sendiri.

**Uji 5 — pengiriman**

Kirim satu pesan dari halaman Kirim Pesan, pastikan statusnya naik sampai `delivered`. Ini sekaligus membuktikan worker berjalan; tanpa worker pesannya mentok di `queued`.

**Uji 6 — webhook**

Daftarkan webhook ke webhook.site, kirim pesan dari HP ke nomor tersebut, pastikan payload-nya sampai dan tanda tangannya lolos verifikasi.

---

## 5. Pindah dari tiga resource ke satu

Untuk tahap yang sudah terlanjur ter-deploy dengan pola lama. Kerjakan di **development** dulu, lalu staging, baru produksi.

**Aturan yang menentukan seluruh urutan di bawah: tidak boleh ada dua engine hidup bersamaan.** Keduanya memulihkan kredensial dari cadangan yang sama lalu membuka koneksi WhatsApp Web untuk sesi yang sama. WhatsApp hanya mengizinkan satu perangkat aktif per nomor, jadi salah satunya akan ditendang — dan yang lebih buruk, keduanya menulis cadangan ke store yang sama. Cadangan yang tertimpa di tengah jalan berarti scan QR ulang, persis hal yang gateway ini dibuat untuk menghilangkan.

Karena itu engine lama **dihentikan lebih dulu**, bukan belakangan. Ada jeda layanan selama build berjalan (±5–10 menit) dan itu memang harga yang dibayar — resource `vexahost-wa` toh ikut dibangun ulang, jadi jedanya tidak bisa dihindari.

1. Push kodenya ke branch tahap yang bersangkutan (`dev` / `staging` / `main`).
2. Di Coolify, **Stop** — bukan Delete — resource `vexahost-wa-engine` dan `vexahost-wa-worker`. Menahan diri untuk tidak menghapusnya sekarang penting: kalau langkah 6 gagal, keduanya tinggal dinyalakan lagi dan gateway kembali seperti semula.
3. Pada resource `vexahost-wa`:
   - **Install Command** → tambahkan `&& npm --prefix engine ci --omit=dev`
   - **Start Command** → `bash ./start.sh`
   - **Persistent Storage** → tambahkan volume kedua ke `/data`. Kalau Coolify menolak nama `wa-sessions` karena masih dipegang resource engine lama, pakai nama baru saja — isi `/data` memang tidak perlu diselamatkan, ia dipulihkan dari cadangan di `wa-storage`.
   - **Environment Variables** → ganti seluruhnya dengan isi `.env.production` di root repo ini
   - **Scheduled Task** `php artisan schedule:run` → **hapus** bila ada, kalau tidak penjadwalnya berjalan dua kali
4. Deploy.
5. Jalankan Uji 0 (keempat proses hidup) dan Uji 1 (sesi Terhubung sendiri tanpa scan QR) di §4.
6. Kalau kedua uji lolos: **Delete** resource `vexahost-wa-engine` dan `vexahost-wa-worker`.
7. Hapus volume yang tidak lagi dirujuk siapa pun lewat **Storages** di Coolify — kalau tidak, ia tetap memakan disk tanpa ada yang membacanya.

**Kalau langkah 5 gagal:** nyalakan lagi kedua resource lama, dan kembalikan Start Command `vexahost-wa` ke `php artisan serve --host=0.0.0.0 --port=80` beserta `ENGINE_URL` yang lama. Selama langkah 6 belum dikerjakan, pembatalannya sepenuhnya bersih — tidak ada data yang hilang, dan sesi WhatsApp-nya pulih dari cadangan yang sama.

---

## 6. Pemecahan masalah

| Gejala | Kemungkinan penyebab |
|---|---|
| Sesi mentok `connecting` | Engine tidak bisa membuka websocket ke WhatsApp — cek log engine untuk `Koneksi websocket terputus` beserta `statusCode`-nya |
| Nomor putus tanpa sebab yang jelas | Buka **Admin → Sesi WhatsApp → nama sesi**: riwayat koneksi menyebut alasan dan kodenya (401 dikeluarkan WhatsApp, 403 ditolak, 440 diambil alih). Putus yang selalu jatuh sesaat setelah "Dipulihkan saat engine menyala" menunjuk ke deploy |
| Semua sesi gagal menyambung bersamaan dengan kode 405 | Versi WhatsApp Web ditolak. Engine menyegarkannya sendiri; kalau `WA_WEB_VERSION` diisi, versi itu dipatok dan tidak pernah disegarkan — kosongkan |
| Deploy lama sekali sebelum sesi kembali | Engine baru menunggu engine lama melepas `.engine.lock`. Maksimal ±30 detik kalau engine lama mati paksa; lebih dari 3 menit diambil alih paksa |
| Semua callback ditolak 401 | `ENGINE_HMAC_SECRET` berbeda antara blok Laravel dan blok engine di env yang sama |
| `Token engine tidak valid` | `ENGINE_TOKEN` berbeda antara keduanya |
| Sesi minta QR ulang tiap deploy | Volume `wa-sessions` atau `wa-storage` belum ter-mount, atau `matikan()` di `start.sh` tidak sempat berjalan — naikkan grace period stop di Coolify. Log `creds.json rusak; disingkirkan` berarti berkas kredensial terpotong; salinannya ada di `<folder sesi>.rusak` |
| Pesan mentok `queued` | Worker tidak berjalan — Start Command masih `php artisan serve`, bukan `bash ./start.sh` |
| Deploy selesai tapi nol sesi aktif | Engine tidak berhasil menghubungi Laravel saat boot. Cek log: `Bootstrap belum berhasil` |
| Engine restart berulang | Kehabisan memori — turunkan `WA_MAX_SESSIONS` |
| `connection refused` ke port 3100 | `ENGINE_PORT` tidak diisi dan Coolify mengoper `PORT` miliknya sendiri |
| Log engine sepi padahal jalan | `ENGINE_LOG_LEVEL` kosong, jadi terbaca `LOG_LEVEL=error` milik Laravel |
| Halaman error menampilkan isi environment | `APP_DEBUG` masih `true` — segera matikan |

Panduan operasional lengkap: [OPERASIONAL.md](OPERASIONAL.md).
