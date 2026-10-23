# Referensi REST API v1

Referensi lengkap setiap endpoint: parameter, contoh permintaan, contoh balasan, dan semua kondisi galat.

---

## Daftar isi

- [Base URL](#base-url)
- [Autentikasi](#autentikasi)
- [Bentuk balasan](#bentuk-balasan)
- [Kode status](#kode-status)
- [Rate limit](#rate-limit)
- [Format nomor](#format-nomor)
- **Endpoint**
  - [Health](#get-health)
  - [Sesi](#sesi)
  - [Pesan](#pesan)
  - [Webhook](#webhook)
  - [OTP](#otp)
- [Menerima webhook](#menerima-webhook)
- [Contoh integrasi](#contoh-integrasi)

---

## Base URL

| Tahap | Base URL |
|---|---|
| Production | `https://wa.vexahostcloud.my.id` |
| Staging | `https://wa-staging.vexahostcloud.my.id` |
| Development | `https://wa-dev.vexahostcloud.my.id` |
| Lokal | `http://localhost:8051` |

API key berbeda per tahap dan tidak bisa dipakai lintas tahap.

## Autentikasi

Setiap permintaan menyertakan API key:

```http
X-Api-Key: vwa_a1b2c3d4.PANJANGSEKALIRAHASIA
```

Atau sebagai bearer token, untuk klien yang lebih nyaman dengan pola itu:

```http
Authorization: Bearer vwa_a1b2c3d4.PANJANGSEKALIRAHASIA
```

Kunci dibuat di dashboard (**API Keys**) atau lewat CLI:

```bash
Dashboard → API Keys → beri nama → Buat
```

**Kunci bisa dibuka lagi** dari halaman API Keys oleh owner atau admin workspace — tersimpan terenkripsi, bukan hanya hash. Nilai terenkripsinya dibuang begitu kunci dicabut.

### Scope

| Scope | Kemampuan |
|---|---|
| `*` | Semua endpoint (bawaan) |
| `otp` | Endpoint OTP. **Hanya untuk flustra-auth** |

Kunci tanpa scope yang diminta ditolak dengan 403.

## Bentuk balasan

Berhasil:

```json
{
  "success": true,
  "data": { }
}
```

Gagal:

```json
{
  "success": false,
  "error": { "message": "Kuota pesan bulan ini sudah habis (1000 pesan)." }
}
```

Bentuk ini konsisten di seluruh endpoint, termasuk galat validasi dan galat autentikasi.

## Kode status

| Kode | Arti | Tindakan yang tepat |
|---|---|---|
| 200 | Berhasil | |
| 202 | Diterima untuk diproses | Telusuri statusnya lewat ULID atau webhook |
| 204 | Berhasil, tanpa isi | |
| 401 | API key tidak ada, salah, dicabut, atau kedaluwarsa | Periksa kunci — jangan diulang |
| 403 | Workspace nonaktif, atau kunci tidak punya scope | Jangan diulang |
| 404 | Sumber daya tidak ada, atau milik workspace lain | Jangan diulang |
| 422 | Data tidak valid, sesi tidak siap, atau kuota habis | Perbaiki datanya |
| 429 | Melewati rate limit | Tunggu, lihat header `Retry-After` |
| 500 | Galat server | Boleh diulang dengan jeda |
| 502 | Engine tidak bisa dihubungi | Boleh diulang dengan jeda |

**202, bukan 200, untuk pengiriman pesan.** Artinya "diterima untuk diproses", bukan "sudah terkirim". Pesan masuk antrean dan dikirim di latar belakang — untuk broadcast, itu bisa memakan menit sampai jam.

## Rate limit

Dihitung **per API key**, bukan per IP. Beberapa aplikasi Flustra berjalan di VPS yang sama; limit per IP akan membuat mereka saling menghabiskan jatah.

Bawaan 60 permintaan/menit, bisa diatur per workspace atau per kunci.

`GET /api/v1/health` dikecualikan, jadi aman dipanggil sesering apa pun untuk monitoring.

Saat melewati batas, balasan 429 disertai header `Retry-After` (detik).

## Format nomor

Semua bentuk berikut diterima dan dinormalisasi menjadi `6281234567890`:

```
081234567890
+62 812-3456-7890
62812-3456-7890
6281234567890
```

Validasinya `62` diikuti 9–13 digit. Nomor di luar itu ditolak 422.

Untuk grup, kirim chat id lengkap berakhiran `@g.us`. Cara mendapatkannya: kirim pesan ke grup dari HP, lalu lihat `chat_id` pada pesan masuk di riwayat.

---

# Endpoint

## `GET /health`

Keadaan workspace dan pemakaian bulan berjalan. Tidak terkena rate limit.

```bash
curl -H "X-Api-Key: $KEY" https://wa.vexahostcloud.my.id/api/v1/health
```

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

`usage.quota` bernilai `null` untuk workspace internal (tanpa batas).

---

## Sesi

### `GET /sessions`

```json
{
  "success": true,
  "data": [
    {
      "id": "01K2B8XQZ4M7NPRT5VW9YC3FGH",
      "name": "CS Utama",
      "driver": "wwebjs",
      "status": "connected",
      "phone_number": "6281234567890",
      "push_name": "Toko Makmur",
      "auto_reconnect": true,
      "connected_at": "2026-08-13T09:12:44+07:00",
      "last_seen_at": "2026-08-13T14:03:11+07:00",
      "last_error": null
    }
  ]
}
```

**Nilai `status`:**

| Status | Arti |
|---|---|
| `pending` | Dibuat, belum pernah dijalankan |
| `connecting` | Engine sedang menyiapkan browser |
| `qr` | Menunggu di-scan |
| `connected` | Siap mengirim |
| `disconnected` | Terputus. Akan disambungkan ulang otomatis bila `auto_reconnect` |
| `failed` | Gagal — lihat `last_error` |

### `POST /sessions`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `name` | string, maks 60 | ya | Unik dalam satu workspace |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/sessions \
  -H "X-Api-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"CS Utama"}'
```

Balasan `201` dengan objek sesi.

Galat `422`: nama sudah dipakai, atau jatah sesi workspace sudah habis.

### `GET /sessions/{id}`

Objek sesi. `404` bila sesi milik workspace lain.

### `POST /sessions/{id}/connect`

Menjalankan sesi. Balasan langsung dengan `status: "connecting"` — QR belum tentu tersedia saat ini.

```bash
curl -X POST -H "X-Api-Key: $KEY" \
  https://wa.vexahostcloud.my.id/api/v1/sessions/$SID/connect
```

Setelah ini, poll `GET /sessions/{id}/qr` sampai QR muncul.

Galat `502`: engine tidak bisa dihubungi.

### `GET /sessions/{id}/qr`

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

`qr` berupa data URI PNG, siap ditaruh di `<img src="…">`.

Bila belum siap atau sudah tersambung, `qr` bernilai `null` dan `status` menunjukkan keadaannya:

```json
{ "success": true, "data": { "status": "connected", "qr": null } }
```

**Cara memakainya:** poll setiap 3 detik setelah `connect`. QR berganti sendiri setiap 20–60 detik; ambil yang terbaru. Hentikan polling begitu `status` menjadi `connected`.

### `POST /sessions/{id}/disconnect`

Menghentikan sesi tanpa memutus tautan. Kredensial tetap tersimpan — menyambung lagi **tidak perlu** scan QR.

### `POST /sessions/{id}/logout`

Memutus tautan perangkat di sisi WhatsApp dan membuang kredensialnya.

Setelah ini, `connect` lagi akan memunculkan QR baru — dan **boleh di-scan dengan nomor yang berbeda**. Inilah cara mengganti nomor.

Riwayat pesan, API key, dan webhook tetap utuh: semuanya menggantung di baris sesi, bukan di kredensialnya. Aplikasi yang memakai `session_id` ini tidak perlu diubah sama sekali.

### `DELETE /sessions/{id}`

Memutus tautan lalu menghapus sesi beserta cadangannya. Balasan `204`.

Pesan yang pernah dikirim lewat sesi ini tetap ada di riwayat, dengan `session_id` menjadi `null`.

---

## Pesan

### `POST /messages/text`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | string, maks 40 | ya | Nomor atau chat id grup |
| `message` | string, maks 4096 | ya | |
| `session_id` | string | tidak | Bila kosong, dipilih otomatis |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/text \
  -H "X-Api-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "to": "081234567890",
    "message": "Invoice INV-001 sudah lunas. Terima kasih!"
  }'
```

```json
{
  "success": true,
  "data": {
    "id": "01K2CDEFGH7JKMNPQRSTVWXYZ",
    "session_id": "01K2B8XQZ4M7NPRT5VW9YC3FGH",
    "direction": "outbound",
    "to": "6281234567890",
    "from": null,
    "type": "text",
    "body": "Invoice INV-001 sudah lunas. Terima kasih!",
    "status": "queued",
    "error": null,
    "batch_id": null,
    "wa_message_id": null,
    "created_at": "2026-08-13T14:03:11+07:00",
    "scheduled_at": null,
    "sent_at": null,
    "delivered_at": null,
    "read_at": null
  }
}
```

Simpan `data.id` untuk menelusuri statusnya. `scheduled_at` terisi untuk pesan yang ditunda masa pemanasan nomor pengirim (`App\Services\RemPengiriman`): diterima, kuotanya tercatat, dan dikirim otomatis pada waktu itu.

**Pemilihan sesi otomatis:** bila `session_id` kosong, dipakai sesi pertama yang berstatus `connected`. Isi `session_id` hanya kalau workspace punya beberapa nomor dan pengirimnya harus dikunci.

Galat `422`:
- `Nomor tujuan tidak valid: 123`
- `Kuota pesan bulan ini sudah habis (1000 pesan).`
- `Workspace sedang tidak aktif.`
- `Tidak ada sesi WhatsApp yang bisa dipakai. Hubungkan satu sesi terlebih dahulu.`
- `Penerima 62… sudah meminta berhenti menerima pesan …` — penerima membalas STOP; tidak memotong kuota
- `Pengiriman ke 62… ditahan sementara: lebih dari 40 pesan dalam 10 menit …` — rem balas-balasan bot; tidak memotong kuota

### `POST /messages/media`

`multipart/form-data`.

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | string | ya | |
| `file` | file, maks 16 MB | ya | Batas WhatsApp |
| `caption` | string, maks 1024 | tidak | |
| `type` | enum | tidak | `image`, `document` (bawaan), `video`, `audio` |
| `session_id` | string | tidak | |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/media \
  -H "X-Api-Key: $KEY" \
  -F "to=081234567890" \
  -F "caption=Invoice Agustus" \
  -F "type=document" \
  -F "file=@invoice.pdf"
```

Balasan `202` dengan objek pesan.


### `POST /messages/bulk`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | array, 1–1000 | ya | |
| `message` | string, maks 4096 | ya | |
| `session_id` | string | tidak | |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/bulk \
  -H "X-Api-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "to": ["081234567890", "081298765432", "bukan-nomor"],
    "message": "Toko tutup tanggal 17 Agustus."
  }'
```

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

Nomor yang rusak dikumpulkan di `rejected` tanpa membatalkan sisanya. Nomor ganda dibuang otomatis. `scheduled` adalah bagian dari `queued` yang ditunda masa pemanasan.

Pantau kemajuannya dengan `GET /messages?batch_id=…`.

**Berapa lama?** Dengan jeda 3–8 detik per pesan, 500 nomor memakan sekitar 30–60 menit. Ini disengaja — lihat [FAQ](FAQ.md#kenapa-broadcast-saya-lambat-sekali).

### `POST /messages/template`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `to` | string | ya | |
| `template` | string | ya | Slug template |
| `variables` | object | tidak | Nilai untuk placeholder |
| `session_id` | string | tidak | |

Template dengan body:

```
Halo {{ nama }}, faktur {{ nomor }} jatuh tempo {{ tanggal }}.
```

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/messages/template \
  -H "X-Api-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "to": "081234567890",
    "template": "pengingat-invoice",
    "variables": { "nama": "Budi", "nomor": "INV-001", "tanggal": "20 Agustus" }
  }'
```

Placeholder yang tidak diberi nilai **dibiarkan apa adanya** di pesan — kesalahan yang terlihat jelas lebih baik daripada kalimat yang diam-diam bolong.

Galat `404`: template tidak ditemukan atau tidak aktif.

### `GET /messages`

| Query | Keterangan |
|---|---|
| `session_id` | Saring per sesi |
| `direction` | `outbound` atau `inbound` |
| `status` | `queued`, `sending`, `sent`, `delivered`, `read`, `failed` |
| `batch_id` | Semua pesan dari satu broadcast |
| `per_page` | Bawaan 50, maks 200 |
| `page` | |

```json
{
  "success": true,
  "data": {
    "items": [ { } ],
    "meta": { "current_page": 1, "last_page": 3, "total": 128 }
  }
}
```

### `GET /messages/{id}`

Satu pesan, lengkap dengan `error` dan `provider_response` bila gagal.

**Arti status:**

| Status | Arti |
|---|---|
| `queued` | Menunggu giliran |
| `sending` | Sedang dikirim |
| `sent` | Sampai ke server WhatsApp (satu centang) |
| `delivered` | Sampai ke HP tujuan (dua centang) |
| `read` | Dibaca (dua centang biru) |
| `failed` | Gagal — alasannya di `error` |

`delivered` dan `read` hanya muncul bila penerima mengaktifkan laporan dibaca.

Status **tidak pernah mundur**, meski ack dari WhatsApp datang tidak berurutan.

---

## Webhook

### `GET /webhooks`

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "url": "https://app.contoh.id/webhook/whatsapp",
      "events": ["message.received"],
      "is_active": true,
      "consecutive_failures": 0,
      "last_success_at": "2026-08-13T14:00:00+07:00",
      "last_failure_at": null
    }
  ]
}
```

### `POST /webhooks`

| Parameter | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `url` | url, maks 500 | ya | |
| `events` | array | tidak | Kosong berarti semua event |

```bash
curl -X POST https://wa.vexahostcloud.my.id/api/v1/webhooks \
  -H "X-Api-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "url": "https://app.contoh.id/webhook/whatsapp",
    "events": ["message.received", "session.status"]
  }'
```

Balasan `201` menyertakan `secret` — **hanya sekali ini**. Simpan untuk memverifikasi tanda tangan.

### `DELETE /webhooks/{id}`

Balasan `204`.

---

## OTP

Butuh scope `otp`. Dipakai flustra-auth untuk memverifikasi kepemilikan nomor.

Jangan berikan scope ini ke kunci integrasi biasa: kunci yang bocor dengannya bisa dipakai membombardir nomor orang lain dan membuat nomor pengirim diblokir.

### `POST /otp/send`

| Parameter | Wajib | Keterangan |
|---|---|---|
| `phone` | ya | |
| `purpose` | tidak | Bawaan `phone_verification` |

```json
{
  "success": true,
  "data": { "expires_at": "2026-08-13T14:08:11+07:00", "resend_after_seconds": 60 }
}
```

Galat `429`: jeda kirim ulang belum lewat, atau batas harian nomor tercapai.

### `POST /otp/verify`

| Parameter | Wajib |
|---|---|
| `phone` | ya |
| `code` | ya |
| `purpose` | tidak |

```json
{ "success": true, "data": { "verified": true } }
```

Galat `422`: kode salah, kedaluwarsa, atau percobaan sudah habis.

---

# Menerima webhook

Gateway mengirim `POST` JSON ke URL yang didaftarkan.

```json
{
  "event": "message.received",
  "timestamp": "2026-08-13T14:12:44+07:00",
  "delivery_id": "01K2CDEFGH7JKMNPQRSTVWXYZ",
  "data": {
    "message_id": "01K2C…",
    "session_id": "01K2B…",
    "from": "6289999999999",
    "chat_id": "6289999999999@c.us",
    "type": "text",
    "body": "Halo, invoice saya sudah dibayar",
    "is_group": false,
    "received_at": "2026-08-13T14:12:44+07:00",
    "sent_at": "2026-08-13T14:12:41+07:00",
    "is_backlog": false
  }
}
```

### Daftar event

| Event | Kapan | Isi `data` |
|---|---|---|
| `message.received` | Ada pesan masuk | `message_id`, `session_id`, `from`, `chat_id`, `type`, `body`, `is_group`, `received_at`, `sent_at`, `is_backlog` |
| `message.status` | Status kirim berubah | `message_id`, `status`, `to`, `error` (bila gagal) |
| `session.status` | Sesi tersambung/terputus/gagal | `session_id`, `name`, `status`, `phone_number`, `reason` |
| `session.qr` | QR baru tersedia | `session_id`, `status` |

`sent_at` adalah waktu pengirim mengirim pesan (bisa `null`); `is_backlog` bernilai `true` untuk pesan yang menumpuk selama nomor offline dan datang sekaligus saat tersambung lagi — integrasi yang membalas otomatis wajib melewatinya. `reason` pada `session.status` terputus berisi salah satu nilai `App\Support\AlasanPutus` (`connection_lost`, `logged_out`, `credentials_missing`, `banned`, `replaced`); `null` untuk status lain. Penjelasan lengkapnya untuk pelanggan ada di `resources/docs/WEBHOOK.md`.

Pesan masuk yang dikirim ulang WhatsApp (`wa_message_id` yang sama untuk sesi yang sama) hanya dicatat dan diteruskan sekali.

### Verifikasi tanda tangan

**Selalu verifikasi sebelum memproses.** Tanpa ini, siapa pun yang tahu URL webhook Anda bisa mengirim data palsu.

Header:

| Header | Isi |
|---|---|
| `X-VexaHost-Signature` | HMAC-SHA256 dari body mentah, dengan secret webhook |
| `X-VexaHost-Event` | Nama event |

PHP / Laravel:

```php
Route::post('/webhook/whatsapp', function (Request $request) {
    $expected = hash_hmac('sha256', $request->getContent(), config('services.wa.webhook_secret'));

    if (! hash_equals($expected, (string) $request->header('X-VexaHost-Signature'))) {
        abort(401);
    }

    $data = $request->json('data');
    // proses…

    return response()->noContent();
})->withoutMiddleware(VerifyCsrfToken::class);
```

Node.js / Express:

```js
import crypto from 'node:crypto';

app.post('/webhook/whatsapp',
    express.raw({ type: 'application/json' }),   // WAJIB raw, bukan express.json()
    (req, res) => {
        const expected = crypto
            .createHmac('sha256', process.env.WA_WEBHOOK_SECRET)
            .update(req.body)
            .digest('hex');

        const given = req.get('X-VexaHost-Signature') ?? '';

        if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(given))) {
            return res.sendStatus(401);
        }

        const payload = JSON.parse(req.body);
        res.sendStatus(204);
    });
```

> Tanda tangan dihitung atas **body mentah**. Kalau framework Anda sudah mem-parsing dan meng-encode ulang JSON-nya, urutan kunci atau escaping bisa berubah dan tanda tangan tidak akan cocok.

### Percobaan ulang

Endpoint Anda harus membalas 2xx. Kegagalan diulang 3 kali dengan jeda 30 detik lalu 5 menit.

Setelah **50 kegagalan berturut-turut**, webhook dinonaktifkan otomatis supaya antrean tidak terus terisi kiriman yang pasti gagal. Nyalakan lagi dari dashboard setelah endpoint pulih.

Pesan masuknya sendiri tetap tersimpan di riwayat gateway meski webhook gagal.

---

# Contoh integrasi

## PHP (Laravel)

```php
use Illuminate\Support\Facades\Http;

class WhatsApp
{
    public static function kirim(string $nomor, string $pesan): bool
    {
        $response = Http::baseUrl(config('whatsapp.url'))
            ->withHeader('X-Api-Key', config('whatsapp.key'))
            ->timeout(5)
            ->acceptJson()
            ->post('/api/v1/messages/text', [
                'to' => $nomor,
                'message' => $pesan,
            ]);

        return $response->successful();
    }
}
```

Klien lengkap yang sudah dipakai aplikasi Flustra ada di `docs/client/app/Services/WhatsAppGateway.php` — lihat [INTEGRASI_APP.md](INTEGRASI_APP.md).

## JavaScript

```js
async function kirimWhatsApp(nomor, pesan) {
    const res = await fetch(`${process.env.WA_URL}/api/v1/messages/text`, {
        method: 'POST',
        headers: {
            'X-Api-Key': process.env.WA_KEY,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ to: nomor, message: pesan }),
    });

    if (!res.ok) {
        const err = await res.json();
        throw new Error(err.error?.message ?? `HTTP ${res.status}`);
    }

    return (await res.json()).data;
}
```

## Python

```python
import requests

def kirim_whatsapp(nomor: str, pesan: str) -> dict:
    r = requests.post(
        f"{WA_URL}/api/v1/messages/text",
        headers={"X-Api-Key": WA_KEY},
        json={"to": nomor, "message": pesan},
        timeout=5,
    )
    r.raise_for_status()
    return r.json()["data"]
```

## Menunggu sampai terkirim

Kalau benar-benar perlu memastikan pesan sampai — misalnya OTP — poll statusnya:

```php
$id = $response->json('data.id');

for ($i = 0; $i < 30; $i++) {
    sleep(2);

    $status = Http::withHeader('X-Api-Key', $key)
        ->get("{$base}/api/v1/messages/{$id}")
        ->json('data.status');

    if (in_array($status, ['delivered', 'read'], true)) {
        return true;
    }

    if ($status === 'failed') {
        return false;
    }
}
```

Untuk kebutuhan biasa, webhook `message.status` lebih hemat daripada polling.

## Kesalahan yang sering terjadi

| Kesalahan | Akibat |
|---|---|
| Mengira 202 berarti sudah terkirim | Status sebenarnya menyusul lewat ULID atau webhook |
| Membuat perulangan sendiri untuk broadcast | Pakai `/messages/bulk` — gateway yang mengatur jedanya |
| Tidak memverifikasi tanda tangan webhook | Endpoint bisa dikirimi data palsu |
| Mem-parsing JSON sebelum verifikasi tanda tangan | Tanda tangan dihitung atas body mentah |
| Timeout terlalu panjang saat memanggil API | Notifikasi adalah pelengkap; jangan menahan permintaan pengguna |
| Memakai satu API key untuk semua aplikasi | Kunci bocor berarti semua aplikasi harus diganti kuncinya sekaligus |
