# Praktik Baik

Cara memakai gateway tanpa membuat nomor Anda diblokir WhatsApp.

---

## Yang perlu dipahami lebih dulu

Nomor Anda tertaut ke sistem kami sebagai **perangkat tertaut**, sama seperti WhatsApp Web di laptop. WhatsApp tidak secara resmi mengizinkan pengiriman otomatis lewat jalur ini.

Artinya: nomor Anda bisa diblokir, dan **tidak ada jalur banding**. Bukan kami yang memblokir, dan bukan kami yang bisa memulihkannya.

Kabar baiknya, pemblokiran hampir selalu punya sebab yang bisa dihindari. Halaman ini merangkumnya.

## Aturan paling penting

**Kirim hanya kepada orang yang mengharapkan pesan Anda.**

Hampir semua pemblokiran berawal dari satu hal: penerima menekan *Blokir* atau *Laporkan spam*. Ketika cukup banyak orang melakukannya dalam waktu singkat, WhatsApp bertindak.

Pelanggan yang baru saja memesan di toko Anda tidak akan melaporkan konfirmasi pesanan sebagai spam. Orang yang nomornya Anda beli dari daftar kontak pasti melaporkannya.

## Yang aman dikirim

| Jenis | Kenapa aman |
|---|---|
| Konfirmasi pesanan | Ditunggu penerima |
| Status pengiriman & nomor resi | Ditunggu |
| Pengingat jatuh tempo | Ada hubungan bisnis yang jelas |
| Konfirmasi pembayaran | Ditunggu |
| Balasan atas pertanyaan pelanggan | Mereka yang memulai |
| Pengingat janji temu | Disetujui sebelumnya |

## Yang berisiko tinggi

| Jenis | Kenapa berisiko |
|---|---|
| Promosi ke nomor yang tidak pernah berhubungan | Sumber laporan spam nomor satu |
| Nomor dari daftar yang dibeli | Penerima tidak mengenal Anda sama sekali |
| Pesan berantai atau ajakan berbagi | Ditandai sistem WhatsApp |
| Pesan identik ke ratusan nomor sekaligus | Pola yang mudah dikenali sebagai robot |
| Nomor baru langsung dipakai broadcast besar | Paling cepat kena |

## Nomor baru: masa pemanasan otomatis

Nomor yang baru tertaut belum punya rekam jejak. Mengirim 500 pesan di hari pertama adalah cara tercepat kehilangan nomor tersebut — karena itu, selama 30 hari pertama sebuah nomor di gateway ini, jumlah pesan per hari dibatasi **otomatis**:

| Hari ke- | Batas per hari |
|---|---|
| 1–3 | 50 pesan |
| 4–7 | 150 pesan |
| 8–14 | 300 pesan |
| 15–30 | 1.000 pesan |
| Sesudahnya | Tanpa batas harian |

**Tidak ada pesan yang hilang.** Pesan yang melewati batas hari itu dijadwalkan ke pukul 08.00 hari berikutnya dan dikirim otomatis. Di dashboard ia tertulis "dikirim [tanggal, jam]", dan di API kolom `scheduled_at` terisi.

**Yang tidak pernah ditunda**, berapa pun yang sudah terkirim hari itu:

- **Kode OTP** — penerimanya sedang menunggu.
- **Balasan ke orang yang mengirim pesan ke Anda dalam 24 jam terakhir** — mereka yang memulai percakapan.

**Nomor Anda sudah lama aktif di ponsel?** Halaman **Sesi WhatsApp** menampilkan hari pemanasan dan jatah hari ini di samping nomornya, beserta tombol **Lewati pemanasan**. Menekannya menghapus batas harian untuk nomor itu, dan pesan yang sudah terjadwal langsung dikirim. Lewati hanya kalau nomornya memang sudah lama dipakai berkirim pesan secara normal — nomor baru yang langsung mengirim ratusan pesan ke orang yang belum mengenalnya adalah penyebab tersering nomor diblokir.

Menautkan ulang nomor yang sama — menghapus lalu membuat sesi baru, atau memindahkannya ke workspace lain — **tidak** mengulang masa pemanasan dari awal.

## Jeda kirim itu perlindungan, bukan hambatan

Kami menyisipkan jeda beberapa detik antar pesan. Broadcast 500 nomor memakan 30–60 menit.

Ini sengaja dan tidak bisa dimatikan. Mengirim beruntun tanpa jeda adalah pola paling khas robot. Kalau kami mengizinkannya, nomor pelanggan kami akan berjatuhan — termasuk nomor Anda.

Kalau perlu mengirim ke ribuan nomor, sebar ke beberapa hari. Bukan karena batas teknis, tapi karena volume mendadak adalah yang paling dicurigai.

## Menyusun pesan

**Sebutkan siapa Anda di awal.** Penerima belum tentu menyimpan nomor Anda.

```
*Toko Makmur*

Halo Budi, pesanan #1234 sudah kami kirim…
```

**Sebutkan kenapa Anda menghubungi.** "Terkait pesanan yang Anda buat kemarin" menghilangkan kecurigaan sebelum muncul.

**Variasikan isinya.** Ratusan pesan yang identik kata per kata lebih mencolok daripada pesan yang berisi nama dan nomor pesanan masing-masing penerima. Gunakan [template dengan placeholder](TEMPLATE_PESAN.md).

**Sediakan cara berhenti** untuk pesan promosi:

```
Balas STOP untuk berhenti menerima info promo.
```

Kami menghormatinya **otomatis**. Penerima yang membalas STOP, BERHENTI, UNSUBSCRIBE, UNSUB, atau STOP PROMO — sebagai seluruh isi pesannya — masuk ke daftar **Berhenti Berlangganan** workspace Anda, dan pesan berikutnya ke nomor itu ditolak tanpa memotong kuota. Orang yang bisa berhenti dengan mudah tidak perlu menekan tombol laporkan.

Yang tetap terkirim ke mereka: kode OTP, dan balasan setelah mereka menghubungi Anda lagi. Penerima yang membalas MULAI, START, atau LANJUT keluar dari daftar itu sendiri. Kalau penerima meminta dikirimi lagi lewat jalur lain, hapus nomornya dari menu **Berhenti Berlangganan**.

## Balasan otomatis yang saling membalas

Integrasi yang membalas otomatis — chatbot, AI agent, auto-reply — bisa bertemu sistem lain yang juga membalas otomatis, lalu keduanya saling membalas tanpa henti. Ratusan pesan ke satu chat dalam beberapa menit adalah pola yang sangat cepat membuat nomor dibatasi.

Karena itu, **lebih dari 40 pesan ke chat yang sama dalam 10 menit** ditahan: pesan berikutnya ke chat itu ditolak sampai 10 menit berlalu, tanpa memotong kuota, dan Anda mendapat kabar di lonceng dashboard. Percakapan dengan manusia — termasuk AI agent yang membalas beberapa gelembung sekaligus — tidak pernah mendekati angka itu.

Kalau integrasi Anda membalas otomatis, lewati juga pesan masuk yang `is_backlog`-nya `true` — lihat [Webhook](WEBHOOK.md).

**Jangan kirim kata sandi, kode OTP milik layanan lain, atau data kartu.** Riwayat WhatsApp tersinkron ke semua perangkat tertaut, tercadangkan ke cloud, dan sering terlihat di notifikasi layar kunci.

## Jaga nomornya tetap sehat

**Pakai nomor khusus untuk keperluan bisnis**, terpisah dari nomor pribadi.

**Buka WhatsApp di HP sesekali.** Perangkat tertaut akan kedaluwarsa kalau HP tidak pernah online dalam waktu lama.

**Balas pesan yang masuk.** Nomor yang hanya mengirim tanpa pernah menerima terlihat seperti mesin. Percakapan dua arah membangun rekam jejak yang baik. Manfaatkan [webhook](WEBHOOK.md) agar balasan pelanggan sampai ke tim Anda.

**Pantau angka kegagalan.** Lonjakan mendadak di kartu "Gagal bulan ini" biasanya tanda pertama ada yang tidak beres — jauh sebelum nomor benar-benar diblokir.

**Perhatikan peringatan "banyak pesan tidak sampai".** Nomor yang sedang dibatasi WhatsApp biasanya tetap tersambung, tapi pesannya berhenti di satu centang. Kami memeriksanya tiap jam: kalau setengah atau lebih dari pesan Anda (dari minimal 100 pesan) belum sampai ke ponsel penerima setelah 2 jam, Anda dikabari lewat lonceng dashboard dan WhatsApp. Kabar ini tidak menghentikan pengiriman apa pun — saat itulah waktu terbaik mengurangi kiriman ke orang yang belum pernah menghubungi Anda, sebelum nomornya diblokir.

## Kalau nomor terlanjur diblokir

Tidak ada cara memulihkannya dari sisi kami.

Yang bisa dilakukan:

1. **Tautkan nomor pengganti ke sesi yang sama.** Klik Putus tautan, lalu Hubungkan dan scan nomor baru. Riwayat pesan dan API key tetap utuh — aplikasi Anda tidak perlu diubah sama sekali.
2. **Cari tahu sebabnya sebelum mengulang pola yang sama.** Nomor pengganti akan bernasib sama kalau perlakuannya tidak berubah.

## Kalau volume Anda sudah besar

Untuk pengiriman puluhan ribu pesan per bulan, atau untuk komunikasi yang tidak boleh gagal sama sekali, jalur non-resmi ini bukan pilihan yang tepat lagi.

WhatsApp Business API resmi tidak punya risiko pemblokiran ini — dengan konsekuensi biaya per pesan, kewajiban verifikasi bisnis, dan template yang harus disetujui lebih dulu.

Hubungi kami untuk membahas pilihan itu.
