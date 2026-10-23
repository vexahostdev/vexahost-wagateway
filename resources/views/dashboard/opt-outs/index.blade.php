@extends('layouts.app')
@section('title', 'Berhenti Berlangganan')

@section('content')
@php
    $terkunci = ($currentSubscription ?? null) && ! $currentSubscription->isUsable();
    $kataBerhenti = implode(', ', config('gateway.rem.kata_berhenti'));
    $kataMulai = implode(', ', config('gateway.rem.kata_mulai'));
@endphp

    <x-section judul="Penerima yang meminta berhenti"
               sub="Nomor yang membalas {{ $kataBerhenti }} tidak dikirimi pesan lagi dari workspace ini, dan pesan ke mereka tidak memotong kuota.">
        <div class="mb-4 space-y-1 text-sm leading-relaxed text-muted-foreground">
            <p>
                Yang tetap terkirim: <span class="text-foreground">kode OTP</span>, dan
                <span class="text-foreground">balasan setelah penerimanya menghubungi Anda lagi</span>.
                Penerima yang membalas {{ $kataMulai }} keluar dari daftar ini sendiri.
            </p>
            <p>Hapus nomor dari daftar hanya kalau penerimanya sendiri yang meminta dikirimi lagi.</p>
        </div>

        <form method="GET" class="mb-4 flex flex-wrap gap-2">
            <input name="cari" value="{{ $cari }}" inputmode="numeric" placeholder="Cari nomor"
                   class="w-56 rounded-lg border-input bg-background text-sm focus:border-primary focus:ring-primary">
            <button class="rounded-lg border border-input bg-background px-4 py-2 text-sm hover:bg-muted">Cari</button>
        </form>

        <x-tabel :kepala="['Nomor', 'Membalas', 'Sejak', '' => 'text-right']">
            @forelse ($optOuts as $optOut)
                <tr class="transition hover:bg-muted/40">
                    <td class="px-4 py-2.5 font-mono text-xs sm:px-3">{{ $optOut->phone }}</td>
                    <td class="px-4 py-2.5 sm:px-3"><x-badge>{{ $optOut->keyword }}</x-badge></td>
                    <td class="px-4 py-2.5 text-muted-foreground sm:px-3">{{ $optOut->updated_at->translatedFormat('j M Y, H:i') }}</td>
                    <td class="px-4 py-2.5 text-right sm:px-3">
                        @unless ($terkunci)
                            <form method="POST" action="{{ route('opt-outs.destroy', $optOut->id) }}"
                                  data-konfirmasi="Hapus {{ $optOut->phone }} dari daftar? Pastikan penerimanya sendiri yang meminta dikirimi pesan lagi — mengirim ke orang yang sudah meminta berhenti adalah cara tercepat nomor Anda dilaporkan.">
                                @csrf @method('DELETE')
                                <button class="text-sm text-primary hover:underline">Hapus dari daftar</button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <x-kosong :kolom="4"
                          :judul="$cari !== '' ? 'Nomor tidak ditemukan' : 'Belum ada yang meminta berhenti'"
                          :pesan="$cari !== '' ? null : 'Nomor yang membalas '.$kataBerhenti.' ke nomor Anda akan muncul di sini.'" />
            @endforelse
        </x-tabel>

        <div class="mt-4">{{ $optOuts->links() }}</div>
    </x-section>
@endsection
