@extends('layouts.admin')
@section('title', $workspace->name)

@section('content')
    @php
        $lencana = \App\Support\StatusBadge::subscription($subscription->status);
        $kuota = (int) $workspace->monthly_message_quota;
        $bulanIni = $pemakaian->firstWhere('period', now()->format('Y-m'));
        $terpakai = (int) ($bulanIni->messages_sent ?? 0);
        $persen = $kuota > 0 ? min(100, round($terpakai / max($kuota, 1) * 100)) : null;
    @endphp

    {{-- ===================== Kepala halaman ===================== --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('admin.workspaces') }}" class="inline-flex items-center gap-1 text-sm text-muted-foreground transition hover:text-foreground">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg>
                Semua workspace
            </a>
            <div class="mt-1.5 flex flex-wrap items-center gap-2.5">
                <h2 class="text-xl font-semibold tracking-tight">{{ $workspace->name }}</h2>
                <x-badge :warna="$lencana['warna']" titik>{{ $lencana['label'] }}</x-badge>
                @if ($workspace->is_internal)
                    <x-badge warna="biru">internal</x-badge>
                @endif
                @if ($workspace->isPartner())
                    <x-badge warna="kuning">rekanan</x-badge>
                @endif
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                <code>{{ $workspace->slug }}</code> ·
                {{ $workspace->owner_email ?? $workspace->owner?->email ?? 'tanpa pemilik' }}
                @if ($workspace->billing_phone)
                    · {{ $workspace->billing_phone }}
                @endif
            </p>
        </div>

        <form method="POST" action="{{ route('admin.workspaces.suspend', $workspace->id) }}"
              data-konfirmasi="Yakin mengubah status layanan workspace ini?">
            @csrf
            @if (in_array($subscription->status, ['suspended', 'past_due'], true))
                <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90">
                    Aktifkan kembali
                </button>
            @else
                <button class="rounded-lg border border-destructive/40 px-4 py-2 text-sm font-medium text-destructive transition hover:bg-destructive/10">
                    Tangguhkan
                </button>
            @endif
        </form>
    </div>

    {{-- ===================== Angka ===================== --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Paket" :nilai="$workspace->plan()->name()"
                :sub="$subscription->current_period_end ? 'berlaku sampai '.$subscription->current_period_end->translatedFormat('j M Y') : 'belum pernah berlangganan'"
                ikon="M2 7h20v12H2zM2 11h20M6 15h4" />

        <x-stat label="Pesan bulan ini" :nilai="number_format($terpakai)"
                :sub="$kuota === 0 ? 'kuota tanpa batas' : ($persen.'% dari '.number_format($kuota))"
                :nada="$persen !== null && $persen >= 90 ? 'bahaya' : 'netral'"
                ikon="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z" />

        <x-stat label="Sesi WhatsApp" :nilai="$workspace->sessions->count().'/'.$workspace->max_sessions"
                :sub="$workspace->sessions->where('status', 'connected')->count().' tersambung'"
                ikon="M12 2a10 10 0 1 0 4.9 18.7L22 22l-1.3-5.1A10 10 0 0 0 12 2z" />

        <x-stat label="Anggota tim" :nilai="$workspace->members->count()"
                :sub="'batas paket: '.($workspace->plan()->maxMembers() === 0 ? 'tanpa batas' : $workspace->plan()->maxMembers())"
                ikon="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-[1.3fr_1fr]">
        <div class="space-y-5">

            {{-- ===================== Tindakan langganan ===================== --}}
            @php
                $pilihKelas = 'w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary';
                // Pindah paket dan tambah hari hanya masuk akal untuk workspace
                // yang punya periode. Coba gratis, belum bayar, dan PAYG diarahkan
                // ke "Aktifkan tanpa pembayaran" — controller menolaknya juga.
                $punyaPeriode = ! $workspace->isPayg() && ! $workspace->isFreeTier() && $subscription->current_period_end !== null;
            @endphp
            <x-section judul="Langganan" sub="Perubahan di sini tidak menerbitkan tagihan." rapat>

                {{-- Rekanan: paket berbayar tanpa pembayaran. Satu form untuk
                     mengaktifkan dan memperpanjang, karena keduanya satu
                     tindakan yang sama — sisa masa yang belum lewat tetap
                     dihitung, dan paketnya boleh diganti sekaligus. --}}
                <form method="POST" action="{{ route('admin.workspaces.partner', $workspace->id) }}"
                      class="rounded-lg border border-border bg-card p-4" data-validasi
                      data-konfirmasi="{{ $workspace->isPartner()
                          ? 'Perpanjang paket '.$workspace->name.' tanpa pembayaran? Sisa masa yang belum lewat tetap dihitung.'
                          : 'Aktifkan paket untuk '.$workspace->name.' tanpa pembayaran? Paket langsung aktif dengan tanggal berakhir, dan tidak ada tagihan yang dibuat.' }}">
                    @csrf
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ $workspace->isPartner() ? 'Perpanjang rekanan' : 'Aktifkan tanpa pembayaran (rekanan)' }}
                        </label>
                        @if ($workspace->isPartner())
                            <span class="text-xs text-muted-foreground">
                                rekanan sejak {{ $workspace->partner_since->translatedFormat('j M Y') }}
                                @if ($subscription->current_period_end)
                                    · sampai {{ $subscription->current_period_end->translatedFormat('j M Y') }}
                                @endif
                            </span>
                        @endif
                    </div>

                    <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                        <select name="plan" required aria-label="Paket" class="{{ $pilihKelas }}">
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->slug }}" @selected($workspace->plan()->slug === $plan->slug)>{{ $plan->name() }}</option>
                            @endforeach
                        </select>
                        <select name="period" required aria-label="Durasi" class="{{ $pilihKelas }}">
                            <option value="monthly" @selected($subscription->period !== 'yearly')>{{ $workspace->isPartner() ? '+1 bulan' : '1 bulan' }}</option>
                            <option value="yearly" @selected($subscription->period === 'yearly')>{{ $workspace->isPartner() ? '+12 bulan' : '12 bulan' }}</option>
                        </select>
                        <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90">
                            {{ $workspace->isPartner() ? 'Perpanjang' : 'Aktifkan' }}
                        </button>
                    </div>

                    <input name="note" maxlength="255" value="{{ old('note', $workspace->partner_note) }}"
                           placeholder="Catatan (opsional) — misalnya: mitra agensi, kesepakatan 12 Sep"
                           class="mt-2 {{ $pilihKelas }}">
                    @error('period') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror

                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        Batas dan tanggal berakhirnya sama persis seperti paket yang dibayar. Tagihan perpanjangan
                        tidak terbit otomatis — perpanjang dari sini, atau biarkan habis. Harga perkenalan dan kode
                        referal pemiliknya tetap berlaku kalau suatu saat ia membayar sendiri.
                    </p>
                </form>

                @if ($workspace->isPartner())
                    <form method="POST" action="{{ route('admin.workspaces.partner.revoke', $workspace->id) }}" class="mt-2 text-right"
                          data-konfirmasi="Cabut status rekanan {{ $workspace->name }}? Workspace langsung kembali ke paket coba gratis, dan pengirimannya berhenti sampai pemiliknya membeli paket."
                          data-konfirmasi-ya="Ya, cabut">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm text-destructive hover:underline">Cabut status rekanan</button>
                    </form>
                @endif

                <div class="mt-5 grid gap-5 border-t border-border pt-5 sm:grid-cols-2">
                    <form method="POST" action="{{ route('admin.workspaces.plan', $workspace->id) }}" class="space-y-2">
                        @csrf
                        <label class="block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pindah paket</label>
                        <select name="plan" @disabled(! $punyaPeriode) class="{{ $pilihKelas }} disabled:opacity-50">
                            {{-- Paket coba gratis tidak ada di daftar yang bisa
                                 dipilih. Tanpa baris ini pilihan pertama
                                 (Essentials) tampak terpilih untuk workspace
                                 yang sebenarnya masih coba gratis. --}}
                            @unless ($workspace->plan()->isSellable())
                                <option value="" selected disabled>{{ $workspace->plan()->name() }} — sekarang</option>
                            @endunless
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->slug }}" @selected($workspace->plan()->slug === $plan->slug)>{{ $plan->name() }}</option>
                            @endforeach
                        </select>
                        <button @disabled(! $punyaPeriode) class="w-full rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50">Terapkan paket</button>
                        <p class="text-xs leading-relaxed text-muted-foreground">Batasnya langsung berlaku; periodenya tidak berubah.</p>
                        @error('plan') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </form>

                    <form method="POST" action="{{ route('admin.workspaces.extend', $workspace->id) }}" class="space-y-2" data-validasi>
                        @csrf
                        <label for="hari" class="block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tambah hari</label>
                        <div class="flex gap-2">
                            <input id="hari" type="number" name="hari" min="1" max="365" value="7" required @disabled(! $punyaPeriode)
                                   class="{{ $pilihKelas }} disabled:opacity-50">
                            <span class="grid shrink-0 place-items-center px-1 text-sm text-muted-foreground">hari</span>
                        </div>
                        <button @disabled(! $punyaPeriode) class="w-full rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50">Perpanjang</button>
                        <p class="text-xs leading-relaxed text-muted-foreground">Untuk uang yang sudah masuk tapi buktinya belum sampai.</p>
                        @error('hari') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    </form>

                    @unless ($punyaPeriode)
                        <p class="text-xs leading-relaxed text-muted-foreground sm:col-span-2">
                            {{ $workspace->isPayg() ? 'Workspace PAYG dibatasi saldo, bukan tanggal' : 'Workspace ini belum punya masa berlangganan' }}
                            — kedua tombol ini baru bisa dipakai setelah paketnya aktif. Pakai <strong>Aktifkan tanpa pembayaran</strong> di atas.
                        </p>
                    @endunless
                </div>

                <form method="POST" action="{{ route('admin.workspaces.limits', $workspace->id) }}" class="mt-5 border-t border-border pt-5" data-validasi>
                    @csrf
                    <label class="block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Kelonggaran batas</label>
                    <div class="mt-2 flex flex-wrap items-end gap-2">
                        <div class="min-w-32 flex-1">
                            <label for="max_sessions" class="mb-1 block text-xs text-muted-foreground">Jumlah sesi</label>
                            <input id="max_sessions" type="number" name="max_sessions" min="0" max="50" required
                                   value="{{ $workspace->max_sessions }}"
                                   class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
                        </div>
                        <div class="min-w-32 flex-1">
                            <label for="monthly_message_quota" class="mb-1 block text-xs text-muted-foreground">Kuota pesan / bulan</label>
                            <input id="monthly_message_quota" type="number" name="monthly_message_quota" min="0" required
                                   value="{{ $workspace->monthly_message_quota }}"
                                   class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
                        </div>
                        <button class="rounded-lg border border-border px-4 py-2 text-sm font-medium transition hover:bg-muted">Simpan batas</button>
                    </div>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        0 berarti tanpa batas. Nilai ini <strong>tertimpa</strong> saat pembayaran berikutnya menerapkan batas paket.
                    </p>
                </form>
            </x-section>

            {{-- ===================== Data Pelanggan & Penagihan ===================== --}}
            <x-section judul="Data Pelanggan &amp; Penagihan" sub="Data resmi untuk kwitansi, rekening dan verifikasi." rapat>
                <div class="space-y-3 text-sm">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-b border-border pb-3">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Jenis Pelanggan</span>
                            <div class="mt-1 flex items-center gap-2">
                                <x-badge :warna="$workspace->billing_type === 'badan' ? 'biru' : 'netral'">
                                    {{ $workspace->billing_type === 'badan' ? 'Badan Usaha' : 'Individu' }}
                                </x-badge>
                                @if ($workspace->isBillingComplete())
                                    <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">✓ Lengkap</span>
                                @else
                                    <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">⚠ Belum Lengkap</span>
                                @endif
                            </div>
                        </div>

                        @if ($workspace->billing_company)
                            <div>
                                <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Nama Perusahaan</span>
                                <p class="mt-1 font-semibold text-foreground">{{ $workspace->billing_company }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-b border-border pb-3">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ $workspace->billing_type === 'badan' ? 'Nama PIC' : 'Nama Lengkap' }}</span>
                            <p class="mt-1 font-medium text-foreground">{{ $workspace->billing_name ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Email Penagihan</span>
                            <p class="mt-1 font-medium text-foreground">{{ $workspace->billing_email ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Nomor WhatsApp</span>
                            <p class="mt-1 font-medium text-foreground">
                                @if ($workspace->billing_phone)
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $workspace->billing_phone) }}" target="_blank"
                                       class="text-primary hover:underline inline-flex items-center gap-1 font-mono">
                                        {{ $workspace->billing_phone }}
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    </a>
                                @else
                                    —
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Rekening Bank Pelanggan</span>
                            <p class="mt-1 font-semibold text-foreground">
                                {{ $workspace->billing_bank_name ?: '—' }}
                                @if ($workspace->billing_bank_account)
                                    · <span class="font-mono">{{ $workspace->billing_bank_account }}</span>
                                @endif
                            </p>
                            @if ($workspace->billing_bank_holder)
                                <p class="text-xs text-muted-foreground mt-0.5">a.n. {{ $workspace->billing_bank_holder }}</p>
                            @endif
                        </div>

                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Alamat Penagihan</span>
                            <p class="mt-1 text-xs leading-relaxed text-foreground">
                                {{ implode(', ', array_filter([$workspace->billing_address, $workspace->billing_district, $workspace->billing_city, $workspace->billing_province, $workspace->billing_postal_code])) ?: '—' }}
                            </p>
                        </div>
                    </div>
                </div>
            </x-section>

            {{-- ===================== Sesi ===================== --}}
            <x-section judul="Sesi WhatsApp">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="border-y border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Nama</th>
                            <th class="px-5 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 font-medium">Nomor</th>
                            <th class="px-5 py-2.5 font-medium">Terakhir tersambung</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($workspace->sessions as $sesi)
                            @php $ls = \App\Support\StatusBadge::session($sesi->status); @endphp
                            <tr>
                                <td class="px-5 py-2.5 font-medium">
                                    <a href="{{ route('admin.sessions.show', $sesi->id) }}" class="hover:text-primary hover:underline">{{ $sesi->name }}</a>
                                </td>
                                <td class="px-5 py-2.5"><x-badge :warna="$ls['warna']" titik>{{ $ls['label'] }}</x-badge></td>
                                <td class="px-5 py-2.5 font-mono text-xs text-muted-foreground">{{ $sesi->phone_number ?? '—' }}</td>
                                <td class="px-5 py-2.5 text-muted-foreground">{{ $sesi->connected_at?->translatedFormat('j M Y, H:i') ?? 'belum pernah' }}</td>
                            </tr>
                        @empty
                            <x-kosong :kolom="4" judul="Belum ada sesi"
                                      pesan="Workspace ini belum pernah menautkan nomor WhatsApp." />
                        @endforelse
                    </tbody>
                </table>
            </x-section>

            {{-- ===================== Tagihan ===================== --}}
            <x-section judul="Tagihan terakhir">
                <x-slot:aksi>
                    <a href="{{ route('admin.invoices', ['cari' => $workspace->name, 'status' => 'semua']) }}"
                       class="text-sm text-primary hover:underline">Lihat semua</a>
                </x-slot:aksi>

                <table class="w-full min-w-[38rem] text-sm">
                    <thead class="border-y border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Nomor</th>
                            <th class="px-5 py-2.5 font-medium">Paket</th>
                            <th class="px-5 py-2.5 text-right font-medium">Jumlah</th>
                            <th class="px-5 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 font-medium">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($invoices as $invoice)
                            @php $li = \App\Support\StatusBadge::invoice($invoice->status); @endphp
                            <tr>
                                <td class="px-5 py-2.5 font-medium">{{ $invoice->number }}</td>
                                <td class="px-5 py-2.5 text-muted-foreground">{{ $invoice->plan()->name() }} · {{ $invoice->periodLabel() }}</td>
                                <td class="px-5 py-2.5 text-right tabular-nums">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
                                <td class="px-5 py-2.5">
                                    <x-badge :warna="$li['warna']">{{ $li['label'] }}</x-badge>
                                    @if ($invoice->proof_path && ! $invoice->isPaid())
                                        <x-badge warna="solid">bukti masuk</x-badge>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5 text-muted-foreground">{{ ($invoice->paid_at ?? $invoice->created_at)->translatedFormat('j M Y') }}</td>
                            </tr>
                        @empty
                            <x-kosong :kolom="5" judul="Belum ada tagihan" />
                        @endforelse
                    </tbody>
                </table>
            </x-section>
        </div>

        <div class="space-y-5">
            {{-- ===================== Pemakaian per bulan ===================== --}}
            <x-section judul="Pemakaian 12 bulan" rapat>
                @forelse ($pemakaian as $baris)
                    @php
                        $tinggi = $kuota > 0 ? min(100, round($baris->messages_sent / max($kuota, 1) * 100)) : 0;
                    @endphp
                    <div class="border-b border-border py-2 last:border-0">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-muted-foreground">{{ \Carbon\Carbon::parse($baris->period.'-01')->translatedFormat('F Y') }}</span>
                            <span class="tabular-nums">{{ number_format($baris->messages_sent) }}</span>
                        </div>
                        @if ($kuota > 0)
                            <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full {{ $tinggi >= 90 ? 'bg-destructive' : 'bg-primary' }}" style="width: {{ $tinggi }}%"></div>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">Belum ada pesan yang tercatat.</p>
                @endforelse
            </x-section>

            {{-- ===================== Anggota ===================== --}}
            <x-section judul="Anggota" rapat>
                @forelse ($workspace->members as $anggota)
                    <div class="flex items-center justify-between gap-3 border-b border-border py-2 text-sm last:border-0">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $anggota->name }}</p>
                            <p class="truncate text-xs text-muted-foreground">{{ $anggota->email }}</p>
                        </div>
                        <x-badge :warna="$anggota->pivot->role === 'owner' ? 'hijau' : 'netral'">{{ $anggota->pivot->role }}</x-badge>
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">Workspace ini tidak punya anggota.</p>
                @endforelse
            </x-section>

            {{-- ===================== Jejak ===================== --}}
            <x-section judul="Jejak terakhir" sub="15 tindakan terakhir di workspace ini." rapat>
                @forelse ($audit as $jejak)
                    <div class="border-b border-border py-2 text-sm last:border-0">
                        <p class="font-mono text-xs">{{ $jejak->action }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ $jejak->created_at->translatedFormat('j M Y, H:i') }}
                            @if ($jejak->user)
                                · {{ $jejak->user->email }}
                            @endif
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">Belum ada jejak.</p>
                @endforelse

                <a href="{{ route('admin.audit', ['cari' => $workspace->name]) }}"
                   class="mt-3 inline-block text-sm text-primary hover:underline">Buka catatan audit</a>
            </x-section>
        </div>
    </div>
@endsection
