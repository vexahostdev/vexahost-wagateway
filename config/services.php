<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Layanan Pihak Ketiga
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
        'site_verification' => env('GOOGLE_SITE_VERIFICATION'),
    ],

    'mayar' => [
        'api_key' => env('MAYAR_API_KEY'),
        'api_url' => env('MAYAR_API_URL', 'https://api.mayar.id/hl/v2'),
        'webhook_token' => env('MAYAR_WEBHOOK_TOKEN'),
        'mode' => env('MAYAR_MODE', 'production'),
    ],

    /*
    | Akun tertaut dengan aplikasi vexahost — satu akun, dua aplikasi, HANYA
    | autentikasi (email, kata sandi, status verifikasi). Tidak ada data lain
    | yang dibagi. Protokolnya di docs/AKUN_TERTAUT.md dan harus sama persis
    | dengan salinannya di repo vexahost.
    |
    | `url` alamat dasar aplikasi vexahost di tahap yang sama; `secret` rahasia
    | HMAC yang identik di kedua aplikasi dan berbeda tiap tahap. Salah satunya
    | kosong = penautan mati di kedua arah: yang keluar tidak dikirim, yang
    | masuk ditolak 503. Mati total lebih aman daripada endpoint yang bisa
    | mengganti kata sandi siapa pun tanpa tanda tangan.
    */
    'linked_accounts' => [
        'url' => rtrim((string) env('LINKED_ACCOUNTS_URL', ''), '/'),
        'secret' => env('LINKED_ACCOUNTS_SECRET'),
        'timeout' => (int) env('LINKED_ACCOUNTS_TIMEOUT', 10),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        'internal_secret' => env('INTERNAL_WEBHOOK_SECRET', 'vexahost_internal_xnd_token_38c92a'),
    ],

];

