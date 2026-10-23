# Webhook

Menerima pesan masuk dan perubahan status di aplikasi Anda.

---

## Cara kerjanya

API dipakai untuk **mengirim**. Webhook adalah kebalikannya: saat ada kejadian, kami yang mengirim data ke aplikasi Anda.

```
Pelanggan membalas  →  VexaHost WA Gateway  →  POST ke URL Anda
```

Tanpa webhook, satu-satunya cara mengetahui ada balasan adalah memeriksa riwayat berulang-ulang — boros dan selalu terlambat.

## Mendaftarkan

Menu **Webhooks** → isi URL → pilih kejadian → **Simpan**.

**Signing secret** ditampilkan di kartu webhook. Simpan — Anda butuh itu untuk memverifikasi bahwa kiriman benar-benar dari kami.

Gunakan tombol **Kirim uji coba** untuk memastikan endpoint Anda menerimanya dengan benar, sebelum ada trafik sungguhan.

## Kejadian yang tersedia

| Kejadian | Kapan dikirim |
|---|---|
| `message.received` | Ada pesan masuk ke nomor Anda |
| `message.status` | Status pengiriman berubah |
| `session.status` | Nomor tersambung, terputus, atau bermasalah |
| `session.qr` | QR baru tersedia |

## Beberapa website di satu workspace

Punya toko online, aplikasi kasir, dan CRM sekaligus? Anda tidak perlu workspace terpisah untuk masing-masing.

Saat menambah webhook, kolom **Berlaku untuk** menentukan siapa yang menerimanya:

| Pilihan | Menerima |
|---|---|
| **Seluruh workspace** | Semua kejadian, dari integrasi mana pun |
| **Satu API key** | `message.status` hanya untuk pesan yang dikirim API key itu, plus semua pesan masuk |

Jadi kalau toko online Anda memakai API key A dan kasir memakai API key B, status pengiriman pesan yang dikirim toko online **hanya** sampai ke webhook milik A. Kasir tidak menerima kabar tentang pesan yang bukan miliknya, dan sebaliknya.

**Dua kejadian sengaja tetap sampai ke semua:**

- **`message.received`** — pesan masuk tidak punya "pengirim" dari sisi Anda, jadi tidak ada dasar untuk menentukan integrasi mana yang seharusnya menerimanya. Menebak di sini berarti pesan pelanggan Anda hilang di sistem yang menunggunya.
- **`session.status` dan `session.qr`** — nomor yang terputus memengaruhi seluruh integrasi Anda sekaligus, bukan salah satunya.

Pesan yang Anda kirim dari **dashboard** tidak berasal dari API key mana pun, jadi statusnya sampai ke webhook tingkat workspace. Kalau Anda ingin tetap menerima kabar itu, sisakan satu webhook **Seluruh workspace**.

> Webhook yang sudah Anda daftarkan sebelum fitur ini ada tetap berjalan persis seperti sebelumnya — semuanya webhook tingkat workspace, dan tidak ada satu pun kejadian yang berhenti sampai.

Kalau API key-nya Anda cabut, webhook-nya **tidak ikut terhapus**: ia turun menjadi webhook tingkat workspace, lengkap dengan signing secret yang sama. Integrasi Anda tidak perlu dipasang ulang.

## Bentuk kiriman

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
    "body": "Halo, pesanan saya sudah sampai mana ya?",
    "is_group": false,
    "received_at": "2026-08-13T14:12:44+07:00",
    "sent_at": "2026-08-13T14:12:41+07:00",
    "is_backlog": false
  }
}
```

Isi `data` per kejadian:

| Kejadian | Isi `data` |
|---|---|
| `message.received` | `message_id`, `session_id`, `from`, `chat_id`, `type`, `body`, `is_group`, `received_at`, `sent_at`, `is_backlog` |
| `message.status` | `message_id`, `status`, `to`, `error` (bila gagal) |
| `session.status` | `session_id`, `name`, `status`, `phone_number`, `reason` |
| `session.qr` | `session_id`, `status` |

### Pesan yang menumpuk saat nomor offline

Saat nomor Anda tersambung kembali setelah terputus, pesan yang masuk selama itu dikirim ke webhook **sekaligus**. Dua kolom membantu Anda membedakannya dari pesan yang baru masuk:

| Kolom | Isi |
|---|---|
| `sent_at` | Waktu pengirim mengirim pesan itu. Bisa `null` kalau WhatsApp tidak menyertakannya |
| `received_at` | Waktu pesan sampai di sistem kami |
| `is_backlog` | `true` untuk pesan yang menumpuk selama nomor offline |

**Kalau integrasi Anda membalas otomatis** (chatbot, AI agent, auto-reply), jangan membalas pesan dengan `is_backlog: true` atau yang `sent_at`-nya sudah lewat beberapa menit. Membalas seluruh tumpukan sekaligus — termasuk "halo?" dari berjam-jam lalu — adalah lonjakan pesan keluar yang paling sering membuat nomor dibatasi WhatsApp. Pesannya tetap dicatat seperti biasa; yang dilewati cukup balasan otomatisnya.

### Alasan nomor terputus

Untuk `session.status` dengan `status: "disconnected"`, kolom `reason` menyebut sebabnya, supaya sistem Anda tahu apakah nomor itu akan pulih sendiri atau perlu ditangani manusia:

| `reason` | Arti | Pulih sendiri? |
|---|---|---|
| `connection_lost` | Sambungan ke WhatsApp putus berulang kali | Ya, kami coba sambungkan lagi otomatis |
| `logged_out` | WhatsApp mengeluarkan perangkat ini (tautan dihapus dari ponsel, WhatsApp dipasang ulang, atau ponsel lama tidak online) | Tidak — perlu scan QR ulang |
| `credentials_missing` | Kredensial nomor tidak bisa dipulihkan | Tidak — perlu scan QR ulang |
| `banned` | WhatsApp menolak sambungan, biasanya karena nomornya diblokir atau dibatasi | Tidak — periksa WhatsApp di ponsel |
| `replaced` | Perangkat lain tersambung dengan kredensial yang sama | Dicoba sekali lagi otomatis setelah beberapa menit; kalau terulang di hari yang sama, tekan Hubungkan |

Untuk status lain, `reason` bernilai `null`.

## Verifikasi tanda tangan

**Selalu lakukan ini sebelum memproses.** Tanpa verifikasi, siapa pun yang mengetahui URL webhook Anda bisa mengirim data palsu — misalnya berpura-pura menjadi pelanggan yang membatalkan pesanan.

Setiap kiriman membawa dua header:

| Header | Isi |
|---|---|
| `X-VexaHost-Signature` | Tanda tangan HMAC-SHA256 dari isi kiriman |
| `X-VexaHost-Event` | Nama kejadian |

### PHP / Laravel

```php
Route::post('/webhook/whatsapp', function (Request $request) {
    $expected = hash_hmac('sha256', $request->getContent(), config('services.wa.webhook_secret'));

    if (! hash_equals($expected, (string) $request->header('X-VexaHost-Signature'))) {
        abort(401);
    }

    $data = $request->json('data');

    // Proses di latar belakang, jangan di sini.
    ProsesPesanMasuk::dispatch($data);

    return response()->noContent();
});
```

Endpoint webhook tidak melalui sesi browser, jadi kecualikan dari pemeriksaan CSRF.

### Node.js / Express

```js
import crypto from 'node:crypto';

app.post('/webhook/whatsapp',
    express.raw({ type: 'application/json' }),   // WAJIB raw
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

### Python / Flask

```python
import hmac, hashlib

@app.post("/webhook/whatsapp")
def webhook():
    expected = hmac.new(
        WA_WEBHOOK_SECRET.encode(),
        request.get_data(),          # data mentah, bukan hasil parsing
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, request.headers.get("X-VexaHost-Signature", "")):
        return "", 401

    data = request.get_json()["data"]
    return "", 204
```

> **Tanda tangan dihitung atas isi mentah.** Kalau framework Anda sudah mem-parsing JSON lalu menyusunnya kembali, urutan kunci atau tanda kutipnya bisa berubah dan tanda tangan tidak akan cocok. Ambil isi mentahnya sebelum di-parse.

## Kalau endpoint Anda gagal

Endpoint Anda harus membalas kode `2xx`.

Kiriman yang gagal diulang **3 kali** dengan jeda 30 detik lalu 5 menit.

Setelah **50 kegagalan berturut-turut**, webhook dinonaktifkan otomatis. Ini melindungi Anda sendiri: tanpa itu, antrean akan terus terisi kiriman yang pasti gagal. Nyalakan lagi dari dashboard setelah endpoint pulih.

Pesan masuknya sendiri **tetap tersimpan** di Riwayat Pesan meski webhook gagal — tidak ada data yang hilang.

## Praktik yang baik

**Balas cepat, proses belakangan.** Terima kiriman, simpan ke antrean Anda, lalu balas `204`. Kalau Anda memproses sampai selesai sebelum membalas, kiriman berikutnya menumpuk.

**Siapkan untuk kiriman ganda.** Dalam kondisi jaringan tertentu, satu kejadian bisa terkirim dua kali. Gunakan `delivery_id` atau `message_id` untuk mengabaikan yang sudah pernah diproses.

**Pakai HTTPS.** Isi pesan pelanggan Anda lewat di sana.

**Jangan taruh secret di URL.** Verifikasi lewat header tanda tangan, bukan lewat `?token=…` di alamat — alamat tercatat di log server dan proxy.

## Menguji saat pengembangan

Kalau aplikasi Anda masih berjalan di komputer lokal, gunakan layanan penerus seperti ngrok agar bisa dijangkau dari internet:

```bash
ngrok http 8000
```

Daftarkan alamat yang diberikan ngrok sebagai URL webhook, lalu tekan **Kirim uji coba** di dashboard.

Untuk sekadar melihat bentuk kirimannya tanpa menulis kode apa pun, daftarkan alamat dari webhook.site.
