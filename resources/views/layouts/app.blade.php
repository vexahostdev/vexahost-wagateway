<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name') }}</title>

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
<body class="h-full bg-background text-foreground antialiased">

@php
    /*
     | Menu samping, dikelompokkan.
     |
     | Sembilan tautan dalam satu daftar rata membuat orang membaca seluruhnya
     | setiap kali mencari satu. Dikelompokkan, mata cukup memilih kelompoknya
     | dulu. Pengelompokannya mengikuti apa yang sedang dikerjakan pengguna,
     | bukan urutan pembuatan fiturnya.
     |
     | `cocok` dipisah dari `rute` karena beberapa halaman anak punya nama rute
     | sendiri — membuka satu pesan (`messages.show`) atau satu tagihan
     | (`billing.invoice`) harus tetap menyalakan menu induknya. Tanpa itu,
     | pengguna di halaman anak melihat seluruh menu padam dan kehilangan
     | petunjuk di mana ia berada.
     */
    $menu = [
        'Utama' => [
            ['rute' => 'dashboard', 'label' => 'Dashboard', 'ikon' => 'M3 12l9-9 9 9M5 10v10h14V10'],
            ['rute' => 'sessions.index', 'label' => 'Sesi WhatsApp', 'ikon' => 'M12 2a10 10 0 1 0 4.9 18.7L22 22l-1.3-5.1A10 10 0 0 0 12 2z'],
        ],
        'Pesan' => [
            ['rute' => 'messages.compose', 'label' => 'Kirim Pesan', 'ikon' => 'M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z'],
            ['rute' => 'messages.index', 'label' => 'Riwayat Pesan', 'cocok' => ['messages.index', 'messages.show'], 'ikon' => 'M12 8v4l3 2M12 22a10 10 0 1 1 0-20 10 10 0 0 1 0 20z'],
            ['rute' => 'templates.index', 'label' => 'Template', 'ikon' => 'M4 4h16v4H4zM4 12h10v8H4zM18 12h2v8h-2z'],
        ],
        'Integrasi' => [
            ['rute' => 'api-keys.index', 'label' => 'API Keys', 'ikon' => 'M21 2l-2 2m-7.6 7.6a5 5 0 1 1-7 7 5 5 0 0 1 7-7zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3'],
            ['rute' => 'webhooks.index', 'label' => 'Webhooks', 'ikon' => 'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0'],
        ],
        'Akun' => [
            ['rute' => 'billing.index', 'label' => 'Langganan', 'cocok' => ['billing.*'], 'ikon' => 'M2 7h20v12H2zM2 11h20M6 15h4'],
            // Hanya untuk workspace PAYG. Menampilkannya ke pelanggan
            // berlangganan berarti menu yang isinya selalu nol dan tidak
            // pernah bisa dipakai — dan menu mati mengajari orang mengabaikan
            // menu.
            ...(($currentWorkspace ?? null)?->isPayg() ? [
                ['rute' => 'balance.index', 'label' => 'Saldo', 'cocok' => ['balance.*'], 'ikon' => 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
            ] : []),
            ['rute' => 'mitra.index', 'label' => 'Program Mitra', 'cocok' => ['mitra.*'], 'ikon' => 'M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm6 3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM7 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0z'],
            ['rute' => 'settings', 'label' => 'Pengaturan', 'cocok' => ['settings', 'settings.*'], 'ikon' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 2.6 7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H7a1.7 1.7 0 0 0 1-1.5V1a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V7a1.7 1.7 0 0 0 1.5 1H23a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'],
        ],
        'Bantuan' => [
            // Selalu ada, termasuk saat langganannya mati — rutenya memang di
            // luar middleware `subscription`: pelanggan yang layanannya berhenti
            // justru yang paling butuh menghubungi kami.
            ['rute' => 'tickets.index', 'label' => 'Bantuan', 'cocok' => ['tickets.*'], 'ikon' => 'M12 17h.01M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'],
            ['rute' => 'docs.index', 'label' => 'Dokumentasi', 'eksternal' => true, 'ikon' => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z'],
        ],
        'Layanan Lain' => [
            ['url' => 'https://vexahostcloud.my.id/', 'label' => 'Cloud VPS', 'eksternal' => true, 'ikon' => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2'],
            ['url' => 'https://build.vexahostcloud.my.id', 'label' => 'Jasa Web', 'eksternal' => true, 'ikon' => 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9'],
        ],
    ];

    $aktif = function (array $item): bool {
        return isset($item['rute']) && request()->routeIs(...($item['cocok'] ?? [$item['rute']]));
    };
@endphp

<div x-data="{ sidebar: false }">

    {{-- Penutup layar saat menu terbuka di layar kecil --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-40 bg-black/50 lg:hidden"></div>

    {{-- ===================== Sidebar =====================

         Menempel di tepi kiri dengan latar dan garisnya sendiri, bukan menyatu
         dengan halaman seperti sebelumnya. Bedanya bukan selera: sidebar yang
         berlatar sama dengan konten tidak terbaca sebagai navigasi tetap —
         orang membacanya sebagai kolom teks pertama halaman, lalu mencari menu
         di tempat lain.
         ============================================================= --}}
    {{-- Buka-tutupnya diatur `.panel-sidebar` di app.css, bukan utilitas
         `-translate-x-full` Tailwind. Dua aturan pendek yang menyebut
         `transform` secara langsung lebih mudah ditelusuri daripada utilitas v4
         yang menyusun nilainya dari beberapa variabel (`--tw-translate-x/y/z`)
         — dan di layar lebar sidebar tidak bergeser sama sekali, jadi tidak ada
         yang hilang dengan tidak memakai varian `lg:`. --}}
    <aside class="panel-sidebar fixed inset-y-0 left-0 z-50 flex w-full lg:w-72 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform duration-200 ease-out"
           :class="sidebar && 'terbuka'">

        <div class="flex h-20 shrink-0 items-center gap-3.5 px-5">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3.5">
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

        {{-- Menu selalu tampil, termasuk sebelum pengguna punya workspace. --}}
        <nav class="flex-1 space-y-6 overflow-y-auto px-3 pt-7 pb-4">
            @php $punyaWorkspace = isset($availableWorkspaces); @endphp
            @foreach ($menu as $kelompok => $tautan)
                <div>
                    <p class="px-3 pb-1.5 text-[0.68rem] font-semibold uppercase tracking-wider text-muted-foreground/80">{{ $kelompok }}</p>
                    <div class="space-y-0.5">
                        @if ($kelompok === 'Utama' && $punyaWorkspace)
                            {{-- Dropdown Pemilih Workspace sebagai menu menurun inline --}}
                            <div x-data="{ terbuka: false }" @click.outside="terbuka = false" class="mb-0.5">
                                <button type="button"
                                        @click="terbuka = ! terbuka"
                                        class="group flex w-full items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                                        :class="terbuka ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium' : 'text-muted-foreground'">
                                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded bg-sidebar-primary text-[10px] font-bold text-sidebar-primary-foreground">
                                        {{ mb_strtoupper(mb_substr($currentWorkspace->name ?? 'W', 0, 1)) }}
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-left font-medium text-foreground">
                                        {{ $currentWorkspace->name }}
                                    </span>
                                    <svg class="h-3.5 w-3.5 shrink-0 text-muted-foreground/70 transition-transform duration-200"
                                         :class="terbuka ? 'rotate-180 text-foreground' : ''"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                        <path d="m6 9 6 6 6-6"/>
                                    </svg>
                                </button>

                                <div x-show="terbuka" x-cloak x-collapse class="ml-4 mt-1 space-y-0.5 border-l border-sidebar-border/80 pl-2.5">
                                    @foreach ($availableWorkspaces as $t)
                                        <form method="POST" action="{{ route('workspaces.switch', $t->id) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="group flex w-full items-center justify-between rounded-md px-2.5 py-1.5 text-xs transition {{ $t->id === $currentWorkspace->id ? 'bg-sidebar-primary/10 font-semibold text-sidebar-primary' : 'text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground' }}">
                                                <span class="truncate">{{ $t->name }}</span>
                                                @if ($t->id === $currentWorkspace->id)
                                                    <svg class="h-3.5 w-3.5 shrink-0 text-sidebar-primary" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                                    </svg>
                                                @endif
                                            </button>
                                        </form>
                                    @endforeach

                                    <a href="{{ route('onboarding.create') }}"
                                       class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs text-muted-foreground/80 transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground">
                                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg>
                                        <span class="truncate">+ Workspace Baru</span>
                                    </a>
                                </div>
                            </div>
                        @endif
                        @foreach ($tautan as $item)
                            @php $eksternal = $item['eksternal'] ?? false; @endphp
                            {{-- Penanda untuk tur pengenalan. Memakai nama rute,
                                 bukan urutan atau kelas CSS: keduanya berubah
                                 tiap kali menunya ditata ulang, dan tur yang
                                 menunjuk sudut kosong layar lebih buruk
                                 daripada tur yang tidak ada. --}}
                            <a @if(isset($item['rute'])) data-tur-menu="{{ $item['rute'] }}" @endif
                               href="{{ $item['url'] ?? (($eksternal || $punyaWorkspace) ? route($item['rute']) : route('onboarding.create')) }}"
                               @if ($eksternal) target="_blank" rel="noopener" @endif
                               class="group flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-sm {{ ! $eksternal && $punyaWorkspace && $aktif($item) ? 'bg-sidebar-primary font-medium text-sidebar-primary-foreground' : 'text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground' }}">
                                <svg class="h-[1.05rem] w-[1.05rem] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="{{ $item['ikon'] }}"/></svg>
                                <span class="truncate">{{ $item['label'] }}</span>
                                @if ($eksternal)
                                    <svg class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground/70 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                        <path d="M7 17L17 7M17 7H7M17 7V17"/>
                                    </svg>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        {{-- Kaki sidebar hanya memuat satu tombol logout. --}}
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

    {{-- ===================== Isi halaman ===================== --}}
    <div class="lg:pl-72">

        {{-- Judul halaman tinggal di bilah atas, bukan lagi sebagai <h1> di
             dalam konten. Dengan begitu ia tetap terlihat saat halaman digulir,
             dan tiap halaman mulai langsung dari isinya. --}}
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-md sm:px-6">
            <button @click="sidebar = true" class="-ml-1 rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground lg:hidden" aria-label="Buka menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="min-w-0 truncate text-lg font-semibold">@yield('title', 'Dashboard')</h1>

            <div class="ml-auto flex items-center gap-1">
                {{-- Lonceng SEBELUM sakelar tema: yang menuntut tindakan berdiri
                     lebih dulu daripada yang cuma preferensi tampilan. --}}
                <x-lonceng audience="workspace" />

                <button @click="const d = document.documentElement.classList.toggle('dark'); localStorage.theme = d ? 'dark' : 'light'; document.documentElement.style.colorScheme = d ? 'dark' : 'light';"
                        class="rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Ganti tema tampilan">
                    <svg class="hidden h-5 w-5 dark:block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <svg class="block h-5 w-5 dark:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                </button>

                {{-- ===================== Profil =====================

                     Di kanan atas, bukan di kaki sidebar. Sidebar menampung apa
                     yang bisa dikerjakan di dalam workspace; akun bukan bagian
                     dari itu, dan di ponsel kaki sidebar hanya terlihat setelah
                     menunya dibuka.
                     ============================================ --}}
                <div class="relative ml-1" x-data="{ profil: false }" @click.outside="profil = false" @keydown.escape.window="profil = false">
                    {{-- Hanya avatar. Namanya tetap ada, tapi di dalam dropdown-nya
                         bersama email — di bilah atas ia cuma mengulang sesuatu yang
                         sudah pasti diketahui orang yang sedang login. --}}
                    <button @click="profil = ! profil"
                            class="flex items-center p-1 rounded-full hover:bg-muted transition-colors focus:outline-none"
                            :aria-expanded="profil" aria-haspopup="true"
                            aria-label="Menu akun" title="{{ auth()->user()->name }}">
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
                                <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ auth()->user()->email }}</p>
                            </div>
                        </div>

                        <div class="p-1.5">
                            <a href="{{ route('profile.show') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                Profil saya
                            </a>
                            @isset($availableWorkspaces)
                                <a href="{{ route('settings') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    Pengaturan workspace
                                </a>
                                <a href="{{ route('billing.index') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    Langganan &amp; tagihan
                                </a>
                            @endisset

                            @if (auth()->user()->is_super_admin)
                                <a href="{{ route('admin.overview') }}" class="block rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                    Panel admin
                                </a>
                            @endif

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

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">

            @include('partials.alert-tagihan-pending')

            {{-- Spanduk langganan.

                 Ini satu-satunya pemberitahuan yang pasti sampai: aplikasi tidak
                 punya konfigurasi email, dan pengingat WhatsApp cuma terkirim ke
                 workspace yang mengisi nomor tagihannya. Karena itu ia muncul di
                 SETIAP halaman dashboard, bukan hanya di halaman Langganan —
                 pelanggan yang gateway-nya berjalan lancar justru yang paling
                 jarang membuka halaman itu. --}}
            {{-- Spanduk ini menyusul pengguna ke SETIAP halaman, kecuali dua yang
                 sudah punya penjelasannya sendiri yang jauh lebih lengkap:
                 Langganan (dengan tanggal dan tangga apa yang terjadi
                 selanjutnya) dan Sesi (dengan nasib nomor yang sudah tertaut).
                 Tanpa pengecualian ini, halaman Sesi menampilkan tiga kotak
                 beruntun yang mengatakan hal yang sama dengan kalimat berbeda —
                 dan ketiganya berhenti dibaca. --}}
            @if (isset($currentSubscription) && ! request()->routeIs('billing.*', 'sessions.*'))
                {{-- Yang belum pernah berlangganan butuh kalimat yang berbeda dari
                     yang langganannya berhenti. "Layanan sedang berhenti"
                     tidak masuk akal bagi orang yang belum pernah mengirim apa pun,
                     dan kalimat yang salah di layar pertama membuat pendaftar baru
                     mengira ada yang rusak. --}}
                @if ($currentSubscription->isFreeTier() && ! $currentWorkspace->isExempt())
                    {{-- Sisa jatah, bukan sisa hari. Masa coba ini tidak punya
                         tanggal berakhir; yang menghabiskannya adalah pesan
                         kelima, dan angka itulah yang harus terlihat sebelum
                         seseorang membangun integrasi di atasnya. --}}
                    @php
                        $jatahGratis = (int) $currentWorkspace->monthly_message_quota;
                        $terpakaiGratis = $currentWorkspace->freeMessagesUsed();
                        $sisaGratis = max(0, $jatahGratis - $terpakaiGratis);
                    @endphp
                    <div @class([
                        'mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 text-sm',
                        'border-primary/20 bg-primary/10 text-primary' => $sisaGratis > 0,
                        'border-destructive/20 bg-destructive/10 text-destructive' => $sisaGratis === 0,
                    ])>
                        <span>
                            @if ($sisaGratis > 0)
                                <strong>Masa coba gratis: sisa {{ $sisaGratis }} dari {{ $jatahGratis }} pesan.</strong>
                                Nomor Anda tetap tertaut setelah jatahnya habis — yang berhenti hanya pengirimannya.
                            @else
                                <strong>Jatah {{ $jatahGratis }} pesan gratis sudah habis.</strong>
                                Nomor Anda masih tertaut dan tidak perlu discan ulang. Pilih paket untuk mengirim lagi.
                            @endif
                        </span>
                        <a href="{{ route('billing.plans') }}" @class([
                            'shrink-0 rounded-lg px-3 py-1.5 font-medium',
                            'bg-primary text-primary-foreground hover:opacity-90' => $sisaGratis > 0,
                            'bg-destructive text-white hover:opacity-90' => $sisaGratis === 0,
                        ])>
                            {{ $sisaGratis > 0 ? 'Lihat paket' : 'Pilih paket' }}
                        </a>
                    </div>
                @elseif ($currentSubscription->isUnpaid())
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/10 px-4 py-3 text-sm text-primary">
                        <span>
                            <strong>Pilih paket untuk mulai.</strong>
                            Menautkan nomor dan mengirim pesan terbuka setelah langganan pertama Anda aktif.
                        </span>
                        <a href="{{ route('billing.plans') }}" class="shrink-0 rounded-lg bg-primary px-3 py-1.5 font-medium text-primary-foreground hover:opacity-90">
                            Lihat paket
                        </a>
                    </div>
                @elseif (! $currentSubscription->isUsable())
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        <span>
                            <strong>Layanan sedang berhenti.</strong>
                            @if ($currentSubscription->status === 'suspended')
                                Nomor sudah dilepas dari gateway. WhatsApp di ponsel Anda tidak terpengaruh.
                            @else
                                Pengiriman, pesan masuk, dan webhook berhenti — WhatsApp di ponsel Anda tidak terpengaruh.
                            @endif
                        </span>
                        {{-- Menuju halaman paket, bukan ringkasan: yang dibutuhkan
                             orang yang menekan tombol ini adalah memilih dan membayar,
                             bukan membaca lagi bahwa langganannya habis. --}}
                        <a href="{{ route('billing.plans') }}" class="shrink-0 rounded-lg bg-destructive px-3 py-1.5 font-medium text-white hover:opacity-90">
                            Perpanjang sekarang
                        </a>
                    </div>
                @elseif ($currentSubscription->isExpiringSoon())
                    @php $sisaHari = $currentSubscription->daysRemaining(); @endphp
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                        <span>
                            Langganan {{ $currentSubscription->plan()->name() }} berakhir
                            <strong>{{ $sisaHari === 0 ? 'hari ini' : ($sisaHari === 1 ? 'besok' : "{$sisaHari} hari lagi") }}</strong>
                            ({{ $currentSubscription->current_period_end->translatedFormat('j F Y') }}).
                        </span>
                        <a href="{{ route('billing.plans') }}" class="shrink-0 rounded-lg border border-amber-500/40 px-3 py-1.5 font-medium hover:bg-amber-500/10">
                            Perpanjang
                        </a>
                    </div>
                @endif
            @endif

            @if (session('status'))
                <div class="mb-5 rounded-lg border border-primary/20 bg-primary/10 px-4 py-3 text-sm font-medium text-primary">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
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
</div>

@include('partials.pesan-server')

@stack('scripts')
</body>
</html>
