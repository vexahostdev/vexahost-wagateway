@extends('layouts.admin')
@section('title', 'Metode Bayar & Paket')

@section('content')
<div class="space-y-6" x-data="{ 
    tab: '{{ request('tab', 'qris_bank') }}',
    modalTambahBank: false,
    modalEditBank: false,
    editBankData: { id: '', bank_name: '', account_number: '', account_holder: '', type: 'bank', instructions: '', is_active: true, sort_order: 0 }
}">
    {{-- Header Halaman --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl">
                Metode Bayar &amp; Paket
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Kelola QRIS, rekening bank, payment gateway, dan harga paket langsung dari dashboard tanpa menyentuh .env.
            </p>
        </div>

        {{-- Navigasi Tab --}}
        <div class="flex rounded-xl border border-border bg-muted/40 p-1 select-none">
            <button type="button" @click="tab = 'qris_bank'" 
                    class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition"
                    :class="tab === 'qris_bank' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'">
                QRIS &amp; Rekening / VA
            </button>
            <button type="button" @click="tab = 'gateways'" 
                    class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition"
                    :class="tab === 'gateways' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'">
                Payment Gateways
            </button>
            <button type="button" @click="tab = 'harga'" 
                    class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition"
                    :class="tab === 'harga' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground'">
                Harga Paket
            </button>
        </div>
    </div>

    {{-- =========================================================================
         TAB 1: QRIS & REKENING BANK / VA
         ========================================================================= --}}
    <div x-show="tab === 'qris_bank'" class="space-y-6">
        {{-- Bagian QRIS Dinamis --}}
        <div class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/80 pb-4">
                <div>
                    <h2 class="text-base font-bold text-foreground sm:text-lg">Konfigurasi QRIS Dinamis</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Payload QRIS statis merchant akan dimodifikasi secara dinamis per tagihan dengan nominal yang pas.
                    </p>
                </div>
                @if ($qrisValid)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                        Payload Valid &amp; Aktif
                    </span>
                @elseif (filled($qrisPayload))
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-500/10 px-3 py-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Format / CRC Tidak Valid
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-semibold text-muted-foreground">
                        Belum Dikonfigurasi
                    </span>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.payment-settings.qris') }}" class="mt-5 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="payload" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                            Payload QRIS Merchant (EMVCo String)
                        </label>
                        <textarea id="payload" name="payload" rows="3"
                                  placeholder="00020101021126670016ID.CO.QRIS.WWW..."
                                  class="w-full rounded-xl border border-input bg-background px-3.5 py-2.5 font-mono text-xs text-foreground transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">{{ old('payload', $qrisPayload) }}</textarea>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Salin teks QRIS statis utuh dari penyedia Anda. Sistem akan memeriksa keabsahan CRC tag 63 secara otomatis sebelum disimpan.
                        </p>
                    </div>

                    <div>
                        <label for="merchant" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                            Nama Merchant Tampilan
                        </label>
                        <input id="merchant" name="merchant" type="text" maxlength="100"
                               value="{{ old('merchant', $qrisMerchant) }}"
                               placeholder="VexaHost WA Gateway"
                               class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-xs font-semibold text-primary-foreground shadow-xs transition hover:opacity-90 active:scale-95">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <span>Simpan Pengaturan QRIS</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- Bagian Daftar Rekening Bank & Virtual Account --}}
        <div class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-border/80 pb-4">
                <div>
                    <h2 class="text-base font-bold text-foreground sm:text-lg">Rekening Bank &amp; Virtual Account</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Rekening bank atau Virtual Account yang aktif di sini akan <strong>pasti muncul di halaman checkout tagihan</strong> pelanggan.
                    </p>
                </div>
                <button type="button" @click="modalTambahBank = true"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground shadow-xs transition hover:opacity-90 active:scale-95">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span>Tambah Rekening / VA</span>
                </button>
            </div>

            @if ($bankAccounts->isEmpty())
                <div class="my-6 rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center text-xs text-muted-foreground">
                    <svg class="mx-auto h-8 w-8 text-muted-foreground/60 mb-2" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
                    </svg>
                    <p class="font-medium text-foreground">Belum ada rekening bank yang tersimpan di database</p>
                    <p class="mt-0.5">
                        Klik tombol &quot;Tambah Rekening / VA&quot; di atas untuk menambahkan rekening bank atau Virtual Account tujuan transfer.
                    </p>
                </div>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-xs text-foreground">
                        <thead class="border-b border-border text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            <tr>
                                <th class="py-3 px-3">Bank / Provider</th>
                                <th class="py-3 px-3">Nomor Rekening / VA</th>
                                <th class="py-3 px-3">Atas Nama</th>
                                <th class="py-3 px-3">Tipe</th>
                                <th class="py-3 px-3">Status</th>
                                <th class="py-3 px-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/60">
                            @foreach ($bankAccounts as $b)
                                <tr class="transition hover:bg-muted/30">
                                    <td class="py-3 px-3 font-semibold">{{ $b->bank_name }}</td>
                                    <td class="py-3 px-3 font-mono font-bold text-foreground">{{ $b->account_number }}</td>
                                    <td class="py-3 px-3 text-muted-foreground">{{ $b->account_holder }}</td>
                                    <td class="py-3 px-3">
                                        <span class="rounded px-2 py-0.5 text-[10px] font-semibold {{ $b->isVirtualAccount() ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'bg-blue-500/10 text-blue-600 dark:text-blue-400' }}">
                                            {{ $b->typeLabel() }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-3">
                                        <form method="POST" action="{{ route('admin.payment-settings.bank.toggle', $b->id) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-semibold transition {{ $b->is_active ? 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20' : 'bg-muted text-muted-foreground hover:bg-muted/80' }}">
                                                <span class="h-1.5 w-1.5 rounded-full {{ $b->is_active ? 'bg-emerald-500' : 'bg-muted-foreground' }}"></span>
                                                {{ $b->is_active ? 'Aktif di Checkout' : 'Nonaktif' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" 
                                                    @click="editBankData = {
                                                        id: {{ $b->id }},
                                                        bank_name: '{{ addslashes($b->bank_name) }}',
                                                        account_number: '{{ addslashes($b->account_number) }}',
                                                        account_holder: '{{ addslashes($b->account_holder) }}',
                                                        type: '{{ $b->type }}',
                                                        instructions: '{{ addslashes($b->instructions ?? '') }}',
                                                        is_active: {{ $b->is_active ? 'true' : 'false' }},
                                                        sort_order: {{ $b->sort_order }}
                                                    }; modalEditBank = true;"
                                                    class="rounded-lg p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <form method="POST" action="{{ route('admin.payment-settings.bank.destroy', $b->id) }}"
                                                  data-konfirmasi="Hapus rekening {{ $b->bank_name }} ({{ $b->account_number }}) dari daftar pembayaran?"
                                                  data-konfirmasi-judul="Hapus Rekening Bank"
                                                  data-konfirmasi-ya="Ya, Hapus Rekening">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-lg p-1.5 text-rose-500 transition hover:bg-rose-500/10">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- =========================================================================
         TAB 2: PAYMENT GATEWAYS (Xendit, iPaymu, Midtrans, DOKU)
         ========================================================================= --}}
    <div x-show="tab === 'gateways'" class="space-y-6" style="display: none;">
        {{-- Banner Status Terpadu --}}
        <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-5 text-xs text-foreground sm:p-6">
            <div class="flex items-start gap-3.5">
                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <div class="space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-bold text-foreground">Sistem Gateway Tunggal Aktif: Xendit Production</h2>
                        <span class="rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[10px] font-bold text-emerald-600 dark:text-emerald-400">
                            Aktif &amp; Terkonfigurasi (.env)
                        </span>
                    </div>
                    <p class="text-muted-foreground leading-relaxed">
                        Seluruh alur checkout tagihan WhatsApp Gateway telah dialihkan ke <strong>Xendit Payment Gateway</strong> produksi. Kredensial dan keamanan webhook dikelola secara tersentralisasi melalui variabel lingkungan <code>.env</code> server untuk memastikan stabilitas dan keamanan tingkat tinggi.
                    </p>
                </div>
            </div>
        </div>

        {{-- Detail Konfigurasi Xendit --}}
        <div class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6 space-y-5">
            <div class="flex items-center justify-between border-b border-border/80 pb-3">
                <div class="flex items-center gap-3">
                    <div class="grid h-10 w-10 place-items-center rounded-xl bg-indigo-500/10 text-indigo-600 font-bold text-sm">
                        X
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">Xendit Unified Production Gateway</h3>
                        <p class="text-xs text-muted-foreground">Otomatisasi Tagihan Langganan WA Gateway (Prefix Invoice: WAG-...)</p>
                    </div>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-500/10 px-3 py-1 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                    <span class="h-2 w-2 rounded-full bg-indigo-500 animate-pulse"></span>
                    Ready &amp; Live
                </span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Kredensial Secret Key (.env)</span>
                    <div class="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-3.5 py-2.5 font-mono text-xs">
                        <span class="text-foreground">
                            @if(filled(config('services.xendit.secret_key')))
                                {{ substr(config('services.xendit.secret_key'), 0, 16) }}••••••••••••••••
                            @else
                                <span class="text-rose-500 font-sans">Belum diatur di .env</span>
                            @endif
                        </span>
                        <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400">Production</span>
                    </div>
                </div>

                <div class="space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Internal Webhook Secret (.env)</span>
                    <div class="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-3.5 py-2.5 font-mono text-xs">
                        <span class="text-foreground">
                            @if(filled(config('services.xendit.internal_secret')))
                                {{ substr(config('services.xendit.internal_secret'), 0, 12) }}••••••
                            @else
                                <span class="text-amber-500 font-sans">Default internal</span>
                            @endif
                        </span>
                        <span class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400">Verified</span>
                    </div>
                </div>

                <div class="sm:col-span-2 space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Internal Webhook Dispatch Endpoint (Menerima dari VexaHost Pusat)</span>
                    <div class="rounded-xl border border-border bg-muted/30 px-3.5 py-2.5 font-mono text-xs text-foreground select-all">
                        {{ url('/api/webhooks/payment/xendit') }}
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        Notifikasi pembayaran dari Xendit diterima terpusat oleh server VexaHost dan diteruskan secara otomatis ke endpoint internal ini.
                    </p>
                </div>
            </div>

            <div class="rounded-xl border border-border/60 bg-muted/20 p-4 space-y-2">
                <span class="text-xs font-bold text-foreground">Saluran Pembayaran yang Didukung (Xendit Invoice Card):</span>
                <div class="flex flex-wrap gap-2 pt-1">
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">📱 QRIS Instan</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">🏦 Mandiri Virtual Account</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">🏦 BNI Virtual Account</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">🏦 BRI Virtual Account</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">🏦 Permata Virtual Account</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">🏪 Indomaret</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">💳 Kartu Debit/Kredit</span>
                    <span class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground">💳 Akulaku PayLater</span>
                </div>
            </div>
        </div>

        {{-- Status Gateway Lama / Dinonaktifkan --}}
        <div class="rounded-2xl border border-border bg-card p-5 shadow-xs sm:p-6 space-y-3">
            <h3 class="text-sm font-bold text-foreground">Status Gateway Eksternal Lainnya</h3>
            <p class="text-xs text-muted-foreground leading-relaxed">
                Gateway lama (Mayar.id, Midtrans, iPaymu, DOKU) telah dinonaktifkan demi kehandalan satu akun terpusat. Form konfigurasi manual di database tidak lagi digunakan karena seluruh proses pembayaran otomatis dikendalikan oleh integrasi Xendit di berkas <code>.env</code>.
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 text-xs">
                <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                    <span class="font-bold text-muted-foreground block">Mayar.id</span>
                    <span class="text-[10px] text-zinc-400">Dinonaktifkan</span>
                </div>
                <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                    <span class="font-bold text-muted-foreground block">Midtrans</span>
                    <span class="text-[10px] text-zinc-400">Dinonaktifkan</span>
                </div>
                <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                    <span class="font-bold text-muted-foreground block">iPaymu</span>
                    <span class="text-[10px] text-zinc-400">Dinonaktifkan</span>
                </div>
                <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                    <span class="font-bold text-muted-foreground block">DOKU</span>
                    <span class="text-[10px] text-zinc-400">Dinonaktifkan</span>
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         TAB 3: HARGA SEMUA PAKET
         ========================================================================= --}}
    <div x-show="tab === 'harga'" class="space-y-6" style="display: none;">
        <div class="rounded-xl border border-border bg-card p-4 text-xs text-muted-foreground">
            <p>
                Perubahan harga di bawah ini langsung berlaku secara dinamis ke halaman harga paket (<code class="font-mono text-foreground">/billing/plans</code>), tagihan checkout, dan katalog langganan tanpa perlu mengubah berkas konfigurasi maupun kode program.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.payment-settings.prices') }}" class="space-y-6">
            @csrf

            {{-- Daftar Paket Utama --}}
            <div class="grid gap-5 lg:grid-cols-3">
                @foreach (['essentials', 'prime', 'elite'] as $slug)
                    @php $p = $catalog[$slug] ?? null; @endphp
                    @if ($p)
                        <div class="rounded-2xl border border-border bg-card p-5 shadow-xs flex flex-col justify-between space-y-4">
                            <div>
                                <div class="flex items-center justify-between border-b border-border/80 pb-2.5">
                                    <h3 class="text-base font-bold text-foreground">{{ $p['name'] }}</h3>
                                    <span class="rounded bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary font-mono">{{ $slug }}</span>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">{{ $p['tagline'] }}</p>

                                <div class="mt-4 space-y-3">
                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-foreground">
                                            Harga Normal Bulanan (Rp)
                                        </label>
                                        <input type="number" step="1" min="0" required
                                               name="plans[{{ $slug }}][price_monthly]"
                                               value="{{ old("plans.{$slug}.price_monthly", $p['price_monthly'] ?? 0) }}"
                                               class="w-full rounded-xl border border-input bg-background px-3 py-2 text-sm font-semibold text-foreground transition focus:border-primary focus:outline-none">
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-foreground">
                                            Promo Perkenalan Bulan Ke-1 (Rp)
                                        </label>
                                        <input type="number" step="1" min="0"
                                               name="plans[{{ $slug }}][intro_price_monthly]"
                                               value="{{ old("plans.{$slug}.intro_price_monthly", $p['intro_price_monthly'] ?? null) }}"
                                               placeholder="Kosongkan jika tidak ada promo"
                                               class="w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                                        <p class="mt-0.5 text-[10px] text-muted-foreground">Hanya untuk pembelian pertama workspace.</p>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-xs font-semibold text-foreground">
                                            Promo Perkenalan Tahun Ke-1 (Rp)
                                        </label>
                                        <input type="number" step="1" min="0"
                                               name="plans[{{ $slug }}][intro_price_yearly]"
                                               value="{{ old("plans.{$slug}.intro_price_yearly", $p['intro_price_yearly'] ?? null) }}"
                                               placeholder="Kosongkan jika tidak ada promo"
                                               class="w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-border/80 bg-muted/20 p-2.5 text-[11px] text-muted-foreground space-y-1">
                                <div class="flex justify-between">
                                    <span>Kuota pesan:</span>
                                    <span class="font-semibold text-foreground">{{ number_format($p['monthly_message_quota'], 0, ',', '.') }} /bln</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Nomor sesi:</span>
                                    <span class="font-semibold text-foreground">{{ $p['max_sessions'] }} nomor</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- Pay As You Go & Pengaturan Global --}}
            <div class="grid gap-5 md:grid-cols-2">
                {{-- PAYG --}}
                <div class="rounded-2xl border border-border bg-card p-5 shadow-xs space-y-4">
                    <div class="border-b border-border/80 pb-2.5">
                        <h3 class="text-base font-bold text-foreground">Pay As You Go</h3>
                        <p class="text-xs text-muted-foreground">Sistem bayar per pesan terkirim dengan saldo top-up</p>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-foreground">Tarif per Pesan Keluar (Rp)</label>
                            <input type="number" min="1" required name="payg_price_per_message"
                                   value="{{ old('payg_price_per_message', $paygPrice) }}"
                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm font-semibold text-foreground transition focus:border-primary focus:outline-none">
                            <p class="mt-1 text-[11px] text-muted-foreground">Standar saat ini: Rp 200/pesan.</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold text-foreground">Minimum Nominal Isi Saldo (Rp)</label>
                            <input type="number" step="1" min="1000" required name="payg_min_topup"
                                   value="{{ old('payg_min_topup', $paygMinTopup) }}"
                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm font-semibold text-foreground transition focus:border-primary focus:outline-none">
                            <p class="mt-1 text-[11px] text-muted-foreground">Standar saat ini: Rp 50.000.</p>
                        </div>
                    </div>
                </div>

                {{-- Global Settings --}}
                <div class="rounded-2xl border border-border bg-card p-5 shadow-xs space-y-4">
                    <div class="border-b border-border/80 pb-2.5">
                        <h3 class="text-base font-bold text-foreground">Kebijakan Multiplier &amp; Pajak</h3>
                        <p class="text-xs text-muted-foreground">Perhitungan otomatis harga tahunan dan perpajakan</p>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-foreground">Pengali Harga Tahunan (Bulan)</label>
                            <input type="number" min="1" max="24" required name="yearly_multiplier"
                                   value="{{ old('yearly_multiplier', $yearlyMultiplier) }}"
                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm font-semibold text-foreground transition focus:border-primary focus:outline-none">
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                Nilai <strong>10</strong> berarti bayar 10 bulan gratis 2 bulan untuk langganan 1 tahun.
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold text-foreground">Persentase PPN (%)</label>
                            <input type="number" step="0.1" min="0" max="100" required name="tax_percent"
                                   value="{{ old('tax_percent', $taxPercent) }}"
                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm font-semibold text-foreground transition focus:border-primary focus:outline-none">
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                0 berarti harga sudah final tanpa pajak. Isi 11 jika ingin membebankan PPN 11% terpisah.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-2.5 text-xs font-semibold text-primary-foreground shadow-xs transition hover:opacity-90 active:scale-95">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Simpan Perubahan Harga Paket</span>
                </button>
            </div>
        </form>
    </div>

    {{-- =========================================================================
         MODAL TAMBAH REKENING / VA
         ========================================================================= --}}
    <div x-show="modalTambahBank" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
        <div @click.away="modalTambahBank = false"
             class="w-full max-w-lg rounded-2xl border border-border bg-card p-6 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-border/80 pb-3">
                <h3 class="text-base font-bold text-foreground">Tambah Rekening Bank / Virtual Account</h3>
                <button type="button" @click="modalTambahBank = false" class="text-muted-foreground hover:text-foreground">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.payment-settings.bank.store') }}" class="space-y-3.5">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Bank / Provider</label>
                    <input type="text" name="bank_name" required placeholder="Contoh: Bank Central Asia (BCA)" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Nomor Rekening / Nomor VA</label>
                    <input type="text" name="account_number" required placeholder="Contoh: 1234567890" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 font-mono text-sm text-foreground transition focus:border-primary focus:outline-none">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Atas Nama / Pemilik Akun</label>
                    <input type="text" name="account_holder" required placeholder="Contoh: PT DESTINARA CHAKRAWALA ARTHA" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Tipe Akun</label>
                        <select name="type" class="w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                            <option value="bank">Transfer Bank</option>
                            <option value="va">Virtual Account</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Urutan Tampil</label>
                        <input type="number" name="sort_order" value="0" min="0" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Instruksi Khusus (Opsional)</label>
                    <textarea name="instructions" rows="2" placeholder="Contoh: Transfer dari ATM atau m-Banking BCA" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs text-foreground transition focus:border-primary focus:outline-none"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="tambah_is_active" name="is_active" value="1" checked class="rounded border-input text-primary focus:ring-primary">
                    <label for="tambah_is_active" class="text-xs text-foreground font-medium">Langsung aktifkan di halaman checkout tagihan</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" @click="modalTambahBank = false" class="rounded-xl border border-border px-4 py-2 text-xs font-semibold text-muted-foreground hover:bg-muted">Batal</button>
                    <button type="submit" class="rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground shadow-xs hover:opacity-90">Simpan Rekening</button>
                </div>
            </form>
        </div>
    </div>

    {{-- =========================================================================
         MODAL EDIT REKENING / VA
         ========================================================================= --}}
    <div x-show="modalEditBank" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
        <div @click.away="modalEditBank = false"
             class="w-full max-w-lg rounded-2xl border border-border bg-card p-6 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-border/80 pb-3">
                <h3 class="text-base font-bold text-foreground">Edit Rekening Bank / Virtual Account</h3>
                <button type="button" @click="modalEditBank = false" class="text-muted-foreground hover:text-foreground">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" :action="'{{ url('admin/pembayaran-paket/bank') }}/' + editBankData.id" class="space-y-3.5">
                @csrf
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Bank / Provider</label>
                    <input type="text" name="bank_name" x-model="editBankData.bank_name" required class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Nomor Rekening / Nomor VA</label>
                    <input type="text" name="account_number" x-model="editBankData.account_number" required class="w-full rounded-xl border border-input bg-background px-3.5 py-2 font-mono text-sm text-foreground transition focus:border-primary focus:outline-none">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Atas Nama / Pemilik Akun</label>
                    <input type="text" name="account_holder" x-model="editBankData.account_holder" required class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Tipe Akun</label>
                        <select name="type" x-model="editBankData.type" class="w-full rounded-xl border border-input bg-background px-3 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                            <option value="bank">Transfer Bank</option>
                            <option value="va">Virtual Account</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Urutan Tampil</label>
                        <input type="number" name="sort_order" x-model="editBankData.sort_order" min="0" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-sm text-foreground transition focus:border-primary focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">Instruksi Khusus (Opsional)</label>
                    <textarea name="instructions" x-model="editBankData.instructions" rows="2" class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs text-foreground transition focus:border-primary focus:outline-none"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="edit_is_active" name="is_active" value="1" :checked="editBankData.is_active" class="rounded border-input text-primary focus:ring-primary">
                    <label for="edit_is_active" class="text-xs text-foreground font-medium">Aktif di halaman checkout tagihan</label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" @click="modalEditBank = false" class="rounded-xl border border-border px-4 py-2 text-xs font-semibold text-muted-foreground hover:bg-muted">Batal</button>
                    <button type="submit" class="rounded-xl bg-primary px-5 py-2 text-xs font-semibold text-primary-foreground shadow-xs hover:opacity-90">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
