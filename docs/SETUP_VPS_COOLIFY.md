# Setup VPS & Coolify

Dari VPS kosong sampai VexaHost WA Gateway melayani permintaan. Ikuti berurutan — setiap bagian mengandalkan yang sebelumnya.

Dokumen internal. Untuk pembagian tahap dev/staging/production lihat [ENVIRONMENT.md](ENVIRONMENT.md); untuk detail tiap resource lihat [DEPLOYMENT.md](DEPLOYMENT.md).

---

## Bagian 0 — Yang perlu disiapkan

| | Keterangan |
|---|---|
| VPS | Ubuntu 22.04/24.04 LTS. **Minimal 4 GB RAM** kalau engine ikut di sini |
| Domain | `vexahostcloud.my.id` — `wa` (produksi), `wa-staging`, dan `wa-dev` |
| Akses | SSH root, dan akses ke akun GitHub `rztechdev` |
| Nomor WhatsApp | Nomor uji coba, **terpisah** dari nomor produksi |

### Kenapa RAM jadi penentu

Setiap nomor WhatsApp menjalankan satu Chromium: **300–500 MB**. Ini batas skala yang sebenarnya, bukan CPU.

| RAM VPS | Sesi wajar | Catatan |
|---|---|---|
| 2 GB | 1–2 | Hanya cukup untuk mencoba |
| 4 GB | 8–10 | Minimum untuk produksi serius |
| 8 GB | 15–20 | Nyaman |

Kalau ekosistem Flustra lain (web, erp, pricing, helpdesk) berbagi VPS yang sama, hitung jatah mereka lebih dulu sebelum menetapkan `WA_MAX_SESSIONS`.

---

## Bagian 1 — Menyiapkan VPS

Masuk sebagai root:

```bash
ssh root@IP_VPS
```

### 1.1 Perbarui sistem

```bash
apt update && apt upgrade -y
apt install -y curl git ufw
```

### 1.2 Zona waktu

```bash
timedatectl set-timezone Asia/Jakarta
```

Penting bukan sekadar kerapian: callback engine ditandatangani dengan timestamp dan ditolak Laravel bila selisihnya lebih dari 5 menit. Jam server yang melenceng jauh membuat **seluruh callback gagal**.

Pastikan sinkronisasi waktu menyala:

```bash
timedatectl set-ntp true
timedatectl        # NTP service: active
```

### 1.3 Swap

Chromium bisa melonjak sesaat. Tanpa swap, lonjakan itu membuat kernel membunuh prosesnya (OOM kill) dan sesi terputus.

```bash
fallocate -l 4G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab

# Pakai swap hanya saat benar-benar mendesak, bukan sebagai pengganti RAM.
sysctl vm.swappiness=10
echo 'vm.swappiness=10' >> /etc/sysctl.conf
```

Verifikasi:

```bash
free -h
```

### 1.4 Firewall

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8000/tcp     # panel Coolify
ufw enable
ufw status
```

> **Port 3100 (engine) tidak dibuka.** Engine hanya dihubungi Laravel lewat jaringan internal Docker. Membukanya ke internet berarti siapa pun yang menebak tokennya bisa mengirim WhatsApp atas nama semua pelanggan.

Setelah semuanya berjalan, tutup 8000 dan akses panel lewat SSH tunnel:

```bash
ufw delete allow 8000/tcp
# dari komputer Anda:
ssh -L 8000:localhost:8000 root@IP_VPS
```

---

## Bagian 2 — Memasang Coolify

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Pemasangan memakan 3–5 menit dan sekalian memasang Docker.

Buka `http://IP_VPS:8000`, buat akun admin **pertama kali itu juga** — pendaftaran terbuka sampai akun pertama dibuat.

Setelah masuk:

1. **Projects** → **+ New** → beri nama `VexaHost` (kalau belum ada)
2. **Sources** → hubungkan GitHub App, beri akses ke repo `rztechdev/vexahost-wagateway`

---

## Bagian 3 — DNS

Di pengelola DNS `vexahostcloud.my.id`:

| Tipe | Nama | Nilai |
|---|---|---|
| A | `wa` | IP VPS |
| A | `wa-staging` | IP VPS |
| A | `wa-dev` | IP VPS |

Tunggu propagasi, lalu pastikan sudah mengarah:

```bash
dig +short wa.vexahostcloud.my.id
dig +short wa-dev.vexahostcloud.my.id
```

Keduanya harus mengembalikan IP VPS. Jangan lanjut sebelum ini benar — Let's Encrypt akan gagal menerbitkan sertifikat dan Coolify menandai deploy sebagai gagal.

---

## Bagian 4 — Database

Kalau MySQL bersama belum ada, buat lewat Coolify: **+ New** → **Database** → **MySQL 8**. Catat nama service internal dan password-nya.

Masuk ke containernya, lalu buat tiga database:

```sql
CREATE DATABASE `db_vexahost-wa-production`         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE `db_vexahost-wa-staging` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE `db_vexahost-wa-dev`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`DB_HOST` diisi **nama service internal** MySQL di Coolify, bukan `localhost` — keduanya container terpisah.

---

## Bagian 5 — Membuat rahasia

Di VPS, buat dua nilai acak untuk **tiap tahap**:

```bash
openssl rand -hex 32    # ENGINE_TOKEN
openssl rand -hex 32    # ENGINE_HMAC_SECRET
```

Aturan yang tidak boleh dilanggar:

- **Sama persis** antara Laravel dan engine dalam satu tahap
- **Berbeda** antar tahap
- **Berbeda** satu sama lain (token ≠ hmac secret)

Kalau token bocor lewat log, ia tidak boleh sekaligus memberi kemampuan memalsukan callback.

---

## Bagian 6 — Resource aplikasi (satu-satunya)

**+ New** → **Public/Private Repository** → pilih `rztechdev/vexahost-wagateway`.

| Pengaturan | Nilai |
|---|---|
| Name | `vexahost-wa` |
| Branch | `main` |
| Build Pack | Nixpacks |
| Base Directory | `/` |
| Domain | `https://wa.vexahostcloud.my.id` |
| Health Check | **Dimatikan** |

**Install Command**

```
composer install --no-dev --optimize-autoloader && npm ci && PUPPETEER_CACHE_DIR=/app/engine/.puppeteer npm --prefix engine ci --omit=dev
```

> Bagian `npm --prefix engine ci` inilah yang dulu dikerjakan resource engine tersendiri. Di sinilah Puppeteer mengunduh Chromium (±170 MB), jadi build pertama setelah perubahan ini lebih lama dari biasanya.

**Build Command**

```
npm run build
```

**Start Command**

```
bash ./start.sh
```

> **Bukan** `php artisan serve` langsung. Sejak 17 Agustus 2026 satu container menjalankan empat proses — web, engine WhatsApp, worker antrean, dan penjadwal — dan `start.sh` yang mengaturnya. Kalau Start Command-nya cuma menjalankan web, gejalanya menyesatkan: dashboard terbuka normal, tapi tidak ada sesi yang pulih dan setiap pesan mentok di status `queued`.

**Post-deployment Command**

```
php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Persistent Storage — dua volume**

| Name | Mount Path | Untuk apa |
|---|---|---|
| `wa-storage` | `/app/storage/app/private` | Cadangan sesi (`session-backups`) dan media lampiran |
| `wa-sessions` | `/data` | Berkas kerja Chromium antar-restart |

> Yang benar-benar menyelamatkan nomor dari scan ulang adalah `wa-storage`, bukan `/data`. `RemoteAuth.extractRemoteSession()` selalu mengosongkan isi `/data` setiap sesi dijalankan lalu memulihkannya dari cadangan di Laravel.

**Catatan Jaringan:**
Pastikan opsi **Connect To Predefined Network** diaktifkan (di tab Configuration → Advanced) agar container ini bisa menghubungi MySQL. Engine tidak lagi butuh jaringan ini — ia ada di container yang sama, dijangkau lewat `127.0.0.1`.

**Environment Variables** — daftar lengkapnya di [ENVIRONMENT.md](ENVIRONMENT.md); yang wajib diperiksa:

| Variabel | Nilai |
|---|---|
| `APP_KEY` | dari `php artisan key:generate --show` |
| `DB_HOST`, `DB_PASSWORD` | dari Coolify |
| `ENGINE_URL` | `http://127.0.0.1:3100` |
| `ENGINE_TOKEN`, `ENGINE_HMAC_SECRET` | dari Bagian 5 — sekarang cukup diisi sekali, bukan disamakan antar dua resource |
| `ENGINE_PORT`, `ENGINE_HOST`, `ENGINE_LOG_LEVEL` | `3100`, `127.0.0.1`, `info` — berawalan karena `PORT`/`HOST`/`LOG_LEVEL` polos sudah dipakai Laravel & Coolify |
| `LARAVEL_URL` | `http://127.0.0.1:80` |
| `WA_DATA_PATH` | `/data/.wwebjs_auth` |
| `WA_MAX_SESSIONS` | `20` di produksi (sejak Baileys), `1` di dev/staging — lihat Bagian 8 |

> **Pastikan `APP_DEBUG=false`.** Kalau `true`, halaman error menampilkan seluruh isi environment — termasuk password database dan secret HMAC.

Deploy. Buka `https://wa.vexahostcloud.my.id` — halaman utama harus muncul dengan HTTPS.

---

## Bagian 7 — Empat proses dalam satu container

**Tidak ada Resource 2 dan Resource 3.** Sampai 16 Agustus 2026 bagian ini berisi cara membuat resource `vexahost-wa-engine` dan `vexahost-wa-worker`. Keduanya dihapus pada 17 Agustus 2026; engine, worker, dan penjadwal sekarang berjalan sebagai proses di dalam container yang sama, diatur [`start.sh`](../start.sh).

Alasannya bukan beban proses — worker yang menganggur puluhan MB, engine tanpa sesi sekitar 80 MB. Alasannya biaya build: **setiap resource Coolify membangun ulang aplikasinya sendiri.** Tiga resource berarti tiga kali `composer install` + `npm ci` pada setiap deploy di VPS 2 vCPU yang juga menampung tujuh aplikasi Flustra lain. Pola yang sama sudah dipakai `flustra-erp` dan `flustra-clientportal`.

Yang dijalankan `start.sh`:

| Proses | Perintah | Kalau ia mati |
|---|---|---|
| web | `php artisan serve --host=0.0.0.0 --port=80` | Container ikut berhenti; Coolify menghidupkannya lagi |
| engine | `node engine/src/server.js` | Dijalankan ulang dalam 3 detik, web tidak tersentuh |
| worker | `php artisan queue:work … --max-time=3600` | Dijalankan ulang dalam 2 detik |
| penjadwal | `php artisan schedule:work` | Dijalankan ulang dalam 2 detik |

**Scheduled Task di Coolify: jangan dipasang.** Penjadwal sudah jadi proses di atas. Kalau resource lama masih punya Scheduled Task `php artisan schedule:run`, **hapus** — kalau tidak, `SyncSessionStatusJob` berjalan dua kali tiap menit.

**Restart Policy** resource: `always`.

Dua hal yang membuat urutan boot-nya benar, dan keduanya gampang dianggap berlebihan sampai gagal:

1. **Engine menunggu web menjawab `/up` sebelum jalan** (maksimal 120 detik). Engine menanyakan daftar sesi ke Laravel saat boot dan dulu tidak pernah mengulang kalau gagal. Selama engine punya container sendiri hal itu jarang menggigit karena Laravel sudah lama hidup; dalam satu container keduanya lahir berbarengan.
2. **Engine dikirimi SIGTERM lebih dulu saat container berhenti**, lalu ditunggu sampai 10 detik. `client.destroy()` tidak menyimpan apa pun pada RemoteAuth — SIGTERM ke proses Node adalah satu-satunya kesempatan menyimpan kredensial yang berubah sejak cadangan berkala terakhir.

Setelah deploy, log Coolify harus memuat, berurutan:

```
[start.sh] Menyalakan web, engine WhatsApp, worker, dan penjadwal dalam satu container.
{"level":30,"msg":"Engine WhatsApp berjalan","port":3100}
{"level":30,"msg":"Memulihkan sesi tersimpan","count":0}
```

Baris ketiga membuktikan engine berhasil menghubungi Laravel **dan** tanda tangan HMAC-nya diterima. Kalau yang muncul `Bootstrap belum berhasil` berulang lalu `Bootstrap gagal`, berarti `ENGINE_HMAC_SECRET` di blok Laravel dan blok engine tidak sama — sekarang keduanya di daftar env yang sama, jadi ini seharusnya tidak bisa terjadi lagi kecuali salah ketik.

Memastikan keempatnya benar-benar hidup:

```bash
sudo docker exec -it <container-vexahost-wa> ps -eo comm,rss --sort=-rss | head -20
```

Harus terlihat tiga `php` dan satu `node`.

---

## Bagian 8 — Berapa sesi yang muat

Tiap sesi WhatsApp = satu Chromium, ±300-500 MB. Ini pos pengeluaran RAM terbesar di seluruh gateway, jauh melampaui PHP dan Node-nya sendiri.

| Proses | RAM saat tenang |
|---|---|
| `php artisan serve` (+ worker PHP-nya) | ±120 MB |
| `queue:work` | ±80 MB |
| `schedule:work` | ±60 MB |
| Engine Node tanpa sesi | ±80 MB |
| **Tiap sesi WhatsApp** | **±300-500 MB** |

Cara menyetel `WA_MAX_SESSIONS`: sisakan minimal 1,5 GB untuk sistem, Coolify, MySQL, dan aplikasi Flustra lain, lalu bagi sisanya dengan 500 MB.

| RAM VPS | `WA_MAX_SESSIONS` |
|---|---|
| 4 GB | 1 |
| 8 GB | 3 |
| 16 GB | 8 |

Angka bawaannya **3**, turun dari 10 pada 17 Agustus 2026. Sepuluh sesi berarti sampai 5 GB hanya untuk Chromium — dan yang dipilih OOM killer belum tentu Chromium-nya, bisa saja MySQL.

Dev dan staging cukup `WA_MAX_SESSIONS=1`. Keduanya hanya perlu membuktikan satu sesi berfungsi, dan tiap sesi tambahan di sana memakan RAM yang seharusnya melayani produksi.

**Swap wajib ada.** Tanpa swap, satu Chromium yang melewati batas membuat kernel membunuh proses — dan pilihannya sering jatuh ke MySQL, yang menjatuhkan seluruh aplikasi di VPS ini sekaligus. Cek dengan `free -h`; kalau baris `Swap` nol, ikuti Langkah 7 di `PANDUAN_SETUP_VPS_COOLIFY_FLUSTRA.md`.

---

## Bagian 9 — Penyiapan pertama

Buka terminal resource `vexahost-wa` di Coolify.

### 9.1 Akun, workspace, nomor, dan kunci

Penyiapan dilakukan lewat dashboard, sama persis seperti pelanggan mana pun — tidak ada jalur CLI istimewa. Perintah `gateway:setup-tenant` dulu ada dan sudah dihapus: ia membuat workspace **tanpa anggota**, sehingga tidak bisa dibuka dari dashboard oleh siapa pun, dan sesi bertipe `platform` yang tidak pernah terpilih otomatis saat pemanggil API mengosongkan `session_id`. Dua sifat itu tidak terlihat di antarmuka mana pun dan menghabiskan berjam-jam penelusuran.

1. Buka `https://wa.vexahostcloud.my.id`, daftar akun, isi nama workspace
2. **Sesi WhatsApp** → Buat sesi → **Hubungkan** → scan QR dengan nomor resmi VexaHost
3. **API Keys** → buat satu kunci untuk tiap aplikasi konsumen (`flustra-erp produksi`, `flustra-web produksi`, dan seterusnya). Halaman itu langsung menampilkan cuplikan `.env` siap salin
4. Untuk **flustra-auth**, buat kunci tersendiri dengan scope `otp` dicentang — scope ini tidak boleh diberikan ke kunci integrasi biasa. Tidak ada langkah lanjutan: OTP dikirim dari sesi terhubung milik workspace pemegang kunci, sama seperti pesan biasa

Kunci bisa dibuka lagi kapan saja dari halaman API Keys, jadi tidak perlu dicatat di tempat lain. Bebas kuota untuk workspace internal disetel dari terminal bila perlu:

```bash
php artisan tinker --execute='App\Models\Workspace::find(1)->update(["is_internal" => true]);'
```

Masukkan tiap kunci ke `WA_GATEWAY_KEY` aplikasi yang bersangkutan, beserta:

```env
WA_GATEWAY_ENABLED=true
WA_GATEWAY_URL=https://wa.vexahostcloud.my.id
WA_GATEWAY_KEY=<kunci aplikasi ini>
WA_GATEWAY_SESSION=
```

**Jangan** berikan scope `otp` ke aplikasi selain flustra-auth. Lihat [INTEGRASI_APP.md](INTEGRASI_APP.md).

---

## Bagian 10 — Verifikasi

Jangan anggap selesai sebelum kelimanya lulus.

### Uji 0 — keempat proses hidup

Kalau ini gagal, sisanya tidak perlu dijalankan.

```bash
sudo docker exec -it <container-vexahost-wa> ps -eo comm,rss --sort=-rss | head -20
```

Harus terlihat tiga `php` (serve, queue:work, schedule:work) dan satu `node`.

### Uji 1 — sesi selamat dari deploy ulang

Ini uji terpenting.

1. Pastikan satu sesi berstatus **Terhubung**
2. Tunggu ±5 menit sampai log memuat `Backup sesi terkirim`
3. Redeploy resource `vexahost-wa`
4. Sesi harus kembali **Terhubung sendiri, tanpa scan QR**

> Uji ini lebih berarti daripada sebelumnya. Dulu redeploy engine tidak menyentuh Laravel; sekarang keduanya restart bersamaan, jadi yang dibuktikan sekaligus adalah penghentian rapi engine **dan** penantian boot-nya.

### Uji 2 — selamat meski volume hilang

Ulangi Uji 1, tapi kosongkan `/data` lebih dulu:

```bash
rm -rf /data/.wwebjs_auth/*
```

Sesi tetap harus pulih — kali ini dari cadangan di database.

### Uji 3 — nomor tetap bisa diganti

**Putus tautan / ganti nomor** → **Hubungkan** → scan dengan nomor berbeda. Sesi tersambung dengan nomor baru, sementara riwayat pesan dan API key tetap utuh.

Uji 1 dan 2 membuktikan nomor tidak terputus sendiri. Uji 3 membuktikan nomor tidak terkunci.

### Uji 3b — engine mati sendiri tidak menjatuhkan dashboard

Ini yang menggantikan jaminan lama "Chromium crash tidak menyentuh aplikasi", satu-satunya hal yang benar-benar hilang saat ketiga resource disatukan.

```bash
sudo docker exec -it <container-vexahost-wa> pkill -f 'node engine/src/server.js'
```

Dashboard harus tetap terbuka. Dalam ±3 detik log memuat `[start.sh] engine berhenti, dijalankan ulang dalam 3 detik.`, lalu sesi kembali terhubung sendiri.

### Uji 4 — pengiriman

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/text \
  -H "X-Api-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"to":"08xxxxxxxxxx","message":"Uji dari produksi"}'
```

Status harus naik sampai `delivered` di Riwayat Pesan.

### Uji 5 — webhook

Daftarkan alamat dari webhook.site, kirim pesan dari HP ke nomor gateway, pastikan payload sampai dan tanda tangannya lolos verifikasi.

---

## Bagian 11 — Dev & staging

Ulangi Bagian 6–9 dengan perbedaan berikut — satu resource per tahap, jadi tiga resource untuk seluruh gateway, bukan sembilan:

| | Development | Staging |
|---|---|---|
| Branch | `dev` | `staging` |
| Domain | `wa-dev.vexahostcloud.my.id` | `wa-staging.vexahostcloud.my.id` |
| Nama resource | `vexahost-wa-dev` | `vexahost-wa-staging` |
| Database | `db_vexahost-wa-dev` | `db_vexahost-wa-staging` |
| Volume | `wa-sessions-dev` → `/data`, `wa-storage-dev` → `/app/storage/app/private` | `…-staging` |
| `WA_MAX_SESSIONS` | 1 | 1 |
| Acuan env | Coolify resource `…-dev` | Coolify resource `…-staging` |

> **Jangan pakai nomor produksi untuk uji coba di dev/staging.** Satu nomor hanya bisa tertaut ke satu sesi aktif — men-scan di sini akan memutus sesi produksinya dan menghentikan notifikasi pelanggan.

---

## Bagian 12 — Perawatan

### Pemantauan harian

| Yang dicek | Cara |
|---|---|
| Sesi masih terhubung | Dashboard → Sesi WhatsApp |
| Pesan gagal tidak melonjak | Dashboard → Ringkasan |
| Antrean tidak menumpuk | `php artisan queue:failed` |
| RAM per proses | `docker exec <container> ps -eo comm,rss --sort=-rss \| head` |
| RAM seluruh VPS | `free -h` — baris `available` di bawah 500 MB berarti `WA_MAX_SESSIONS` terlalu tinggi |

### Cadangan

Coolify bisa menjadwalkan backup database. **Aktifkan** — di sanalah cadangan kredensial sesi tersimpan. Kehilangan database berarti semua nomor harus di-scan ulang.

### Pembaruan berkala

Per kuartal, perbarui `whatsapp-web.js`. Versi lama bisa berhenti bekerja saat WhatsApp Web berubah:

```bash
npm --prefix engine update whatsapp-web.js
```

Uji di development lebih dulu, lalu staging, baru produksi.

---

## Pemecahan masalah

| Gejala | Penyebab |
|---|---|
| Deploy gagal saat menerbitkan SSL | DNS belum mengarah ke VPS |
| Sesi mentok `connecting`, tidak ada QR | Chromium gagal jalan — cek log engine; biasanya RAM kurang |
| Log engine: `Bootstrap gagal` | `ENGINE_HMAC_SECRET` beda antara Laravel dan engine |
| `Token engine tidak valid` | `ENGINE_TOKEN` beda antara keduanya |
| Semua callback ditolak 401 padahal secret sama | Jam server melenceng — periksa `timedatectl` |
| Sesi minta QR ulang tiap deploy | Volume belum ter-mount ke `/data` |
| Pesan mentok `queued` | Resource worker tidak berjalan |
| Engine restart berulang | Kehabisan memori — turunkan `WA_MAX_SESSIONS` atau tambah RAM/swap |
| `Could not resolve host` saat menghubungi engine | `ENGINE_URL` memakai nama tampilan resource, bukan UUID-nya |
| Halaman error menampilkan isi environment | `APP_DEBUG` masih `true` |

Panduan operasional harian: [OPERASIONAL.md](OPERASIONAL.md).
