# Environment: Development, Staging, Production

VexaHost WA Gateway berjalan di **tiga tahap terpisah**, mengikuti pola yang sudah dipakai seluruh ekosistem Flustra (`PANDUAN_DEVTOSPROD_DAN_COOLIFY_FLUSTRA.md`).

Setiap tahap adalah lingkungan yang berdiri sendiri: domain sendiri, database sendiri, volume sendiri, dan **nomor WhatsApp sendiri**.

## Peta tahap

| | Development | Staging | Production |
|---|---|---|---|
| Domain | `wa-dev.vexahostcloud.my.id` | `wa-staging.vexahostcloud.my.id` | `wa.vexahostcloud.my.id` |
| Branch git | `dev` | `staging` | `main` |
| `APP_ENV` | `development` | `staging` | `production` |
| `APP_DEBUG` | `true` | `false` | `false` |
| `LOG_LEVEL` | `debug` | `info` | `error` |
| Database | `db_vexahost-wa-dev` | `db_vexahost-wa-staging` | `db_vexahost-wa-production` |
| Nama resource | `vexahost-wa-dev` | `vexahost-wa-staging` | `vexahost-wa` |
| `ENGINE_URL` | `http://127.0.0.1:3100` | idem | idem |
| Volume sesi | `wa-sessions-dev` | `wa-sessions-staging` | `wa-sessions` |
| Volume cadangan | `wa-storage-dev` | `wa-storage-staging` | `wa-storage` |
| `WA_MAX_SESSIONS` | 1 | 1 | 20 |
| Nomor WhatsApp | nomor uji coba | nomor uji coba (boleh sama dengan dev, sesi terpisah) | nomor resmi VexaHost |

> **Satu resource per tahap sejak 17 Agustus 2026.** Engine dan worker tidak lagi punya resource sendiri; keduanya proses di dalam container yang sama (lihat [DEPLOYMENT.md](DEPLOYMENT.md)). Akibatnya untuk berkas ini: **variabel Laravel dan engine berada di satu daftar env yang sama**, dan tiga nama harus diberi awalan supaya tidak bertabrakan — `ENGINE_PORT`, `ENGINE_HOST`, `ENGINE_LOG_LEVEL`. `PORT`, `HOST`, dan `LOG_LEVEL` polos sekarang milik Laravel dan Coolify.
>
> Nama lama masih diterima sebagai cadangan supaya engine tetap bisa dijalankan sendiri saat pengembangan lokal. Yang tidak boleh terjadi diam-diam: `ENGINE_LOG_LEVEL` yang lupa diisi membuat engine mengikuti `LOG_LEVEL=error` milik Laravel, dan baris `Backup sesi terkirim` — satu-satunya bukti cadangan sesi tersimpan — berhenti muncul. `engine/tests/config.test.js` menjaga keempat aturan ini.

> **Nomor tidak boleh dipakai bersama antar tahap.** Satu nomor WhatsApp hanya bisa tertaut ke satu sesi aktif. Kalau nomor produksi dipakai men-scan di staging, sesi produksinya akan terputus — dan notifikasi pelanggan ikut berhenti. Sediakan nomor terpisah untuk dev/staging.

> **`WA_WEB_VERSION` sebaiknya dibiarkan kosong di semua tahap.** Kosong berarti engine mengambil versi WhatsApp Web terbaru saat menyala dan menyegarkannya sendiri (lihat `engine/src/versi-wa.js`); versi bawaan Baileys hanya cadangan kalau web.whatsapp.com tidak terjangkau. Diisi (format `2.3000.1051456098`) berarti versinya **dipatok** dan tidak pernah disegarkan — jalan keluar darurat kalau versi terbaru justru bermasalah, bukan pengaturan tetap. Versi yang dipatok tertinggal diam-diam, sampai suatu hari WhatsApp menolaknya dan seluruh sesi seluruh pelanggan gagal menyambung bersamaan dengan kode 405. Nilai yang tidak bisa dibaca (salah ketik) tidak dihitung sebagai patokan.

## Sumber kebenaran nilai rahasia

Nilai lengkap per tahap ada di berkas `.env.development`, `.env.staging`, dan `.env.production` di root repo ini (gitignored, tidak pernah ikut ter-commit). Bagian di bawah hanya menjelaskan **yang berbeda antar tahap**; selebihnya sama.

---

## Development — `wa-dev.vexahostcloud.my.id`

Tempat mencoba hal baru. Boleh dirusak.

```env
APP_ENV=development
APP_DEBUG=true
APP_URL=https://wa-dev.vexahostcloud.my.id
LOG_LEVEL=debug

DB_DATABASE=db_vexahost-wa-dev

ENGINE_URL=http://127.0.0.1:3100

# Jeda dipendekkan agar pengujian tidak berlarut-larut.
# Aman karena nomornya nomor uji coba, bukan nomor yang perlu dijaga.
WA_MIN_DELAY_MS=1000
WA_MAX_DELAY_MS=2000

# Batas longgar supaya pengujian tidak mentok kuota.
WORKSPACE_DEFAULT_MONTHLY_QUOTA=100000
WORKSPACE_DEFAULT_MAX_SESSIONS=3

# Retensi pendek — data uji tidak perlu disimpan lama.
RETENTION_MESSAGES_DAYS=7
RETENTION_WEBHOOK_DAYS=7
```

Blok engine, di daftar env yang sama:

```env
ENGINE_PORT=3100
ENGINE_HOST=127.0.0.1
ENGINE_LOG_LEVEL=debug
LARAVEL_URL=http://127.0.0.1:80
WA_DATA_PATH=/data/.wwebjs_auth
WA_MAX_SESSIONS=1
```

## Staging — `wa-staging.vexahostcloud.my.id`

Latihan sebelum produksi. Nilainya **harus semirip mungkin dengan produksi** — kalau staging dibuat lebih longgar, ia berhenti berfungsi sebagai latihan.

```env
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://wa-staging.vexahostcloud.my.id
LOG_LEVEL=info

DB_DATABASE=db_vexahost-wa-staging

ENGINE_URL=http://127.0.0.1:3100

# Sama dengan produksi. Jeda inilah yang menentukan berapa lama broadcast
# berjalan; kalau staging lebih cepat, hasil ujinya menyesatkan.
WA_MIN_DELAY_MS=3000
WA_MAX_DELAY_MS=8000

WORKSPACE_DEFAULT_MONTHLY_QUOTA=1000
WORKSPACE_DEFAULT_MAX_SESSIONS=1

RETENTION_MESSAGES_DAYS=30
RETENTION_WEBHOOK_DAYS=14
```

Blok engine, di daftar env yang sama:

```env
ENGINE_PORT=3100
ENGINE_HOST=127.0.0.1
ENGINE_LOG_LEVEL=info
LARAVEL_URL=http://127.0.0.1:80
WA_DATA_PATH=/data/.wwebjs_auth
WA_MAX_SESSIONS=1
```

## Production — `wa.vexahostcloud.my.id`

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wa.vexahostcloud.my.id
LOG_LEVEL=error

DB_DATABASE=db_vexahost-wa-production

ENGINE_URL=http://127.0.0.1:3100

WA_MIN_DELAY_MS=3000
WA_MAX_DELAY_MS=8000

WORKSPACE_DEFAULT_MONTHLY_QUOTA=1000
WORKSPACE_DEFAULT_MAX_SESSIONS=1

RETENTION_MESSAGES_DAYS=90
RETENTION_WEBHOOK_DAYS=30
```

Blok engine, di daftar env yang sama:

```env
ENGINE_PORT=3100
ENGINE_HOST=127.0.0.1
ENGINE_LOG_LEVEL=info
LARAVEL_URL=http://127.0.0.1:80
WA_DATA_PATH=/data/.wwebjs_auth

# Era Chromium: 10, lalu 3 (17 Agu 2026) karena tiap sesi ±300-500 MB.
# Sejak Baileys (satu websocket per sesi) dinaikkan ke 20 dan berjalan aman
# (23 Okt 2026). RAM tetap dibagi dengan PHP, worker, penjadwal, MySQL, dan
# aplikasi Flustra lain — pantau rss_mb di /health engine. DEPLOYMENT.md §2.
WA_MAX_SESSIONS=20
```

> `APP_DEBUG=true` di produksi akan menampilkan isi environment — termasuk password database dan secret HMAC — di halaman error. Pastikan tetap `false`.

---

## Penagihan

Variabel berikut sama bentuknya di ketiga tahap; yang berbeda hanya isinya.
**Dev dan staging tidak boleh memakai QRIS merchant sungguhan** — pengujian di
sana akan menghasilkan kode QR yang benar-benar bisa dibayar orang.

```env
> **Aturan kutip berlaku untuk SEMUA nilai bernilai lebih dari satu kata, bukan
> hanya QRIS.** `APP_NAME=VexaHost WA Gateway` tanpa kutip menolak seluruh berkas
> dengan `Failed to parse dotenv file ... unexpected whitespace` dan aplikasi
> tidak boot sama sekali — bukan sekadar kehilangan nama aplikasinya. Ini sudah
> terjadi di `.env.production` dan tidak ketahuan sampai berkasnya benar-benar
> dicoba dimuat, karena Coolify menyetel env-nya lewat jalur lain.

# QRIS statis milik merchant, string panjang berawalan 00020101021126...
# Kosong = halaman pembayaran hanya menampilkan instruksi transfer bank,
# bukan kode QR rusak. Pakai QRIS MERCHANT, bukan QRIS akun pribadi: akun
# pribadi punya batas nominal bulanan dan bisa dibekukan bank begitu polanya
# terbaca komersial.
#
# WAJIB DIKUTIP. Nama kota merchant di dalamnya (tag 60) hampir selalu memuat
# spasi; tanpa kutip dotenv menolak seluruh berkas dan aplikasi gagal boot,
# bukan sekadar kehilangan QRIS-nya. Salin apa adanya — spasi di ujung nama
# kota ikut dihitung panjang tag, dan satu saja terpangkas membuat CRC-nya
# tidak cocok lagi.
QRIS_PAYLOAD=
QRIS_MERCHANT_NAME=DESTINARA

# Ditampilkan berdampingan dengan kode QR, bukan sebagai jalur darurat: batas
# QRIS per transaksi mengikuti kebijakan tiap dompet digital dan bisa berhenti
# di bawah nilai paket tahunan Elite.
BILLING_BANK_NAME=
BILLING_BANK_ACCOUNT=
BILLING_BANK_HOLDER=

# Irama siklus. Nilai bawaan sudah masuk akal; ubah hanya kalau ada alasan.
BILLING_INVOICE_DUE_DAYS=7      # batas bayar sejak tagihan terbit
BILLING_ISSUE_DAYS_BEFORE=3     # tagihan perpanjangan terbit sekian hari sebelum habis
BILLING_GRACE_DAYS=30           # jarak antara pengiriman berhenti dan sesi dilepas
BILLING_UNIQUE_CODE=true        # kode unik 3 digit di nominal, untuk mencocokkan mutasi
BILLING_TAX_PERCENT=0           # 0 = harga yang dipajang sudah final, tanpa baris pajak

# Workspace milik VexaHost sendiri yang nomornya dipakai mengirim SELURUH
# pemberitahuan WhatsApp. Jangan diisi workspace pelanggan: kuotanya yang
# terpotong dan laporan spam-nya yang jatuh ke nomor mereka.
#
# Kosong = seluruh pemberitahuan diam, tanpa satu pun yang gagal secara
# terlihat. Panel admin menampilkan peringatan merah di halaman Ringkasan
# selama keadaan ini berlangsung — itu satu-satunya gejalanya.
#
# SEJAK 6 SEP 2026 INI CUMA CADANGAN. Pilihannya ada di panel admin, menu
# Pengecualian, dan disimpan di tabel `app_settings` — itu yang dibaca lebih
# dulu. Alasannya urutan pemasangan lewat env mustahil: id workspace baru ada
# setelah workspace-nya dibuat lewat dashboard, jadi mengisinya di sini berarti
# deploy, buat workspace, salin id, deploy lagi.
BILLING_NOTIFY_WORKSPACE_ID=

# Nomor WhatsApp tim, untuk pemberitahuan yang butuh tindakan manusia —
# terutama "ada bukti pembayaran baru masuk". Selama pencocokan masih manual,
# tagihan hanya menjadi lunas kalau ada orang yang membukanya di panel, dan
# tanpa nomor ini tidak ada yang memberi tahu bahwa ada yang perlu dibuka.
BILLING_ADMIN_PHONE=

# Nomor WhatsApp yang tampil sebagai tombol chat di halaman Enterprise
# (`/enterprise`). Sengaja TERPISAH dari BILLING_ADMIN_PHONE di atas: yang
# itu kotak masuk tim — arah masuk, dari gateway ke kami — dan memasangnya
# di halaman publik membuat kabar bukti bayar tenggelam di antara chat orang
# asing, pada nomor yang tidak bisa ditarik lagi setelah tersebar.
# Kosong berarti tombolnya tidak dirender; formulir Enterprise tetap ada,
# jadi halaman itu tidak pernah kehilangan satu-satunya cara menghubungi.
ENTERPRISE_WHATSAPP=

# Alamat yang dipakai pelanggan saat butuh manusia — termasuk saat lupa kata
# sandi, karena pemulihan mandiri sengaja belum dibuat.
BILLING_SUPPORT_EMAIL=vexahostcloudtech@gmail.com

# Lantai masa percobaan. Percobaan selalu berakhir di akhir bulan; kalau sisa
# bulan berjalan lebih pendek dari ini, masanya melompat ke akhir bulan
# berikutnya supaya pendaftar tanggal 30 tidak cuma dapat satu hari.
BILLING_TRIAL_MIN_DAYS=10
```

### Pemberitahuan WhatsApp — apa saja yang dikirim

Semuanya lewat gateway ini sendiri, dari nomor workspace VexaHost.

| Peristiwa | Kepada | Kenapa ada |
|---|---|---|
| Bukti pembayaran diunggah | Pelanggan | Menutup keraguan yang dulu berujung tagihan dibatalkan sendiri |
| Bukti pembayaran diunggah | **Tim** | Tagihan hanya lunas kalau ada yang membukanya di panel |
| Pembayaran dikonfirmasi | Pelanggan | Menutup lingkaran; tanpa ini mereka menunggu tanpa kabar |
| Bukti ditolak + alasan | Pelanggan | Penolakan tanpa alasan cuma membuat tagihan terbuka lagi tanpa ada yang tahu kenapa |
| Masa berlaku H-7/3/1/0 | Pelanggan | Spanduk dashboard hanya terlihat oleh yang kebetulan membukanya |
| Pengiriman dihentikan | Pelanggan | Layanan berhenti harus terasa sebagai kebijakan, bukan kerusakan |
| Nomor dilepas setelah tenggang | Pelanggan | Menjelaskan bahwa datanya tetap aman |
| **Sesi WhatsApp terputus** | Pelanggan | Gateway yang mati diam baru ketahuan saat pelanggan *mereka* yang mengeluh |
| Kuota 80% dan 100% | Pelanggan | Kuota habis tanpa peringatan terasa seperti kerusakan |

Semuanya **pelengkap**: kegagalan mengirim dicatat di log dan tidak pernah
menjatuhkan tindakan yang sedang berjalan — tagihan tetap lunas meski pesannya
gagal terkirim. Tiap peristiwa dijaga penanda di cache supaya job yang berjalan
dua kali tidak mengirim pesan yang sama dua kali; pesan kembar soal uang membuat
orang mengira ia ditagih dua kali.

### Email — Brevo milik vexahost

Sejak pergantian merek ke VexaHost (19 Sep 2026), seluruh `MAIL_*` disamakan
**persis** dengan aplikasi vexahost: akun Brevo yang sama, pengirim yang sama.
Satu orang yang membaca kotak masuk `vexahostcloudtech@gmail.com` menerima
balasan dari kedua aplikasi, dan reputasi pengirim di Brevo tidak terpecah dua.

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=              # sama dengan MAIL_USERNAME aplikasi vexahost
MAIL_PASSWORD=              # sama dengan MAIL_PASSWORD aplikasi vexahost
MAIL_SCHEME=null            # Laravel 12 membaca MAIL_SCHEME; MAIL_ENCRYPTION tidak dibaca siapa pun
MAIL_FROM_ADDRESS=vexahostcloudtech@gmail.com
MAIL_FROM_NAME="VexaHost Cloud"
```

Setelah mengganti kredensial, kirim email tes dari `/admin/pengecualian` —
pengirim yang belum terverifikasi di Brevo ditolak tanpa gejala di sisi kita.

### Database — MySQL milik vexahost

Ketiga tahap memakai server MySQL yang sama dengan aplikasi vexahost: `DB_HOST`
berisi UUID resource MySQL-nya (bukan nama tampilan, lihat CLAUDE.md),
`DB_USERNAME`/`DB_PASSWORD` sama dengan milik vexahost, dan tiap tahap punya
database sendiri (`db_vexahost-wa-dev`, `db_vexahost-wa-staging`,
`db_vexahost-wa-production`). Server yang dipakai bersama inilah alasan
pemangkasan data dijalankan bertahap — satu DELETE besar menahan kunci yang
juga dibutuhkan aplikasi vexahost.

### Login Google

Memakai OAuth client milik vexahost — satu project Google untuk kedua
aplikasi, jadi akun Google yang sama dikenali sebagai orang yang sama. Tiap
tahap wajib didaftarkan sebagai *Authorized redirect URI* di Google Cloud
Console, kalau tidak tombolnya gagal dengan `redirect_uri_mismatch`:

```
https://wa.vexahostcloud.my.id/auth/google/callback
https://wa-staging.vexahostcloud.my.id/auth/google/callback
https://wa-dev.vexahostcloud.my.id/auth/google/callback
```

```env
GOOGLE_CLIENT_ID=           # sama dengan milik vexahost
GOOGLE_CLIENT_SECRET=       # sama dengan milik vexahost
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
```

### Akun tertaut dengan vexahost

Satu akun untuk WA Gateway dan vexahost — hanya autentikasi. Rincian protokol
dan cara menyalakannya di [AKUN_TERTAUT.md](AKUN_TERTAUT.md).

```env
LINKED_ACCOUNTS_URL=https://vexahostcloud.my.id   # alamat vexahost di tahap yang sama
LINKED_ACCOUNTS_SECRET=                            # IDENTIK dengan milik vexahost, BERBEDA tiap tahap
```

| Tahap | `LINKED_ACCOUNTS_URL` |
|---|---|
| dev | `https://dev.vexahostcloud.my.id` |
| staging | `https://staging.vexahostcloud.my.id` |
| produksi | `https://vexahostcloud.my.id` |

Kosong di lokal, dan `phpunit.xml` memaksanya kosong di tes. Salah satu dari
keduanya kosong = penautan mati di kedua arah, tanpa galat apa pun — akun
yang didaftarkan selama itu tetap ditandai dan ikut terkirim begitu env-nya
diisi.

### Akun super admin

Panel `/admin` hanya bisa dibuka super admin, dan super admin hanya bisa
diangkat super admin lain — jadi akun pertama lahir dari seeder, bukan dari
antarmuka. `start.sh` menjalankan `AdminUserSeeder` **setiap boot**: admin
dibuat kalau belum ada, dan nama, nomor, perusahaan, serta kata sandinya
disamakan dengan env — persis seperti seeder aplikasi vexahost. Artinya kata
sandi admin diganti **lewat env**; yang diganti lewat halaman profil kembali ke
nilai env pada deploy berikutnya.

Identitasnya disamakan **persis** dengan admin aplikasi vexahost — nama
variabel dan isinya sama. Di database yang sudah berisi admin lama, migrasi
`pindahkan_admin_ke_identitas_vexahost` mengubah super admin tertua di tempat
ke identitas ini (bukan membuat admin kedua); kata sandinya hanya diganti kalau
`ADMIN_PASSWORD` terisi saat deploy.

```env
ADMIN_NAME="Ryan Rizki"
ADMIN_EMAIL=vexahostcloudtech@gmail.com
ADMIN_PASSWORD=            # WAJIB diisi di staging dan produksi, sama dengan vexahost
ADMIN_PHONE=6285808749131
ADMIN_COMPANY="VexaHost Cloud Indonesia"

# Autentikasi dua faktor (2FA) untuk super admin.
# Bawaannya false (2FA opsional untuk semua akun). Jika diatur true, super admin
# yang belum memasang 2FA akan dipaksa memasangnya sebelum dapat mengakses
# dashboard maupun panel admin.
AUTH_2FA_MANDATORY_FOR_ADMIN=false
```

`ADMIN_PASSWORD` yang dikosongkan jatuh ke nilai bawaan `12345678` — tapi
hanya di lokal. Nilai itu ada di dalam repo, artinya **sudah bocor**, jadi di
produksi seeder menolak memakainya: selama `ADMIN_PASSWORD` kosong, admin tidak
dibuat sama sekali dan log boot memuat peringatannya. Mengisi env-nya dengan
nilai bawaan itu juga tetap memunculkan peringatan di setiap boot.

---

## Rahasia yang wajib berbeda tiap tahap

Jangan pernah menyalin nilai ini antar tahap. Kalau secret dev bocor dan nilainya sama dengan produksi, penyerang bisa mengirim WhatsApp atas nama seluruh pelanggan.

| Variabel | Cara membuat |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` |
| `ENGINE_TOKEN` | `openssl rand -hex 32` |
| `ENGINE_HMAC_SECRET` | `openssl rand -hex 32` — harus berbeda dari `ENGINE_TOKEN` |
| `DB_PASSWORD` | dari Coolify |
| API key workspace | Dashboard → API Keys → Buat |

`ENGINE_TOKEN` dan `ENGINE_HMAC_SECRET` harus **identik antara Laravel dan engine dalam satu tahap**, dan **berbeda antar tahap**.

## Keamanan sesi — hanya staging & produksi

| Variabel | Lokal | Staging | Production |
|---|---|---|---|
| `SESSION_SECURE_COOKIE` | **tidak diisi** | `true` | `true` |
| `SESSION_ENCRYPT` | `false` (bawaan) | `true` | `true` |

`SESSION_SECURE_COOKIE=true` membuat cookie sesi tidak pernah ikut permintaan
`http://`. Tanpanya, satu permintaan polos sebelum redirect ke HTTPS sudah cukup
membocorkan sesi admin kepada siapa pun yang sejaringan.

**Jangan diisi di `.env` lokal.** Dev berjalan di `http://127.0.0.1:8051`, dan
cookie secure di sana membuat login gagal **tanpa satu pun pesan galat** —
gejalanya cuma formulir yang kembali ke halaman login, dan itu sangat sulit
ditebak penyebabnya.

`SESSION_ENCRYPT=true` mengenkripsi isi sesi sebelum ditulis ke tabel
`sessions`. Tanpanya, siapa pun yang bisa membaca database bisa membaca isi
sesi pengguna mana pun.

Keduanya, plus HTTPS dan `APP_DEBUG`, muncul sebagai pemeriksaan di
`/admin/sistem` — sengaja hijau di `local`/`testing`, karena di sana keduanya
memang dimatikan dan merah tiap hari melatih orang mengabaikan merah.

Header keamanan (`HSTS`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`,
`Permissions-Policy`) **tidak** punya env — semuanya dipasang
`App\Http\Middleware\HeaderKeamanan` untuk seluruh respons. HSTS hanya keluar
saat `$request->secure()`.

## Daftar periksa promosi dev → staging → production

1. Migrasi berjalan bersih di tahap sebelumnya (`php artisan migrate --force`).
2. `php artisan test` hijau.
2b. `ADMIN_PASSWORD` terisi di env tahap itu — admin dibuat dan disamakan otomatis oleh `start.sh` saat boot.
3. Deploy ulang resource di tahap tersebut, lalu pastikan sesi kembali tersambung **tanpa scan QR** — ini uji regresi utamanya. Sejak penyatuan, redeploy ikut me-restart web, jadi uji ini sekaligus membuktikan penghentian rapi engine dan penantian boot-nya.
4. Kirim satu pesan uji dan pastikan statusnya sampai `delivered`.
5. Webhook uji coba menerima kiriman dan tanda tangannya lolos verifikasi.
6. Baru merge ke branch berikutnya.

## Menjalankan lokal

Lokal adalah tahap keempat yang tidak ada di Coolify, dan satu-satunya tempat engine masih dijalankan sebagai proses yang benar-benar terpisah (`npm --prefix engine run dev` membaca `engine/.env` sendiri). Buat `.env` di root dan di `engine/` — daftar variabelnya ada di tabel atas — lalu:

> `engine/.env` hanya untuk lokal. Berkas `engine/.env.development`, `.env.staging`, dan `.env.production` sudah dihapus (19 Sep 2026): sejak penyatuan container tidak ada yang membacanya, dan isinya masih menunjuk domain merek lama.

```bash
npm run all
```

Laravel di `http://localhost:8051`, engine di `http://127.0.0.1:3100`, plus queue worker dan Vite.
