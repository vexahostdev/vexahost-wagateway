@extends('layouts.checkout')
@section('title', 'Invoice Pencairan ' . $payout->payout_number)

@section('content')
<div class="mx-auto max-w-4xl py-2 sm:py-6">

    {{-- Tombol Aksi Navigasi & Print (Hidden saat cetak) --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div>
            @if (!empty($isAdmin))
                <a href="{{ route('admin.referrals') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3.5 py-2 text-xs font-semibold text-muted-foreground hover:bg-muted hover:text-foreground transition shadow-xs">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Kembali ke Panel Reseller Admin</span>
                </a>
            @else
                <a href="{{ route('mitra.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3.5 py-2 text-xs font-semibold text-muted-foreground hover:bg-muted hover:text-foreground transition shadow-xs">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    <span>Kembali ke Dashboard Mitra</span>
                </a>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @php
                $downloadUrl = !empty($isAdmin)
                    ? route('admin.referrals.payout.download', $payout->id)
                    : route('mitra.payout.download', $payout->id);
            @endphp
            <a href="{{ $downloadUrl }}" class="inline-flex items-center gap-2 rounded-xl border border-border bg-card px-4 py-2 text-xs sm:text-sm font-semibold text-foreground hover:bg-muted transition shadow-xs">
                <svg class="h-4 w-4 text-primary" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                <span>Unduh Dokumen PDF</span>
            </a>
            <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition active:scale-95">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                <span>Cetak / Print</span>
            </button>
        </div>
    </div>

    {{-- Kertas Invoice Standar Enterprise --}}
    <div class="overflow-hidden rounded-2xl border border-border/80 bg-card p-6 sm:p-10 shadow-lg print:border-none print:shadow-none print:p-0 print:m-0">
        
        {{-- Header Korporat --}}
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-6 border-b border-border/80 pb-8">
            <div class="flex items-start gap-4">
                <img src="{{ asset('images/vexahost-wa.png') }}" alt="VexaHost Logo" class="h-12 w-auto object-contain shrink-0">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-foreground">VexaHost WA Gateway</h2>
                    <p class="text-xs font-semibold text-primary uppercase tracking-wider">PT DESTINARA CHAKRAWALA ARTHA</p>
                    <p class="mt-1 text-xs text-muted-foreground leading-relaxed">
                        Layanan WhatsApp API Gateway &amp; Enterprise Messaging<br>
                        Email: {{ \App\Support\MailIdentity::contactEmail() }} &bull; Web: wa.vexahostcloud.my.id
                    </p>
                </div>
            </div>

            <div class="text-left sm:text-right">
                <span class="inline-block text-[11px] font-bold uppercase tracking-widest text-muted-foreground">
                    INVOICE PENCAIRAN KOMISI
                </span>
                <h1 class="mt-1 font-mono text-xl sm:text-2xl font-extrabold tracking-tight text-foreground">
                    {{ $payout->payout_number }}
                </h1>
                <div class="mt-2.5">
                    @if ($payout->isPaid())
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            DITRANSFER / LUNAS
                        </span>
                    @elseif ($payout->isRejected())
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-destructive/30 bg-destructive/10 px-3 py-1 text-xs font-bold text-destructive">
                            <span class="h-2 w-2 rounded-full bg-destructive"></span>
                            DITOLAK
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/40 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-700 dark:text-amber-300">
                            <span class="h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                            MENUNGGU VERIFIKASI TRANSFER
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Data Pihak & Detail Transaksi --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 py-6 border-b border-border/80 text-xs sm:text-sm">
            <div>
                <span class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Penerima Komisi (Mitra Reseller):</span>
                <p class="mt-1.5 text-base font-bold text-foreground">{{ $payout->user->name }}</p>
                <div class="mt-1 space-y-0.5 text-muted-foreground">
                    <p>Email: <span class="font-medium text-foreground">{{ $payout->user->email }}</span></p>
                    <p>WhatsApp: <span class="font-medium text-foreground">{{ $payout->referralCode?->whatsapp_number ?: ($payout->user->phone ?: '—') }}</span></p>
                    <p>Kode Mitra: <span class="font-mono font-bold text-primary">{{ $payout->referralCode?->code ?: '—' }}</span></p>
                </div>
            </div>

            <div class="rounded-xl border border-border/70 bg-muted/25 p-4 md:text-left">
                <span class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Tujuan Transfer Bank / E-Wallet:</span>
                <p class="mt-1.5 text-base font-bold text-primary">{{ $payout->bank_name }}</p>
                <p class="font-mono text-sm font-semibold tracking-wide text-foreground mt-0.5">
                    {{ $payout->bank_account_number }}
                </p>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Atas Nama: <span class="font-semibold text-foreground">{{ $payout->bank_account_name }}</span>
                </p>
            </div>
        </div>

        {{-- Metadata Tanggal & Waktu --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 py-4 border-b border-border/80 text-xs">
            <div>
                <span class="text-muted-foreground">Tanggal Pengajuan:</span>
                <p class="mt-0.5 font-semibold text-foreground">{{ $payout->created_at->translatedFormat('d F Y, H:i') }} WIB</p>
            </div>
            <div>
                <span class="text-muted-foreground">Tanggal Diproses:</span>
                <p class="mt-0.5 font-semibold text-foreground">
                    {{ $payout->paid_at ? $payout->paid_at->translatedFormat('d F Y, H:i') . ' WIB' : 'Sedang diverifikasi' }}
                </p>
            </div>
            <div>
                <span class="text-muted-foreground">Metode Pembayaran:</span>
                <p class="mt-0.5 font-semibold text-foreground">Transfer Bank Manual</p>
            </div>
            <div>
                <span class="text-muted-foreground">ID Referensi Sistem:</span>
                <p class="mt-0.5 font-mono font-semibold text-foreground">#PO-{{ str_pad((string)$payout->id, 5, '0', STR_PAD_LEFT) }}</p>
            </div>
        </div>

        {{-- Tabel Rincian Keuangan Enterprise --}}
        <div class="py-6">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-border text-xs uppercase tracking-wider text-muted-foreground">
                        <th class="py-3 font-semibold">Deskripsi Layanan &amp; Penarikan</th>
                        <th class="py-3 text-center font-semibold">Tarif / Ketentuan</th>
                        <th class="py-3 text-right font-semibold">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr>
                        <td class="py-4 pr-4">
                            <p class="font-semibold text-foreground">Pencairan Komisi Penjualan Kemitraan (Reseller)</p>
                            <p class="mt-1 text-xs text-muted-foreground leading-relaxed">
                                Akumulasi bagi hasil transaksi langganan klien downline kode <span class="font-mono font-bold text-foreground">{{ $payout->referralCode?->code }}</span> yang telah berstatus disetujui (approved).
                            </p>
                        </td>
                        <td class="py-4 px-2 text-center text-xs text-muted-foreground whitespace-nowrap">
                            Akumulasi Komisi
                        </td>
                        <td class="py-4 pl-4 text-right font-medium text-foreground tabular-nums whitespace-nowrap">
                            Rp {{ number_format($payout->amount, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td class="py-4 pr-4">
                            <p class="font-semibold text-foreground">Biaya Administrasi &amp; Penanganan Pencairan</p>
                            <p class="mt-1 text-xs text-muted-foreground leading-relaxed">
                                Biaya pemrosesan transfer perbankan dan administrasi sistem kemitraan sesuai Syarat &amp; Ketentuan resmi VexaHost WA Gateway ({{ $payout->fee_percent }}%).
                            </p>
                        </td>
                        <td class="py-4 px-2 text-center text-xs text-muted-foreground whitespace-nowrap">
                            {{ $payout->fee_percent }}% dari nominal
                        </td>
                        <td class="py-4 pl-4 text-right font-medium text-destructive tabular-nums whitespace-nowrap">
                            - Rp {{ number_format($payout->fee_amount, 0, ',', '.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Ringkasan Perhitungan Akhir --}}
        <div class="border-t border-border/80 pt-4">
            <div class="ml-auto max-w-sm space-y-2 text-xs sm:text-sm">
                <div class="flex justify-between text-muted-foreground">
                    <span>Total Komisi Bruto</span>
                    <span class="font-medium text-foreground tabular-nums">Rp {{ number_format($payout->amount, 0, ',', '.') }}</span>
                </div>
                <div class="flex justify-between text-muted-foreground">
                    <span>Biaya Administrasi ({{ $payout->fee_percent }}%)</span>
                    <span class="font-medium text-destructive tabular-nums">- Rp {{ number_format($payout->fee_amount, 0, ',', '.') }}</span>
                </div>
                <div class="flex justify-between border-t border-border pt-2 text-base font-extrabold">
                    <span class="text-foreground">Total Ditransfer (Net Payout)</span>
                    <span class="text-primary tabular-nums font-mono text-lg sm:text-xl">
                        Rp {{ number_format($payout->net_amount, 0, ',', '.') }}
                    </span>
                </div>
                <p class="text-[11px] text-muted-foreground text-right italic">
                    Terbilang: {{ ucwords(\Illuminate\Support\Str::headline(\NumberFormatter::create('id_ID', \NumberFormatter::SPELLOUT)?->format($payout->net_amount) ?? 'rupiah')) }} Rupiah
                </p>
            </div>
        </div>

        {{-- Catatan & Pengesahan Digital --}}
        <div class="mt-8 pt-6 border-t border-border/80 grid grid-cols-1 md:grid-cols-2 gap-6 items-end text-xs">
            <div class="rounded-xl border border-border/60 bg-muted/20 p-4 space-y-2">
                <span class="font-bold text-foreground">Catatan / Keterangan Transaksi:</span>
                <p class="text-muted-foreground leading-relaxed">
                    {{ $payout->admin_notes ?: ($payout->notes ?: 'Pencairan komisi reseller diproses dan diverifikasi manual oleh Finance VexaHost sesuai prosedur pencairan 1x24 jam kerja.') }}
                </p>
                @if ($payout->isPaid() && $payout->payer)
                    <p class="text-[11px] text-muted-foreground pt-1 border-t border-border/40">
                        Otorisasi transfer oleh: <strong class="text-foreground">{{ $payout->payer->name }}</strong> (Finance Officer)
                    </p>
                @endif
            </div>

            <div class="text-right space-y-2">
                <div class="inline-flex flex-col items-center border border-primary/20 bg-primary/5 rounded-xl px-5 py-3 text-center">
                    <div class="h-6 w-6 rounded-full bg-primary/20 text-primary grid place-items-center mb-1">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <span class="text-[10px] uppercase font-bold tracking-wider text-primary">Dokumen Elektronik Sah</span>
                    <span class="text-[11px] font-semibold text-foreground">PT DESTINARA CHAKRAWALA ARTHA</span>
                    <span class="text-[9px] text-muted-foreground font-mono mt-0.5">SHA256: {{ substr(hash('sha256', $payout->payout_number . $payout->amount . $payout->created_at), 0, 16) }}</span>
                </div>
                <p class="text-[10px] text-muted-foreground leading-tight">
                    Dokumen ini sah dan diterbitkan otomatis oleh sistem penagihan VexaHost WA Gateway.<br>
                    Diakui secara legal berdasarkan UU ITE Republik Indonesia.
                </p>
            </div>
        </div>

    </div>

    {{-- Footnote Print --}}
    <div class="mt-4 text-center text-[11px] text-muted-foreground hidden print:block">
        Dicetak pada {{ now()->translatedFormat('d F Y, H:i') }} WIB &bull; VexaHost WA Gateway Enterprise System
    </div>

</div>

<style>
@media print {
    body {
        background-color: #ffffff !important;
        color: #000000 !important;
    }
    header, nav, .print\:hidden {
        display: none !important;
    }
    .print\:border-none {
        border: none !important;
    }
    .print\:shadow-none {
        box-shadow: none !important;
    }
}
</style>
@endsection
