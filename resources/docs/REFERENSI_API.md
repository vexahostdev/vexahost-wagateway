# Referensi API

REST API v1. Setiap endpoint dengan parameter, contoh, dan kode galatnya.

---

## Alamat dasar

```
https://wa.vexahostcloud.my.id
```

Semua endpoint diawali `/api/v1`.

## Autentikasi

```http
X-Api-Key: vwa_a1b2c3d4.7HkQmZpXvR2wLnT9sYbF4jGcE6dAuN8i
```

Lihat [API Key & Keamanan](API_KEY.md).

## Bentuk balasan

Berhasil:

```json
{ "success": true, "data": { } }
```

Gagal:

```json
{ "success": false, "error": { "message": "Penjelasan galatnya." } }
```

Bentuknya sama untuk semua endpoint, termasuk galat validasi dan autentikasi. Jadi penanganan galat di aplikasi Anda cukup satu tempat.

## Kode status

| Kode | Arti | Boleh diulang? |
|---|---|---|
| `200` | Berhasil | — |
| `202` | Diterima dan diantrekan | — |
| `204` | Berhasil, tanpa isi | — |
| `401` | API key tidak ada, salah, atau dicabut | Tidak |
| `403` | Workspace nonaktif, atau kunci tidak berhak | Tidak |
| `404` | Tidak ditemukan | Tidak |
| `422` | Data tidak valid, sesi belum siap, atau kuota habis | Tidak, perbaiki dulu |
| `429` | Melewati batas permintaan | Ya, setelah `Retry-After` |
| `500` `502` | Gangguan di pihak kami | Ya, dengan jeda |

> **`202` bukan berarti sudah terkirim.** Artinya pesan sudah kami terima dan masuk antrean. Status sebenarnya ditelusuri lewat ID pesan atau [webhook](WEBHOOK.md).

---

# Pesan

## Kirim teks

`POST /api/v1/messages/text`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | string | ya | Nomor tujuan atau ID grup |
| `message` | string, maks 4096 | ya | Isi pesan |
| `session_id` | string | tidak | Kosongkan untuk memakai sesi aktif pertama |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/text \
  -H "X-Api-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{"to":"081234567890","message":"Halo!"}'
```

```json
{
  "success": true,
  "data": {
    "id": "01K2CDEFGH7JKMNPQRSTVWXYZ",
    "session_id": "01K2B8XQZ4M7NPRT5VW9YC3FGH",
    "direction": "outbound",
    "to": "6281234567890",
    "type": "text",
    "body": "Halo!",
    "status": "queued",
    "error": null,
    "created_at": "2026-08-13T14:03:11+07:00",
    "scheduled_at": null,
    "sent_at": null,
    "delivered_at": null,
    "read_at": null
  }
}
```

`scheduled_at` terisi kalau nomor pengirim masih dalam masa pemanasan dan jatah hari itu sudah terpakai: pesannya diterima, kuotanya tercatat, dan ia dikirim otomatis pada waktu tersebut. Lihat [Praktik Baik](PRAKTIK_BAIK.md).

Galat `422` yang mungkin muncul:

- `Nomor tujuan tidak valid: 123`
- `Kuota pesan bulan ini sudah habis (1000 pesan).`
- `Tidak ada sesi WhatsApp yang bisa dipakai. Hubungkan satu sesi terlebih dahulu.`
- `Penerima 62… sudah meminta berhenti menerima pesan dari workspace ini (membalas "STOP" …)` — tidak memotong kuota
- `Pengiriman ke 62… ditahan sementara: lebih dari 40 pesan dalam 10 menit ke chat yang sama …` — tidak memotong kuota

## Kirim lampiran

`POST /api/v1/messages/media` — `multipart/form-data`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | string | ya | |
| `file` | file, maks 16 MB | ya | Batas dari WhatsApp |
| `caption` | string, maks 1024 | tidak | |
| `type` | `image` `document` `video` `audio` | tidak | Bawaan `document` |
| `session_id` | string | tidak | |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/media \
  -H "X-Api-Key: $KEY" \
  -F "to=081234567890" \
  -F "caption=Invoice Agustus" \
  -F "type=document" \
  -F "file=@invoice.pdf"
```

## Kirim ke banyak nomor

`POST /api/v1/messages/bulk`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | array, 1–1000 | ya | |
| `message` | string, maks 4096 | ya | |
| `session_id` | string | tidak | |

```json
{
  "success": true,
  "data": {
    "batch_id": "01K2CDEFGH7JKMNPQRSTVWXYZ",
    "queued": 2,
    "scheduled": 0,
    "rejected": [
      { "to": "bukan-nomor", "reason": "Nomor tujuan tidak valid: bukan-nomor" }
    ],
    "message_ids": ["01K2C…", "01K2C…"]
  }
}
```

Nomor rusak masuk `rejected` tanpa membatalkan sisanya. Nomor ganda dibuang otomatis. `scheduled` adalah bagian dari `queued` yang dijadwalkan ke hari berikutnya karena masa pemanasan nomor pengirim.

## Kirim dari template

`POST /api/v1/messages/template`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | string | ya | |
| `template` | string | ya | Slug template |
| `variables` | object | tidak | Nilai untuk placeholder |
| `session_id` | string | tidak | |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/template \
  -H "X-Api-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "081234567890",
    "template": "pengingat-invoice",
    "variables": { "nama": "Budi", "nomor": "INV-001" }
  }'
```

Galat `404` bila template tidak ada atau tidak aktif.

## Riwayat pesan

`GET /api/v1/messages`

| Query | Keterangan |
|---|---|
| `session_id` | Saring per sesi |
| `direction` | `outbound` atau `inbound` |
| `status` | `queued` `sending` `sent` `delivered` `read` `failed` |
| `batch_id` | Semua pesan dari satu pengiriman massal |
| `per_page` | Bawaan 50, maksimal 200 |
| `page` | |

```json
{
  "success": true,
  "data": {
    "items": [ ],
    "meta": { "current_page": 1, "last_page": 3, "total": 128 }
  }
}
```

## Detail satu pesan

`GET /api/v1/messages/{id}`

Termasuk `error` bila gagal.

---

# Sesi

## Daftar sesi

`GET /api/v1/sessions`

```json
{
  "success": true,
  "data": [
    {
      "id": "01K2B8XQZ4M7NPRT5VW9YC3FGH",
      "name": "CS Utama",
      "status": "connected",
      "phone_number": "6281234567890",
      "push_name": "Toko Makmur",
      "auto_reconnect": true,
      "connected_at": "2026-08-13T09:12:44+07:00",
      "last_error": null
    }
  ]
}
```

| `status` | Arti |
|---|---|
| `pending` | Belum pernah dijalankan |
| `connecting` | Sedang menyiapkan koneksi |
| `qr` | Menunggu scan QR |
| `connected` | Siap mengirim |
| `disconnected` | Terputus, akan disambungkan ulang otomatis |
| `failed` | Gagal — lihat `last_error` |

## Buat sesi

`POST /api/v1/sessions`

| Parameter | Wajib | Keterangan |
|---|---|---|
| `name` | ya | Maks 60 karakter, unik dalam workspace |

Galat `422` bila nama sudah dipakai atau jatah sesi habis.

## Detail sesi

`GET /api/v1/sessions/{id}`

## Jalankan sesi

`POST /api/v1/sessions/{id}/connect`

Balasan langsung dengan `status: "connecting"`. QR belum tentu siap saat itu juga — ambil dengan endpoint berikutnya.

## Ambil QR

`GET /api/v1/sessions/{id}/qr`

```json
{
  "success": true,
  "data": {
    "status": "qr",
    "qr": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUg…",
    "expires_at": "2026-08-13T14:05:11+07:00"
  }
}
```

`qr` sudah berupa gambar siap pakai — taruh langsung di `<img src="…">`.

Bila belum siap atau sudah tersambung, `qr` bernilai `null`.

**Cara memakainya:** setelah `connect`, periksa endpoint ini setiap 3 detik sampai `qr` terisi. Hentikan begitu `status` menjadi `connected`.

## Hentikan sesi

`POST /api/v1/sessions/{id}/disconnect`

Menghentikan tanpa memutus tautan. Menjalankan lagi **tidak perlu** scan QR.

## Putus tautan / ganti nomor

`POST /api/v1/sessions/{id}/logout`

Memutus tautan nomor. Setelah ini, `connect` lagi akan memunculkan QR baru — dan **boleh di-scan dengan nomor yang berbeda**.

ID sesi tidak berubah, jadi aplikasi Anda tidak perlu diubah sama sekali.

## Hapus sesi

`DELETE /api/v1/sessions/{id}` → `204`

---

# Webhook

## Daftar webhook

`GET /api/v1/webhooks`

## Tambah webhook

`POST /api/v1/webhooks`

| Parameter | Wajib | Keterangan |
|---|---|---|
| `url` | ya | Alamat endpoint Anda |
| `events` | tidak | Kosongkan untuk berlangganan semua |

Balasan `201` menyertakan `secret` — **hanya sekali ini**. Simpan untuk memverifikasi tanda tangan.

## Hapus webhook

`DELETE /api/v1/webhooks/{id}` → `204`

Penjelasan lengkap cara menerimanya: [Webhook](WEBHOOK.md).

---

# Status workspace

`GET /api/v1/health`

Tidak terkena batas permintaan, jadi aman dipanggil sesering apa pun untuk pemantauan.

```json
{
  "success": true,
  "data": {
    "workspace": "Toko Makmur",
    "status": "active",
    "sessions": { "total": 2, "connected": 1, "limit": 3 },
    "usage": {
      "period": "2026-08",
      "messages_sent": 143,
      "messages_received": 27,
      "messages_failed": 2,
      "quota": 1000
    }
  }
}
```

Berguna untuk membuat peringatan di sistem Anda sendiri — misalnya memberi tahu tim saat `sessions.connected` bernilai nol, atau saat pemakaian mendekati kuota.
