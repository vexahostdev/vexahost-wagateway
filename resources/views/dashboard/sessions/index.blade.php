@extends('layouts.app')
@section('title', 'Sesi WhatsApp')

@section('content')
@php
    // Ditentukan sekali di atas: formulir apa pun di halaman ini hanya
    // ditampilkan kalau langganannya memang berlaku.
    $terkunci = ($currentSubscription ?? null) && ! $currentSubscription->isUsable();

    // Nomor yang berhenti karena tagihan bukan kerusakan, dan tidak boleh
    // terbaca seperti kerusakan.
    $alasanLangganan = ($currentSubscription ?? null)?->alasanNomorBerhenti();
@endphp

    @include('partials.sesi-perlu-dihubungkan')

<div
    x-data="qrModal(@js(session('open_qr')))"
    x-init="init()">

    @if ($terkunci)
        <x-kunci-langganan :subscription="$currentSubscription" aksi="menautkan nomor WhatsApp" />
    @else
    {{-- Formulir tetap berbingkai: ia satu-satunya hal di halaman ini yang
         menunggu keputusan, dan bingkainya yang memisahkannya dari daftar. --}}
    <div class="rounded-xl border border-border bg-card p-4 shadow-xs">
        <form data-tur="buat-sesi" method="POST" action="{{ route('sessions.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-48 flex-1">
                <label class="mb-1 block text-sm font-medium" for="name">Nama sesi</label>
                <input id="name" name="name" required maxlength="60" value="{{ old('name') }}"
                       placeholder="mis. CS Utama"
                       class="w-full rounded-lg border-input bg-background text-sm focus:border-primary focus:ring-primary">
            </div>
            <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">Buat sesi</button>
        </form>
        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
            Satu sesi = satu nomor WhatsApp. Nomor bisa diganti kapan saja lewat
            <em>Putus tautan</em> lalu <em>Hubungkan</em> dan scan dengan nomor baru —
            dan nomor yang sudah tertaut tidak akan terputus sendiri hanya karena server di-deploy ulang.
        </p>
    </div>
    @endif

    <x-section data-tur="daftar-sesi" judul="Nomor tertaut"
               sub="{{ $sessions->count() }} dari {{ $currentWorkspace->max_sessions }} nomor yang diizinkan paket Anda.">
        <x-tabel :kepala="['Sesi' => '', 'Nomor' => '', 'Status' => '', 'Tindakan' => 'text-right']">
            @forelse ($sessions as $session)
                <tr class="align-top transition hover:bg-muted/40">
                    <td class="px-4 py-3 sm:px-3">
                        <p class="font-medium">{{ $session->name }}</p>
                        {{-- ID sesi ditampilkan supaya tidak perlu ada yang menelusuri
                             URL atau memanggil API hanya untuk mengunci pengirim ke
                             satu nomor tertentu lewat WA_GATEWAY_SESSION. --}}
                        <div class="mt-1 flex items-center gap-1.5" x-data="{ disalin: false }">
                            <code class="truncate rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                                  x-ref="id">{{ $session->id }}</code>
                            <button type="button" title="Salin ID sesi"
                                    @click="navigator.clipboard.writeText($refs.id.textContent.trim()); disalin = true; setTimeout(() => disalin = false, 2000)"
                                    class="shrink-0 text-[11px] font-medium text-primary hover:underline"
                                    x-text="disalin ? 'Tersalin' : 'Salin ID'"></button>
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 sm:px-3">
                        {{ $session->phone_number ? '+'.$session->phone_number : '—' }}
                        @if ($session->push_name)
                            <span class="block text-xs text-muted-foreground">{{ $session->push_name }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 sm:px-3">
                        <x-session-status :status="$session->status" />

                        {{-- Galat teknis disembunyikan saat penyebabnya
                             langganan: blok di atas sudah menjelaskannya sekali
                             untuk seluruh daftar, dan mengulanginya di tiap
                             baris membuat pesan yang sama tercetak berkali-kali. --}}
                        @if (! $terkunci && $session->last_error)
                            <p class="mt-1.5 max-w-56 text-xs leading-relaxed text-destructive">{{ $session->last_error }}</p>
                        @endif

                        {{-- Masa pemanasan ditampilkan di samping statusnya, bukan
                             di halaman lain: pesan yang ditunda terasa seperti
                             gateway yang lambat kalau alasannya tidak terlihat
                             di tempat orang pertama kali mencarinya. --}}
                        @php $tahap = $pemanasan[$session->id] ?? null; @endphp
                        @if ($tahap)
                            <div class="mt-1.5 max-w-60 text-xs leading-relaxed text-muted-foreground">
                                <p><span class="font-medium text-foreground">Masa pemanasan</span> · hari {{ $tahap['hari'] }} dari {{ $tahap['sampai'] }}</p>
                                <p>
                                    {{ number_format($tahap['terpakai'], 0, ',', '.') }}/{{ number_format($tahap['batas'], 0, ',', '.') }} pesan hari ini
                                    @if ($tahap['tertunda'] > 0)
                                        · {{ number_format($tahap['tertunda'], 0, ',', '.') }} terjadwal ke hari berikutnya
                                    @endif
                                </p>
                                @unless ($terkunci)
                                    <form method="POST" action="{{ route('sessions.warmup.skip', $session->id) }}"
                                          data-konfirmasi="Lewati masa pemanasan untuk {{ $session->name }}? Lakukan ini hanya kalau nomornya sudah lama aktif dipakai di ponsel. Nomor baru yang langsung mengirim ratusan pesan ke orang yang belum mengenalnya adalah penyebab tersering nomor diblokir WhatsApp.">
                                        @csrf
                                        <button class="mt-0.5 font-medium text-primary hover:underline">Lewati pemanasan</button>
                                    </form>
                                @endunless
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-3 sm:px-3">
                        <div class="flex flex-wrap justify-end gap-1.5">
                            @if ($terkunci)
                                {{-- Seluruh tindakan di sini POST, dan seluruh POST
                                     ditolak `EnsureSubscriptionActive` selama langganan
                                     mati. Menampilkannya berarti empat tombol yang
                                     hanya bisa gagal; satu tautan yang benar-benar
                                     menyelesaikan masalahnya jauh lebih berguna. --}}
                                <a href="{{ route('billing.plans') }}"
                                   class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground transition hover:opacity-90">
                                    Perpanjang untuk memakai lagi
                                </a>
                            @elseif ($session->status !== 'connected')
                                <form method="POST" action="{{ route('sessions.connect', $session->id) }}">
                                    @csrf
                                    <button class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90">Hubungkan</button>
                                </form>
                            @else
                                <button type="button" @click="open('{{ $session->id }}')"
                                        class="rounded-lg border border-input bg-background px-3 py-1.5 text-xs hover:bg-muted">Lihat status</button>
                                <form method="POST" action="{{ route('sessions.disconnect', $session->id) }}">
                                    @csrf
                                    <button class="rounded-lg border border-input bg-background px-3 py-1.5 text-xs hover:bg-muted">Hentikan</button>
                                </form>
                            @endif

                            @unless ($terkunci)
                            <form method="POST" action="{{ route('sessions.logout', $session->id) }}"
                                  data-konfirmasi="Putus tautan nomor ini? Setelah itu Anda bisa Hubungkan lagi dan scan QR dengan nomor mana pun — termasuk nomor yang berbeda.">
                                @csrf
                                <button title="Putus tautan, lalu Hubungkan lagi untuk memakai nomor lain"
                                        class="rounded-lg border border-amber-500/50 px-3 py-1.5 text-xs text-amber-700 hover:bg-amber-500/10 dark:text-amber-400">
                                    Putus tautan
                                </button>
                            </form>

                            <form method="POST" action="{{ route('sessions.destroy', $session->id) }}"
                                  data-konfirmasi="Hapus sesi {{ $session->name }} beserta backup kredensialnya?">
                                @csrf @method('DELETE')
                                <button class="rounded-lg border border-destructive/50 px-3 py-1.5 text-xs text-destructive hover:bg-destructive/10">Hapus</button>
                            </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <x-kosong :kolom="4" judul="Belum ada nomor tertaut"
                          :pesan="$terkunci
                              ? 'Nomor pertama bisa ditautkan setelah tagihan pertama Anda lunas.'
                              : 'Buat sesi di atas, klik Hubungkan, lalu scan QR-nya lewat menu Perangkat Tertaut di WhatsApp.'" />
            @endforelse
        </x-tabel>
    </x-section>

    {{-- Modal QR: polling tiap 3 detik hanya selama modal terbuka. --}}
    <div x-show="sessionId" x-cloak
         class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
         @click.self="close()" @keydown.escape.window="close()">
        <div class="w-full max-w-sm rounded-2xl bg-card text-card-foreground p-6 text-center shadow-lg">
            <h3 class="font-semibold">Scan QR WhatsApp</h3>

            <template x-if="state.status === 'connected'">
                <div class="py-8">
                    <p class="text-4xl">&#10003;</p>
                    <p class="mt-2 font-medium text-primary">Terhubung</p>
                    <p class="mt-1 text-sm text-muted-foreground" x-text="state.phone_number ? '+' + state.phone_number : ''"></p>
                    <button @click="window.location.reload()" class="mt-4 rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground hover:bg-primary/90">Selesai</button>
                </div>
            </template>

            <template x-if="state.status !== 'connected' && state.qr">
                <div>
                    <p class="mt-1 text-sm text-muted-foreground">Buka WhatsApp &rarr; Perangkat Tertaut &rarr; Tautkan Perangkat</p>
                    <img :src="state.qr" alt="QR Code" class="mx-auto mt-4 h-64 w-64 rounded-lg bg-white p-2 shadow-sm border border-border">
                    <p class="mt-3 text-xs text-muted-foreground">QR berganti otomatis setiap beberapa detik.</p>
                </div>
            </template>

            {{-- Tanpa penanda waktu dan petunjuk, layar ini tidak bisa dibedakan
                 dari macet total: pengguna menatap kalimat yang sama selama
                 berapa pun lamanya, tanpa tahu apakah ada kemajuan. Penarikan
                 riwayat chat setelah QR ter-scan memang bisa memakan menit. --}}
            <template x-if="state.status !== 'connected' && !state.qr">
                <div class="py-10">
                    <p class="text-sm text-muted-foreground" x-text="pesanTunggu()"></p>

                    <template x-if="state.loading_percent !== null && state.loading_percent !== undefined">
                        <div class="mx-auto mt-4 w-56">
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full bg-primary transition-all" :style="`width: ${state.loading_percent}%`"></div>
                            </div>
                            <p class="mt-1.5 text-xs text-muted-foreground" x-text="`${state.loading_percent}% riwayat chat tersalin`"></p>
                        </div>
                    </template>

                    <p class="mt-4 text-xs text-muted-foreground" x-text="`${detik} detik berjalan`"></p>

                    <template x-if="detik >= 90">
                        <p class="mx-auto mt-3 max-w-xs rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                            Lebih lama dari biasanya. Jika belum tersambung dalam beberapa menit, tutup jendela ini lalu coba hubungkan kembali, atau hubungi bantuan jika kendala berlanjut.
                        </p>
                    </template>
                </div>
            </template>

            <button @click="close()" class="mt-4 text-sm text-muted-foreground hover:underline">Tutup</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
function qrModal(autoOpenId) {
    return {
        sessionId: null,
        timer: null,
        jam: null,
        detik: 0,
        state: { status: 'pending', qr: null, phone_number: null, loading_percent: null, error: null },

        init() {
            // Setelah menekan "Hubungkan", controller menandai sesi mana yang
            // baru dinyalakan supaya modalnya langsung terbuka tanpa klik lagi.
            if (autoOpenId) this.open(autoOpenId);
        },

        open(id) {
            this.sessionId = id;
            this.detik = 0;
            this.state = { status: 'connecting', qr: null, phone_number: null, loading_percent: null, error: null };
            this.poll();
            this.timer = setInterval(() => this.poll(), 3000);
            this.jam = setInterval(() => this.detik++, 1000);
        },

        close() {
            this.sessionId = null;
            clearInterval(this.timer);
            clearInterval(this.jam);
            this.timer = null;
            this.jam = null;
        },

        pesanTunggu() {
            if (this.state.error) return this.state.error;

            // Dibedakan supaya pengguna tahu tahap mana yang sedang berjalan:
            // menunggu QR terbit itu hitungan detik, sedangkan menunggu setelah
            // QR ter-scan adalah fase otentikasi dan koneksi ke WhatsApp.
            if (this.state.status === 'connecting' && this.detik > 5) {
                return 'QR sudah dipindai. Menghubungkan sesi ke WhatsApp…';
            }

            return 'Menyiapkan sesi, mohon tunggu…';
        },

        async poll() {
            if (!this.sessionId) return;

            try {
                const res = await fetch(`/sessions/${this.sessionId}/status`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) return;

                this.state = await res.json();

                // Berhenti polling begitu tersambung — membiarkannya jalan
                // hanya menambah request tanpa informasi baru.
                if (this.state.status === 'connected') {
                    clearInterval(this.timer);
                    this.timer = null;
                }
            } catch (e) {
                this.state.error = 'Gagal menghubungi server.';
            }
        },
    };
}
</script>
@endpush
    {{-- Tur halaman ini. Kuncinya sendiri, jadi menutup tur di dashboard tidak
         ikut membungkam yang di sini — tiap halaman punya hal yang perlu
         dijelaskan sekali, dan sekali itu terjadi saat halamannya dibuka. --}}
    <x-tur-pengenalan kunci="sesi.mulai" :versi="1" :langkah="[
        [
            'target' => '[data-tur=\'buat-sesi\']',
            'judul' => 'Buat sesi dulu, baru scan',
            'isi' => 'Satu sesi mewakili satu nomor WhatsApp. Namanya bebas dan hanya untuk Anda '
                .'sendiri — dipakai membedakan nomor kalau paket Anda mengizinkan lebih dari satu.',
        ],
        [
            'target' => '[data-tur=\'daftar-sesi\']',
            'judul' => 'Scan QR sekali saja',
            'isi' => 'Tekan Hubungkan, lalu scan kode QR-nya dari WhatsApp di ponsel Anda '
                .'(Perangkat Tertaut). Kredensialnya kami simpan, jadi nomor tersambung sendiri '
                .'setiap kali server di-deploy ulang — tidak ada scan kedua.',
        ],
        [
            'judul' => 'Kalau nomornya terputus',
            'isi' => 'Baris sesi akan menyebutkan alasannya. Terputus karena tagihan akan '
                .'tersambung sendiri setelah pembayaran dikonfirmasi; terputus karena ponsel lama '
                .'offline cukup ditekan Hubungkan lagi. Jangan hapus sesinya — menghapus berarti '
                .'harus scan ulang dari nol.',
        ],
    ]" />
@endsection
