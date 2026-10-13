@extends('layouts.auth')

@section('title', 'Masuk - VexaHost WA Gateway')

@section('content')
<div>
    {{-- Header Form --}}
    <div class="mb-6">
        <h2 class="text-2xl sm:text-[1.7rem] font-bold tracking-tight text-foreground">
            Selamat Datang
        </h2>
        <p class="mt-1.5 text-xs sm:text-sm text-muted-foreground">
            Masuk ke dashboard akun VexaHost WA Anda
        </p>
    </div>

    {{-- Pesan Status / Sesi --}}
    @if (session('status'))
        <div class="mb-5 rounded-xl border border-primary/30 bg-primary/10 p-3.5 text-xs sm:text-sm text-primary flex items-start gap-2.5">
            <i class="bi bi-check-circle mt-0.5 shrink-0 text-base"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if (session('success'))
        <div class="mb-5 rounded-xl border border-primary/30 bg-primary/10 p-3.5 text-xs sm:text-sm text-primary flex items-start gap-2.5">
            <i class="bi bi-check-circle mt-0.5 shrink-0 text-base"></i>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($errors->any() && !$errors->has('email') && !$errors->has('password'))
        <div class="mb-5 rounded-xl border border-destructive/30 bg-destructive/10 p-3.5 text-xs sm:text-sm text-destructive flex items-start gap-2.5">
            <i class="bi bi-exclamation-triangle mt-0.5 shrink-0 text-base"></i>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    {{-- Form Login --}}
    <form action="{{ route('login') }}" method="POST" class="space-y-4">
        @csrf

        {{-- Field Email --}}
        <div>
            <label for="email" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-foreground">
                Email
            </label>
            <div class="relative">
                <input type="email" name="email" id="email" 
                       value="{{ old('email') }}" 
                       required autocomplete="email" autofocus
                       placeholder="nama@perusahaan.com"
                       class="w-full rounded-xl border @error('email') border-destructive focus:ring-destructive/20 focus:border-destructive @else border-input focus:border-primary focus:ring-primary/20 @enderror bg-background px-4 py-2.5 text-sm transition focus:outline-none focus:ring-2">
            </div>
            @error('email')
                <p class="mt-1.5 flex items-center gap-1.5 text-xs text-destructive font-medium">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        {{-- Field Password --}}
        <div>
            <label for="password" class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-foreground">
                Password
            </label>
            <div class="relative">
                <input type="password" name="password" id="password" 
                       required autocomplete="current-password"
                       placeholder="••••••••"
                       class="w-full rounded-xl border @error('password') border-destructive focus:ring-destructive/20 focus:border-destructive @else border-input focus:border-primary focus:ring-primary/20 @enderror bg-background px-4 py-2.5 pr-11 text-sm transition focus:outline-none focus:ring-2">
                <button type="button" 
                        onclick="togglePasswordVisibility('password', this)" 
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground transition hover:text-foreground p-1"
                        title="Tampilkan / Sembunyikan Kata Sandi"
                        aria-label="Tampilkan atau sembunyikan kata sandi">
                    <i class="bi bi-eye text-base"></i>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 flex items-center gap-1.5 text-xs text-destructive font-medium">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        {{-- Baris Ingat Saya & Lupa Kata Sandi --}}
        <div class="flex items-center justify-between text-xs pt-1">
            <label class="flex items-center gap-2 cursor-pointer select-none text-muted-foreground hover:text-foreground">
                <input type="checkbox" name="remember" id="remember" value="1"
                       class="rounded border-input text-primary focus:ring-primary h-4 w-4">
                <span>Ingat saya</span>
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="font-medium text-primary hover:underline">
                    Lupa kata sandi?
                </a>
            @else
                <a href="mailto:{{ \App\Support\MailIdentity::contactEmail() }}?subject=Lupa%20kata%20sandi%20VexaHost%20WA"
                   class="font-medium text-primary hover:underline">
                    Lupa kata sandi?
                </a>
            @endif
        </div>

        {{-- Tombol Submit --}}
        <div class="pt-1.5">
            <button type="submit" 
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90 active:scale-[0.99]">
                <span>Masuk ke Akun</span>
                <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </form>

    {{-- Pemisah --}}
    <div class="relative my-5">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-border"></div>
        </div>
        <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-background px-3 text-muted-foreground font-medium">atau masuk dengan</span>
        </div>
    </div>

    {{-- Tombol Google --}}
    <div>
        <a href="{{ route('auth.google') }}" 
           class="inline-flex w-full items-center justify-center gap-3 rounded-xl border border-border bg-card px-4 py-2.5 text-sm font-semibold text-foreground shadow-2xs transition hover:bg-muted hover:border-border/80 active:scale-[0.99]">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Lanjutkan dengan Google</span>
        </a>
    </div>

    {{-- Tautan Daftar Akun --}}
    <p class="mt-5 text-center text-xs text-muted-foreground">
        Belum punya akun? 
        <a href="{{ route('register') }}" class="font-semibold text-primary hover:underline">
            Daftar di sini
        </a>
    </p>
</div>
@endsection

