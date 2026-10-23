# Memantau Koneksi

Panduan memasang pemantauan koneksi WhatsApp **di dalam aplikasi Anda sendiri**, supaya Anda tidak perlu membuka dashboard VexaHost untuk tahu nomor Anda masih tertaut atau tidak.

Ini bukan kenyamanan. Nomor WhatsApp bisa terputus tanpa satu pun gejala di aplikasi Anda: pengiriman berikutnya gagal, dan yang pertama menyadarinya biasanya pelanggan Anda yang tidak menerima notifikasi. Dengan lencana status di halaman admin Anda sendiri, keadaan itu terlihat sebelum ada yang mengeluh.

---

## Dua cara, dan keduanya sebaiknya dipakai

| | Polling `/health` | Webhook `session.status` |
|---|---|---|
| **Cara kerja** | Aplikasi Anda bertanya berkala | Kami memberi tahu saat berubah |
| **Cepatnya** | Selambat jeda polling Anda | Dalam hitungan detik |
| **Kalau aplikasi Anda mati** | Tidak ada yang hilang, tinggal bertanya lagi | Kabarnya hilang |
| **Cocok untuk** | Lencana di halaman admin | Peringatan otomatis, mencatat riwayat |

Pakai **keduanya**: webhook untuk tahu secepatnya, polling sebagai jaring pengaman. Webhook yang hilang karena server Anda sedang di-deploy tidak akan pernah dikirim ulang selamanya, dan tanpa polling, aplikasi Anda akan menyimpan keadaan yang keliru sampai perubahan berikutnya.

---

## 1. Endpoint kesehatan

```http
GET /api/v1/health
X-Api-Key: kunci_api_anda
```

Jawaban:

```json
{
  "data": {
    "workspace": "Toko Saya",
    "status": "active",
    "sessions": { "total": 2, "connected": 1, "limit": 2 },
    "usage": {
      "period": "2026-09",
      "messages_sent": 1240,
      "messages_received": 318,
      "messages_failed": 4,
      "quota": 5000
    }
  }
}
```

Yang perlu Anda baca:

| Bidang | Artinya |
|---|---|
| `status` | Keadaan langganan workspace. Selain `active`, pengiriman ditolak |
| `sessions.connected` | Berapa nomor yang benar-benar tertaut **sekarang** |
| `sessions.total` | Berapa nomor yang terdaftar, tertaut atau tidak |
| `usage.quota` | `null` berarti tanpa batas |

**`connected` lebih kecil dari `total` berarti ada nomor yang putus.** Itu keadaan yang perlu Anda tampilkan.

Endpoint ini **tidak** memotong kuota pesan Anda, tetapi tetap dihitung terhadap batas laju API. Jangan memanggilnya lebih sering dari 30 detik sekali.

---

## 2. Lencana status di halaman admin Anda

Pola yang kami pakai sendiri di Flustra Office: satu endpoint proksi di aplikasi Anda, satu lencana kecil yang menyegarkan sendiri.

**Kenapa harus lewat proksi, bukan memanggil VexaHost langsung dari peramban:** API key Anda akan terbaca siapa pun yang membuka Inspect Element. Kunci yang bocor bisa dipakai mengirim pesan atas nama Anda dan memotong kuota Anda. Panggilan ke VexaHost **selalu** dari server aplikasi Anda.

### Laravel — controller proksi

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StatusWhatsAppController extends Controller
{
    public function __invoke(): JsonResponse
    {
        if (! config('vexahost.key')) {
            return response()->json([
                'status' => 'belum_dikonfigurasi',
                'pesan' => 'VEXAHOST_WA_KEY belum diisi di .env aplikasi ini.',
            ]);
        }

        // Ditahan 30 detik. Sepuluh admin yang membuka halaman bersamaan
        // tidak boleh menjadi sepuluh panggilan ke gateway — terutama saat
        // gateway sedang lambat, yang justru saat halaman ini paling dibuka.
        $hasil = Cache::remember('wa:status', 30, function (): array {
            try {
                $jawaban = Http::baseUrl(rtrim(config('vexahost.url'), '/'))
                    ->withHeader('X-Api-Key', config('vexahost.key'))
                    ->connectTimeout(3)
                    ->timeout(8)
                    ->acceptJson()
                    ->get('/api/v1/health');

                if ($jawaban->failed()) {
                    return [
                        'status' => 'galat',
                        'pesan' => $jawaban->json('error.message') ?? 'Gateway menolak permintaan.',
                    ];
                }

                $data = $jawaban->json('data');
                $tertaut = $data['sessions']['connected'] ?? 0;
                $total = $data['sessions']['total'] ?? 0;

                return [
                    // Sebagian nomor putus BUKAN keadaan yang sama dengan
                    // semuanya putus, dan menyamakannya membuat lencana ini
                    // berteriak merah untuk hal yang masih berjalan separuh.
                    'status' => match (true) {
                        $tertaut === 0 => 'terputus',
                        $tertaut < $total => 'sebagian',
                        default => 'tertaut',
                    },
                    'sesi' => $data['sessions'],
                    'pemakaian' => $data['usage'],
                    'langganan' => $data['status'],
                ];
            } catch (\Throwable $e) {
                Log::warning('Gateway WhatsApp tidak terjangkau: '.$e->getMessage());

                // Tidak terjangkau BUKAN berarti nomor Anda terputus — gateway
                // yang sedang di-deploy tidak memutus satu pun nomor. Bedakan
                // keduanya, kalau tidak setiap deploy terbaca sebagai gangguan.
                return ['status' => 'tak_terjangkau', 'pesan' => 'Gateway tidak dapat dihubungi.'];
            }
        });

        return response()->json($hasil);
    }
}
```

`config/vexahost.php`:

```php
<?php

return [
    'url' => env('VEXAHOST_WA_URL', 'https://{{legal.domain}}'),
    'key' => env('VEXAHOST_WA_KEY'),
];
```

Rutenya — **wajib di balik middleware admin Anda**, karena jawabannya memuat angka pemakaian:

```php
Route::get('/admin/whatsapp/status', StatusWhatsAppController::class)
    ->middleware(['auth', 'can:admin'])
    ->name('whatsapp.status');
```

### Lencananya (Alpine.js)

```html
<div x-data="statusWhatsApp()" x-init="muat()" class="flex items-center gap-2">
    <span class="h-2 w-2 rounded-full"
          :class="{
              'bg-emerald-500': status === 'tertaut',
              'bg-amber-500':   status === 'sebagian',
              'bg-red-500':     status === 'terputus' || status === 'tak_terjangkau' || status === 'galat',
              'bg-slate-400':   status === 'memuat' || status === 'belum_dikonfigurasi',
          }"></span>

    <span class="text-sm" x-text="label()"></span>

    <button type="button" @click="muat()" class="text-xs text-slate-500 hover:underline">
        Muat ulang
    </button>
</div>

<script>
function statusWhatsApp() {
    return {
        status: 'memuat',
        sesi: null,
        timer: null,

        async muat() {
            try {
                const r = await fetch('{{ route('whatsapp.status') }}', {
                    headers: { 'Accept': 'application/json' },
                });
                const d = await r.json();
                this.status = d.status;
                this.sesi = d.sesi ?? null;
            } catch (e) {
                // Jaringan peramban yang putus bukan gateway yang mati.
                this.status = 'tak_terjangkau';
            }

            // Dijadwalkan ULANG setelah jawaban datang, bukan setInterval.
            // setInterval tetap menembak tiap 30 detik walau permintaan
            // sebelumnya belum selesai — saat gateway lambat, permintaan
            // menumpuk sampai peramban kehabisan koneksi.
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.muat(), 30000);
        },

        label() {
            return {
                memuat: 'Memuat…',
                tertaut: this.sesi ? `Terhubung (${this.sesi.connected}/${this.sesi.total} nomor)` : 'Terhubung',
                sebagian: this.sesi ? `Sebagian terputus (${this.sesi.connected}/${this.sesi.total})` : 'Sebagian terputus',
                terputus: 'Tidak ada nomor tertaut',
                tak_terjangkau: 'Gateway tidak terjangkau',
                galat: 'Gateway menolak permintaan',
                belum_dikonfigurasi: 'Belum dikonfigurasi',
            }[this.status] ?? this.status;
        },
    };
}
</script>
```

### Node.js

```js
const CACHE_MS = 30_000;
let cache = { pada: 0, hasil: null };

export async function statusWhatsApp() {
  if (Date.now() - cache.pada < CACHE_MS) return cache.hasil;

  const kendali = new AbortController();
  const batas = setTimeout(() => kendali.abort(), 8000);

  try {
    const r = await fetch(`${process.env.VEXAHOST_WA_URL}/api/v1/health`, {
      headers: { 'X-Api-Key': process.env.VEXAHOST_WA_KEY, Accept: 'application/json' },
      signal: kendali.signal,
    });

    if (!r.ok) throw new Error(`Gateway menjawab ${r.status}`);

    const { data } = await r.json();
    const { connected, total } = data.sessions;

    cache = {
      pada: Date.now(),
      hasil: {
        status: connected === 0 ? 'terputus' : connected < total ? 'sebagian' : 'tertaut',
        sesi: data.sessions,
        pemakaian: data.usage,
      },
    };
  } catch (e) {
    cache = { pada: Date.now(), hasil: { status: 'tak_terjangkau', pesan: e.message } };
  } finally {
    clearTimeout(batas);
  }

  return cache.hasil;
}
```

### Python

```python
import os, time, requests

_cache = {"pada": 0, "hasil": None}
_CACHE_DETIK = 30


def status_whatsapp():
    if time.time() - _cache["pada"] < _CACHE_DETIK:
        return _cache["hasil"]

    try:
        r = requests.get(
            f"{os.environ['VEXAHOST_WA_URL']}/api/v1/health",
            headers={"X-Api-Key": os.environ["VEXAHOST_WA_KEY"]},
            timeout=(3, 8),
        )
        r.raise_for_status()
        data = r.json()["data"]
        tertaut = data["sessions"]["connected"]
        total = data["sessions"]["total"]

        hasil = {
            "status": "terputus" if tertaut == 0 else ("sebagian" if tertaut < total else "tertaut"),
            "sesi": data["sessions"],
            "pemakaian": data["usage"],
        }
    except requests.RequestException as e:
        hasil = {"status": "tak_terjangkau", "pesan": str(e)}

    _cache.update(pada=time.time(), hasil=hasil)
    return hasil
```

---

## 3. Webhook `session.status` — tahu dalam hitungan detik

Polling 30 detik berarti Anda baru tahu paling lambat 30 detik setelah nomor putus. Webhook memberi tahu seketika.

Aktifkan di **Dashboard → Webhook**, centang kejadian **Status sesi berubah** (`session.status`).

Yang kami kirim:

```json
{
  "event": "session.status",
  "timestamp": "2026-09-07T14:03:11+07:00",
  "delivery_id": "01k4v2h9c8rqf3m7...",
  "data": {
    "session_id": "01k3m8x...",
    "name": "Nomor CS",
    "status": "disconnected",
    "phone_number": "628123456789",
    "reason": "connection_lost"
  }
}
```

Nilai `status` yang mungkin: `connecting`, `qr`, `connected`, `disconnected`, `failed`.

Untuk `disconnected`, kolom `reason` menyebut apakah nomornya akan pulih sendiri (`connection_lost`, dan `replaced` yang pertama dalam sehari) atau perlu ditangani manusia (`logged_out`, `credentials_missing`, `banned`). Arti tiap nilainya ada di [Webhook](webhook). Gunakan ini untuk menentukan siapa yang perlu dibangunkan: `connection_lost` biasanya pulih dalam beberapa menit, sedangkan `logged_out` tidak akan pulih sampai seseorang men-scan QR.

### Menerimanya di Laravel

```php
Route::post('/webhook/vexahost', function (Request $request) {
    // Tanda tangan diperiksa SEBELUM apa pun dibaca dari isinya. Tanpa ini,
    // siapa pun yang menebak alamat webhook Anda bisa mengirimkan
    // "nomor Anda terputus" dan memicu peringatan palsu — atau sebaliknya,
    // menutupi gangguan yang sungguhan.
    $tandaTangan = hash_hmac('sha256', $request->getContent(), config('vexahost.webhook_secret'));

    // hash_equals, bukan ===. Perbandingan string biasa berhenti di karakter
    // pertama yang berbeda, dan selisih waktunya cukup untuk menebak tanda
    // tangan satu karakter demi satu karakter.
    abort_unless(hash_equals($tandaTangan, $request->header('X-VexaHost-Signature', '')), 403);

    if ($request->input('event') !== 'session.status') {
        return response()->noContent();
    }

    $data = $request->input('data');

    if (in_array($data['status'], ['disconnected', 'failed'], true)) {
        // Kabari tim Anda lewat jalur yang TIDAK memakai WhatsApp — email,
        // Slack, Telegram. Mengabari putusnya WhatsApp lewat WhatsApp adalah
        // kabar yang tidak akan pernah sampai.
        Notification::route('mail', config('mail.admin'))
            ->notify(new NomorWhatsAppTerputus($data['name'], $data['phone_number']));
    }

    cache()->put('wa:status_terakhir', $data, now()->addDay());

    return response()->noContent();
})->withoutMiddleware([VerifyCsrfToken::class]);
```

**Tiga hal yang harus benar**, dan ketiganya pernah menjadi sumber kegagalan nyata:

1. **Jawab cepat, kerjakan belakangan.** Kami menunggu jawaban paling lama 15 detik. Endpoint yang mengirim email lebih dulu lalu menjawab akan kehabisan waktu, dan kami menganggapnya gagal lalu mengulang — tim Anda menerima email yang sama berkali-kali. Antrekan pekerjaannya, lalu jawab `204` seketika.
2. **Kecualikan dari CSRF.** Permintaan kami tidak membawa token sesi peramban. Tanpa pengecualian, seluruh webhook dijawab 419 dan tidak ada satu pun yang tercatat gagal di sisi Anda.
3. **Jangan mengandalkan urutan.** Webhook dikirim lewat antrean dan dapat diulang. Bandingkan dengan keadaan yang Anda simpan, jangan menganggap tiap kiriman adalah perubahan baru.

---

## 4. Keadaan gateway kami sendiri

Endpoint di atas menjawab keadaan **workspace Anda**. Untuk keadaan **layanan VexaHost secara keseluruhan** — API, dashboard, engine WhatsApp, antrean — ada halaman status publik yang tidak memerlukan API key:

```http
GET https://{{legal.domain}}/status.json
```

```json
{
  "status": "operasional",
  "komponen": {
    "api": { "nama": "REST API", "keadaan": "operasional", "catatan": "Menjawab normal." },
    "whatsapp": { "nama": "Koneksi WhatsApp", "keadaan": "operasional", "catatan": "Menjawab normal." }
  },
  "insiden_berjalan": 0,
  "diperiksa_pada": "2026-09-07T14:03:11+07:00"
}
```

Nilai `status`: `operasional`, `terganggu`, atau `mati`.

Halaman untuk manusia ada di **https://{{legal.domain}}/status**, lengkap dengan riwayat 90 hari dan catatan insiden.

> **Batas yang perlu Anda perhitungkan.** `status.json` disajikan oleh sistem yang keadaannya ia laporkan. Kalau layanan kami mati total, permintaan ini tidak akan menjawab sama sekali. Jadi perlakukan **permintaan yang gagal** sebagai gangguan juga — jangan hanya membaca isi jawabannya.

---

## 5. Yang TIDAK akan terlihat dari pemantauan mana pun

Satu hal yang perlu Anda ketahui sebelum menyusun peringatan otomatis: **pemblokiran nomor Anda oleh WhatsApp tidak selalu tampak sebagai `disconnected`.** Yang terlihat sebagai `disconnected` dengan `reason: "banned"` hanya nomor yang sambungannya benar-benar ditolak; pembatasan yang lebih ringan sering tidak memutus sambungan sama sekali.

Nomor yang dibatasi Meta bisa tetap terbaca `connected` sementara pesannya tidak sampai ke penerima. Yang menandakannya bukan status sesi melainkan **lonjakan `usage.messages_failed`** pada `/api/v1/health`.

Karena itu, pasang juga peringatan untuk itu:

```php
// Bandingkan dengan pemeriksaan sebelumnya, bukan dengan angka mutlak.
// Total 40 gagal sebulan itu wajar; 40 gagal dalam sepuluh menit tidak.
$sekarang = $data['usage']['messages_failed'];
$sebelumnya = Cache::get('wa:gagal_terakhir', $sekarang);

if ($sekarang - $sebelumnya > 10) {
    // Periksa nomor Anda di dashboard VexaHost sebelum mengirim lagi.
    kabariTim('Pesan gagal melonjak — nomor mungkin dibatasi WhatsApp.');
}

Cache::put('wa:gagal_terakhir', $sekarang, now()->addDay());
```

Cara memperkecil peluang nomor dibatasi ada di [Praktik Baik](praktik-baik).

---

## Ringkasnya

| Anda ingin tahu | Pakai |
|---|---|
| Nomor saya masih tertaut? | `GET /api/v1/health` → `sessions.connected` |
| Beri tahu saya begitu putus | Webhook `session.status` |
| Kuota saya tinggal berapa? | `GET /api/v1/health` → `usage` |
| Gateway VexaHost sedang gangguan? | `GET /status.json` |
| Nomor saya dibatasi WhatsApp? | Lonjakan `usage.messages_failed` |

Lihat juga: [Referensi API](referensi-api), [Webhook](webhook), [Praktik Baik](praktik-baik).
