@extends('layouts.admin')
@section('title', 'Sesi WhatsApp')

@section('content')
    @php $persen = $kapasitas > 0 ? min(100, round($hidup / $kapasitas * 100)) : 0; @endphp

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Sesi hidup" :nilai="$hidup.'/'.$kapasitas"
                sub="tersambung, menyambung, atau menunggu scan"
                :nada="$persen >= 90 ? 'bahaya' : ($persen >= 70 ? 'perhatian' : 'netral')"
                ikon="M12 2a10 10 0 1 0 4.9 18.7L22 22l-1.3-5.1A10 10 0 0 0 12 2z" />

        <x-stat label="Kapasitas terpakai" :nilai="$persen.'%'"
                sub="batas dari WA_MAX_SESSIONS milik engine"
                :nada="$persen >= 90 ? 'bahaya' : 'netral'"
                ikon="M3 12h4l3 8 4-16 3 8h4" />

        <x-stat label="Tersambung penuh" :nilai="$sessions->where('status', 'connected')->count()"
                sub="di halaman ini" ikon="M20 6 9 17l-5-5" />

        <x-stat label="Perlu perhatian"
                :nilai="$sessions->whereIn('status', ['failed', 'qr'])->count()"
                sub="gagal atau menunggu scan"
                :nada="$sessions->whereIn('status', ['failed', 'qr'])->count() > 0 ? 'perhatian' : 'netral'"
                ikon="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
    </div>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <select name="status" class="rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
            <option value="">Semua status</option>
            @foreach (['connected', 'connecting', 'qr', 'pending', 'failed', 'disconnected'] as $nilai)
                <option value="{{ $nilai }}" @selected($status === $nilai)>{{ $nilai }}</option>
            @endforeach
        </select>
        <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">Saring</button>
    </form>

    <x-section>
            <table class="w-full min-w-[46rem] text-sm">
                <thead class="border-y border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-5 py-2 font-medium">Sesi</th>
                        <th class="px-5 py-2 font-medium">Workspace</th>
                        <th class="px-5 py-2 font-medium">Status</th>
                        <th class="px-5 py-2 font-medium">Nomor</th>
                        <th class="px-5 py-2 font-medium">Langganan</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($sessions as $session)
                        <tr class="transition hover:bg-muted/40">
                            <td class="px-5 py-2.5 font-medium">
                                <a href="{{ route('admin.sessions.show', $session->id) }}" class="hover:text-primary hover:underline">{{ $session->name }}</a>
                            </td>
                            <td class="px-5 py-2.5 text-muted-foreground">{{ $session->workspace?->name ?? '—' }}</td>
                            <td class="px-5 py-2.5"><x-session-status :status="$session->status" /></td>
                            <td class="px-5 py-2.5 font-mono text-xs text-muted-foreground">{{ $session->phone_number ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-muted-foreground">
                                {{ $session->workspace?->subscription?->statusLabel() ?? '—' }}
                            </td>
                            <td class="px-5 py-2.5 text-right">
                                @if ($session->status !== 'disconnected')
                                    {{-- Memutus, bukan melepas tautan: kredensialnya tetap
                                         tersimpan, jadi pemiliknya menyalakan lagi tanpa scan QR. --}}
                                    <form method="POST" action="{{ route('admin.sessions.disconnect', $session->id) }}"
                                          data-konfirmasi="Putus sesi ini? Pemiliknya bisa menyalakannya lagi tanpa scan ulang.">
                                        @csrf
                                        <button class="text-destructive hover:underline">Putus</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-kosong :kolom="6" judul="Tidak ada sesi" pesan="Belum ada nomor WhatsApp yang ditautkan di platform ini." />
                    @endforelse
                </tbody>
            </table>
    </x-section>

    <div class="mt-4">{{ $sessions->links() }}</div>
@endsection
