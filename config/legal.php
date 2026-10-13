<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identitas hukum
    |--------------------------------------------------------------------------
    |
    | Satu-satunya tempat identitas badan usaha ditulis. Keenam dokumen hukum di
    | `resources/docs/` memakai penanda `{{legal.*}}` yang diganti isinya saat
    | halaman dirender, BUKAN menyalin namanya ke dalam teks.
    |
    | Alasannya bukan kerapian. Dokumen hukum yang menyebut nama badan usaha
    | berbeda-beda di enam berkas adalah dokumen yang salah satunya pasti
    | ketinggalan saat badan usahanya berganti nama atau naik bentuk — dan yang
    | menemukan ketidakcocokannya adalah lawan Anda dalam sengketa, yang memakai
    | itu untuk menyanggah bahwa perjanjiannya pernah mengikat.
    |
    | Nilai yang belum diisi TIDAK dibiarkan kosong. Ia dirender sebagai
    | `[BELUM DIISI: ...]` yang mencolok di halaman publik, karena dokumen hukum
    | dengan bagian kosong yang tidak kentara adalah dokumen yang tayang
    | bertahun-tahun tanpa ada yang sadar ia tidak lengkap.
    |
    */

    'entity' => [
        // Bentuk badan usaha. Ryan (7 Sep 2026): PT Perseorangan — bentuk untuk
        // UMKM sejak UU Cipta Kerja, didirikan satu orang tanpa akta notaris.
        // Penting untuk klausul tanggung jawab: harta perseroan sudah terpisah
        // dari harta pribadi pendirinya, TIDAK seperti usaha perorangan biasa.
        'form' => env('LEGAL_ENTITY_FORM', 'PT Perseorangan'),

        // Nama lengkap sesuai SK Kemenkumham, contoh: "PT Destinara Chakrawala Artha".
        'name' => env('LEGAL_ENTITY_NAME', 'PT DESTINARA CHAKRAWALA ARTHA'),

        // Alamat terdaftar. Ditulis lengkap sampai kode pos — ini alamat resmi
        // untuk surat-menyurat hukum, dan alamat yang tidak bisa dijangkau
        // membuat pemberitahuan pemutusan kontrak tidak sah.
        'address' => env('LEGAL_ENTITY_ADDRESS'),

        // Nomor Induk Berusaha dari OSS.
        'nib' => env('LEGAL_ENTITY_NIB'),

        'npwp' => env('LEGAL_ENTITY_NPWP'),
    ],

    /*
    | Nama dagang. Berbeda dari `entity.name` dengan sengaja: pelanggan mengenal
    | "VexaHost", pengadilan mengenal nama PT-nya, dan dokumen yang baik menyebut
    | keduanya sekali di awal lalu memakai yang pendek seterusnya.
    */
    'brand' => env('LEGAL_BRAND', 'VexaHost'),

    'product' => env('LEGAL_PRODUCT', 'VexaHost WA Gateway'),

    'domain' => env('LEGAL_DOMAIN', 'wa.vexahostcloud.my.id'),

    /*
    | Penyedia server tempat data pelanggan benar-benar berada. Wajib disebut
    | namanya di DPA: pelanggan korporat perlu tahu siapa lagi yang secara teknis
    | bisa menyentuh datanya, dan "server kami" bukan jawaban yang bisa diaudit.
    */
    'hosting' => env('LEGAL_HOSTING_PROVIDER'),

    'hosting_region' => env('LEGAL_HOSTING_REGION', 'Singapura'),

    'contact' => [
        'email' => env('LEGAL_CONTACT_EMAIL', env('BILLING_SUPPORT_EMAIL', env('MAIL_SUPPORT_ADDRESS', 'support@vexahostcloud.my.id'))),

        // Alamat khusus urusan data pribadi. Dipisah dari email dukungan karena
        // UU PDP memberi tenggat 3×24 jam untuk permintaan subjek data, dan
        // permintaan seperti itu tidak boleh antre di belakang pertanyaan teknis.
        'privacy_email' => env('LEGAL_PRIVACY_EMAIL', env('BILLING_SUPPORT_EMAIL', env('MAIL_SUPPORT_ADDRESS', 'support@vexahostcloud.my.id'))),
    ],

    /*
    | Penyelesaian sengketa. Ryan (7 Sep 2026): musyawarah dulu, lalu BANI.
    |
    | Konsekuensi yang harus tetap tertulis di dokumennya: arbitrase BANI
    | bersifat final dan mengikat — para pihak melepaskan hak banding. Pelanggan
    | berhak tahu itu sebelum menyetujui, bukan sesudah bersengketa.
    */
    'dispute' => [
        'forum' => env('LEGAL_DISPUTE_FORUM', 'Badan Arbitrase Nasional Indonesia (BANI)'),
        'negotiation_days' => 30,
        'seat' => env('LEGAL_DISPUTE_SEAT', 'Jakarta'),
    ],

    /*
    | Tanggal berlaku dokumen. Diubah manual setiap kali isinya berubah secara
    | material — bukan otomatis dari tanggal deploy, karena tanggal yang bergeser
    | sendiri tiap rilis membuat pelanggan tidak bisa membuktikan syarat mana
    | yang berlaku saat mereka menyetujuinya.
    */
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '7 September 2026'),

    'version' => env('LEGAL_VERSION', '1.0'),

    /*
    |--------------------------------------------------------------------------
    | Janji layanan yang bisa ditepati hari ini
    |--------------------------------------------------------------------------
    |
    | Angka-angka ini masuk ke dokumen SLA apa adanya. Sengaja rendah, dan itu
    | keputusan sadar: satu container tanpa cadangan, kapasitas tiga sesi
    | bersama, dan ketergantungan pada WhatsApp yang bukan milik kami. SLA
    | 99,9% berarti jatah mati 43 menit sebulan — kurang dari satu deploy yang
    | bermasalah. Janji yang dilanggar bulan pertama lebih merusak kepercayaan
    | daripada janji yang sederhana tapi ditepati, dan ia mengubah gangguan
    | biasa menjadi wanprestasi.
    |
    | Naikkan angkanya setelah ada redundansi nyata, bukan sebelumnya.
    |
    */

    'sla' => [
        'uptime_percent' => env('LEGAL_SLA_UPTIME', '99.0'),

        // Jam kerja penanganan, bukan 24/7. Menjanjikan 24/7 dengan satu orang
        // berarti menjanjikan sesuatu yang tidak ada saat orang itu tidur.
        'support_hours' => 'Senin–Jumat, 09.00–17.00 WIB, di luar hari libur nasional',

        'response_hours' => [
            'kritis' => 4,
            'tinggi' => 8,
            'normal' => 24,
        ],

        // Kompensasi berupa perpanjangan masa langganan, BUKAN uang kembali.
        // Pengembalian tunai atas gangguan membuka pintu klaim yang nilainya
        // bisa jauh melampaui langganan itu sendiri.
        'credit_tiers' => [
            ['below' => 99.0, 'credit_percent' => 10],
            ['below' => 95.0, 'credit_percent' => 25],
            ['below' => 90.0, 'credit_percent' => 50],
        ],

        'claim_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retensi & penghapusan
    |--------------------------------------------------------------------------
    */

    'retention' => [
        // Jeda sebelum akun yang dihapus benar-benar hilang. Bukan basa-basi:
        // penghapusan akun adalah satu-satunya tindakan di produk ini yang tidak
        // bisa dibatalkan sama sekali, dan sebagian besar permintaan hapus yang
        // disesali terjadi dalam hitungan jam.
        'account_grace_days' => 14,

        // Data penagihan wajib disimpan lebih lama dari sisanya — UU Perpajakan
        // menuntut dokumen pembukuan disimpan 10 tahun. Menghapusnya atas
        // permintaan pelanggan berarti melanggar kewajiban yang berbeda, dan
        // dokumen ini harus menyebut pengecualian itu secara terbuka.
        'billing_years' => 10,

        'audit_log_days' => 365,
    ],

];
