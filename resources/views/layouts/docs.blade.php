<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $docTitle = trim($__env->yieldContent('title'));
        $docDesc = trim($__env->yieldContent('description'));

        // Halaman dokumentasi berada dua tingkat di bawah beranda; indeksnya
        // satu tingkat. Butir terakhir tanpa URL karena itu halaman ini sendiri.
        $remahDokumentasi = [['name' => 'Beranda', 'url' => url('/')]];
        if (request()->routeIs('docs.show') && ! empty($docTitle)) {
            $remahDokumentasi[] = ['name' => 'Dokumentasi', 'url' => route('docs.index')];
            $remahDokumentasi[] = ['name' => $docTitle];
        } else {
            $remahDokumentasi[] = ['name' => 'Dokumentasi'];
        }
    @endphp
    @include('partials.seo-head', [
        'title' => !empty($docTitle) ? $docTitle . ' · Dokumentasi ' . config('app.name') : 'Dokumentasi ' . config('app.name'),
        'description' => !empty($docDesc) ? $docDesc : 'Dokumentasi VexaHost WA Gateway: Panduan integrasi WhatsApp API, automasi broadcast, webhook, dan multi-sesi.',
        'breadcrumbs' => $remahDokumentasi,
    ])

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" type="image/png" sizes="96x96" href="{{ asset('images/favicon-96x96.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/android-chrome-192x192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        const isDark = localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    </script>
</head>
<body class="bg-background text-foreground antialiased font-sans"
      x-data="{
          sidebar: false,
          searchModal: false,
          searchQuery: '',
          pages: @js(array_values(\App\Support\DocsRepository::flat())),
          get searchResults() {
              if (!this.searchQuery.trim()) return this.pages.slice(0, 6);
              const q = this.searchQuery.toLowerCase();
              return this.pages.filter(p => 
                  p.title.toLowerCase().includes(q) || 
                  p.summary.toLowerCase().includes(q) || 
                  p.group.toLowerCase().includes(q)
              );
          }
      }"
      @keydown.window.prevent.ctrl.k="searchModal = true"
      @keydown.window.prevent.cmd.k="searchModal = true">

    {{-- ===================== MODAL PENCARIAN (CTRL + K) ===================== --}}
    <div x-show="searchModal" x-cloak
         @keydown.escape.window="searchModal = false"
         class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:pt-20">
        {{-- Backdrop --}}
        <div x-show="searchModal" 
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="searchModal = false" 
             class="fixed inset-0 bg-black/60 backdrop-blur-xs"></div>

        {{-- Panel Modal --}}
        <div x-show="searchModal"
             x-transition:enter="ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="relative w-full max-w-xl rounded-2xl border border-border bg-popover shadow-2xl overflow-hidden z-10">
            
            {{-- Input Pencarian --}}
            <div class="flex items-center gap-3 border-b border-border px-4 py-3.5 bg-muted/30">
                <i class="bi bi-search text-muted-foreground text-base"></i>
                <input type="text" 
                       x-model="searchQuery" 
                       x-ref="searchInput"
                       x-init="$watch('searchModal', value => { if (value) setTimeout(() => $refs.searchInput.focus(), 50); else searchQuery = ''; })"
                       placeholder="Cari dokumentasi atau topik (mis: kirim pesan, webhook, api)..."
                       class="w-full bg-transparent text-sm placeholder:text-muted-foreground focus:outline-none text-foreground">
                <button @click="searchModal = false" class="rounded-lg p-1 text-muted-foreground hover:bg-muted hover:text-foreground text-xs font-semibold px-2 border border-border">
                    ESC
                </button>
            </div>

            {{-- Hasil Pencarian --}}
            <div class="max-h-96 overflow-y-auto p-2 divide-y divide-border/40">
                <template x-for="item in searchResults" :key="item.slug">
                    <a :href="'/docs/' + item.slug" 
                       class="flex flex-col gap-1 p-3 rounded-xl transition hover:bg-muted group">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-sm text-foreground group-hover:text-primary transition" x-text="item.title"></span>
                            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-muted text-muted-foreground" x-text="item.group"></span>
                        </div>
                        <p class="text-xs text-muted-foreground line-clamp-1" x-text="item.summary"></p>
                    </a>
                </template>
                <div x-show="searchResults.length === 0" class="p-8 text-center text-sm text-muted-foreground">
                    <i class="bi bi-search text-2xl d-block mb-2 text-muted-foreground/50"></i>
                    <p>Tidak ada hasil untuk "<span x-text="searchQuery" class="font-semibold text-foreground"></span>"</p>
                </div>
            </div>

            {{-- Footer Hint --}}
            <div class="border-t border-border bg-muted/40 px-4 py-2 text-[11px] text-muted-foreground flex items-center justify-between">
                <span>Pencarian Cepat Dokumentasi</span>
                <span class="flex items-center gap-1.5">
                    <kbd class="px-1.5 py-0.5 rounded bg-muted border border-border text-[10px]">↵</kbd> untuk memilih
                </span>
            </div>
        </div>
    </div>

    {{-- ===================== BILAH ATAS (NAVBAR STICKY) ===================== --}}
    <header x-data="{ scrolled: false }"
            x-init="scrolled = (window.scrollY > 10)"
            @scroll.window.passive="scrolled = (window.scrollY > 10)"
            class="sticky top-0 z-40 w-full border-b transition-all duration-300"
            :class="scrolled ? 'border-border/70 bg-background/80 backdrop-blur-md shadow-2xs' : 'border-transparent bg-transparent'">
        <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 sm:px-6 py-3">

            {{-- Brand Identity --}}
            <a href="{{ route('welcome') }}" class="flex items-center gap-3 font-semibold group shrink-0">
                <img src="{{ asset('images/vexahost-wa.png') }}" alt="VexaHost WA" class="h-8 w-auto object-contain transition group-hover:scale-105">
                <div class="flex items-center gap-2">
                    <span class="text-base font-bold tracking-tight text-foreground">VexaHost WA</span>
                    <span class="rounded-full bg-primary/15 border border-primary/30 px-2 py-0.5 text-[10px] font-bold text-primary tracking-wider uppercase">
                        Docs
                    </span>
                </div>
            </a>

            {{-- Search Bar Trigger (Tengah / Desktop) --}}
            <button type="button" 
                    @click="searchModal = true"
                    class="hidden sm:flex items-center gap-3 w-64 md:w-72 lg:w-80 rounded-xl border border-border bg-card/60 px-3.5 py-2 text-xs text-muted-foreground transition hover:border-primary/40 hover:bg-card shadow-2xs">
                <i class="bi bi-search text-xs"></i>
                <span class="flex-1 text-left">Cari dokumentasi...</span>
                <kbd class="rounded border border-border bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">Ctrl K</kbd>
            </button>

            {{-- Menu Kanan --}}
            <div class="ml-auto flex items-center gap-1 sm:gap-2">
                @php
                    $isDocs = request()->routeIs('docs.index');
                    $isMulaiCepat = ($activeSlug ?? null) === 'mulai-cepat';
                    $isApi = ($activeSlug ?? null) === 'referensi-api';
                @endphp

                {{-- Quick Nav Links (Garis Bawah, Tanpa Background Hover) --}}
                <a href="{{ route('docs.index') }}" 
                   class="group relative hidden md:inline-flex items-center gap-1.5 px-2.5 py-2 text-xs sm:text-sm font-medium transition-colors hover:text-foreground {{ $isDocs ? 'text-foreground' : 'text-muted-foreground' }}">
                    <i class="bi bi-book text-xs"></i>
                    <span>Dokumentasi</span>
                    <span class="absolute bottom-0 left-2.5 right-2.5 h-0.5 bg-primary transition-all duration-200 {{ $isDocs ? 'opacity-100' : 'opacity-0 group-hover:opacity-100' }}"></span>
                </a>

                <a href="{{ route('docs.show', 'mulai-cepat') }}" 
                   class="group relative hidden md:inline-flex items-center gap-1.5 px-2.5 py-2 text-xs sm:text-sm font-medium transition-colors hover:text-foreground {{ $isMulaiCepat ? 'text-foreground' : 'text-muted-foreground' }}">
                    <i class="bi bi-rocket-takeoff text-xs"></i>
                    <span>Mulai Cepat</span>
                    <span class="absolute bottom-0 left-2.5 right-2.5 h-0.5 bg-primary transition-all duration-200 {{ $isMulaiCepat ? 'opacity-100' : 'opacity-0 group-hover:opacity-100' }}"></span>
                </a>

                <a href="{{ route('docs.show', 'referensi-api') }}" 
                   class="group relative hidden md:inline-flex items-center gap-1.5 px-2.5 py-2 text-xs sm:text-sm font-medium transition-colors hover:text-foreground {{ $isApi ? 'text-foreground' : 'text-muted-foreground' }}">
                    <i class="bi bi-code-slash text-xs"></i>
                    <span>Referensi API</span>
                    <span class="absolute bottom-0 left-2.5 right-2.5 h-0.5 bg-primary transition-all duration-200 {{ $isApi ? 'opacity-100' : 'opacity-0 group-hover:opacity-100' }}"></span>
                </a>

                <a href="{{ route('ai.index') }}" 
                   class="group relative hidden lg:inline-flex items-center gap-1.5 px-2.5 py-2 text-xs sm:text-sm font-medium transition-colors hover:text-foreground text-muted-foreground">
                    <i class="bi bi-robot text-xs text-primary"></i>
                    <span>AI Agent</span>
                    <span class="absolute bottom-0 left-2.5 right-2.5 h-0.5 bg-primary transition-all duration-200 opacity-0 group-hover:opacity-100"></span>
                </a>

                <a href="https://vexahostcloud.my.id/" target="_blank" rel="noopener noreferrer"
                   class="group relative hidden md:inline-flex items-center gap-1 px-2.5 py-2 text-xs sm:text-sm font-medium transition-colors hover:text-foreground text-muted-foreground">
                    <span>VPS</span>
                    <svg class="h-3.5 w-3.5 opacity-70 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 17L17 7M17 7H7M17 7V17"/></svg>
                    <span class="absolute bottom-0 left-2.5 right-2.5 h-0.5 bg-primary transition-all duration-200 opacity-0 group-hover:opacity-100"></span>
                </a>

                <div class="hidden md:block h-4 w-px bg-border/80 mx-1"></div>

                {{-- Tombol Ganti Tema (Tanpa Background) --}}
                <button type="button"
                        @click="const d = document.documentElement.classList.toggle('dark'); localStorage.theme = d ? 'dark' : 'light'; document.documentElement.style.colorScheme = d ? 'dark' : 'light';"
                        class="rounded-lg p-2 text-muted-foreground hover:text-foreground transition-colors"
                        aria-label="Ganti mode tema">
                    <svg class="hidden h-4 w-4 text-amber-400 dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <svg class="block h-4 w-4 text-foreground dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                </button>

                {{-- Action / Auth Button --}}
                @auth
                    <a href="{{ route('dashboard') }}" class="hidden sm:inline-flex items-center gap-1.5 rounded-xl bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-2xs transition hover:opacity-90 active:scale-95">
                        <i class="bi bi-speedometer2"></i>
                        <span>Dashboard</span>
                    </a>
                @else
                    {{-- Tombol Masuk (Tanpa Background) --}}
                    <a href="{{ route('login') }}" class="group relative hidden sm:inline-flex items-center px-2.5 py-2 text-xs sm:text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">
                        <span>Masuk</span>
                        <span class="absolute bottom-0 left-2.5 right-2.5 h-0.5 bg-primary opacity-0 transition-opacity duration-200 group-hover:opacity-100"></span>
                    </a>
                    <a href="{{ route('register') }}" class="hidden sm:inline-flex items-center rounded-xl bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-2xs transition hover:opacity-90 active:scale-95">
                        Mulai Gratis
                    </a>
                @endauth

                {{-- Search Trigger di Mobile --}}
                <button type="button" @click="searchModal = true" class="rounded-lg p-2 text-muted-foreground hover:text-foreground sm:hidden" aria-label="Cari">
                    <i class="bi bi-search text-base"></i>
                </button>

                {{-- Hamburger Menu di Mobile --}}
                <button type="button" @click="sidebar = !sidebar" class="rounded-lg p-2 text-muted-foreground hover:text-foreground lg:hidden" aria-label="Buka Menu">
                    <i class="bi bi-list text-xl"></i>
                </button>
            </div>
        </div>
    </header>

    {{-- ===================== DOKUMEN & SIDEBAR CONTAINER ===================== --}}
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="flex gap-8 py-8">

            {{-- SIDEBAR KIRI (Navigasi Katalog) --}}
            <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-border bg-background p-5 transition-transform duration-200 lg:sticky lg:top-16 lg:z-0 lg:flex lg:h-[calc(100vh-4rem)] lg:w-64 xl:w-72 lg:border-r lg:border-border/60 lg:bg-transparent lg:p-0 lg:pt-2 lg:pr-6"
                   :class="sidebar ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0'"
                   x-cloak>
                
                {{-- Mobile Close Header --}}
                <div class="flex items-center justify-between pb-4 mb-3 border-b border-border lg:hidden">
                    <div class="flex items-center gap-2 font-bold text-base">
                        <img src="{{ asset('images/vexahost-wa.png') }}" alt="" class="h-6 w-auto">
                        <span>Dokumentasi</span>
                    </div>
                    <button @click="sidebar = false" class="rounded-lg p-1 text-muted-foreground hover:bg-muted" aria-label="Tutup">
                        <i class="bi bi-x-lg text-base"></i>
                    </button>
                </div>

                {{-- Daftar Menu Dokumen --}}
                <div class="flex-1 overflow-y-auto space-y-6 pr-2 scrollbar-thin">
                    {{-- Tombol Route Khusus Halaman AI Agent --}}
                    <div>
                        <a href="{{ route('ai.index') }}"
                           class="group relative flex items-center justify-between gap-2.5 rounded-xl border border-primary/30 bg-primary/10 p-2.5 text-xs font-semibold text-primary transition-all hover:bg-primary/15 hover:border-primary/50 shadow-2xs">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-xs">
                                    <i class="bi bi-robot text-sm"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate text-foreground font-bold group-hover:text-primary transition-colors">Integrasi AI Agent</div>
                                    <div class="text-[10px] text-muted-foreground truncate">Prompt &amp; Client SDK</div>
                                </div>
                            </div>
                            <span class="rounded bg-primary/20 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-primary shrink-0">Buka</span>
                        </a>
                    </div>

                    <nav class="space-y-6 text-sm">
                        @php
                            $groupIcons = [
                                'Mulai' => 'bi-rocket-takeoff',
                                'Untuk Developer' => 'bi-code-slash',
                                'Panduan' => 'bi-book',
                            ];
                        @endphp
                        @foreach ($catalogue as $group => $pages)
                            <div>
                                <div class="flex items-center gap-2 mb-2 px-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                                    <i class="bi {{ $groupIcons[$group] ?? 'bi-folder2' }} text-primary"></i>
                                    <span>{{ $group }}</span>
                                </div>
                                <ul class="space-y-0.5 border-l border-border/60 ml-3 pl-2">
                                    @foreach ($pages as $slug => $item)
                                        @php $isCurrent = ($activeSlug ?? null) === $slug; @endphp
                                        <li>
                                            <a href="{{ route('docs.show', $slug) }}"
                                               class="group flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs transition {{ $isCurrent
                                                    ? 'bg-primary/10 font-semibold text-primary'
                                                    : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground' }}">
                                                <span class="truncate">{{ $item['title'] }}</span>
                                                @if ($isCurrent)
                                                    <span class="h-1.5 w-1.5 rounded-full bg-primary shrink-0"></span>
                                                @endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </nav>

                    {{-- Card Gateway Status & Shortcut --}}
                    <div class="pt-4 mt-6 border-t border-border/70">
                        <div class="rounded-xl border border-border/80 bg-card p-3.5 text-xs shadow-2xs">
                            <div class="flex items-center gap-2 text-foreground font-semibold">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>API v1.0 Gateway</span>
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground leading-normal">
                                Didukung multi-sesi WhatsApp dan sistem webhook otomatis.
                            </p>
                            <a href="{{ route('docs.index') }}" class="mt-2.5 inline-flex items-center gap-1 font-semibold text-primary hover:underline text-[11px]">
                                <span>Semua Direktori</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Mobile Drawer Footer --}}
                <div class="pt-4 mt-auto border-t border-border lg:hidden space-y-2">
                    @auth
                        <a href="{{ route('dashboard') }}" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-xs font-semibold text-primary-foreground">
                            <span>Buka Dashboard</span>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="flex w-full items-center justify-center rounded-xl border border-border bg-card px-4 py-2.5 text-xs font-semibold text-foreground">
                            Masuk
                        </a>
                        <a href="{{ route('register') }}" class="flex w-full items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-xs font-semibold text-primary-foreground">
                            Mulai Gratis
                        </a>
                    @endauth
                </div>
            </aside>

            {{-- Backdrop Mobile Saat Sidebar Terbuka --}}
            <div x-show="sidebar" x-cloak @click="sidebar = false" class="fixed inset-0 z-40 bg-black/50 lg:hidden"></div>

            {{-- KONTEN UTAMA --}}
            <main class="min-w-0 flex-1 max-w-full">
                @yield('content')
            </main>
        </div>
    </div>

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
                        <li><a href="{{ route('welcome') }}#fitur" class="text-muted-foreground hover:text-foreground transition-colors">Fitur Gateway</a></li>
                        <li><a href="{{ route('welcome') }}#cara-kerja" class="text-muted-foreground hover:text-foreground transition-colors">Cara Kerja</a></li>
                        <li><a href="{{ route('welcome') }}#harga" class="text-muted-foreground hover:text-foreground transition-colors">Paket & Harga</a></li>
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
                        <li><a href="{{ route('docs.show', 'kebijakan-privasi') }}" class="text-muted-foreground hover:text-foreground transition-colors">Kebijakan Privasi</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Baris Bawah: Legal Metadata, Hak Cipta & Social Icons Lengkap (Full-Width Grounded) --}}
    <div class="border-t border-border/70 bg-muted/40 dark:bg-muted/20 py-3 sm:py-3.5">
        <div class="mx-auto flex max-w-[1440px] flex-col sm:flex-row items-center justify-between gap-3 sm:gap-4 px-4 sm:px-6 lg:px-8 xl:px-10 text-xs text-muted-foreground">
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

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Otomatis tambahkan tombol salin ke setiap blok pre di dalam .doc-body
        document.querySelectorAll('.doc-body pre').forEach((pre) => {
            if (pre.querySelector('.doc-copy-btn')) return;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'doc-copy-btn absolute top-3 right-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-700/80 bg-slate-800/90 px-2.5 py-1 text-[11px] font-medium text-slate-300 backdrop-blur-xs transition hover:bg-slate-700 hover:text-white cursor-pointer select-none';
            btn.innerHTML = '<i class="bi bi-clipboard"></i> <span>Salin</span>';
            btn.title = 'Salin kode';

            btn.addEventListener('click', async () => {
                const codeEl = pre.querySelector('code');
                const text = codeEl ? codeEl.innerText : pre.innerText;
                try {
                    await navigator.clipboard.writeText(text.trim());
                    btn.innerHTML = '<i class="bi bi-check2 text-emerald-400"></i> <span class="text-emerald-400">Tersalin!</span>';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="bi bi-clipboard"></i> <span>Salin</span>';
                    }, 2000);
                } catch (err) {
                    console.error('Gagal menyalin:', err);
                }
            });

            pre.classList.add('relative', 'group');
            pre.appendChild(btn);
        });
    });
</script>
</body>
</html>
