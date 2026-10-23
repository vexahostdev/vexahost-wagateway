<?php

namespace App\Models;

use App\Support\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'owner_email',
        'billing_phone',
        'billing_name',
        'billing_email',
        'billing_type',
        'billing_company',
        'billing_bank_name',
        'billing_bank_account',
        'billing_bank_holder',
        'billing_province',
        'billing_city',
        'billing_district',
        'billing_address',
        'billing_postal_code',
        'status',
        'service_until',
        'plan_slug',
        'billing_mode',
        'balance',
        'max_sessions',
        'monthly_message_quota',
        'api_rate_limit_per_minute',
        'is_internal',
    ];

    /**
     * Memeriksa apakah data penagihan pelanggan sudah terisi lengkap.
     */
    public function isBillingComplete(): bool
    {
        $basic = filled($this->billing_name)
            && filled($this->billing_email)
            && filled($this->billing_phone)
            && filled($this->billing_bank_name)
            && filled($this->billing_bank_account)
            && filled($this->billing_bank_holder)
            && filled($this->billing_province)
            && filled($this->billing_city)
            && filled($this->billing_district)
            && filled($this->billing_address);

        if (! $basic) {
            return false;
        }

        if ($this->billing_type === 'badan') {
            return filled($this->billing_company);
        }

        return true;
    }

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'is_internal' => 'boolean',
            'partner_since' => 'datetime',
            'service_until' => 'datetime',
            'max_sessions' => 'integer',
            'monthly_message_quota' => 'integer',
            'api_rate_limit_per_minute' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WaSession::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function optOuts(): HasMany
    {
        return $this->hasMany(OptOut::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Paket yang sedang berlaku.
     *
     * Workspace tanpa langganan — internal VexaHost, atau baris yang terbuat
     * sebelum penagihan dinyalakan — jatuh ke paket bawaan. Itu membuat setiap
     * pemanggil bisa menganggap paket selalu ada, sehingga tidak ada satu pun
     * tempat di antarmuka yang perlu menangani "workspace tanpa paket".
     */
    public function plan(): Plan
    {
        return Plan::find($this->subscription?->plan_slug ?? $this->plan_slug);
    }

    /**
     * Berapa hari riwayat pesan disimpan untuk workspace ini.
     *
     * Workspace internal memakai angka retensi global: ia tidak berlangganan,
     * jadi tidak ada paket yang bisa menjawabnya.
     */
    public function messageRetentionDays(): int
    {
        return $this->isExempt()
            ? (int) config('gateway.retention.messages_days')
            : $this->plan()->messageRetentionDays();
    }

    /**
     * Layanannya benar-benar berjalan sekarang.
     *
     * Dua syarat, bukan satu. Kolom `status` diubah oleh `BillingCycleJob`
     * pukul 08:00, sementara masa berlaku habis pukul 23:59:59 — tanpa
     * pemeriksaan tanggal di sini, ada delapan jam tiap periode ketika
     * langganan sudah habis tapi kolomnya belum sempat berubah, dan seluruh
     * penegakan (pengiriman lewat dashboard maupun REST API) meloloskannya.
     *
     * `service_until` kosong berarti tidak ada tanggal berakhir sama sekali —
     * keadaan paket coba gratis, yang dibatasi jumlah pesan, bukan waktu.
     */
    public function isActive(): bool
    {
        // Workspace yang dibebaskan tidak pernah mati karena tagihan.
        if ($this->isExempt()) {
            return true;
        }

        if ($this->status !== 'active') {
            return false;
        }

        return $this->service_until === null || $this->service_until->isFuture();
    }

    /** Masa berlakunya lewat, apa pun yang tertulis di kolom status. */
    public function serviceExpired(): bool
    {
        return $this->service_until !== null && $this->service_until->isPast();
    }

    /** Workspace ini sedang memakai jatah coba gratis. */
    public function isFreeTier(): bool
    {
        return $this->plan_slug === config('plans.free');
    }

    /**
     * Workspace ini membayar per pesan, bukan per bulan.
     *
     * Yang membatasinya saldo, bukan kuota dan bukan tanggal — jadi
     * `current_period_end` dan `service_until` keduanya `null`, dan seluruh
     * kode yang membaca tanggal itu harus tahan `null`. Paket coba gratis
     * sudah punya masalah yang sama dan sudah diperbaiki; polanya sama.
     */
    public function isPayg(): bool
    {
        return $this->billing_mode === 'payg';
    }

    /** Berapa pesan lagi yang bisa dikirim dengan saldo yang tersisa. */
    public function sisaPesanPayg(): int
    {
        $harga = (int) config('billing.payg.price_per_message');

        return $harga > 0 ? max(0, intdiv((int) $this->balance, $harga)) : 0;
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(BalanceTransaction::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Workspace ini belum pernah membayar apa pun, jadi berhak harga perkenalan.
     *
     * Diperiksa dari TAGIHAN LUNAS, bukan dari status langganan: workspace bisa
     * saja pernah aktif tanpa pernah membayar (paket coba gratis, akun yang
     * dibebaskan, atau langganan yang diberikan admin), dan tidak satu pun dari
     * itu berarti mereka sudah pernah menjadi pelanggan berbayar.
     *
     * Aturan yang sama persis dipakai kode referal, dan itu disengaja: keduanya
     * potongan "sekali seumur workspace", dan dua definisi "pertama" yang
     * berbeda akan menghasilkan tagihan yang salah satu potongannya berlaku
     * sementara yang lain tidak — tanpa ada yang bisa menjelaskan kenapa.
     */
    public function belumPernahBayar(): bool
    {
        return ! $this->invoices()->where('status', 'paid')->exists();
    }

    /**
     * Harga perkenalan hanya untuk yang belum pernah membayar DAN paketnya
     * memang punya promo.
     */
    public function berhakHargaPerkenalan(string $planSlug, string $period): bool
    {
        if (! Plan::exists($planSlug)) {
            return false;
        }

        return Plan::get($planSlug)->hasIntro($period) && $this->belumPernahBayar();
    }

    /**
     * Pesan keluar sepanjang umur workspace, dijumlahkan dari agregat bulanan.
     *
     * Jatah coba gratis dihitung seumur hidup, bukan per bulan: kalau ia
     * memakai `currentUsage()` seperti kuota paket berbayar, angkanya kembali
     * penuh tiap tanggal 1 dan lima pesan gratis berubah menjadi lima pesan
     * gratis setiap bulan, selamanya.
     *
     * Dihitung dari `usage_counters` — bukan dari tabel `messages` — karena
     * baris pesan dipangkas mengikuti retensi paket, dan jatah yang sudah
     * terpakai tidak boleh ikut terhapus bersamanya.
     */
    public function freeMessagesUsed(): int
    {
        return (int) $this->usageCounters()->sum('messages_sent');
    }

    /**
     * Slug yang belum terpakai, termasuk oleh workspace yang sudah dihapus.
     *
     * `withTrashed()` bukan kehati-hatian berlebih: kolom `slug` unik tanpa
     * memandang `deleted_at`, jadi memakai ulang slug milik workspace terhapus
     * gagal dengan galat 1062 yang tidak menyebut slug sama sekali.
     *
     * Aturannya ditaruh di sini, bukan disalin ke tiap pemanggil, karena dua
     * salinan yang berbeda sedikit saja akan menghasilkan galat itu di satu
     * jalur pendaftaran tapi tidak di jalur lainnya.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;

        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Pemakaian bulan berjalan. Dibuat lazily supaya workspace baru tidak perlu
     * baris counter sampai benar-benar mengirim pesan pertamanya.
     *
     * Nilai awal ditulis eksplisit, bukan diserahkan ke default kolom di
     * database: baris yang baru saja dibuat tidak membaca ulang nilai default
     * dari database, sehingga counter-nya akan terbaca null — membuat API
     * melaporkan null alih-alih 0, dan membuat perbandingan kuota bergantung
     * pada perilaku PHP saat membandingkan null dengan angka.
     */
    public function currentUsage(): UsageCounter
    {
        return $this->usageCounters()->firstOrCreate(
            ['period' => now()->format('Y-m')],
            ['messages_sent' => 0, 'messages_received' => 0, 'messages_failed' => 0],
        );
    }

    public function hasQuotaRemaining(): bool
    {
        if ($this->isExempt() || $this->monthly_message_quota === 0) {
            return true;
        }

        return $this->currentUsage()->messages_sent < $this->monthly_message_quota;
    }

    /**
     * Bebas dari penagihan sepenuhnya.
     *
     * Dua sumbernya sengaja dipisah. `is_internal` menandai workspace tertentu
     * — dipasang per workspace dari panel admin. `users.is_exempt` menandai
     * ORANGNYA, sehingga workspace yang ia buat besok ikut bebas tanpa ada yang
     * perlu ingat menandainya lagi; itu yang dibutuhkan untuk akun perusahaan
     * sendiri, yang workspace-nya bertambah seiring waktu.
     *
     * Dipakai di semua tempat yang dulu memeriksa `is_internal` langsung.
     * Menambah pemeriksaan baru yang cuma membaca `is_internal` berarti
     * pengecualian yang berlaku di satu tempat tapi tidak di tempat lain — dan
     * pemiliknya baru tahu dari kegagalan, bukan dari halaman mana pun.
     */
    public function isExempt(): bool
    {
        return (bool) $this->is_internal || (bool) $this->owner?->is_exempt;
    }

    /**
     * Paketnya diberikan admin tanpa tagihan (rekanan).
     *
     * Bukan pengecualian: batas paket dan tanggal berakhirnya tetap berlaku
     * penuh. Yang berbeda cuma dua hal — tagihan perpanjangan tidak diterbitkan
     * otomatis, dan perpanjangannya dilakukan admin dari halaman Rekanan.
     */
    public function isPartner(): bool
    {
        return $this->partner_since !== null;
    }

    /**
     * Nomor istimewa milik perusahaan tidak menghitung jatah sesi siapa pun.
     *
     * Karena itu jumlah yang dibandingkan bukan seluruh sesi, melainkan sesi
     * yang benar-benar dibayar. Tanpa ini, menautkan nomor perusahaan di
     * workspace pelanggan akan memakan jatah nomor yang sudah mereka bayar.
     */
    public function canAddSession(): bool
    {
        if ($this->isExempt()) {
            return true;
        }

        return $this->sessionsTerhitung() < $this->max_sessions;
    }

    /** Jumlah sesi yang menghitung jatah paket — nomor istimewa tidak ikut. */
    public function sessionsTerhitung(): int
    {
        $istimewa = SpecialNumber::daftar();

        if ($istimewa === []) {
            return $this->sessions()->count();
        }

        return $this->sessions()
            ->where(function ($q) use ($istimewa): void {
                $q->whereNull('phone_number')->orWhereNotIn('phone_number', $istimewa);
            })
            ->count();
    }

    /**
     * Batas jumlah API key dan anggota tim dibaca langsung dari paket, bukan
     * dari kolom di tabel ini seperti sesi dan kuota pesan.
     *
     * Bedanya disengaja. Kolom `max_sessions` dan `monthly_message_quota`
     * dipakai juga sebagai kelonggaran manual — admin sesekali perlu menaikkan
     * satu workspace tanpa memindahkannya ke paket lain. Untuk API key dan
     * anggota tim kebutuhan itu tidak pernah muncul, dan menambah dua kolom
     * hanya untuk kesetangkupan berarti dua nilai lagi yang bisa menyimpang
     * dari paket tanpa ada yang menyadarinya.
     */
    public function canAddApiKey(): bool
    {
        $batas = $this->plan()->maxApiKeys();

        if ($this->isExempt() || $batas === 0) {
            return true;
        }

        return $this->apiKeys()->whereNull('revoked_at')->count() < $batas;
    }

    public function canAddMember(): bool
    {
        $batas = $this->plan()->maxMembers();

        if ($this->isExempt() || $batas === 0) {
            return true;
        }

        return $this->members()->count() < $batas;
    }
}
