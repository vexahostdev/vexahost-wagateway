@extends('layouts.app')
@section('title', 'Detail Pesan')

@section('content')
    @php
        $lm = \App\Support\StatusBadge::message($message->status);

        $rincian = [
            'Sesi' => $message->session?->name ?? '—',
            'Arah' => $message->direction === 'outbound' ? 'Keluar' : 'Masuk',
            'Tujuan' => $message->to_number,
            'Pengirim' => $message->from_number ?? '—',
            'Tipe' => $message->type,
            'Percobaan' => $message->attempts,
            'ID pesan' => $message->id,
            'ID WhatsApp' => $message->wa_message_id ?? '—',
            'Batch' => $message->batch_id ?? '—',
        ];

        // Empat penanda waktu yang sama untuk tiap pesan; ditampilkan sebagai
        // urutan supaya terlihat sampai tahap mana pesannya berjalan.
        $jejak = [
            'Dibuat' => $message->created_at,
            'Terkirim' => $message->sent_at,
            'Sampai' => $message->delivered_at,
            'Dibaca' => $message->read_at,
        ];
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2.5 text-lg font-semibold tracking-tight">
                {{ $message->to_number }}
                <x-badge :warna="$lm['warna']" titik>{{ $lm['label'] }}</x-badge>
            </p>
            <p class="mt-0.5 text-sm text-muted-foreground">
                {{ $message->created_at->translatedFormat('j F Y, H:i:s') }}
            </p>
        </div>
        <a href="{{ route('messages.index') }}"
           class="inline-flex items-center gap-1.5 shrink-0 rounded-lg border border-border px-3.5 py-2 text-sm font-medium transition hover:bg-muted">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Kembali ke Riwayat</span>
        </a>
    </div>

    @if ($message->status === 'queued' && $message->scheduled_at?->isFuture())
        <div class="mb-5 rounded-xl border border-border bg-muted/40 px-4 py-3.5 text-sm">
            <p class="font-medium">Dijadwalkan {{ $message->scheduled_at->translatedFormat('j F Y, H:i') }}</p>
            <p class="mt-1 leading-relaxed text-muted-foreground">
                Nomor pengirimnya masih dalam masa pemanasan, dan jatah hari ini sudah terpakai. Pesannya tidak hilang
                — ia dikirim otomatis pada waktu di atas. Pemilik nomor yang sudah lama aktif bisa melewati masa
                pemanasan dari halaman Sesi WhatsApp.
            </p>
        </div>
    @endif

    @if ($message->error)
        <div class="mb-5 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3.5 text-sm text-destructive">
            <p class="font-medium">Pengiriman gagal</p>
            <p class="mt-1 break-words leading-relaxed">{{ $message->error }}</p>
        </div>
    @endif

    <x-section judul="Isi pesan" rapat>
        <pre class="whitespace-pre-wrap break-words rounded-lg border border-border bg-muted/40 p-4 text-sm">{{ $message->body }}</pre>
    </x-section>

    <x-section judul="Perjalanan pesan" rapat>
        <ol class="space-y-3 text-sm">
            @foreach ($jejak as $label => $waktu)
                <li class="flex items-start gap-3">
                    <span @class([
                        'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                        'bg-primary' => $waktu !== null,
                        'bg-border' => $waktu === null,
                    ])></span>
                    <span class="{{ $waktu ? '' : 'text-muted-foreground' }}">
                        <span class="font-medium">{{ $label }}</span>
                        <span class="ml-2 tabular-nums text-muted-foreground">
                            {{ $waktu?->translatedFormat('j M Y, H:i:s') ?? 'belum' }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ol>
    </x-section>

    <x-section judul="Rincian">
        <x-tabel :kepala="['Kolom' => '', 'Nilai' => '']">
            @foreach ($rincian as $label => $nilai)
                <tr class="transition hover:bg-muted/40">
                    <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground sm:px-3">{{ $label }}</td>
                    <td class="break-all px-4 py-2.5 font-mono text-xs sm:px-3">{{ $nilai }}</td>
                </tr>
            @endforeach
        </x-tabel>
    </x-section>

    @if ($message->provider_response)
        <x-section judul="Balasan provider"
                   sub="Jawaban mentah dari engine. Berguna saat status di sini tidak cocok dengan yang terlihat di WhatsApp."
                   rapat>
            <pre class="overflow-x-auto rounded-lg bg-muted p-3 font-mono text-xs">{{ json_encode($message->provider_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-section>
    @endif
@endsection
