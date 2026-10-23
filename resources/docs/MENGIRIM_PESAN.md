# Mengirim Pesan

Teks, media, pengiriman massal, dan cara melacak statusnya.

---

## Format nomor tujuan

Semua bentuk ini diterima dan diseragamkan otomatis:

```
081234567890
+62 812-3456-7890
62812-3456-7890
6281234567890
```

Yang ditolak: nomor terlalu pendek, mengandung huruf, atau bukan nomor Indonesia yang valid.

Untuk grup, gunakan ID grup yang berakhiran `@g.us`. Cara mendapatkannya: kirim satu pesan ke grup dari HP Anda, lalu lihat kolom **Nomor** pada pesan masuk di Riwayat Pesan.

## Pesan teks

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/text \
  -H "X-Api-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "081234567890",
    "message": "Pesanan #1234 sudah dikirim. Resi: JX0987654321"
  }'
```

Maksimal 4096 karakter. Baris baru pakai `\n`.

Format WhatsApp berlaku seperti biasa:

| Tulis | Hasil |
|---|---|
| `*tebal*` | **tebal** |
| `_miring_` | *miring* |
| `~coret~` | ~~coret~~ |
| ` ```kode``` ` | `kode` |

## Pesan dengan lampiran

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/media \
  -H "X-Api-Key: $KEY" \
  -F "to=081234567890" \
  -F "caption=Invoice Agustus 2026" \
  -F "type=document" \
  -F "file=@invoice.pdf"
```

| `type` | Untuk |
|---|---|
| `image` | JPG, PNG |
| `document` | PDF, Word, Excel, dan lainnya |
| `video` | MP4 |
| `audio` | MP3, OGG |

Batas ukuran **16 MB** — itu batas dari WhatsApp, bukan dari kami.

## Mengirim ke banyak nomor

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/bulk \
  -H "X-Api-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "to": ["081234567890", "081298765432", "085612345678"],
    "message": "Toko tutup tanggal 17 Agustus. Pesanan diproses kembali tanggal 18."
  }'
```

Balasannya:

```json
{
  "success": true,
  "data": {
    "batch_id": "01K2CDEFGH7JKMNPQRSTVWXYZ",
    "queued": 3,
    "scheduled": 0,
    "rejected": [],
    "message_ids": ["01K2C…", "01K2C…", "01K2C…"]
  }
}
```

Nomor yang formatnya rusak, atau yang penerimanya sudah membalas STOP, masuk ke `rejected` **tanpa membatalkan sisanya** dan tanpa memotong kuota. Nomor ganda dibuang otomatis.

`scheduled` adalah bagian dari `queued` yang dijadwalkan ke hari berikutnya karena nomor pengirim masih dalam [masa pemanasan](PRAKTIK_BAIK.md). Pesan-pesan itu pasti terkirim, hanya tidak hari ini.

Maksimal 1000 nomor per permintaan.

### Kenapa pengiriman massal lambat

Setiap pesan diberi jeda beberapa detik sebelum dikirim. Broadcast 500 nomor wajar memakan 30–60 menit.

Ini disengaja dan **tidak bisa dimatikan**. Mengirim beruntun tanpa jeda adalah pola paling khas robot, dan cara paling cepat membuat nomor Anda diblokir WhatsApp. Jeda inilah yang menjaga nomor Anda tetap hidup.

Pantau kemajuannya lewat `batch_id`:

```bash
curl -H "X-Api-Key: $KEY" \
  "https://wa.vexahostcloud.my.id/api/v1/messages?batch_id=01K2CDEFGH7JKMNPQRSTVWXYZ"
```

## Melacak status pengiriman

Setiap pesan punya ID. Simpan dan gunakan untuk memeriksa statusnya:

```bash
curl -H "X-Api-Key: $KEY" \
  https://wa.vexahostcloud.my.id/api/v1/messages/01K2CDEFGH7JKMNPQRSTVWXYZ
```

| Status | Arti |
|---|---|
| `queued` | Menunggu giliran di antrean. Kalau `scheduled_at` terisi, pesannya dijadwalkan pada waktu itu |
| `sending` | Sedang dikirim |
| `sent` | Sampai ke server WhatsApp (satu centang) |
| `delivered` | Sampai ke HP tujuan (dua centang) |
| `read` | Dibaca (dua centang biru) |
| `failed` | Gagal — alasannya ada di kolom `error` |

`delivered` dan `read` hanya muncul kalau penerima mengaktifkan laporan dibaca di WhatsApp mereka.

**Cara yang lebih hemat:** daripada memeriksa berulang-ulang, daftarkan [webhook](WEBHOOK.md) dan biarkan kami yang mengabari saat status berubah.

## Kalau pengiriman gagal

Penyebab tersering:

| Penyebab | Solusi |
|---|---|
| Nomor tidak terdaftar di WhatsApp | Periksa nomornya |
| Sesi terputus dan tidak pulih | Cek halaman Sesi WhatsApp |
| Format nomor tidak valid | Periksa formatnya |
| Kuota bulanan habis | Lihat [Batas & Kuota](BATAS_DAN_KUOTA.md) |
| Penerima sudah membalas STOP | Hormati permintaannya. Kalau ia sendiri meminta dikirimi lagi, hapus nomornya di menu **Berhenti Berlangganan** |
| Lebih dari 40 pesan ke chat yang sama dalam 10 menit | Hampir selalu dua sistem yang saling membalas otomatis — periksa balasan otomatis di integrasi Anda. Pengiriman ke chat itu dibuka lagi setelah 10 menit |

Dua penolakan terakhir terjadi **sebelum** pesannya dicatat, jadi tidak memotong kuota. Penjelasannya ada di [Praktik Baik](PRAKTIK_BAIK.md).

Pesan yang gagal karena sesi terputus **dicoba ulang otomatis** sampai empat kali dengan jeda menaik. Baru setelah itu ditandai gagal.

Pesan yang gagal karena nomor tidak terdaftar **tidak** dicoba ulang — mengulanginya tidak akan menolong.

## Hal yang sering keliru

**Mengira balasan `202` berarti pesan sudah terkirim.** `202` berarti "kami terima dan antrekan". Status sebenarnya menyusul.

**Membuat perulangan sendiri untuk kirim massal.** Gunakan `/messages/bulk`. Kalau Anda membuat perulangan sendiri tanpa jeda, nomor Anda berisiko diblokir.

**Menahan permintaan pengguna sampai WhatsApp terkirim.** Kirim pesannya lalu lanjutkan; jangan buat pelanggan menunggu di halaman checkout hanya karena notifikasi WhatsApp belum selesai.
