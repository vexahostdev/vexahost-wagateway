# VexaHost WA Gateway — catatan untuk sesi berikutnya

Gateway WhatsApp terpusat multi-tenant untuk ekosistem VexaHost, dirancang agar bisa dijual sebagai SaaS. Menggantikan proses `whatsapp-web.js` yang dulu menempel di dalam `flustra-erp`.

> **Sedang berjalan: enam penambahan menuju enterprise.** Rencananya lengkap di
> [PRD.md](PRD.md) — reseller & referal, pay as you go, keamanan, webhook per
> API key, helpdesk, dan email Brevo. **Dikerjakan satu per satu** dalam urutan
> yang ditentukan di PRD §0 — berurutan dalam satu rangkaian, bukan dicampur:
> keenamnya menyentuh
> `SubscriptionService`, `MessageDispatcher`, atau panel admin — tiga tempat yang
> paling mudah rusak diam-diam. Keputusan produk yang sudah diambil Ryan ada di
> tabel "Keputusan yang SUDAH diambil" di PRD; **jangan ditanya ulang, dan jangan
> diusulkan alternatifnya**. Yang paling sering tergoda ditawar: helpdesk dibangun
> **baru di dalam repo ini**, dan `flustra-helpdesk` yang sudah ada **tidak boleh
> disambungkan** — Ryan sudah tahu project itu ada dan sudah memutuskan.

> **Merek produk ini VexaHost sejak 19 Sep 2026 — dulu Flustra.** Domain
> `wa.vexahostcloud.my.id` (dev `wa-dev.`, staging `wa-staging.`), badan hukum
> **PT DESTINARA CHAKRAWALA ARTHA**, repo tujuan `rztechdev/vexahost-wagateway`. Yang
> tetap bernama Flustra hanyalah **project lain** yang memakai gateway ini
> (flustra-erp, flustra-web, flustra-pricing, flustra-helpdesk,
> flustra-clientportal, flustra-auth): sebutan mereka di kode dan dokumentasi
> adalah fakta, **jangan diganti dan jangan dihapus**. Yang tidak boleh ikut
> rusak oleh pergantian ini:
>
> - **Produksi baru mulai dari database kosong** (keputusan Ryan): belum ada
>   pelanggan, data lama hanya diarsipkan lewat satu `mysqldump`. Admin dibuat dan
>   disamakan dengan env `ADMIN_*` oleh `start.sh` **setiap boot**
>   (`AdminUserSeeder`) — dulu langkah manual `db:seed`, dan produksi pertama
>   berakhir tanpa admin. Kata sandi admin diganti lewat env, bukan lewat profil.
>   `APP_KEY` dibiarkan yang lama walau tidak lagi wajib.
> - **API key baru berawalan `vwa_`, yang lama `fwa_` tetap dikenali** — kunci
>   dicari lewat kolom `prefix`, bukan awalannya. Jangan menambah pemeriksaan
>   awalan: database lokal dan yang pernah dipulihkan dari arsip masih memuat `fwa_`.
> - **Header webhook `X-VexaHost-Signature` / `X-VexaHost-Event`.**
> - **Admin disamakan persis dengan vexahost** (`ADMIN_*` di `config/vexahost.php`).
>   Admin lama diubah di tempat oleh migrasi `pindahkan_admin_ke_identitas_vexahost`,
>   bukan dibuatkan akun kedua.
> - **Akun tertaut dengan vexahost — hanya auth.** Daftar atau ganti kata sandi
>   di satu aplikasi ikut berlaku di aplikasi lain lewat endpoint bertanda tangan
>   HMAC (`/api/internal/akun-tertaut`), dipicu observer model `User` — bukan
>   controller, karena akun lahir lewat terlalu banyak jalan. Kode dan
>   protokolnya ada di **dua repo** dan harus sama persis:
>   [docs/AKUN_TERTAUT.md](docs/AKUN_TERTAUT.md). Workspace, tagihan, VPS, 2FA,
>   dan peran admin TIDAK dibagi; akun admin di penerima tidak pernah diubah dari
>   seberang, dan akun yang sudah ada hanya diubah oleh kiriman yang emailnya
>   **terverifikasi** — tanpa itu pendaftaran dengan email orang lain bisa
>   mengambil alih akunnya di aplikasi seberang. Login WA yang gagal menjemput
>   akun dari vexahost (`LinkedAccountLookup`), jadi akun lama vexahost tidak
>   disuruh mendaftar ulang.
> - **Pembayaran belum dipindah.** Mayar dan rekening dibiarkan seperti sebelum
>   pergantian merek sampai Ryan memilih gateway baru; QRIS memakai payload akun
>   DANA yang sama (nama tokonya DESTINARA). Jangan mengusulkan Lynk — akun Lynk
>   VexaHost cuma punya satu webhook dan sudah dipakai aplikasi vexahost.
> - **Nomor WhatsApp bisnis `085808749131`** untuk admin, notifikasi tim, dan
>   kontak publik — dibaca `App\Support\KontakWhatsApp` dari env, tidak pernah
>   ditulis mati di Blade.
>
> Riwayat dan urutan pindahnya: [docs/PINDAH_KE_VEXAHOST.md](docs/PINDAH_KE_VEXAHOST.md).

**Baca dulu:** [docs/PENGANTAR.md](docs/PENGANTAR.md) lalu [docs/ARSITEKTUR.md](docs/ARSITEKTUR.md). Peta seluruh dokumentasi internal ada di [docs/README.md](docs/README.md).

---

## Bentuknya

Empat proses, satu repo, **satu container** ([`start.sh`](start.sh)):

| Proses | Bahasa | Tugas |
|---|---|---|
| web | Laravel 12 | Dashboard, REST API, workspace, antrean, webhook |
| `engine/` | Node 20 | Baileys (WebSocket, tanpa Chromium sejak migrasi) — satu sesi = satu koneksi websocket |
| worker | Laravel | `queue:work` — pengiriman & webhook di latar belakang |
| penjadwal | Laravel | `schedule:work` — sinkronisasi status sesi tiap menit |

Laravel memegang **keadaan**, engine memegang **koneksi**. Engine tidak punya database: saat boot ia menanyakan daftar sesi ke Laravel lewat `/internal/engine/bootstrap`.

Ketahanan sesi (alasan utama project ini ada): kredensial disimpan di persistent volume `/data` **dan** dicadangkan berkala ke Laravel. Redeploy tidak memutus nomor. Tapi nomor tetap **bisa diganti** kapan saja lewat Putus tautan → Hubungkan → scan nomor lain.

## Perintah

```bash
npm run all          # Laravel :8051 + engine :3100 + queue + vite sekaligus
php artisan test     # seluruh tes
./vendor/bin/pint    # format PHP, jalankan sebelum commit
```

Tidak ada perintah CLI untuk menyiapkan workspace — semuanya lewat dashboard, termasuk untuk VexaHost sendiri dan aplikasi Flustra yang memakainya.

## Aturan yang berlaku di seluruh kode

**Semua teks Bahasa Indonesia** — komentar, pesan galat, nama tes, dokumentasi, UI. Nama kelas/method/kolom tetap Inggris.

**Selalu berangkat dari workspace.** `EnsureWorkspaceSelected::from($request)->sessions()->findOrFail($id)`, bukan `WaSession::find($id)`. Tidak ada global scope yang menangkap kelalaian ini.

**Pesan keluar hanya lewat `MessageDispatcher::queue()`** — di situlah normalisasi nomor, pemeriksaan kuota, dan pencatatan pemakaian terjadi.

**Nomor selalu lewat `PhoneNumber`** — `normalize()` sebelum menyimpan, `mask()` sebelum menulis log.

**Kegagalan WhatsApp tidak boleh menjatuhkan proses lain.** Notifikasi itu pelengkap; invoice tetap harus tersimpan meski WhatsApp-nya gagal.

**Komentar menjelaskan alasan, bukan mekanisme.** Kode sudah menunjukkan *apa*; komentar berguna untuk *kenapa begitu* dan *apa akibatnya kalau tidak*.

## Dua kumpulan dokumentasi — jangan tertukar

| | Untuk siapa | Lokasi | Tampil di web |
|---|---|---|---|
| Internal | Tim | `docs/` | Tidak |
| Produk | Pelanggan SaaS | `resources/docs/` | Ya, di `/docs` |

Halaman publik **tidak boleh** memuat cara meng-clone source code, perintah instalasi, atau alamat repo GitHub. Ryan menjual ini sebagai SaaS. `DocsTest` menjaga batas itu — ia menolak frasa `git clone`, `github.com`, `composer install`, `npm install`, `php artisan` muncul di halaman publik.

Menambah halaman publik: buat berkas di `resources/docs/`, daftarkan di katalog `app/Support/DocsRepository.php` (katalog itu sekaligus daftar putih).

## Jebakan yang pernah menggigit

**Nama rute bertabrakan.** `Route::apiResource` di `routes/api.php` harus diberi awalan `api.` — tanpa itu ia menimpa nama rute dashboard, dan `route('sessions.index')` menghasilkan URL API.

**`where($kolom, null)` menjadi `whereNull`.** Pernah membuat satu ack tanpa id menandai pesan yang masih mengantre sebagai terbaca. Periksa nilai null secara eksplisit sebelum menyusun kueri.

**Baris baru dari `firstOrCreate` tidak membaca nilai default database.** Tulis nilai awal secara eksplisit, kalau tidak counter terbaca `null` bukan `0`.

**Hostname internal Coolify adalah UUID resource, bukan nama tampilannya.** `DB_HOST=flustra-mysql` menghasilkan `cURL error 6: Could not resolve host` — nama itu cuma label di antarmuka. Pakai UUID dari URL resource (`.../application/<uuid>`); Coolify sudah mengisinya begitu untuk `DB_HOST`. Sejak penyatuan container ini tidak lagi berlaku untuk `ENGINE_URL`/`LARAVEL_URL` — keduanya `127.0.0.1` sekarang, dan satu kelas kegagalan ikut hilang bersamanya.

**Env engine dan env Laravel berbagi satu ruang nama.** Tiga nama bertabrakan dan harus berawalan: `ENGINE_PORT`, `ENGINE_HOST`, `ENGINE_LOG_LEVEL`. `PORT` dan `HOST` polos dipakai Coolify untuk proses web; `LOG_LEVEL` polos dipakai Laravel dengan skala nilai berbeda — `error` di produksi, yang di engine berarti kehilangan baris `Backup sesi terkirim`, satu-satunya bukti cadangan sesi tersimpan dan patokan uji regresi utamanya. Nama lama tetap diterima sebagai cadangan supaya `npm --prefix engine run dev` masih jalan di lokal. `engine/tests/config.test.js` menjaganya.

**(Era whatsapp-web.js — tidak berlaku sejak engine pindah ke Baileys, disimpan sebagai riwayat.) Chromium hilang dari image kalau `PUPPETEER_CACHE_DIR` tidak diset.** Puppeteer mengunduhnya saat `npm ci` ke `$HOME/.cache/puppeteer` = `/root/.cache/puppeteer`. Selama engine punya resource sendiri, folder itu ikut ke image akhir. Sejak base directory-nya `/`, yang dibawa Nixpacks ke image akhir hanya `/app` — unduhannya hilang. Kegagalannya terlambat dan di tempat yang salah: build hijau, container menyala normal, dan `Could not find Chrome (ver. ...)` baru muncul di **kartu sesi milik pelanggan**, dalam bahasa Inggris, saat sesi pertama dijalankan. Sekarang variabelnya dipasang dua kali — di Install Command (tempat mengunduh) dan di environment (tempat mencari) — dan `periksa_chromium()` di `start.sh` memindahkan kegagalannya ke saat boot, dengan perbaikannya disebutkan langsung.

**`PHP_CLI_SERVER_WORKERS` diabaikan tanpa `--no-reload`.** `ServeCommand::initialize()` mengembalikan `false` kalau flag itu tidak ada, dan peringatannya cuma muncul sekali di awal log — sesudah itu tidak ada gejala apa pun. Aplikasinya berfungsi normal, hanya melayani satu permintaan pada satu waktu, dan callback engine (`qr`, `ready`, pesan masuk, `message_ack`) antre di belakang halaman dashboard yang sedang dibuka orang. Cara memastikannya: `ps -eo pid,ppid,args | grep '[p]hp -S'` harus menghasilkan lebih dari satu baris. `start.sh` sudah memakai `--no-reload`; yang dilepas dengannya cuma restart otomatis saat `.env` berubah, dan di produksi berkas itu memang tidak pernah berubah saat container hidup.

**Nixpacks memakai Node 18 kalau `NIXPACKS_NODE_VERSION` tidak diset.** Engine menuntut Node 20 lewat `engines` di `engine/package.json`, dan Node 18 sudah EOL. Env Laravel sudah memuatnya sejak awal; env engine dulu tidak.

**Engine mati bukan kegagalan sesi.** `SyncSessionStatusJob` berjalan tiap menit dan memanggil `connect()` untuk sesi yang terbaca `disconnected`. Selama engine di-deploy ulang, panggilan itu gagal — dan dulu `connect()` menandai sesi `failed` untuk kegagalan apa pun. `BootstrapController` mengecualikan `failed` dari pemulihan otomatis, jadi nomor yang kredensialnya masih utuh di volume tampak putus dan seolah harus di-scan ulang **setiap deploy** — persis hal yang project ini dibuat untuk menghilangkan. Sekarang `ConnectionException` ditangani terpisah (tetap `disconnected`), dan bootstrap ikut memulihkan sesi `failed` yang `connected_at`-nya terisi. `SessionRecoveryTest` menjaga keduanya.

**`client.sendMessage()` kadang mengembalikan `undefined` walau pesannya sampai.** whatsapp-web.js tidak selalu berhasil menyusun objek `Message` balasannya. `sent.id?._serialized` melempar TypeError, engine menjawab 503, Laravel menganggapnya gagal dan mengulang — penerima menerima pesan yang sama tiga sampai empat kali sementara riwayat mencatatnya `failed`. Gejalanya: kolom `error` berbunyi `Cannot read properties of undefined (reading 'id')` pada pesan yang sebenarnya terkirim. **ID pesan tidak boleh menentukan berhasil-tidaknya pengiriman** — ia cuma pelengkap untuk melacak status.

**Habis waktu bukan berarti tidak terkirim.** Engine menahan tiap pesan 3–8 detik (jeda anti-ban) dan mengantre per sesi, jadi pesan keempat dalam satu giliran bisa menunggu lebih dari 30 detik. Dengan `ENGINE_TIMEOUT=15`, Laravel menyerah lebih awal lalu mengulang job-nya — penerima menerima pesan yang sama tiga sampai empat kali, persis sejumlah `$tries`. Dua penjagaan sekarang: engine menyaring kiriman ganda berdasarkan `message_id` (`SessionManager#kiriman`), dan pengiriman memakai `ENGINE_SEND_TIMEOUT` (60 detik) yang terpisah dari `ENGINE_TIMEOUT` (15 detik). **Jangan menyatukan keduanya lagi** — batas panjang untuk perintah sesi membuat tombol Hapus dan Putus tautan menggantung semenit di muka pengguna, batas pendek untuk pengiriman memunculkan kiriman ganda. Dan jangan menambah pemanggil engine yang tidak mengirim `message_id`.

**Paket hidup di `config/plans.php`, bukan di tabel.** Essentials/Prime/Elite (149rb/249rb/449rb per bulan, tahunan = ×10) beserta seluruh batasnya dibaca dari satu berkas config oleh halaman depan, halaman langganan, penegakan batas, dan penyusunan tagihan sekaligus. Jangan pernah menulis ulang angka atau daftar fitur sebagai teks di Blade — halaman harga yang menjanjikan angka berbeda dari yang ditegakkan adalah janji yang kita langgar di hadapan orang yang baru saja membayar. Mengubah angka di config **tidak berlaku seragam**, dan ini pernah salah ditulis di berkas ini: `max_sessions`, `monthly_message_quota`, dan `api_rate_limit_per_minute` disalin ke kolom `workspaces` saat `markPaid()`, jadi pelanggan lama baru ikut berubah pada pembayaran berikutnya — sengaja, karena mengecilkan kuota di tengah periode berarti memangkas sesuatu yang sudah dibayar penuh. Sebaliknya `max_api_keys`, `max_members`, dan `message_retention_days` dibaca langsung tiap kali, jadi berlaku seketika untuk semua orang di paket itu; menurunkan retensi berarti pesan lama pelanggan mulai terhapus pada pemangkasan berikutnya. Harga dibaca saat tagihan terbit, jadi langsung berlaku untuk perpanjangan siapa pun. Kalau perlu mengunci harga lama untuk pelanggan lama, beri slug baru (`prime-2027`), jangan ubah paket yang sedang dipakai orang.

**Batas ditegakkan dari kolom `workspaces`, bukan dibaca dari paket tiap kali.** `markPaid()` menyalin `max_sessions`, `monthly_message_quota`, dan `api_rate_limit_per_minute` dari paket ke kolom. Alasannya dua: dua penegakan terpanas (kuota pesan dan jumlah sesi) berjalan di jalur pengiriman dan tidak boleh memuat config per pesan, dan kolom itu sekaligus memberi admin ruang menaikkan satu workspace tanpa memindahkan seluruh pelanggan paket itu. Yang **tidak** disalin: batas API key dan anggota tim — keduanya dibaca langsung dari paket, karena kebutuhan kelonggaran manual di sana tidak pernah muncul.

**Status langganan hanya berpindah lewat `SubscriptionService`.** Tiap perpindahan menyentuh `subscriptions.status` *dan* `workspaces.status` sekaligus. Keduanya harus selalu sepakat: langganan lewat jatuh tempo dengan workspace masih `active` berarti pelanggan mengirim tanpa membayar; sebaliknya berarti layanan mati padahal tagihannya lunas. Tidak ada controller, job, atau perintah yang boleh menulis salah satunya sendiri.

**Penegakan langganan punya jalur otomatis, bukan cuma tombol.** Yang menahan pemakaian tanpa membayar ada lima, dan `PenegakanLanggananTest` menjaga seluruhnya sebagai satu daftar: `EnsureSubscriptionActive` (menolak semua POST), `AuthenticateApiKey` (menolak API key workspace mati), `MessageDispatcher::guardWorkspace()` (menolak pengiriman), `SendMessageJob` (memeriksa ulang sebelum kirim), dan `WaSession::scopeLayananHidup()`.

Yang terakhir menutup dua jalur yang menjalankan sesi **tanpa ada manusia yang menekan apa pun**, dan keduanya dulu tidak memandang status workspace sama sekali: `SyncSessionStatusJob` memanggil `connect()` tiap menit untuk sesi `disconnected` — jadi sesi yang baru dilepas `releaseSessions()` karena menunggak dijalankan lagi semenit kemudian, tiap menit, selamanya — dan `BootstrapController` menyuruh engine memulihkan seluruh sesi tiap boot, termasuk milik yang menunggak. Yang bocor di situ bukan pengiriman pesan (itu tetap ditolak) melainkan sumber daya paling langka: satu dari **tiga** slot sesi untuk seluruh pelanggan, plus pesan masuk dan webhook yang tetap mengalir. Penegakan yang bisa dibatalkan oleh penjadwal kita sendiri bukan penegakan. Kalau menambah jalur baru yang menjalankan sesi otomatis, ia wajib lewat `layananHidup()`.

**Tangga penangguhan, dan kenapa tiap anak tangganya begitu.**

| Kapan | Yang berhenti | Yang tetap jalan |
|---|---|---|
| Jatuh tempo lewat (`past_due`) | pengiriman keluar, **pesan masuk, webhook** | dashboard, riwayat, WhatsApp di ponsel pelanggan |
| +3 hari (`suspended`) | nomor dilepas dari gateway | riwayat, template, webhook, API key, anggota |
| Tagihan lunas | — | nomor tersambung **sendiri** dalam hitungan menit |

`past_due` menandai workspace `suspended` — dengan itu `MessageDispatcher::guardWorkspace()` dan `AuthenticateApiKey` yang sudah ada ikut berlaku tanpa satu pun pemeriksaan tambahan.

Pelepasan memakai `disconnect()`, **bukan** `logout()`: `logout()` membuang cadangan kredensial (`$session->backups()->delete()`) dan memaksa scan QR ulang saat pelanggan kembali. Karena cadangannya utuh, sambung ulang setelah pembayaran berjalan sendiri lewat `SyncSessionStatusJob` — pelanggan tidak menekan apa pun. `PulihSetelahBayarTest` menjaga ketiga syaratnya sekaligus (cadangan tidak terhapus, workspace hidup lagi, penjadwal mau menjalankan ulang); kalau salah satu rusak, seluruh tangga ini berubah dari "menghemat kapasitas" menjadi "menghukum yang telat bayar".

**Pesan masuk ikut ditutup (6 Sep 2026).** Sebelumnya hanya pengiriman keluar yang berhenti, sementara pesan masuk tetap dicatat dan tetap diteruskan ke webhook sepanjang masa tenggang — integrasi pelanggan tetap berjalan separuh, dan separuh itu justru yang paling banyak dipakai orang yang memakai gateway ini untuk mencatat percakapan ke CRM. Penjagaannya di `EngineEventController::onIncomingMessage()`. Yang sengaja **tidak** ikut ditutup: peristiwa siklus hidup sesi (`qr`, `ready`, `disconnected`) dan ack pesan yang sudah telanjur terkirim — keduanya justru yang memberi tahu sistem pelanggan bahwa nomornya berhenti, dan membisukannya menghasilkan kegagalan yang tidak bisa dilacak siapa pun.

Kalimat yang wajib ikut tiap kali keadaan ini dijelaskan: **pesannya sendiri tidak hilang.** Kita perangkat tertaut, jadi pesan yang dikirim orang tetap sampai ke WhatsApp di ponsel pelanggan; yang berhenti cuma pencatatan dan penerusannya. Tanpa kalimat itu, "pesan masuk berhenti" terbaca seperti nomornya diblokir.

**Masa tenggang 3 hari, turun dari 30 lalu 14.** Angka 30 dulu dibenarkan dengan alasan yang ternyata keliru — dikira melepas sesi memaksa scan ulang. Tidak. Lalu sejak pesan masuk ikut ditutup, jendela ini berhenti memberi apa pun kepada pelanggan: pengiriman mati, penerimaan mati, webhook mati, dan yang tersisa cuma sesi menyala tanpa melayani apa-apa sambil memakan satu dari **tiga** slot untuk seluruh pelanggan. Tidak nol karena melepas lalu menyambung lagi adalah operasi nyata yang bisa gagal; tiga hari membuat pelanggan yang bayarnya cepat — dan pembayarannya masih diperiksa manusia — tidak perlu melewati siklus itu sama sekali.

**Nomor yang berhenti wajib mengatakan kenapa.** `Subscription::alasanNomorBerhenti()` satu-satunya sumbernya. Sebelum ada ini, sesi yang dilepas cuma berubah jadi "Terputus" tanpa sepatah kata pun — dan pelanggan menyimpulkan satu-satunya hal yang masuk akal: ada yang rusak. Lalu menekan Hubungkan berulang kali, gagal terus, dan menghubungi kami untuk masalah yang sebenarnya cuma tagihan. Kalimatnya tidak dirender merah dan tidak lewat `last_error`: itu kolom untuk gangguan, dan tagihan bukan gangguan.

**Satu keadaan, satu penjelasan, satu tempat.** Halaman Sesi pernah menampilkan tiga kotak beruntun yang mengatakan hal yang sama dengan kalimat berbeda — spanduk layout, blok kunci formulir, dan spanduk alasan. Sekarang spanduk layout tidak muncul di `billing.*` dan `sessions.*` karena keduanya punya penjelasan sendiri yang lebih lengkap, dan alasan nomor berhenti menumpang di blok kunci, bukan jadi kotak keempat. Dan selama terkunci, tombol tindakan sesi **tidak dirender sama sekali** — seluruhnya POST, seluruhnya ditolak middleware; empat tombol yang cuma bisa gagal kalah berguna dari satu tautan Perpanjang.




**Workspace baru lahir di paket coba gratis (6 Sep 2026).** `ensureFor()` memberi paket `coba` berstatus `trialing` **tanpa tanggal berakhir**, workspace-nya `active`, dan batasnya batas terkecil: 1 nomor, 1 API key, dan **5 pesan seumur hidup workspace**. Pendaftar baru memang boleh menautkan nomor dan menguji integrasinya — yang membatasi jumlah pesan, bukan status.

Jatah itu dihitung `Workspace::freeMessagesUsed()` = jumlah `usage_counters.messages_sent` **seluruh periode**, bukan `currentUsage()`. Tiga cara ia bisa bocor jadi layanan gratis selamanya, dan ketiganya dijaga `FreeTierTest`: dihitung per bulan (angkanya pulih tiap tanggal 1), dihitung dari tabel `messages` (barisnya dipangkas retensi, jatah terpakai ikut hilang), atau diperiksa hanya saat mengantre. Paket `coba` bertanda `sellable => false` sehingga tidak muncul di halaman harga maupun dropdown admin — tidak ada yang bisa membelinya, dan tidak boleh ada yang tidak sengaja memindahkan pelanggan berbayar ke sana.

Jatahnya sekali per **pemilik**, bukan sekali per workspace. Tidak ada batas berapa workspace yang boleh dibuat satu akun — itu disengaja, satu orang dengan tiga cabang membayar tiga kali — tapi kalau tiap workspace baru ikut membawa masa cobanya sendiri, akun yang sama tinggal menekan "Workspace Baru" sepuluh kali untuk mendapat sepuluh kali lima pesan gratis, masing-masing menahan satu sesi WhatsApp dari **tiga** yang tersedia untuk seluruh pelanggan. Kapasitas engine habis oleh yang belum membayar sepeser pun, dan yang tertahan di luar justru pelanggan berbayar. Workspace kedua dan seterusnya milik pemilik yang sama lahir `unpaid`.

Masa gratis sampai akhir bulan untuk akun **pra-SaaS** adalah hal yang berbeda dan tetap berlaku: diberikan sekali oleh migrasi `give_existing_workspaces_a_trial` pada paket `essentials` dengan tanggal berakhir. Ini pernah salah dengan arah sebaliknya — bawaannya `trialing` di paket berbayar, sehingga setiap pendaftar baru memakai produk penuh cuma-cuma tanpa pernah diputuskan siapa pun.

**Layanan berhenti pada detiknya, bukan pukul 08:00.** `workspaces.service_until` menyimpan salinan `current_period_end`, dan `Workspace::isActive()` memeriksa **dua** hal: kolom status *dan* tanggal itu. Sebelum ada kolom ini, satu-satunya yang memutus layanan adalah `BillingCycleJob` pukul 08:00 sementara masa berlaku habis pukul 23:59:59 — delapan jam tiap periode ketika langganan sudah habis tapi seluruh penegakan (`MessageDispatcher`, `AuthenticateApiKey`) masih meloloskannya. Yang paling mungkin memakainya bukan orang yang sengaja curang, melainkan aplikasi terintegrasi yang mengirim otomatis semalaman dan tidak tahu langganannya sudah habis. `null` berarti tidak pernah kedaluwarsa — keadaan paket coba gratis. Setiap perpindahan status di `SubscriptionService` harus ikut menulis kolom ini; kalau tidak, ia justru menjadi sumber kebenaran ketiga yang bisa berbeda diam-diam.

**`SendMessageJob` memeriksa ulang workspace sebelum mengirim.** Penjagaan di `MessageDispatcher::queue()` saja tidak cukup: antara antre dan kirim bisa lewat berjam-jam (jeda anti-ban 3–8 detik per pesan, antrean per sesi, backoff job yang gagal). Broadcast beberapa ribu nomor yang diantrekan menit terakhir sebelum langganan habis dulu tetap terkirim seluruhnya sesudahnya. Yang gagal ditandai `failed`, **bukan** dilepas untuk diulang — langganan tidak akan hidup lagi dalam tiga kali backoff, dan pesan yang menggantung berhari-hari lalu tiba-tiba terkirim saat pembayaran masuk jauh lebih buruk daripada pesan yang jelas-jelas gagal.

`unpaid` sengaja dibedakan dari `past_due`: yang satu belum pernah mulai, yang lain pernah lalu berhenti, dan keduanya butuh kalimat berbeda di depan pengguna. "Pengiriman pesan dihentikan" tidak masuk akal bagi orang yang belum pernah mengirim apa pun. Sejak paket coba gratis ada, pendaftar baru tidak lagi lahir `unpaid` — status itu tetap dipakai dan tetap ditegakkan, tapi sekarang hanya dicapai lewat pembatalan.

**Panel admin: daftar untuk mencari, halaman untuk bertindak.** Semua daftar berbentuk tabel; pengelolaan satu workspace pindah ke `/admin/workspaces/{id}`. Sebelumnya tiap baris bisa mengembang jadi panel berisi empat form — daftar yang berubah bentuk saat disentuh sulit dipindai, dan form yang bersembunyi di dalam baris membuat orang tidak yakin sedang mengubah workspace yang mana. Pengecualiannya halaman Tagihan: tindakannya berjajar di bawah tabel karena memeriksa bukti adalah pekerjaan berulang belasan kali berturut-turut.

Menu lain yang harus tetap ada: **Lalu Lintas Pesan** (satu-satunya tempat yang menjawab "masalahnya di satu pelanggan atau di gateway kami"), **Catatan Audit** (`audit_logs` sudah lama ditulis tapi dulu hanya bisa dibaca lewat tinker — halaman ini lahir dari kejadian nyata: tagihan berbukti dibatalkan 24 detik setelah buktinya diunggah), dan **Sistem** (satu tempat untuk semua yang bisa berhenti bekerja tanpa gejala: engine mati sementara sesi tampak hijau, notifikasi diam karena env kosong, QRIS salah salin). Label dan warna status hidup di `App\Support\StatusBadge`, bukan ditulis ulang tiap Blade.

**Dashboard memakai sidebar tetap (5 Sep 2026).** Sebelumnya sidebar-nya menyatu dengan latar halaman (`lg:bg-transparent lg:border-0`) dan tidak terbaca sebagai navigasi tetap — orang membacanya sebagai kolom teks pertama, lalu mencari menu di tempat lain. Sekarang: `<aside>` melekat di tepi kiri dengan `bg-sidebar` dan garisnya sendiri, konten diberi ruang lewat `lg:pl-64`, judul halaman pindah ke bilah atas yang lengket, dan pemilih workspace turun ke dalam sidebar (seluruh isi menu di bawahnya memang milik workspace itu). Menunya dikelompokkan; `cocok` pada tiap butir menjaga halaman anak — `messages.show`, `billing.*` — tetap menyalakan menu induknya.

Pembagian tempatnya disengaja dan jangan ditukar: **sidebar** memuat apa yang bisa dikerjakan di dalam workspace, **kanan atas** memuat akun. Tombol profil di bilah atas hanya avatar; nama dan email muncul di dalam dropdown-nya, karena di bilah atas keduanya cuma mengulang sesuatu yang sudah pasti diketahui orang yang sedang login. Kaki sidebar menyisakan satu tombol Keluar saja — menu akun di dua tempat berarti dua daftar yang harus dijaga tetap sama.

Menu **selalu** dirender, termasuk sebelum pengguna punya workspace; saat itu tautannya diarahkan ke onboarding karena middleware memantulkan semua halaman di dalamnya. Menyembunyikannya membuat pendaftar baru tidak pernah tahu apa yang sebenarnya mereka dapat.

Di dalam sidebar tidak ada garis pemisah horizontal — baik di bawah logo maupun di bawah pemilih workspace — supaya ia terbaca sebagai satu bidang utuh; yang tetap bergaris hanya bilah atas konten dan kaki sidebar. Lebarnya `w-72`, dan konten diberi ruang lewat `lg:pl-72`; kalau salah satunya diubah, yang lain harus ikut. Geseran buka-tutup di layar kecil diatur `.panel-sidebar` di `app.css`, bukan `-translate-x-full`. Warna sidebar di mode terang bersemu hijau muda (`--sidebar: #eaf2ea`), bukan krem preset aslinya; mode gelap tidak diubah.

**Halaman langganan dipecah jadi empat alamat.** `/billing` (ringkasan), `/billing/paket`, `/billing/riwayat`, `/billing/invoices/{id}`. Yang penting dari pemisahan ini bukan kerapian melainkan bahwa tiap langkah bisa ditautkan langsung — spanduk "langganan habis" menuju **paket**, bukan ringkasan, karena yang dibutuhkan orang yang menekannya adalah memilih dan membayar. Navigasi antar keempatnya ada di satu partial (`_nav.blade.php`) yang dipakai bersama; tab yang hilang di salah satu halaman berarti jalan buntu bagi yang membukanya dari sana.

**Halaman bayar berbentuk checkout dua kolom.** Kiri tiga langkah bernomor (data penagihan → bayar → kirim bukti), kanan ringkasan pesanan yang menempel saat digulir. Pemilihan metode ada di kolom **kanan**, berdampingan dengan totalnya: memilih cara membayar adalah keputusan tentang uang, dan angkanya harus terlihat saat keputusan itu diambil. Metode yang tidak dikonfigurasi **tidak pernah** muncul sebagai pilihan — menawarkan cara bayar yang belum ada di baliknya persis kesalahan driver `fonnte` dulu; `BillingTest` menjaganya dari dua arah (payload QRIS rusak tidak ditawarkan, rekening terisi ditawarkan).

Data penagihan (`workspaces.billing_name/billing_email/billing_phone`) menempel pada workspace, bukan pada tiap tagihan — yang membayar tidak berganti tiap bulan. Konsekuensinya: mengganti nama penagihan mengubah tampilan seluruh tagihan lama juga. Kalau suatu saat faktur harus membeku pada keadaan saat terbit, salin nilainya ke `invoices` pada saat itu.

**Tidak ada pemulihan kata sandi mandiri, dan itu keputusan sadar (6 Sep 2026).** Selama email belum benar-benar terkirim, tautan reset akan berakhir di berkas log sementara antarmuka tetap bilang "sudah kami kirim" — kegagalan yang baru ketahuan saat ada pelanggan terkunci. Jalur resminya: pelanggan menghubungi admin, admin mengatur ulang dari `/admin/pengguna`. Halaman login menyembunyikan tautan "Lupa kata sandi?" sendiri lewat `Route::has('password.request')`, jadi tidak ada tombol yang menuju jalan buntu. Kalau nanti SMTP sudah jalan, yang perlu dibuat kembali: `PasswordResetController` + dua view + empat rute bernama `password.*`.

**Pemberitahuan WhatsApp dikirim lewat gateway ini sendiri.** Semua teksnya di `BillingMessages`, pengirimannya lewat `WhatsAppNotifier` — jangan menulis pesan langsung di controller: pelanggan menerima semuanya dari nomor yang sama, dan nada yang berbeda-beda terbaca sebagai ketidakrapian atau penipuan. Tiga aturan yang berlaku di sana: tidak pernah melempar galat (notifikasi itu pelengkap, tagihan tetap harus lunas meski pesannya gagal), selalu dari sesi workspace VexaHost (`BILLING_NOTIFY_WORKSPACE_ID`) bukan sesi pelanggan, dan satu peristiwa satu pesan lewat penanda cache.

Yang perlu diingat saat menambah pemanggil baru: `WhatsAppNotifier` memakai `MessageDispatcher`, jadi apa pun yang dipakai `MessageDispatcher` **tidak boleh** menyuntikkan notifier lewat constructor — ambil dari container di dalam method (lihat `warnIfQuotaLow`), kalau tidak lingkarannya menutup dan seluruh pengiriman pesan mati. Dan panggil di luar `DB::transaction`: di dalamnya, pesan yang gagal akan menggulung balik penandaan lunas.

Kalau `BILLING_NOTIFY_WORKSPACE_ID` kosong atau nomor VexaHost terputus, **seluruh** pemberitahuan diam tanpa satu pun gejala. Halaman Ringkasan panel admin menampilkan peringatan merah selama keadaan itu berlangsung — itu satu-satunya cara menyadarinya.

**Bukti yang sudah masuk mengunci tagihannya.** Pelanggan tidak bisa membatalkan tagihan yang buktinya sudah dikirim, dan saringan bawaan `/admin/tagihan` adalah **perlu-diperiksa** — yang menentukan sebuah tagihan perlu dilihat manusia bukan statusnya, melainkan adanya bukti yang belum dijawab. Keduanya menutup kegagalan yang sudah benar-benar terjadi (6 Sep 2026): bukti terunggah, tidak ada tanda yang cukup jelas bahwa ia diterima, pelanggan mengira gagal lalu membatalkan tagihannya 24 detik kemudian — dan tagihan `canceled` waktu itu lenyap dari layar admin. Uang masuk, layanan mati, tidak ada satu pun tempat yang menunjukkannya. Tagihan yang telanjur ditutup **tetap** bisa ditandai lunas dari panel; menolaknya berarti memaksa pelanggan membayar dua kali.

**Setelah bukti terkirim, pelanggan mendarat di `billing.verifying`.** Halaman tersendiri, bukan kembali ke form dengan spanduk hijau — spanduk tipis terbukti tidak cukup untuk keputusan yang menyangkut uang.

**Tiap tombol yang menyentuh engine wajib punya penanganan gagalnya sendiri, dan jawabannya tidak sama untuk semua.** `connect` dulu satu-satunya yang dijaga; `disconnect` dan `logout` tidak punya `try` sama sekali, jadi menekan "Putus tautan" saat engine tidak menjawab melempar `ConnectionException` mentah dan pelanggan mendarat di halaman Internal Server Error lengkap dengan jejak tumpukan dan potongan source code kami. Aturannya sekarang: **Hentikan** menandai `disconnected` walau engine bisu (engine yang tidak menjawab berarti ia tidak sedang menjalankan sesi itu juga, dan tombol yang tidak pernah bisa berhasil lebih buruk daripada baris yang jujur); **Putus tautan** justru TIDAK mengubah apa pun (kegagalan tersering di sana habis waktu 15 detik — bukan bukti perintahnya tidak sampai, dan menandai nomor terputus padahal engine masih memegangnya menghasilkan dashboard yang bilang "belum tertaut" sementara nomor aslinya masih menerima pesan; `SyncSessionStatusJob` merapikannya dalam satu menit); **Hapus** tetap menghapus, kalau tidak sesi hantu yang tidak bisa diapa-apakan menumpuk. Di API, kegagalan engine dijawab **502**, bukan 500 — yang gagal layanan di belakang kami, dan klien yang membacanya tahu permintaannya sendiri tidak salah. `EngineErrorTest` menyapu keempat tombol sekaligus supaya tombol baru tidak lolos dengan lubang yang sama.

**Galat engine diterjemahkan sebelum sampai ke layar.** `App\Support\EngineError::pesan()` satu-satunya jalan; rincian teknisnya ke `Log`, bukan ke muka pengguna. Yang pernah tampil apa adanya di modal pelanggan: `cURL error 7: Failed to connect to 127.0.0.1 port 3100 ... for http://127.0.0.1:3100/sessions/01m1ttj.../start` — membocorkan alamat, port internal, dan ULID sesi ke layar yang bisa di-screenshot siapa saja, tidak bisa ditindaklanjuti pembacanya, dan terbaca seperti kerusakan permanen padahal engine yang sedang di-deploy ulang tidak memutus satu pun nomor. Yang perlu diingat: `wa_sessions.last_error` **juga muka pengguna** — ia tampil di daftar sesi dan di modal QR, jadi `SessionService` menyimpan kalimat terjemahannya ke sana, bukan pesan mentah. Kalimatnya selalu menyebut apa yang terjadi pada nomor pelanggan, karena itu satu-satunya pertanyaan yang benar-benar ada di kepala orang saat layar itu muncul. `EngineErrorTest` menjaganya.

**Dialog memakai SweetAlert2, bukan `confirm()` bawaan.** Pasang lewat atribut, bukan `onsubmit`: `data-konfirmasi="pesan"` pada `<form>` untuk konfirmasi, `data-validasi` untuk menahan pengiriman saat ada isian wajib yang kosong lalu menyebutkan yang mana. Pesan dari server dititipkan lewat flash `swal` dan dirender `partials/pesan-server` sebagai `<script type="application/json">` — bukan sebagai kode JS yang di-echo, supaya nama workspace milik orang lain tidak pernah bisa merusak atau menyisipkan sesuatu ke halaman. Layout baru harus ikut meng-include partial itu, kalau tidak pesannya hilang diam-diam.

**Pembayaran masih dicocokkan manusia.** Tidak ada notifikasi otomatis dari mana pun. Yang menghubungkan uang masuk dengan tagihan cuma nominal yang dibuat unik lewat kode tiga digit dan bukti transfer yang diunggah pelanggan; admin yang memutuskan di `/admin/tagihan`. Karena itu `QrisManual` sengaja **tidak** diberi antarmuka bersama meski iPaymu direncanakan — antarmuka dengan satu implementasi adalah slot kosong yang menjanjikan sesuatu yang belum ada, persis pola `cloud_api` yang akhirnya dihapus. Saat iPaymu jalan, saat itulah antarmukanya dibuat dengan dua implementasi nyata. Yang sudah disiapkan dari sekarang hanya bekasnya di data: `invoices.channel` dan `invoices.external_id`.

**`QRIS_PAYLOAD` di env wajib dikutip.** Payload QRIS memuat nama kota merchant (tag 60) yang hampir selalu mengandung spasi — punya kita berbunyi `6015Kota Tangerang ` lengkap dengan spasi di ujungnya. Tanpa kutip, dotenv menolak **seluruh** berkas dan aplikasi gagal boot dengan `Failed to parse dotenv file`, bukan sekadar kehilangan QRIS-nya. Dan spasi itu ikut dihitung panjang tag: dikutip tapi terpangkas berarti CRC tidak cocok lagi, `Qris::valid()` menolaknya, dan halaman bayar diam-diam turun ke transfer bank untuk payload yang sebenarnya benar.

**QRIS rusak tidak boleh tergambar.** Payload yang salah ketik saat disalin ke env menghasilkan kode QR yang tampak wajar tapi ditolak setiap aplikasi bank — dan kegagalannya baru terlihat saat pelanggan sudah berdiri di depan layar. `Qris::valid()` memeriksa CRC sebelum halaman merender apa pun; yang gagal jatuh ke instruksi transfer bank. Batasnya: CRC dihitung atas string mentah tanpa memedulikan struktur TLV, jadi panjang tag yang salah tulis tetap lolos. `tests/Unit/QrisTest.php` menjaga keduanya.

**Warna diambil dari satu tempat.** Palet tema "Nature" (hijau, mengikuti logo) hidup sebagai variabel CSS di `resources/css/app.css`; `resources/css/auth.css` punya salinannya sendiri karena halaman auth memakai latar WebGL dan efek kaca yang butuh warna solid. Menambah warna baru berarti menambah token, bukan menulis `bg-green-600` di Blade. Yang sengaja dikecualikan: status sesi (amber/biru/merah — lima keadaan tidak mungkin dibedakan dengan lima rona hijau) dan blok kode (selalu gelap di kedua mode).

**Font seluruh produk sans-serif: Plus Jakarta Sans (5 Sep 2026).** Anthropic Serif dilepas atas permintaan Ryan bersamaan dengan perombakan halaman depan; keempat `@font-face`-nya dibuang dari `app.css` dan berkas `.otf`-nya tidak lagi dimuat siapa pun. Plus Jakarta Sans dipilih karena halaman auth sudah memakainya sejak awal — font lain di sini akan membuat satu produk terasa seperti dua aplikasi saat pengguna berpindah dari layar masuk ke dashboard. Dimuat dari Google Fonts di **empat** layout (`app`, `admin`, `docs`, `welcome`); menambah layout baru berarti menambah tautannya juga, kalau tidak halaman itu diam-diam jatuh ke font sistem. Tumpukan cadangannya sengaja berujung sans-serif, bukan serif.

**Kartu hanya di baris atas (6 Sep 2026).** Berlaku untuk seluruh dashboard pelanggan dan panel admin, bukan cuma satu halaman. Yang berbingkai tinggal tiga hal: ubin angka `<x-stat>` di baris paling atas, formulir yang menunggu keputusan (buat sesi, buat API key, hapus workspace), dan spanduk keadaan yang butuh tindakan. Sisanya mengalir langsung di atas latar lewat `<x-section>` (judul + garis tipis) dan `<x-tabel>` (tabel tanpa bingkai luar). Alasannya keluhan nyata Ryan: halaman yang seluruh isinya kotak membuat tiap bagian tampak sama penting, dan mata kehilangan tempat berpijak — `dashboard/index` dulu 14 kotak, `admin/overview` 16. Jangan membungkus daftar atau tabel dengan `<x-card>` lagi; kotak di dalam kotak (bingkai panel + garis tabel) itu justru sumber rasa penuhnya. `<x-card>` sengaja dibiarkan hidup untuk tiga pengecualian di atas dan untuk kartu harga, tempat tiga pilihan memang harus terbaca sebagai tiga benda terpisah.

**Halaman depan memakai ritme, bukan kartu.** Sampai 5 Sep 2026 tiga bagian berturut-turut memakai kotak `border` berukuran sama — tiga belas kotak identik beruntun, dan tidak ada satu pun bagian yang terasa lebih penting dari yang lain. Sekarang kartu berbingkai hanya enam di seluruh halaman (tiga kartu harga, dua panel kode, satu panel ilustrasi), dan bagian dibedakan dengan berselang antara latar polos dan `bg-muted/30`. Kalau menambah bagian baru, ikuti selang-selingnya — jangan menambah kotak.

**Pengecualian dikelola dari panel admin, dan hanya dari sana.** Menu **Pengecualian** (`/admin/pengecualian`) memegang tiga hal sekaligus, digabung karena ketiganya menjawab pertanyaan yang sama — siapa yang tidak tunduk pada aturan biasa, dan kenapa:

- **Nomor istimewa** (`special_numbers`) — nomor milik perusahaan sendiri. Tidak menghitung `max_sessions` workspace mana pun, tidak ikut dilepas saat langganan mati, dan pengiriman darinya tidak dihalangi status workspace. Batasnya penting dan dijaga tes: **menumpang tidak membebaskan yang ditumpangi** — workspace pelanggan yang kebetulan menampung nomor kami tetap ditagih untuk nomor-nomornya sendiri. Pengecualian yang bocor lebih jauh dari maksudnya adalah cara paling halus sebuah SaaS berhenti menagih tanpa ada yang menyadarinya.
- **Akun bebas** (`users.is_exempt`) — seluruh workspace milik akun itu bebas, **termasuk yang dibuat besok**. Penandanya di orangnya, bukan di workspace-nya, supaya tidak ada yang perlu ingat menandai lagi.
- **Pengirim pemberitahuan** — `notify_workspace_id` di `app_settings`, env cuma cadangan.

Satu sumber kebenaran: `Workspace::isExempt()` = `is_internal || owner->is_exempt`. Seluruh titik penegakan memakainya. Menambah pemeriksaan baru yang cuma membaca `is_internal` menghasilkan pengecualian yang berlaku di satu tempat tapi tidak di tempat lain, dan pemiliknya baru tahu dari kegagalan.

**Pengirim pemberitahuan dipilih dari panel, bukan dari env.** Env-nya tetap dibaca sebagai cadangan, tapi urutan pemasangan lewat env mustahil: id workspace baru ada setelah workspace-nya dibuat lewat dashboard, jadi mengisinya berarti deploy, buat workspace, salin id, deploy lagi — dan selama dua deploy itu seluruh pemberitahuan diam tanpa gejala. Halaman Pengecualian memuat langkah pemasangannya apa adanya, **termasuk bahwa menautkan nomor WhatsApp selalu butuh satu kali scan QR**. Tidak ada cara melewatinya: kita perangkat tertaut. Yang benar dijanjikan bukan "tanpa scan" melainkan "sekali saja, dan tidak pernah lagi sesudahnya" — karena kredensialnya dicadangkan dan nomor tersambung sendiri tiap deploy.

**Ada tombol Kirim tes, dan pesan gagalnya menyebut langkah yang kurang.** `WhatsAppNotifier::kirimTes()` membedakan tiga keadaan yang butuh tindakan berbeda — pengirim belum dipilih, sudah dipilih tapi nomornya belum discan, engine sedang mati — karena `ready()` yang cuma menjawab ya/tidak tidak cukup untuk memasang. Tanpa tombol ini, satu-satunya cara memastikan jalurnya benar adalah menunggu peristiwa penagihan sungguhan, dan yang menemukan kesalahannya jadi pelanggan yang tidak dikabari.

Di dalam workspace pengirim, sesi bernomor istimewa didahulukan — nomor itu tidak pernah ikut dilepas, jadi pemberitahuan penagihan kami tidak bisa ikut mati bersama pelanggan yang menunggak.

**VexaHost dan aplikasi Flustra bukan pengguna istimewa.** Tidak ada tenant internal yang dibuat lewat CLI, tidak ada sesi bertipe `platform`. Aplikasi Flustra mendaftar, membuat workspace, dan menempel API key ke `.env` seperti pelanggan mana pun. Jalur istimewa yang dulu ada menghasilkan workspace tanpa anggota — mustahil dibuka lewat dashboard oleh siapa pun — dan sesi yang tidak pernah terpilih otomatis saat `session_id` dikosongkan. Keduanya tidak terlihat di antarmuka mana pun. Kalau ada kebutuhan baru yang "cuma bisa lewat CLI", itu tanda antarmukanya yang kurang, bukan alasan menambah command.

**Satu produk, satu cara mengirim.** Driver `fonnte` (layanan gateway berbayar milik pihak lain) dan slot `cloud_api` yang tidak pernah diimplementasi sudah dihapus sampai ke kelasnya. Keduanya dulu muncul sebagai pilihan di form pembuatan sesi — halaman pertama yang dilihat pelanggan baru menawarkan produk orang lain dan sebuah janji yang belum ada. `wa_sessions.driver` sekarang mencatat, bukan menawarkan pilihan; API menolak nilai selain `wwebjs`. Jangan menambahkan penyalur pihak ketiga ke sini lagi; kalau sebuah kebutuhan menuntut jalur resmi Meta, arahkan keluar — alasannya di [docs/PERBANDINGAN_PROVIDER.md](docs/PERBANDINGAN_PROVIDER.md).

**OTP dikirim dari nomor workspace pemanggil.** Dulu seluruh OTP keluar dari satu sesi global (`OTP_SESSION_ID`) dengan teks yang menyebut merek kami sendiri — padahal endpoint-nya terbuka untuk API key mana pun ber-scope `otp`. Artinya pelanggan mengirim kode dari nomor kami, atas nama kami, memotong kuota kami, dan laporan spam atasnya jatuh ke nomor kami. Sekarang pengirimnya dipilih dengan aturan yang sama persis dengan pesan biasa, dan teksnya memakai nama workspace.

**API key bisa dibuka lagi, dan itu disengaja.** `key_hash` tetap satu-satunya jalan verifikasi permintaan; `key_ciphertext` (cast `encrypted`) adalah salinan terpisah untuk ditampilkan ulang di dashboard kepada owner dan admin, dan dibuang saat kunci dicabut. Pertimbangannya: "hanya tampil sekali" tidak membuat orang lebih hati-hati, ia membuat mereka menyalin kunci ke catatan pribadi dan grup chat — tempat yang jauh lebih mudah bocor daripada tabel ini. Jangan menyatukan kedua kolom itu.

**Istilah produk hanya satu: workspace.** Sampai ke nama tabel dan kolom (`workspaces`, `workspace_id`). `tenant` hanya boleh muncul sebagai istilah arsitektur (multi-tenant) di dokumentasi internal.

**Hapus lunak bertabrakan dengan indeks unik.** `wa_sessions` unik pada `(workspace_id, name)` tanpa memandang `deleted_at`, jadi membuat ulang sesi dengan nama yang sama gagal dengan galat 1062. `SessionService::create()` membuang permanen baris tertrash bernama sama lebih dulu — permanen, bukan dipulihkan, supaya baris baru dapat ULID baru dan tidak mewarisi folder kredensial lama di engine.

**Sejak Baileys, volume `/data` yang menyelamatkan sesi — cadangan Laravel cuma jaring kedua.** (Di era whatsapp-web.js kebalikannya: `RemoteAuth` mengosongkan volume tiap sesi dijalankan; catatan tentang zip RemoteAuth dan `client.destroy()` hanya riwayat.) `creds.json` yang tertaut di volume selalu dipakai lebih dulu karena paling baru; cadangan hanya dipakai kalau berkas itu hilang atau rusak. Yang tetap berlaku dari era lama: `LaravelStore.sessionExists()` **tidak boleh** menjawab `false` saat Laravel sekadar tidak terjangkau, dan engine yang menyala saat Laravel tidak terjangkau **tidak boleh** melaporkan kredensial hilang — keduanya membuat pelanggan disuruh scan ulang nomor yang kredensialnya utuh. Penyimpanan saat SIGTERM tetap eksplisit lewat `manager.persist()` sebelum `stop()`, lalu `tungguPenulisanSelesai()` sebelum kunci data dilepas.

**Nomor yang "tiba-tiba minta scan ulang" punya empat penyebab di kode kita, dan keempatnya sudah ditutup (23 Okt 2026).** Pelanggan dengan nomor baru yang belum pernah mengirim apa pun tetap ter-logout — itu yang memicu audit ini. Kalau menyentuh engine, jangan buka lagi lubang-lubang ini:

- **Alasan putus menentukan tindakan** (`golonganPutus()` di `session-manager.js`). Dulu semua kode selain 401/515 disambung ulang seperti gangguan jaringan: akun yang diblokir (403) dicoba login terus, dan perangkat yang diambil alih (440) direbut balik lalu direbut lagi tanpa akhir — penghitung percobaannya juga nol tiap `open`, jadi saling tendang itu tidak pernah berhenti. Sekarang 401 membuang kredensial di kedua sisi, 403 dan 440 berhenti tanpa membuang apa pun, dan penghitung baru nol setelah koneksi bertahan 60 detik. **Kode 500 bukan "sesi rusak"**: itu bawaan Baileys untuk galat stream apa pun yang tidak dikenalinya; membuang kredensial karenanya memaksa scan ulang untuk gangguan sesaat.
- **Kredensial yang dicabut WhatsApp dibuang** (401). Dulu folder dan cadangannya disimpan, jadi Hubungkan, penjadwal, dan setiap deploy login dengan kunci mati, ditolak lagi, dan QR tidak pernah muncul.
- **`creds.json` rusak tidak boleh berubah diam-diam jadi identitas baru.** `useMultiFileAuthState` bawaan menimpa berkas langsung (terpotong kalau proses mati di tengah penulisan) dan menjawab `null` untuk galat apa pun, lalu Baileys membuat identitas baru — QR muncul, perangkat lama tetap tercatat di ponsel. `engine/src/auth-state.js` menggantinya: tulis atomik, kunci per berkas yang juga dipakai pencadangan, dan `creds.json` rusak dipindah ke `{folder}.rusak` lalu dipulihkan dari cadangan terbaru yang **layak**.
- **Dua engine tidak boleh memegang satu folder data.** Coolify menyalakan container baru sebelum menghentikan yang lama, di volume yang sama. `engine/src/kunci-data.js` membuat engine baru menunggu `.engine.lock` dilepas sebelum membuka port.

Aturan pendampingnya di Laravel: alasan putus diterjemahkan **hanya** lewat `App\Support\AlasanPutus` (`last_error` muka pengguna, bukan kode mentah) dan disimpan di `wa_sessions.disconnect_reason` — penjadwal dan bootstrap melewati `banned`/`replaced` sampai manusia menekan Hubungkan (satu pengecualian: `replaced` pertama dalam 24 jam dicoba ulang sekali oleh penjadwal setelah dua menit, tanpa kabar ke pemilik — pengambil alih tersering adalah container lama saat deploy, terutama deploy pertama versi berkunci data saat engine lama belum mengenal kuncinya), **tanpa** dititipkan pada `connect_failures` (yang membuat penjadwal mengumumkan "berhenti setelah 8 percobaan" untuk nomor yang tidak pernah dicoba). Jalur otomatis hanya boleh **memulihkan** (`restore_only`): penjadwal tidak menyalakan sesi yang `connected_at`-nya kosong, sama dengan bootstrap, karena tanpa kredensial yang muncul cuma QR tanpa penonton. Cadangan disimpan **tiga** terakhir dan dinilai isinya (`App\Support\CadanganSesi`, aturannya harus sama persis dengan `nilaiKredensial()` di engine). Riwayat koneksi per sesi ada di `session_events` dan tampil di **Admin → Sesi WhatsApp → nama sesi** — tempat pertama yang dibuka saat ada keluhan "nomor saya logout". `AlasanPutusSesiTest`, `SessionBackupTest`, dan `engine/tests/ketahanan-sesi.test.js` menjaga semuanya.

**Rem pengiriman ada di `App\Services\RemPengiriman`, dipanggil dari `MessageDispatcher::queue()` (23 Okt 2026).** Semua nomor pelanggan keluar dari server yang sama, jadi satu nomor yang dipakai ceroboh memperburuk nasib nomor lain. Tiga rem plus satu peringatan, dan setiap aturannya dirancang supaya **paket yang sudah dibayar tetap terpakai penuh tanpa keluhan** — itu permintaan eksplisit Ryan:

- **Masa pemanasan 30 hari per nomor** (50 → 150 → 300 → 1.000 pesan/hari, angkanya di `config('gateway.rem.pemanasan')`). Kelebihannya **dijadwalkan** ke 08.00 hari berikutnya (`messages.scheduled_at` + job tertunda), tidak pernah ditolak. OTP (`is_otp`) dan balasan ke orang yang menghubungi dalam 24 jam **tidak pernah ditunda**. Umur nomor diwarisi lintas sesi (`WaSession::catatNomorTertaut()`), nomor istimewa dibebaskan, dan pemilik bisa **Lewati pemanasan** dari halaman Sesi — pesan terjadwal langsung dilepas. **Seluruh nomor yang sudah tertaut saat migrasi `create_sending_brakes` berjalan dibebaskan total** (keputusan Ryan, 23 Okt 2026), termasuk dari sesi yang sudah dihapus supaya tautan ulangnya ikut bebas; yang dipanaskan hanya nomor yang ditautkan sesudahnya. Kuota paling besar (Elite) cuma butuh ±830 pesan per nomor per hari, jadi pemanasan tidak pernah membuat kuota bulanan tidak terpakai.
- **Rem balas-balasan bot**: lebih dari 40 pesan ke satu chat dalam 10 menit ditolak. Ambangnya sengaja tinggi — bot yang saling membalas menghasilkan 75–200, percakapan manusia jauh di bawah 40.
- **STOP/BERHENTI** dicatat di `opt_outs` per workspace; pesan ke nomor itu ditolak kecuali OTP atau setelah ia menghubungi lagi. MULAI/START mencabutnya; pemilik bisa menghapusnya di menu Berhenti Berlangganan.
- **Peringatan dini** (`PantauNomorJob`, tiap jam): ≥50% dari minimal 100 pesan belum sampai setelah 2 jam → kabar ke pemilik. **Hanya kabar, tidak pernah menghentikan** — centang satu juga muncul untuk penerima yang ponselnya mati, dan rem yang salah baca lebih merugikan daripada kabar yang tidak perlu.

Yang tidak boleh berubah tanpa sadar: penolakan rem dilempar **sebelum** `Message::create` dan `incrementUsage` (pesan yang ditolak tidak memotong kuota), dan kalimat penolakannya tidak boleh memuat kata yang membuat `EngineError::pesan()` menggantinya (mis. "batas" → "kapasitas penuh") — API meneruskannya lewat fungsi itu. `RemPengirimanTest` menjaga keduanya.

**Versi WhatsApp Web tidak dipatok lagi.** Versi bawaan Baileys yang dulu dipatok ternyata tertinggal jauh dari yang dipakai WhatsApp Web sendiri — dan saat WhatsApp menaikkan versi minimum, seluruh sesi seluruh pelanggan gagal bersamaan dengan kode 405. `engine/src/versi-wa.js` mengambil versi terbaru saat boot dan menyegarkannya; `WA_WEB_VERSION` yang diisi mematoknya (jalan keluar darurat, bukan pengaturan tetap).

**Pesan masuk membawa `sent_at` dan `is_backlog`, dan yang ganda disaring.** Pesan yang menumpuk selama offline datang sekaligus tiap sesi tersambung lagi — setiap deploy. Integrasi yang membalas otomatis (AI agent di CRM) yang tidak bisa membedakannya menjawab seluruh tumpukan dalam satu ledakan; itu persis yang pernah membuat nomor VexaHost sendiri dibatasi. Yang tidak boleh dibuang dari jalur ini: penyaring `wa_message_id` ganda di `onIncomingMessage`, dan pemetaan chat `@lid` ke nomor telepon di engine (tanpa itu `from` kosong dan balasan ke `chat_id`-nya ditolak `MessageDispatcher`).

**Dashboard tidak boleh mengandalkan callback saja untuk status sesi.** Satu event `ready` yang hilang membuat modal QR menampilkan "Menyiapkan sesi" tanpa akhir. Endpoint `sessions.status` menanyakan engine langsung selama sesi belum `connected`/`failed`, jadi keadaan sebenarnya muncul dalam hitungan detik tanpa menunggu `SyncSessionStatusJob` yang berjalan tiap menit — dan tanpa bergantung pada Scheduled Task Coolify yang bisa saja belum dipasang.

**`QR_TTL_SECONDS` jangan di bawah 60.** whatsapp-web.js menerbitkan QR baru dengan jeda tidak tetap sampai ~60 detik; masa berlaku lebih pendek membuat modal QR berkedip kosong.

**Antrean gagal tanpa gejala, jadi ia diawasi terpisah.** `queue:work` yang mati tidak menghasilkan galat, pesan merah, atau baris log baru — pesan cuma diam berstatus `queued` selamanya. Pengirimnya melihat "Mengantre" dan menyimpulkan gateway-nya lambat; penerimanya tidak menerima apa-apa dan tidak tahu ada yang mengirim. `App\Support\KesehatanAntrean` mendeteksinya dan Ringkasan admin memasang spanduk merah.

Yang sulit bukan mendeteksi worker mati, melainkan **tidak memberi peringatan palsu saat antreannya memang panjang**: broadcast seribu nomor sah-sah saja menyisakan pekerjaan berjam-jam karena engine menahan tiap pesan 3–8 detik. Karena itu yang diukur **gerakannya, bukan panjangnya** — macet berarti ada pekerjaan menunggu lebih dari 10 menit DAN tidak satu pun pesan berpindah ke `sent` selama itu. Patokannya `messages.sent_at`, bukan `updated_at`, karena yang terakhir ikut berubah oleh ack WhatsApp untuk pesan lama sementara worker-nya sendiri sudah mati. `KesehatanAntreanTest` menguji kedua arahnya sekaligus.

Di produksi `supervisi_worker` di `start.sh` menyalakannya lagi kalau mati, jadi spanduk ini menangkap yang lolos dari situ — worker yang menggantung, bukan yang jatuh. Di lokal `npm run all` memakai `--kill-others`: mematikan satu proses mematikan semuanya, termasuk worker, dan itu sudah sekali membuat pesan tampak "lama banget" padahal tidak ada yang rusak.

**Engine dev TIDAK memakai `--watch`, dan itu disengaja (6 Sep 2026).** (Sempat kembali ke `--watch` saat migrasi Baileys; dikembalikan lagi 23 Okt 2026 — alasannya tetap berlaku untuk websocket.) Restart otomatis bagus untuk API tanpa keadaan; engine ini memegang sesi WhatsApp yang hidup, dan **restart di tengah scan QR membuang hasil scan-nya tanpa satu pun pesan** — di layar pengguna modalnya diam di "100% riwayat chat tersalin" sampai menit-menit, persis seperti gangguan sungguhan. Ini sudah terjadi: log engine memuat delapan baris `Restarting 'src/server.js'` pada sesi yang sedang discan, dan status sesinya kembali ke `qr` sendiri.

`npm --prefix engine run dev` sekarang menjalankan engine polos. Yang butuh muat-ulang otomatis saat menyunting `engine/src` pakai `dev:watch` — dan jangan menjalankannya sambil menautkan nomor. Produksi tidak pernah terpengaruh: `start.sh` memanggil `node engine/src/server.js` tanpa flag apa pun.

Cara memastikan gejalanya ini dan bukan yang lain: `grep -c Restarting` di log engine. Kalau lebih dari nol selama scan berlangsung, penyebabnya di sini — bukan di whatsapp-web.js dan bukan di riwayat chat yang besar.

**Laravel Pail tidak disertakan di `npm run all`** — butuh `pcntl` yang tidak ada di PHP Windows, dan `--kill-others` membuat matinya Pail menjatuhkan proses lain.

**Tidak ada template env di repo.** Berkas `.env.*.example` dihapus atas permintaan Ryan (13 Agu 2026) supaya isian env tinggal disalin dari berkas `.env.<tahap>` di mesinnya ke Coolify tanpa komentar yang mengganggu; `EnvTemplateTest` ikut dihapus. Konsekuensinya: menambah variabel env **tidak** akan ketahuan terlewat oleh tes apa pun. Daftarkan variabel baru di `docs/ENVIRONMENT.md` dan isikan ke resource Coolify pada perubahan yang sama — sejak 17 Agu 2026 cuma satu resource per tahap, jadi satu tempat saja.

## Alur git

`dev` → `staging` → `main`. Coolify men-deploy otomatis setiap push. Jangan push langsung ke `main`.

**Jangan pernah commit** `.env`, `engine/.env`, isi `.wwebjs_auth/`, atau zip sesi — kredensial WhatsApp yang bocor sama dengan memberi akses penuh ke nomor tersebut.

## Keadaan sekarang

Sudah ter-deploy di Coolify, **belum diuji end-to-end di produksi**. Uji regresi wajib ada di [docs/SETUP_VPS_COOLIFY.md](docs/SETUP_VPS_COOLIFY.md) Bagian 10 — yang terpenting: redeploy lalu pastikan sesi tersambung sendiri tanpa scan QR.

**Penyatuan tiga resource jadi satu — sudah berjalan di server.** Sampai tanggal itu tiap tahap punya tiga resource Coolify (`vexahost-wa`, `-engine`, `-worker`) yang masing-masing membangun ulang aplikasinya sendiri tiap deploy — tiga kali `composer install` + `npm ci` di VPS 2 vCPU yang menampung tujuh aplikasi Flustra lain. Sekarang satu container menjalankan keempat prosesnya lewat `start.sh`, mengikuti pola `flustra-erp` dan `flustra-clientportal`. **Sudah diterapkan di server** (dikonfirmasi Ryan 6 Sep 2026): tinggal satu resource Coolify per tahap, resource `-engine` dan `-worker` sudah tidak ada, dan gateway berjalan normal begitu. Yang perlu diingat saat membaca log atau menjelaskan arsitekturnya: "tidak ada engine dan worker" berarti tidak ada **resource** terpisah — prosesnya tetap empat dan tetap jalan, dijaga loop pengawas di `start.sh`. Kalau engine benar-benar mati, tidak ada satu pun pesan WhatsApp yang bisa keluar; kalau `queue:work` mati, pesan menumpuk `queued` selamanya. Langkah pindahnya tetap tercatat di [docs/DEPLOYMENT.md §5](docs/DEPLOYMENT.md) sebagai riwayat.

Alasan lama untuk memisahkan engine masih tertulis di [docs/ARSITEKTUR.md §2](docs/ARSITEKTUR.md) beserta apa yang terjadi pada masing-masing — baca itu dulu sebelum mengusulkan memisahkannya lagi. Yang tidak terjawab dan harus dijaga dengan angka, bukan arsitektur: engine berebut RAM dengan PHP. Di era Chromium itu berarti `WA_MAX_SESSIONS` diturunkan dari 10 ke 3; sejak Baileys dinaikkan ke 20 (lihat "Kapasitas adalah pembatas jualan" di bawah).

Integrasi ke `flustra-erp`, `flustra-web`, `flustra-pricing`, `flustra-helpdesk` sudah ditulis (`app/Services/WhatsAppGateway.php` disalin identik ke keempatnya) tapi belum dijalankan dengan gateway produksi. Lihat [docs/INTEGRASI_APP.md](docs/INTEGRASI_APP.md).

**Penagihan menyala (5 Sep 2026).** Paket, langganan, tagihan, QRIS dinamis, panel `/admin`, dan siklus perpanjangan harian (`BillingCycleJob`, 08:00) sudah ada dan berjalan di lokal. Seluruh workspace yang sudah ada dipindahkan ke Essentials berstatus `trialing` sampai akhir bulan berjalan lewat migrasi, **tanpa** menurunkan batas mereka — batas paket baru berlaku saat langganan pertama dibayar. Belum dijalankan di server, dan belum ada satu rupiah pun yang benar-benar masuk lewatnya.

Yang belum: gateway pembayaran otomatis (iPaymu, menunggu verifikasi akun), realtime dashboard, beberapa instance engine. Rinciannya di [docs/ARSITEKTUR.md](docs/ARSITEKTUR.md#11-yang-sengaja-belum-dibuat).

**Kapasitas adalah pembatas jualan, bukan kode.** `WA_MAX_SESSIONS=20` di `.env.production` — dinaikkan Ryan dari 3 setelah engine pindah ke Baileys, dan dikonfirmasi berjalan aman di produksi (23 Okt 2026). Bawaan di `engine/src/config.js` tetap 3, cuma cadangan kalau env-nya kosong. Artinya platform ini melayani **20 nomor aktif untuk seluruh pelanggan sekaligus** — itu batas jualannya, dan free tier ikut memakan dari jatah yang sama. Sebutan "**tiga** slot" di paragraf-paragraf lama berkas ini adalah angka era Chromium (±300–500 MB per sesi); argumennya tetap berlaku — slotnya terbatas dan dibagi semua pelanggan — hanya angkanya yang berubah. Biaya RAM per sesi Baileys belum pernah diukur resmi: RAM-nya tetap dibagi dengan PHP, worker, penjadwal, MySQL, dan aplikasi Flustra lain, dan yang dibunuh OOM killer belum tentu engine. Pantau `rss_mb` di `/health` engine dan `free -h` di VPS selama sesi yang tersambung bertambah; kalau memori menipis, `WA_MAX_SESSIONS` yang diturunkan lebih dulu. Panel `/admin` menampilkan angkanya paling atas supaya batas itu tidak baru ketahuan dari keluhan.

**Env engine yang benar ada di `.env.production`, bukan di `engine/.env.production`.** Berkas kedua itu mati sejak resource-nya disatukan, dan pada 19 Sep 2026 ketiga `engine/.env.<tahap>` dihapus sekalian — nilainya (termasuk `WA_MAX_SESSIONS=10` dan domain merek lama) **tidak pernah dibaca siapa pun**, tapi terus menyesatkan yang membacanya. `engine/.env` polos tetap ada: itu milik engine lokal (`npm --prefix engine run dev`). Ini sudah sekali membuat catatan di berkas ini sendiri salah selama beberapa hari: dilaporkan "kapasitas masih disetel 10" padahal yang hidup sudah 3. Kalau perlu memastikan nilai env produksi, baca `.env.production` di root — dan yang benar-benar berlaku adalah yang tersalin ke environment resource Coolify.
