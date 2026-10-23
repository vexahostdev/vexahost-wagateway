@extends('layouts.admin')
@section('title', $session->name)

@section('content')
    @php
        $terakhirTersambung = $kejadian->firstWhere('event', 'connected');
        $putus30Hari = $kejadian->where('event', 'disconnected')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $cadanganTerbaru = $cadangan->first();
    @endphp

    {{-- ===================== Kepala halaman ===================== --}}
    <div class="mb-5 min-w-0">
        <a href="{{ route('admin.sessions') }}" class="inline-flex items-center gap-1 text-sm text-muted-foreground transition hover:text-foreground">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg>
            Semua sesi
        </a>
        <div class="mt-1.5 flex flex-wrap items-center gap-2.5">
            <h2 class="text-xl font-semibold tracking-tight">{{ $session->name }}</h2>
            <x-session-status :status="$session->status" />
            @if ($session->trashed())
                <x-badge warna="merah">dihapus</x-badge>
            @endif
        </div>
        <p class="mt-1 text-sm text-muted-foreground">
            @if ($session->workspace)
                <a href="{{ route('admin.workspaces.show', $session->workspace->id) }}" class="hover:text-foreground hover:underline">{{ $session->workspace->name }}</a>
            @else
                tanpa workspace
            @endif
            · <span class="font-mono text-xs">{{ $session->phone_number ?? 'belum tertaut' }}</span>
            · <code class="text-xs">{{ $session->id }}</code>
        </p>
    </div>

    {{-- ===================== Angka ===================== --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Tersambung sejak"
                :nilai="$session->connected_at?->translatedFormat('j M, H:i') ?? '—'"
                :sub="$session->connected_at ? $session->connected_at->diffForHumans() : 'tidak punya kredensial tersambung'"
                ikon="M20 6 9 17l-5-5" />

        <x-stat label="Putus 30 hari" :nilai="$putus30Hari"
                sub="dari riwayat di bawah"
                :nada="$putus30Hari >= 5 ? 'bahaya' : ($putus30Hari >= 2 ? 'perhatian' : 'netral')"
                ikon="M18.4 5.6a9 9 0 1 1-12.7 0M12 2v10" />

        <x-stat label="Percobaan otomatis" :nilai="$session->connect_failures.'/'.$batasPercobaan"
                sub="penjadwal berhenti di ambang ini"
                :nada="$session->connect_failures >= $batasPercobaan ? 'bahaya' : 'netral'"
                ikon="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5" />

        <x-stat label="Cadangan kredensial"
                :nilai="$cadanganTerbaru?->backed_up_at?->diffForHumans() ?? 'tidak ada'"
                :sub="$cadangan->count().' tersimpan'"
                :nada="$cadanganTerbaru === null && $session->connected_at ? 'bahaya' : 'netral'"
                ikon="M4 7h16M4 12h16M4 17h10" />
    </div>

    @if ($session->last_error)
        <x-section judul="Yang dilihat pemilik nomor" class="mt-8">
            <p class="max-w-3xl text-sm leading-relaxed">{{ $session->last_error }}</p>
            @if ($session->disconnect_reason)
                <p class="mt-1 text-xs text-muted-foreground">Alasan dari engine: <code>{{ $session->disconnect_reason }}</code></p>
            @endif
        </x-section>
    @endif

    {{-- ===================== Riwayat ===================== --}}
    <x-section judul="Riwayat koneksi" class="mt-8"
               sub="100 kejadian terakhir. Putus yang selalu jatuh sesaat setelah &quot;Dipulihkan saat engine menyala&quot; menunjuk ke deploy, bukan ke nomornya.">
        <x-tabel :kepala="['Waktu' => 'w-44', 'Kejadian', 'Kode' => 'w-20 text-right']">
            @forelse ($kejadian as $baris)
                <tr class="transition hover:bg-muted/40">
                    <td class="px-4 py-2.5 text-muted-foreground tabular-nums sm:px-3" title="{{ $baris->created_at->toDateTimeString() }}">
                        {{ $baris->created_at->translatedFormat('j M Y, H:i:s') }}
                    </td>
                    <td @class(['px-4 py-2.5 sm:px-3', 'text-destructive' => $baris->buruk()])>
                        {{ $baris->label() }}
                        @if ($baris->detail)
                            <span class="block text-xs text-muted-foreground">{{ $baris->detail }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs text-muted-foreground sm:px-3">{{ $baris->status_code ?? '' }}</td>
                </tr>
            @empty
                <x-kosong :kolom="3" judul="Belum ada riwayat"
                          pesan="Riwayat mulai dicatat sejak fitur ini dipasang. Kejadian sebelumnya hanya ada di log engine." />
            @endforelse
        </x-tabel>
    </x-section>

    {{-- ===================== Cadangan ===================== --}}
    <x-section judul="Cadangan kredensial" class="mt-8"
               sub="Yang dipakai memulihkan selalu cadangan terbaru yang layak; yang rusak dilewati.">
        <x-tabel :kepala="['Waktu' => 'w-44', 'Berkas', 'Ukuran' => 'w-24 text-right', 'Layak' => 'w-20 text-right']">
            @forelse ($cadangan as $backup)
                <tr class="transition hover:bg-muted/40">
                    <td class="px-4 py-2.5 text-muted-foreground tabular-nums sm:px-3">{{ $backup->backed_up_at?->translatedFormat('j M Y, H:i:s') ?? '—' }}</td>
                    <td class="px-4 py-2.5 font-mono text-xs text-muted-foreground sm:px-3">{{ $backup->path }}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground sm:px-3">{{ number_format($backup->size / 1024, 1) }} KB</td>
                    <td class="px-4 py-2.5 text-right sm:px-3">
                        @if ($backup->isiLayak() !== null)
                            <x-badge warna="hijau">ya</x-badge>
                        @else
                            <x-badge warna="merah">tidak</x-badge>
                        @endif
                    </td>
                </tr>
            @empty
                <x-kosong :kolom="4" judul="Tidak ada cadangan"
                          pesan="Tanpa cadangan, sesi ini hanya bisa pulih dari berkas di volume engine." />
            @endforelse
        </x-tabel>
    </x-section>
@endsection
