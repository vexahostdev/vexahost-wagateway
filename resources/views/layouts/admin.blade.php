<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Panel internal: tidak ada gunanya muncul di hasil pencarian. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Admin') &middot; Admin {{ config('app.name') }}</title>

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" type="image/png" sizes="96x96" href="{{ asset('images/favicon-96x96.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/android-chrome-192x192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        const isDark = localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    </script>
</head>
<body class="h-full bg-background text-foreground antialiased" x-data="{ sidebar: false }">

@php
    $adminMenu = [
        'Ikhtisar' => [
            ['rute' => 'admin.overview', 'label' => 'Ringkasan', 'ikon' => 'M3 12l9-9 9 9M5 10v10h14V10'],
        ],
        'Operasional' => [
            ['rute' => 'admin.workspaces', 'label' => 'Workspace', 'cocok' => ['admin.workspaces', 'admin.workspaces.*'], 'ikon' => 'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9h1M9 13h1M9 17h1M15 13h1M15 17h1'],
            ['rute' => 'admin.invoices', 'label' => 'Tagihan', 'cocok' => ['admin.invoices', 'admin.invoices.*'], 'ikon' => 'M4 2v20l3-2 3 2 3-2 3 2 3-2 3 2V2l-3 2-3-2-3 2-3-2-3 2-3-2zM8 8h8M8 12h8M8 16h5'],
            ['rute' => 'admin.payment-settings', 'label' => 'Metode Bayar & Paket', 'cocok' => ['admin.payment-settings', 'admin.payment-settings.*'], 'ikon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H6a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3z'],
            ['rute' => 'admin.sessions', 'label' => 'Sesi WhatsApp', 'cocok' => ['admin.sessions', 'admin.sessions.*'], 'ikon' => 'M12 2a10 10 0 1 0 4.9 18.7L22 22l-1.3-5.1A10 10 0 0 0 12 2z'],
            ['rute' => 'admin.messages', 'label' => 'Lalu Lintas Pesan', 'ikon' => 'M4 4h16v12H5.17L4 17.17V4zM8 9h8M8 12h5'],
            ['rute' => 'admin.enterprise', 'label' => 'Enterprise', 'cocok' => ['admin.enterprise', 'admin.enterprise.*'], 'ikon' => 'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9h1M9 13h1M9 17h1M15 13h1M15 17h1'],
            ['rute' => 'admin.tickets', 'label' => 'Tiket', 'cocok' => ['admin.tickets', 'admin.tickets.*'], 'ikon' => 'M12 17h.01M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'],
            ['rute' => 'admin.referrals', 'label' => 'Reseller', 'cocok' => ['admin.referrals', 'admin.referrals.*'], 'ikon' => 'M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z'],
        ],
        'Akun & Jejak' => [
            ['rute' => 'admin.users', 'label' => 'Pengguna', 'cocok' => ['admin.users', 'admin.users.*'], 'ikon' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm10 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75'],
            ['rute' => 'admin.audit', 'label' => 'Catatan Audit', 'ikon' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h4'],
            ['rute' => 'admin.exemptions', 'label' => 'Pemberitahuan & Tes', 'cocok' => ['admin.exemptions', 'admin.exemptions.*'], 'ikon' => 'M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.3 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8L12 2z'],
            ['rute' => 'admin.system', 'label' => 'Sistem', 'ikon' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM3 12h2m14 0h2M12 3v2m0 14v2M5.6 5.6l1.4 1.4m10 10 1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4'],
            // Bersebelahan dengan Sistem karena keduanya soal kesehatan, tapi
            // tetap menu sendiri: yang satu untuk mendiagnosis ke dalam, yang
            // satu untuk berbicara ke luar. Tombol "umumkan ke seluruh
            // pelanggan" tidak boleh berjarak satu salah klik dari pemeriksaan
            // sehari-hari.
            ['rute' => 'admin.status', 'label' => 'Status & Insiden', 'cocok' => ['admin.status', 'admin.status.*'], 'ikon' => 'M22 12h-4l-3 9L9 3l-3 9H2'],
        ],
    ];

    /*
     | Angka pada menu.
     |
     | Hanya yang benar-benar menunggu TINDAKAN MANUSIA yang diberi angka, dan
     | sejauh ini cuma ada dua: bukti pembayaran yang belum diperiksa, dan tiket
     | yang belum dijawab. Lencana pada setiap menu berubah jadi hiasan yang
     | diabaikan mata; lencana yang muncul hanya saat memang ada pekerjaan justru
     | terbaca. Jangan menambahkan lencana untuk angka yang sekadar informatif.
     |
     | Tiket ikut di sini karena tanpa satu pun tanda di navigasi, tiket yang
     | masuk cuma terlihat oleh yang kebetulan membuka halamannya — dan tiket
     | yang tidak terlihat adalah pelanggan yang mengira kami tidak menjawab.
    */
    $perluDiperiksa = \App\Models\Invoice::whereNotNull('proof_path')->where('status', '!=', 'paid')->count()
        + \App\Models\Invoice::where('status', 'pending')->whereNull('proof_path')->count();

    $lencanaMenu = [
        'admin.invoices' => $perluDiperiksa,
        'admin.tickets' => \App\Models\Ticket::where('status', 'open')->count(),
        'admin.enterprise' => \App\Models\EnterpriseLead::where('status', 'baru')->count(),
    ];

    $aktif = function (array $item): bool {
        return request()->routeIs(...($item['cocok'] ?? [$item['rute']]));
    };
@endphp

    {{-- Penutup layar saat menu terbuka di layar kecil --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-40 bg-black/50 lg:hidden"></div>

    {{-- ===================== Sidebar Admin =====================
         Menggunakan token warna sidebar yang sama persis dengan panel user (bg-sidebar,
         border-sidebar-border, text-sidebar-foreground, dsb.) agar konsisten di light dan dark mode.
         ============================================================ --}}
    <aside class="panel-sidebar fixed inset-y-0 left-0 z-50 flex w-full lg:w-64 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform duration-200 ease-out select-none"
           :class="sidebar && 'terbuka'">

        {{-- Header Sidebar: Logo & Nama Aplikasi (sama persis seperti panel user) --}}
        <div class="flex h-20 shrink-0 items-center gap-3.5 px-5">
            <a href="{{ route('admin.overview') }}" class="flex min-w-0 items-center gap-3.5">
                <img src="{{ asset('images/vexahost-wa.png') }}" alt="VexaHost WA Gateway" class="h-11 w-auto shrink-0 object-contain">
                <div class="flex min-w-0 flex-col">
                    <span class="truncate text-lg font-extrabold tracking-tight text-foreground leading-tight">VexaHost</span>
                    <span class="truncate text-xs font-semibold tracking-wide text-muted-foreground leading-tight">WA Gateway</span>
                </div>
            </a>
            <button @click="sidebar = false" class="ml-auto rounded-md p-1 text-muted-foreground hover:bg-sidebar-accent lg:hidden" aria-label="Tutup menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Navigasi Menu Admin --}}
        <nav class="flex-1 space-y-4 overflow-y-auto px-3 pt-5 pb-4">
            @foreach ($adminMenu as $kelompok => $tautan)
                <div>
                    <p class="px-3 pb-1.5 text-[0.68rem] font-semibold uppercase tracking-wider text-muted-foreground/80">{{ $kelompok }}</p>
                    <div class="space-y-0.5">
                        @foreach ($tautan as $item)
                            @php $isAktif = $aktif($item); @endphp
                            <a href="{{ route($item['rute']) }}"
                               class="group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm transition {{ $isAktif 
                                    ? 'bg-sidebar-primary font-medium text-sidebar-primary-foreground' 
                                    : 'text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground' }}">
                                <svg class="h-[1.05rem] w-[1.05rem] shrink-0 text-current" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                    <path d="{{ $item['ikon'] }}"/>
                                </svg>
                                <span class="truncate">{{ $item['label'] }}</span>
                                @if (($lencanaMenu[$item['rute']] ?? 0) > 0)
                                    <span class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-primary-foreground">
                                        {{ $lencanaMenu[$item['rute']] > 99 ? '99+' : $lencanaMenu[$item['rute']] }}
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div>
                <p class="px-3 pb-1.5 text-[0.68rem] font-semibold uppercase tracking-wider text-muted-foreground/80">Pintasan</p>
                <div class="space-y-0.5">
                    <a href="{{ route('dashboard') }}"
                       class="group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground">
                        <svg class="h-[1.05rem] w-[1.05rem] shrink-0 text-current" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                        <span class="truncate">Ke Dashboard User</span>
                    </a>
                    <a href="{{ route('docs.index') }}" target="_blank" rel="noopener"
                       class="group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground">
                        <svg class="h-[1.05rem] w-[1.05rem] shrink-0 text-current" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z"/>
                        </svg>
                        <span class="truncate">Dokumentasi</span>
                        <svg class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground/70 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M7 17L17 7M17 7H7M17 7V17"/>
                        </svg>
                    </a>
                    <a href="https://vexahostcloud.my.id/" target="_blank" rel="noopener"
                       class="group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground">
                        <svg class="h-[1.05rem] w-[1.05rem] shrink-0 text-current" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                        </svg>
                        <span class="truncate">Cloud VPS</span>
                        <svg class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground/70 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M7 17L17 7M17 7H7M17 7V17"/>
                        </svg>
                    </a>
                    <a href="https://build.vexahostcloud.my.id" target="_blank" rel="noopener"
                       class="group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground">
                        <svg class="h-[1.05rem] w-[1.05rem] shrink-0 text-current" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                        </svg>
                        <span class="truncate">Jasa Web</span>
                        <svg class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground/70 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M7 17L17 7M17 7H7M17 7V17"/>
                        </svg>
                    </a>
                </div>
            </div>
        </nav>

        {{-- Footer Sidebar: Hanya tombol logout (sama persis seperti panel user) --}}
        <div class="shrink-0 border-t border-sidebar-border px-3 py-2">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="flex w-full items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground">
                    <svg class="h-[1.05rem] w-[1.05rem] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    {{-- ===================== Isi Halaman Admin ===================== --}}
    <div class="lg:pl-64">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-md sm:px-6">
            <button @click="sidebar = true" class="-ml-1 rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground lg:hidden" aria-label="Buka menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="min-w-0 truncate text-lg font-semibold">@yield('title', 'Admin')</h1>

            <div class="ml-auto flex items-center gap-1">
                {{-- Lonceng SEBELUM sakelar tema: yang menuntut tindakan berdiri
                     lebih dulu daripada yang cuma preferensi tampilan. --}}
                <x-lonceng audience="admin" />

                <button @click="const d = document.documentElement.classList.toggle('dark'); localStorage.theme = d ? 'dark' : 'light'; document.documentElement.style.colorScheme = d ? 'dark' : 'light';"
                        class="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Ganti tema tampilan">
                    <svg class="hidden h-5 w-5 dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <svg class="block h-5 w-5 dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                </button>

                {{-- ===================== Profil =====================
                     Sama seperti panel user: diletakkan di kanan atas header,
                     bukan di footer sidebar.
                     ============================================ --}}
                <div class="relative ml-1" x-data="{ profil: false }" @click.outside="profil = false" @keydown.escape.window="profil = false">
                    <button @click="profil = ! profil"
                            class="flex items-center p-1 rounded-full hover:bg-muted transition-colors focus:outline-none"
                            :aria-expanded="profil" aria-haspopup="true"
                            aria-label="Menu akun" title="{{ auth()->user()->name }} (Admin)">
                        <div class="rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center text-primary shrink-0"
                             style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; border-radius: 9999px; aspect-ratio: 1 / 1;">
                            <svg class="text-primary shrink-0" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                    </button>

                    <div x-show="profil" x-cloak x-transition.opacity.duration.150ms
                         class="absolute right-0 z-40 mt-1.5 w-60 overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-lg">
                        <div class="border-b border-border px-4 py-3 flex items-center gap-3">
                            <div class="rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center text-primary shrink-0"
                                 style="width: 38px; height: 38px; min-width: 38px; min-height: 38px; border-radius: 9999px; aspect-ratio: 1 / 1;">
                                <svg class="text-primary shrink-0" style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ auth()->user()->name }} <span class="text-xs text-muted-foreground font-normal">(Admin)</span></p>
                                <p class="truncate text-xs text-muted-foreground">{{ auth()->user()->email }}</p>
                            </div>
                        </div>

                        <div class="p-1.5">
                            <a href="{{ route('profile.show') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                Profil saya
                            </a>
                            <a href="{{ route('dashboard') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                Dashboard user
                            </a>
                            @isset($availableWorkspaces)
                                <a href="{{ route('settings') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    Pengaturan workspace
                                </a>
                                <a href="{{ route('billing.index') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    Langganan &amp; tagihan
                                </a>
                            @endisset

                            <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-border pt-1.5">
                                @csrf
                                <button class="block w-full rounded-lg px-3 py-2 text-left text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    Keluar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <h1 class="mb-4 text-xl font-semibold lg:hidden">@yield('title', 'Admin')</h1>

            @if (session('status'))
                <div class="mb-4 rounded-lg border border-primary/20 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    @include('partials.pesan-server')

@stack('scripts')
</body>
</html>
