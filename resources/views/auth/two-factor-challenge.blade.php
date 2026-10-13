@extends('layouts.auth')

@section('title', 'Kode Verifikasi - VexaHost WA Gateway')

@section('content')
<div>
    <div class="mb-6">
        <h2 class="text-2xl font-bold tracking-tight text-foreground">Masukkan kode</h2>
        <p class="mt-1.5 text-xs text-muted-foreground sm:text-sm">
            Buka aplikasi authenticator di ponsel Anda dan masukkan kode enam digit yang sedang ditampilkan.
        </p>
    </div>

    <form method="POST" action="{{ route('two-factor.verify') }}">
        @csrf
        <input type="text" name="kode" required autofocus
               inputmode="numeric" autocomplete="one-time-code"
               maxlength="12" placeholder="123456"
               class="w-full rounded-xl border-input bg-background text-center font-mono text-lg tracking-[0.4em] focus:border-primary focus:ring-primary">

        @error('kode')
            <p class="mt-2 text-xs text-destructive">{{ $message }}</p>
        @enderror

        <button class="mt-4 w-full rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition hover:opacity-90">
            Verifikasi
        </button>
    </form>

    {{-- Jalan keluar kalau ponselnya hilang, ditulis di halaman ini juga.
         Kalau kalimat ini tidak ada, satu-satunya kesimpulan yang bisa diambil
         orang yang kehilangan ponselnya adalah bahwa akunnya hilang — dan
         sebagian akan berhenti mencoba di situ. --}}
    <div class="mt-6 rounded-xl border border-border bg-muted/40 px-4 py-3 text-xs text-muted-foreground">
        <p class="font-medium text-foreground">Ponsel Anda hilang atau tidak bisa dibuka?</p>
        <p class="mt-1">
            Masukkan salah satu <strong>kode pemulihan</strong> yang Anda simpan saat menyalakan 2FA —
            ketik di kolom yang sama. Tiap kode hanya berlaku sekali.
        </p>
        <p class="mt-1">
            Kode pemulihannya juga hilang? Hubungi kami di
            <a href="mailto:{{ \App\Support\MailIdentity::contactEmail() }}" class="underline">{{ \App\Support\MailIdentity::contactEmail() }}</a>
            dari alamat email akun ini. Kami akan meminta bukti kepemilikan sebelum melepaskannya —
            tanpa itu, siapa pun yang mengetahui email Anda bisa melewati 2FA hanya dengan mengaku kehilangan ponsel.
        </p>
    </div>

    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button class="text-xs text-muted-foreground underline hover:text-foreground">Keluar</button>
    </form>
</div>
@endsection
