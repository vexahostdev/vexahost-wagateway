<?php

/*
|--------------------------------------------------------------------------
| Penagihan
|--------------------------------------------------------------------------
|
| Saluran pembayaran yang aktif sekarang hanya satu: QRIS dinamis dengan
| konfirmasi manual. Pembayaran tidak memberi tahu kami secara otomatis, jadi
| pencocokannya bersandar pada dua hal — nominal yang dibuat unik lewat kode
| tiga digit, dan bukti transfer yang diunggah pelanggan.
|
| Saat akun iPaymu terverifikasi, saluran kedua masuk sebagai satu kelas baru
| di `app/Services/Billing/` plus satu route webhook. Tidak ada satu pun bagian
| langganan, invoice, atau penegakan batas yang perlu berubah — itulah sebabnya
| `invoices.channel` mencatat saluran, bukan menawarkannya sebagai pilihan.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | QRIS
    |--------------------------------------------------------------------------
    |
    | `payload` adalah isi QRIS STATIS merchant, yaitu string panjang berawalan
    | `00020101021126...` yang tercetak di balik kode QR dari penyedia QRIS.
    | Aplikasi menyisipkan nominal ke dalamnya untuk membuat QRIS dinamis, jadi
    | pelanggan tidak perlu mengetik jumlah dan tidak bisa salah ketik.
    |
    | Pakai QRIS MERCHANT, bukan QRIS akun pribadi: akun pribadi punya batas
    | nominal bulanan dan bisa dibekukan bank begitu polanya terbaca komersial.
    |
    | Selama `payload` kosong, halaman pembayaran menampilkan instruksi transfer
    | bank dan menyembunyikan kode QR — lebih baik daripada memunculkan kode
    | rusak yang gagal discan di depan pelanggan yang sedang mau membayar.
    |
    | Di env, nilainya HARUS dikutip. Payload QRIS memuat nama kota merchant
    | (tag 60) yang hampir selalu mengandung spasi, dan dotenv menolak seluruh
    | berkas begitu menemukan spasi tanpa kutip — aplikasi mati total, bukan
    | cuma kehilangan QRIS-nya. Spasi di dalamnya, termasuk yang di ujung nama
    | kota, ikut dihitung panjang tag: satu saja terpangkas, CRC tidak cocok
    | lagi dan `Qris::valid()` menolak payload yang sebenarnya benar.
    |
    */

    'qris' => [
        'payload' => env('QRIS_PAYLOAD'),
        'merchant' => env('QRIS_MERCHANT_NAME', 'VexaHost'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rekening bank (cadangan, dan untuk nominal besar)
    |--------------------------------------------------------------------------
    |
    | Batas QRIS per transaksi mengikuti kebijakan tiap penerbit dompet digital
    | dan bisa berhenti di Rp 5 juta — sementara paket tahunan Elite mendekati
    | angka itu. Transfer bank karena itu selalu ditampilkan berdampingan,
    | bukan disembunyikan sebagai jalur darurat.
    |
    */

    'bank' => [
        'name' => env('BILLING_BANK_NAME'),
        'account_number' => env('BILLING_BANK_ACCOUNT'),
        'account_holder' => env('BILLING_BANK_HOLDER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kode unik
    |--------------------------------------------------------------------------
    |
    | Ditambahkan ke nominal setiap tagihan supaya dua pelanggan yang membayar
    | paket sama di hari sama tidak menghasilkan mutasi yang identik. Tanpa ini
    | tidak ada cara mencocokkan pembayaran dengan tagihan selain menebak.
    |
    | Kode dijamin unik di antara tagihan yang masih menunggu bayar, bukan unik
    | selamanya — dua tagihan yang sudah lunas boleh memakai kode yang sama.
    |
    */

    'unique_code' => [
        'enabled' => (bool) env('BILLING_UNIQUE_CODE', true),
        'min' => 1,
        'max' => 999,
    ],

    /*
    |--------------------------------------------------------------------------
    | Irama siklus
    |--------------------------------------------------------------------------
    |
    | `issue_days_before` sengaja lebih dari nol: VA dan gerai ritel butuh waktu,
    | dan tagihan yang baru terbit di hari jatuh tempo berarti setiap pelanggan
    | mengalami layanan berhenti minimal sekali.
    |
    | `grace_days` adalah jarak antara "pengiriman berhenti" dan "nomor dilepas".
    |
    | Dulu 30 hari, dengan alasan yang ternyata keliru: dikira melepas sesi
    | memaksa scan QR ulang saat pelanggan kembali. Tidak — `suspend()` memakai
    | `disconnect()`, bukan `logout()`, jadi kredensialnya tetap tersimpan di
    | store Laravel dan nomor yang sama tersambung sendiri begitu tagihan lunas.
    | Yang benar-benar hilang saat dilepas cuma otomatisasinya; WhatsApp-nya
    | sendiri tetap hidup di ponsel pelanggan, karena kita perangkat tertaut,
    | bukan pemilik akunnya.
    |
    | Sejak pesan masuk ikut ditutup saat langganan mati (6 Sep 2026), jendela
    | ini nyaris tidak memberi apa-apa lagi kepada pelanggan: pengiriman mati,
    | penerimaan mati, webhook mati. Yang tersisa cuma sesi yang menyala tanpa
    | melayani apa pun — sambil memakan satu dari tiga slot untuk SELURUH
    | pelanggan. Karena itu 3 hari, bukan 14 apalagi 30.
    |
    | Tidak nol, dan alasannya bukan kemurahan hati: melepas lalu menyambungkan
    | lagi adalah operasi nyata yang bisa gagal (engine memulihkan kredensial
    | dari store). Tiga hari membuat pelanggan yang membayar cepat — dan
    | pembayarannya masih diperiksa manusia — tidak perlu melewati siklus itu
    | sama sekali.
    |
    */

    'invoice_due_days' => (int) env('BILLING_INVOICE_DUE_DAYS', 7),
    'issue_days_before' => (int) env('BILLING_ISSUE_DAYS_BEFORE', 3),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
    'reminder_days' => [7, 3, 1, 0],

    /*
    |--------------------------------------------------------------------------
    | Pengingat lewat WhatsApp
    |--------------------------------------------------------------------------
    |
    | Diisi id workspace milik VexaHost sendiri yang nomornya sudah tertaut.
    | Kosong = pengingat cukup lewat spanduk di dashboard. Kegagalan mengirim
    | pengingat tidak pernah boleh menjatuhkan siklus penagihan; ia pelengkap.
    |
    */

    'notify_workspace_id' => env('BILLING_NOTIFY_WORKSPACE_ID'),

    /*
    | Nomor WhatsApp tim kami sendiri. Ke sinilah pemberitahuan yang butuh
    | tindakan manusia dikirim — terutama "ada bukti pembayaran baru masuk",
    | karena selama pembayaran dicocokkan manual, tagihan hanya menjadi lunas
    | kalau ada orang yang membukanya di panel.
    */
    'admin_phone' => env('BILLING_ADMIN_PHONE', '085808749131'),

    /*
    | Biaya administrasi & penanganan pencairan komisi reseller (persen).
    | Standar pemotongan: 5% dari total nominal komisi yang dicairkan.
    */
    'payout_fee_percent' => (int) env('MITRA_PAYOUT_FEE_PERCENT', 5),

    /*
    | Alamat yang dipakai pelanggan saat butuh manusia — termasuk saat lupa
    | kata sandi, karena pemulihan mandiri sengaja belum dibuat.
    */
    'support_email' => env('BILLING_SUPPORT_EMAIL', env('MAIL_SUPPORT_ADDRESS', 'support@vexahostcloud.my.id')),

    /*
    |--------------------------------------------------------------------------
    | Pay as you go
    |--------------------------------------------------------------------------
    |
    | Prepaid, dan itu keputusan yang menempel pada kenyataan bahwa pembayaran
    | di sistem ini masih dicocokkan manusia lewat QRIS. Postpaid berarti
    | pelanggan mengirim ribuan pesan lalu menghilang — dan pesannya sudah
    | telanjur terkirim, uangnya tidak bisa ditarik kembali.
    |
    | Rp 200 per pesan jauh di atas harga per pesan paket termurah (Essentials
    | Rp 74,50), jadi PAYG tidak menggerus penjualan paket. Titik impasnya 745
    | pesan per bulan.
    |
    | Minimum isi saldo Rp 50.000 (250 pesan): di bawah itu ongkos verifikasi
    | manualnya lebih besar dari nilainya.
    |
    | Rupiah penuh, bilangan bulat. Jangan pernah float untuk uang.
    |
    */

    'payg' => [
        'price_per_message' => (int) env('PAYG_PRICE_PER_MESSAGE', 200),
        'min_topup' => (int) env('PAYG_MIN_TOPUP', 50_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enterprise — komponen harga untuk kalkulator perkiraan
    |--------------------------------------------------------------------------
    |
    | Angka di sini dipakai halaman /enterprise untuk menyusun perkiraan yang
    | bisa dihitung sendiri pengunjung. Ia PERKIRAAN, bukan penawaran: yang
    | mengikat tetap kesepakatan yang disusun admin di panel, dan halaman itu
    | menyebutkannya apa adanya.
    |
    | Kenapa nomor yang paling mahal: biaya nyata kami per nomor adalah satu
    | sesi engine tersendiri, dan RAM serta slot itu diperebutkan dengan PHP,
    | worker, MySQL, dan aplikasi lain di server yang sama. Pesan
    | jauh lebih murah — yang dibayar cuma waktu proses.
    |
    | `base` sengaja di atas harga Elite (449.000). Enterprise yang lebih murah
    | dari paket tertinggi akan menarik orang yang sebenarnya cocok di Elite,
    | dan tiap orang seperti itu menghabiskan waktu negosiasi untuk pendapatan
    | yang lebih kecil.
    |
    */

    'enterprise' => [
        /*
         | Nomor untuk tombol chat di halaman Enterprise — SENGAJA bukan
         | `admin_phone` di atas, walaupun keduanya sama-sama nomor kami.
         |
         | `admin_phone` itu kotak masuk pribadi tim: arah masuk, dari gateway
         | ke kami — bukti bayar, lead baru, peringatan sistem. Memasangnya di
         | halaman publik berarti nomor itu ikut ter-index dan orang asing
         | mulai mengirim ke sana, sehingga kabar yang menuntut tindakan
         | tenggelam di antara pertanyaan calon pelanggan. Dan tidak bisa
         | ditarik lagi setelah halamannya terlanjur tersebar.
         |
         | Kosong berarti tombolnya TIDAK dirender — bukan menuju wa.me tanpa
         | nomor, yang membuka WhatsApp ke layar galat. Formulirnya tetap ada,
         | jadi halaman ini tidak pernah kehilangan satu-satunya cara menghubungi.
        */
        // Nomor ini juga yang dipakai setiap tautan "Hubungi admin" yang dilihat
        // pelanggan (tagihan, verifikasi pembayaran, footer) lewat
        // App\Support\KontakWhatsApp — satu nomor publik, satu tempat.
        'whatsapp' => env('ENTERPRISE_WHATSAPP', '6285808749131'),

        // Sudah termasuk: 1 nomor, 50.000 pesan/bln, retensi 12 bulan,
        // API 300/menit, API key dan anggota tanpa batas.
        'base' => (int) env('ENTERPRISE_BASE_PRICE', 750_000),

        'per_session' => (int) env('ENTERPRISE_PER_SESSION', 250_000),
        'included_sessions' => 1,

        'per_message_block' => (int) env('ENTERPRISE_PER_MESSAGE_BLOCK', 150_000),
        'message_block_size' => 50_000,
        'included_messages' => 50_000,

        'retention_24_months' => (int) env('ENTERPRISE_RETENTION_24', 200_000),
        'api_600_per_minute' => (int) env('ENTERPRISE_API_600', 300_000),

        // Sekali bayar, bukan bulanan — dan itu harus terbaca jelas di layar,
        // kalau tidak totalnya salah dibaca sebagai biaya tetap tiap bulan.
        'onboarding' => (int) env('ENTERPRISE_ONBOARDING', 500_000),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pajak
    |--------------------------------------------------------------------------
    |
    | 0 berarti harga yang dipajang sudah final dan tidak ada baris pajak di
    | tagihan. Begitu diisi (mis. 11), harga paket diperlakukan sebagai harga
    | SEBELUM pajak dan pajaknya muncul sebagai baris tersendiri — jadi jangan
    | mengisinya tanpa memutuskan lebih dulu apakah harga yang dipajang di
    | halaman depan sudah termasuk pajak atau belum.
    |
    */

    'tax_percent' => (float) env('BILLING_TAX_PERCENT', 0),

];
