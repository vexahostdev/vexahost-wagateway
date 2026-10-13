@php
    $beranda = request()->routeIs('welcome') ? '' : route('welcome');
@endphp

{{-- ===================== Footer ===================== --}}
<footer class="border-t border-border bg-background pt-12 sm:pt-16 pb-0">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        {{-- Banner Atas: Enterprise Ready & Akselerasi Integrasi --}}
        <div class="pb-10 border-b border-border/70">
            <div class="grid gap-8 lg:grid-cols-12 items-center">
                <div class="lg:col-span-7">
                    <h3 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">
                        Siap mengotomatiskan pesan WhatsApp skala besar?
                    </h3>
                    <p class="mt-1.5 text-xs sm:text-sm text-muted-foreground max-w-xl leading-relaxed">
                        Bergabunglah dengan ratusan pengembang dan bisnis yang mengandalkan stabilitas gateway VexaHost untuk pesan transaksional berkecepatan tinggi tanpa khawatir sesi terputus.
                    </p>
                </div>
                <div class="lg:col-span-5 flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                    <div class="relative flex-1 min-w-0">
                        <input type="email"
                               placeholder="Masukkan email kantor Anda..."
                               class="w-full rounded-xl border border-input bg-card px-3.5 py-2.5 text-xs sm:text-sm text-foreground shadow-xs placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                               readonly
                               onfocus="this.removeAttribute('readonly');">
                    </div>
                    <a href="{{ route('register') }}"
                       class="inline-flex shrink-0 items-center justify-center rounded-xl bg-primary px-5 py-2.5 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all text-center">
                        Mulai Sekarang
                    </a>
                </div>
            </div>
        </div>

        {{-- Grid Navigasi Utama: Brand + 4 Kolom Menu --}}
        <div class="pt-10 sm:pt-12 pb-8 sm:pb-10 grid gap-10 lg:grid-cols-12">
            {{-- Sisi Kiri: Profil Brand & Security Pillars --}}
            <div class="lg:col-span-4 space-y-5">
                <a href="{{ route('welcome') }}" class="flex items-center gap-2.5 text-xl font-bold tracking-tight text-foreground">
                    <img src="{{ asset('images/vexahost-wa.png') }}" alt="Logo" class="h-8 w-auto object-contain">
                    <div class="flex flex-col">
                        <span class="leading-none">VexaHost WA Gateway</span>
                        <span class="text-[10px] font-mono tracking-wider uppercase text-primary font-semibold mt-1">Enterprise API Platform</span>
                    </div>
                </a>

                <p class="text-xs sm:text-sm text-muted-foreground leading-relaxed max-w-sm">
                    Infrastruktur WhatsApp Gateway berkinerja tinggi untuk pengiriman OTP, tagihan otomatis, notifikasi e-commerce, dan broadcast dengan perlindungan anti-blokir multi-sesi.
                </p>

                {{-- Pilar Keamanan & Rekayasa --}}
                <div class="pt-1 space-y-2 text-xs text-muted-foreground">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                        <span>Enkripsi TLS 1.3 & API Token Scoped</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        <span>Isolasi Volume Sesi Terenkripsi</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                        <span>Antrean Pesan Jeda Acak Anti-Spam</span>
                    </div>
                </div>
            </div>

            {{-- Sisi Kanan: 4 Kolom Link Enterprise --}}
            <div class="lg:col-span-8 grid grid-cols-2 sm:grid-cols-4 gap-6 sm:gap-8">
                {{-- Kolom 1: Produk API --}}
                <div class="min-w-0">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-primary">Produk API</h4>
                    <ul class="mt-4 space-y-2.5 text-xs sm:text-sm">
                        <li><a href="{{ $beranda }}#fitur" class="text-muted-foreground hover:text-foreground transition-colors">Fitur Gateway</a></li>
                        <li><a href="{{ $beranda }}#cara-kerja" class="text-muted-foreground hover:text-foreground transition-colors">Cara Kerja</a></li>
                        <li><a href="{{ $beranda }}#harga" class="text-muted-foreground hover:text-foreground transition-colors">Paket & Harga</a></li>
                        <li><a href="{{ route('mitra.landing') }}" class="text-muted-foreground hover:text-foreground transition-colors">Program Mitra (Reseller)</a></li>
                        <li><a href="{{ route('docs.show', 'referensi-api') }}" class="text-muted-foreground hover:text-foreground transition-colors">Kirim Pesan Teks</a></li>
                        <li><a href="{{ route('docs.show', 'webhook') }}" class="text-muted-foreground hover:text-foreground transition-colors">Webhook Dispatcher</a></li>
                        <li><a href="{{ route('docs.show', 'praktik-baik') }}" class="text-muted-foreground hover:text-foreground transition-colors">Proteksi Anti-Blokir</a></li>
                        <li><a href="{{ route('login') }}" class="text-muted-foreground hover:text-foreground transition-colors">Console Masuk</a></li>
                    </ul>
                </div>

                {{-- Kolom 2: Developer --}}
                <div class="min-w-0">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-primary">Developer</h4>
                    <ul class="mt-4 space-y-2.5 text-xs sm:text-sm">
                        <li><a href="{{ route('docs.index') }}" class="text-muted-foreground hover:text-foreground transition-colors">Pusat Dokumentasi</a></li>
                        <li><a href="{{ route('docs.show', 'mulai-cepat') }}" class="text-muted-foreground hover:text-foreground transition-colors">Panduan Mulai Cepat</a></li>
                        <li><a href="{{ route('docs.show', 'referensi-api') }}" class="text-muted-foreground hover:text-foreground transition-colors">Referensi API v1.0</a></li>
                        <li><a href="{{ route('docs.show', 'contoh-integrasi') }}" class="text-muted-foreground hover:text-foreground transition-colors">Integrasi PHP / Laravel</a></li>
                        <li><a href="{{ route('docs.show', 'integrasi-ai-agent') }}" class="text-muted-foreground hover:text-foreground transition-colors">Integrasi AI Agent</a></li>
                        <li><a href="{{ route('docs.show', 'koleksi-postman') }}" class="text-muted-foreground hover:text-foreground transition-colors">Koleksi Postman</a></li>
                    </ul>
                </div>

                {{-- Kolom 3: Ekosistem VexaHost --}}
                <div class="min-w-0">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-primary">Ekosistem</h4>
                    <ul class="mt-4 space-y-2.5 text-xs sm:text-sm">
                        @foreach (config('vexahost.produk') as $nama => $alamat)
                            <li><a href="{{ $alamat }}" target="_blank" rel="noopener" class="text-muted-foreground hover:text-foreground transition-colors">{{ $nama }}</a></li>
                        @endforeach
                        <li><a href="https://lynk.id/vexahost" target="_blank" rel="noopener" class="text-muted-foreground hover:text-foreground transition-colors">Lynk.id Official</a></li>
                    </ul>
                </div>

                {{-- Kolom 4: Perusahaan & Hubungi Kami --}}
                <div class="min-w-0">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-primary">Perusahaan</h4>
                    <ul class="mt-4 space-y-2.5 text-xs sm:text-sm">
                        <li><a href="https://vexahostcloud.my.id" target="_blank" rel="noopener" class="text-muted-foreground hover:text-foreground transition-colors">Tentang Kami</a></li>
                        <li><a href="{{ route('enterprise') }}" class="text-muted-foreground hover:text-foreground transition-colors">Hubungi Sales</a></li>
                        <li><a href="{{ route('docs.show', 'bantuan') }}" class="text-muted-foreground hover:text-foreground transition-colors">Pusat Bantuan</a></li>
                        <li><a href="mailto:{{ \App\Support\MailIdentity::contactEmail() }}" class="text-muted-foreground hover:text-foreground transition-colors break-all">{{ \App\Support\MailIdentity::contactEmail() }}</a></li>
                        <li><a href="{{ \App\Support\KontakWhatsApp::tautan() }}" target="_blank" rel="noopener" class="text-muted-foreground hover:text-foreground transition-colors">WhatsApp Helpdesk</a></li>
                        <li><a href="{{ route('status') }}" class="text-muted-foreground hover:text-foreground transition-colors">Status Layanan</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Baris Bawah: Legal Metadata, Hak Cipta & Social Icons Lengkap (Full-Width Grounded) --}}
    <div class="border-t border-border/70 bg-muted/40 dark:bg-muted/20 py-3 sm:py-3.5">
        <div class="mx-auto flex max-w-[1440px] flex-col sm:flex-row items-center justify-between gap-3 sm:gap-4 px-4 sm:px-6 lg:px-8 xl:px-10 text-xs text-muted-foreground">
            {{-- Dokumen hukum ditaruh di baris paling bawah bersama hak cipta,
                 bukan di kolom Perusahaan. Di situlah orang mencarinya, dan
                 bagian pengadaan calon pelanggan menggulung sampai bawah persis
                 untuk menemukan keenamnya berjajar. --}}
            <div class="text-center sm:text-left">
                <span>&copy; {{ date('Y') }} VexaHost. All rights reserved. Created by vexahostcloud.</span>
            </div>

            {{-- Dokumen hukum & Kanal resmi VexaHost --}}
            <div class="flex flex-wrap items-center justify-center sm:justify-end gap-x-4 gap-y-2 text-muted-foreground">
                <nav class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 sm:justify-end" aria-label="Dokumen hukum">
                    @foreach ([
                        'syarat-layanan' => 'Syarat Layanan',
                        'kebijakan-privasi' => 'Privasi',
                        'dpa' => 'DPA',
                        'penggunaan-wajar' => 'Penggunaan Wajar',
                        'sla' => 'SLA',
                        'kebijakan-refund' => 'Refund',
                    ] as $slug => $label)
                        <a href="{{ route('docs.show', $slug) }}" class="hover:text-foreground transition-colors">{{ $label }}</a>
                    @endforeach
                </nav>

                {{-- Lynk.id --}}
                <a href="https://lynk.id/vexahost" target="_blank" rel="noopener" class="hover:text-foreground transition-colors shrink-0" aria-label="Lynk.id">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="18" viewBox="0 0 38 24" fill="none">
                        <rect x=".75" y=".75" width="36.5" height="22.5" rx="6" stroke="currentColor" stroke-width="1.5"></rect>
                        <text x="19" y="15.6" fill="currentColor" text-anchor="middle" font-size="10" font-weight="700" font-family="Arial, sans-serif">lynk</text>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</footer>
