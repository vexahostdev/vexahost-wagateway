<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engine Node.js (whatsapp-web.js)
    |--------------------------------------------------------------------------
    |
    | Engine berjalan sebagai resource Coolify terpisah tanpa domain publik.
    | `url` memakai UUID resource Coolify, bukan localhost maupun nama tampilan
    | resource, karena Laravel dan engine ada di container berbeda.
    |
    | Dua batas waktu, sengaja berbeda jauh.
    |
    | `send_timeout` harus melampaui antrean anti-ban engine, bukan sekadar lama
    | pengiriman satu pesan. Engine menahan tiap pesan 3-8 detik dan mengantre
    | per sesi, jadi pesan keempat dalam satu giliran bisa menunggu lebih dari
    | 30 detik. Batas pendek membuat Laravel menyerah lebih awal lalu mengulang
    | job-nya — dan penerima menerima pesan yang sama tiga sampai empat kali.
    | Pengiriman berjalan di antrean, jadi menunggu lama tidak dirasakan siapa pun.
    |
    | `timeout` untuk perintah sesi (start, stop, logout, status) justru harus
    | pendek: di situ ada manusia yang menunggu tombolnya selesai. Kalau engine
    | tersendat, lebih baik halaman kembali dengan pesan galat dalam belasan
    | detik daripada menggantung semenit.
    |
    */

    'engine' => [
        'url' => env('ENGINE_URL', 'http://127.0.0.1:3100'),
        'token' => env('ENGINE_TOKEN'),
        'hmac_secret' => env('ENGINE_HMAC_SECRET'),
        'timeout' => (int) env('ENGINE_TIMEOUT', 15),
        'send_timeout' => (int) env('ENGINE_SEND_TIMEOUT', 60),
        'connect_timeout' => (int) env('ENGINE_CONNECT_TIMEOUT', 5),

        /*
        | Cermin dari `WA_MAX_SESSIONS` milik engine. Laravel tidak memakainya
        | untuk memutuskan apa pun — engine yang menolak sesi ke-N+1 — tapi
        | panel admin perlu menampilkannya berdampingan dengan jumlah sesi yang
        | sedang hidup. Tanpa angka itu di layar, batas kapasitas platform baru
        | ketahuan saat pelanggan berbayar gagal menautkan nomornya.
        |
        | Kalau nilai di sini berbeda dengan env engine, yang berlaku tetap env
        | engine; yang salah cuma angka di panel.
        */
        'max_sessions' => (int) env('WA_MAX_SESSIONS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Masa Berlaku QR
    |--------------------------------------------------------------------------
    |
    | Harus lebih panjang dari jeda terlama antar penerbitan QR oleh
    | whatsapp-web.js (sekitar 60 detik), supaya tidak pernah ada celah di mana
    | dashboard tidak punya QR untuk ditampilkan.
    |
    */

    'qr_ttl_seconds' => (int) env('QR_TTL_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Batas Bawaan Workspace
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'max_sessions' => (int) env('WORKSPACE_DEFAULT_MAX_SESSIONS', 1),
        'monthly_message_quota' => (int) env('WORKSPACE_DEFAULT_MONTHLY_QUOTA', 1000),
        'api_rate_limit_per_minute' => (int) env('WORKSPACE_DEFAULT_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jeda Antar Pesan (anti-ban)
    |--------------------------------------------------------------------------
    |
    | Engine menyisipkan jeda acak antara min dan max detik pada setiap pesan
    | keluar dalam satu sesi. Mengirim ratusan pesan tanpa jeda adalah pola
    | paling cepat membuat nomor diblokir.
    |
    */

    'throttle' => [
        'min_delay_ms' => (int) env('WA_MIN_DELAY_MS', 3000),
        'max_delay_ms' => (int) env('WA_MAX_DELAY_MS', 8000),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP
    |--------------------------------------------------------------------------
    */

    'otp' => [
        'length' => 6,
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN', 60),
        'max_per_phone_per_day' => (int) env('OTP_MAX_PER_PHONE_PER_DAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retensi Data
    |--------------------------------------------------------------------------
    */

    /*
    | Dua jenis data dengan aturan yang berbeda, dan bedanya bukan teknis.
    |
    | DATA BISNIS \u2014 riwayat pesan \u2014 adalah yang dibayar pelanggan, dan lamanya
    | mengikuti paket (`Workspace::messageRetentionDays()`, config/plans.php).
    | Tidak ada satu angka di sini yang boleh memangkasnya lebih pendek dari
    | yang dijanjikan paketnya.
    |
    | DATA TEKNIS \u2014 log kiriman webhook, catatan audit, notifikasi, sisa antrean
    | \u2014 tidak dibayar siapa pun dan tidak dibuka siapa pun setelah beberapa hari.
    | Ia dipangkas agresif, karena justru inilah yang tumbuh paling cepat: satu
    | pesan keluar menghasilkan sampai empat kejadian webhook, dan tiap kejadian
    | yang gagal menghasilkan satu baris per percobaan.
    */
    'retention' => [
        // Cadangan saja. Yang berlaku `Workspace::messageRetentionDays()` dari
        // paketnya; angka ini dipakai kalau paketnya tidak menyebut apa-apa.
        'messages_days' => (int) env('RETENTION_MESSAGES_DAYS', 90),

        // Kiriman webhook yang GAGAL: satu-satunya yang benar-benar dibuka
        // orang, dan dibukanya dalam hitungan jam setelah integrasinya rusak.
        'webhook_deliveries_days' => (int) env('RETENTION_WEBHOOK_DAYS', 7),

        // Kiriman webhook yang BERHASIL: tidak pernah dibuka siapa pun, dan
        // jumlahnya sebagian besar dari tabel itu.
        'webhook_ok_hours' => (int) env('RETENTION_WEBHOOK_OK_HOURS', 48),

        // Catatan audit menjawab \"siapa yang melakukan itu\" \u2014 pertanyaan yang
        // muncul saat ada sengketa, bukan saat ada gangguan. Setahun melampaui
        // satu siklus sengketa penuh.
        'audit_days' => (int) env('RETENTION_AUDIT_DAYS', 365),

        // Notifikasi yang SUDAH DIBACA. Yang belum dibaca tidak pernah dibuang
        // berapa pun umurnya \u2014 kabar yang hilang sebelum sempat dilihat adalah
        // persis kegagalan yang lonceng ini dibuat untuk mencegahnya.
        'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 90),

        'failed_jobs_days' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
        'job_batches_days' => (int) env('RETENTION_JOB_BATCHES_DAYS', 7),

        // Halaman status menggambar 90 hari; setahun lebih memberi ruang untuk
        // klaim kredit SLA yang diajukan belakangan.
        'status_daily_days' => (int) env('RETENTION_STATUS_DAILY_DAYS', 400),
    ],

    /*
    | Bentuk pemangkasannya, bukan lamanya.
    |
    | Tidak ada DELETE tanpa batas di mana pun. MySQL yang dipakai gateway ini
    | juga dipakai aplikasi vexahost: satu DELETE atas ratusan ribu baris menahan
    | kunci dan menggelembungkan undo log untuk SELURUH aplikasi di server itu,
    | dan gejalanya muncul di tempat yang tidak ada hubungannya dengan kita.
    */
    'pemangkasan' => [
        'potongan' => (int) env('PANGKAS_POTONGAN', 1000),

        // Jeda antar potongan, memberi ruang bernapas untuk kueri lain.
        'jeda_ms' => (int) env('PANGKAS_JEDA_MS', 200),

        // Batas atas baris per tabel per jalan. Sisanya diambil jalan
        // berikutnya. Pemangkasan yang berjalan berjam-jam adalah pemangkasan
        // yang bertabrakan dengan jam sibuk.
        'batas_per_tabel' => (int) env('PANGKAS_BATAS_PER_TABEL', 200000),
    ],

];
