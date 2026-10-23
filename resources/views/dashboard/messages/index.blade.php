@extends('layouts.app')
@section('title', 'Riwayat Pesan')

@section('content')
    <x-section rapat>
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="min-w-40 flex-1">
                <label class="mb-1 block text-sm font-medium" for="q">Cari</label>
                <input id="q" name="q" value="{{ request('q') }}" placeholder="nomor atau isi pesan"
                       class="w-full rounded-lg border-input bg-background text-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium" for="session">Sesi</label>
                <select id="session" name="session" class="rounded-lg border-input bg-background text-sm focus:border-primary focus:ring-primary">
                    <option value="">Semua</option>
                    @foreach ($sessions as $session)
                        <option value="{{ $session->id }}" @selected(request('session') === $session->id)>{{ $session->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium" for="direction">Arah</label>
                <select id="direction" name="direction" class="rounded-lg border-input bg-background text-sm focus:border-primary focus:ring-primary">
                    <option value="">Semua</option>
                    <option value="outbound" @selected(request('direction') === 'outbound')>Keluar</option>
                    <option value="inbound" @selected(request('direction') === 'inbound')>Masuk</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium" for="status">Status</label>
                <select id="status" name="status" class="rounded-lg border-input bg-background text-sm focus:border-primary focus:ring-primary">
                    <option value="">Semua</option>
                    @foreach (['queued' => 'Antre', 'sending' => 'Mengirim', 'sent' => 'Terkirim', 'delivered' => 'Sampai', 'read' => 'Dibaca', 'failed' => 'Gagal'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="rounded-lg border border-input bg-background px-4 py-2 text-sm hover:bg-muted">Filter</button>
        </form>
    </x-section>

    <x-section>
        @if ($messages->isEmpty())
            <p class="py-6 text-center text-sm text-muted-foreground">Tidak ada pesan yang cocok dengan saringan ini.</p>
        @else
                            <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-y border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2">Waktu</th>
                            <th class="px-5 py-2">Sesi</th>
                            <th class="px-5 py-2">Arah</th>
                            <th class="px-5 py-2">Nomor</th>
                            <th class="px-5 py-2">Isi</th>
                            <th class="px-5 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($messages as $m)
                            <tr class="transition hover:bg-muted/40">
                                <td class="whitespace-nowrap px-5 py-2 text-muted-foreground">
                                    <a href="{{ route('messages.show', $m->id) }}" class="hover:underline">{{ $m->created_at->format('d M H:i:s') }}</a>
                                </td>
                                <td class="whitespace-nowrap px-5 py-2">{{ $m->session?->name ?? '—' }}</td>
                                <td class="px-5 py-2">{{ $m->direction === 'outbound' ? 'Keluar' : 'Masuk' }}</td>
                                <td class="whitespace-nowrap px-5 py-2 font-mono text-xs">{{ $m->to_number ?? $m->from_number }}</td>
                                <td class="max-w-sm truncate px-5 py-2 text-muted-foreground">{{ $m->body }}</td>
                                <td class="px-5 py-2">
                                    @php $lm = \App\Support\StatusBadge::message($m->status); @endphp
                                    <x-badge :warna="$lm['warna']">{{ $lm['label'] }}</x-badge>
                                    {{-- Tanpa ini pesan yang ditunda masa pemanasan terbaca
                                         sebagai "mengantre" berjam-jam — persis seperti
                                         antrean yang macet. --}}
                                    @if ($m->status === 'queued' && $m->scheduled_at?->isFuture())
                                        <span class="mt-0.5 block whitespace-nowrap text-xs text-muted-foreground"
                                              title="Ditunda masa pemanasan nomor pengirim">
                                            dikirim {{ $m->scheduled_at->translatedFormat('j M, H:i') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $messages->links() }}</div>
        @endif
    </x-section>
@endsection
