@extends('layouts.admin')
@section('title', 'Pemberitahuan & Pengecualian')

@section('content')
    {{-- ===================== Keadaan pemberitahuan =====================

         Paling atas, dan berwarna hanya saat memang bermasalah. Konfigurasi
         yang belum lengkap membuat SELURUH pemberitahuan diam tanpa satu pun
         kegagalan yang tercatat — dan diam adalah gejala yang paling sulit
         disadari. Halaman ini satu-satunya tempat yang menunjukkannya.
         ============================================================= --}}
    @unless ($notifikasiSiap)
        <div class="mb-5 flex flex-wrap items-start gap-3 rounded-lg border border-destructive/25 bg-destructive/10 px-4 py-3.5 text-sm text-destructive">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
            <span>
                <strong>Pemberitahuan WhatsApp tidak berjalan.</strong>
                Pelanggan tidak dikabari saat pembayarannya lunas, kuotanya habis, atau nomornya terputus —
                dan tim tidak dikabari saat ada bukti pembayaran masuk. Tidak ada satu pun yang gagal
                secara terlihat. Pilih workspace pengirimnya di bawah.
            </span>
        </div>
    @endunless

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat label="Pemberitahuan WhatsApp"
                :nilai="$notifikasiSiap ? 'Berjalan' : 'Mati'"
                :sub="$notifikasiSiap ? 'ada sesi tersambung di workspace pengirim' : 'tidak ada sesi pengirim yang siap'"
                :nada="$notifikasiSiap ? 'netral' : 'bahaya'"
                ikon="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z" />

        <x-stat label="Email keluar"
                :nilai="$emailSiap ? 'Berjalan' : 'Mati'"
                :sub="$emailSiap ? 'lewat '.$emailMailer.', dari '.$emailPengirim : 'MAIL_MAILER='.$emailMailer"
                :nada="$emailSiap ? 'netral' : 'bahaya'"
                ikon="M4 4h16v16H4zM4 7l8 6 8-6" />

        <x-stat label="Nomor tim penerima"
                :nilai="$nomorAdmin ? \App\Support\PhoneNumber::mask($nomorAdmin) : 'Belum diisi'"
                :sub="$nomorAdmin ? 'menerima kabar bukti pembayaran baru' : 'BILLING_ADMIN_PHONE kosong di env'"
                :nada="$nomorAdmin ? 'netral' : 'perhatian'"
                ikon="M12 2a10 10 0 1 0 4.9 18.7L22 22l-1.3-5.1A10 10 0 0 0 12 2z" />

        <x-stat label="Nomor istimewa"
                :nilai="$nomorIstimewa->count()"
                :sub="$sesiIstimewaHidup.' sedang tersambung'"
                ikon="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.3 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8L12 2z" />

        <x-stat label="Bebas berlangganan"
                :nilai="$akunBebas->count() + $workspaceBebas->count()"
                :sub="$akunBebas->count().' akun · '.$workspaceBebas->count().' workspace'"
                :nada="($akunBebas->count() + $workspaceBebas->count()) > 0 ? 'perhatian' : 'netral'"
                ikon="M9 12l2 2 4-4M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
    </div>

    {{-- ===================== Pengirim pemberitahuan ===================== --}}
    <x-section judul="Nomor pengirim pemberitahuan"
               sub="Satu nomor mengirim SELURUH pemberitahuan ke semua pelanggan — dari nomor yang sama, dengan nada yang sama."
               rapat>
        <form method="POST" action="{{ route('admin.exemptions.notifier') }}" class="max-w-2xl">
            @csrf
            <label for="workspace_id" class="mb-1 block text-sm font-medium">Workspace pengirim</label>
            <div class="flex flex-wrap gap-2">
                <select id="workspace_id" name="workspace_id"
                        class="min-w-64 flex-1 rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
                    <option value="">— tidak ada, pemberitahuan dimatikan —</option>
                    @foreach ($kandidatPengirim as $ws)
                        <option value="{{ $ws->id }}" @selected((string) $pengirimTerpilih === (string) $ws->id)>
                            {{ $ws->name }}
                            @if ($ws->sesi_tersambung > 0)
                                — {{ $ws->sesi_tersambung }} nomor tersambung
                            @else
                                — belum ada nomor tersambung
                            @endif
                        </option>
                    @endforeach
                </select>
                <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90">
                    Simpan
                </button>
            </div>

            {{-- Langkahnya ditulis apa adanya, termasuk bagian yang tidak
                 menyenangkan. Menautkan nomor WhatsApp SELALU butuh satu kali
                 scan QR — kita perangkat tertaut, dan tidak ada jalan lain.
                 Yang benar untuk dijanjikan bukan "tanpa scan", melainkan
                 "sekali saja, dan tidak pernah lagi sesudahnya". --}}
        </form>

        {{-- Percobaan terpisah dari penyimpanan: yang ingin diuji orang bukan
             "apakah pilihannya tersimpan" melainkan "apakah pesannya sampai",
             dan menunggu peristiwa penagihan sungguhan untuk mengetahuinya
             berarti yang menemukan kesalahannya adalah pelanggan yang tidak
             dikabari. --}}
        <form method="POST" action="{{ route('admin.exemptions.notifier.test') }}"
              class="mt-5 max-w-2xl rounded-lg border border-border bg-card p-4" data-validasi>
            @csrf
            <label for="tes_phone" class="mb-1 block text-sm font-medium">Kirim pesan tes</label>
            <div class="flex flex-wrap gap-2">
                <input id="tes_phone" name="phone" required maxlength="20" inputmode="tel"
                       value="{{ old('phone', $nomorAdmin) }}" placeholder="08xxxxxxxxxx"
                       class="min-w-48 flex-1 rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
                <button class="rounded-lg border border-border px-4 py-2 text-sm font-medium transition hover:bg-muted">
                    Kirim tes
                </button>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                Dikirim lewat jalur yang sama persis dengan pemberitahuan sungguhan. Kalau gagal,
                pesan galatnya menyebutkan langkah mana yang belum selesai.
            </p>
        </form>

        {{-- Langkah pemasangan ditulis apa adanya, termasuk bagian yang tidak
             menyenangkan: menautkan nomor WhatsApp SELALU butuh satu kali scan
             QR — kita perangkat tertaut, dan tidak ada jalan lain. Yang benar
             dijanjikan bukan "tanpa scan" melainkan "sekali saja". --}}
        <div class="mt-5 max-w-2xl rounded-lg border border-border bg-muted/40 p-4 text-sm">
            <p class="font-medium">Cara memasangnya, sekali saja</p>
            <ol class="mt-2 list-decimal space-y-1.5 pl-5 leading-relaxed text-muted-foreground">
                <li>Daftar akun biasa di dashboard, lalu buat workspace — misalnya bernama <em>VexaHost Notifikasi</em>.</li>
                <li>Bebaskan akun itu dari penagihan lewat menu <strong>Pengguna</strong>.</li>
                <li>Buka menu Sesi WhatsApp di dashboard, buat sesi, klik Hubungkan, lalu <strong>scan QR satu kali</strong> dengan nomor perusahaan.</li>
                <li>Daftarkan nomornya di <strong>Nomor istimewa</strong> supaya ia tidak pernah ikut dilepas.</li>
                <li>Pilih workspace itu di kotak di atas, Simpan, lalu tekan <strong>Kirim tes</strong>.</li>
            </ol>
            <p class="mt-3 leading-relaxed text-muted-foreground">
                Scan QR hanya perlu sekali. Sesudah itu kredensialnya kami simpan dan nomor tersambung
                sendiri setiap kali server di-deploy ulang — tidak ada scan kedua.
            </p>
        </div>
    </x-section>

    {{-- ===================== Email keluar =====================

         Bertetangga dengan pengirim WhatsApp karena keduanya jalur
         pemberitahuan yang sama, dengan bentuk kegagalan yang sama: diam.
         Bedanya cuma di mana konfigurasinya hidup — WhatsApp dipilih dari
         halaman ini, email dari env.
         ============================================================= --}}
    <x-section judul="Email keluar"
               sub="Jalur kedua untuk peristiwa penagihan yang sama. Nomor tagihan boleh kosong; alamat penagihan jauh lebih jarang berubah."
               rapat>
        @unless ($emailSiap)
            <div class="mb-4 flex flex-wrap items-start gap-3 rounded-lg border border-destructive/25 bg-destructive/10 px-4 py-3.5 text-sm text-destructive">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                <span>
                    <strong>Tidak ada satu pun email yang benar-benar terkirim.</strong>
                    <code>MAIL_MAILER</code> sekarang <code>{{ $emailMailer }}</code>. Laravel menerimanya
                    tanpa keluhan apa pun dan menulis seluruh isi email ke berkas log — dari dalam aplikasi,
                    "terkirim" dan "ditulis ke log" tampak persis sama. Isi <code>MAIL_*</code> di env resource
                    Coolify, lalu buktikan dengan tombol di bawah.
                </span>
            </div>
        @endunless

        <dl class="mb-4 max-w-2xl space-y-2.5 text-sm">
            @foreach ([
                'Mailer' => $emailMailer,
                'Pengirim' => $emailPengirim ?: 'belum diisi',
                'Alamat tim' => $emailAdmin ?: 'belum diisi',
            ] as $label => $nilai)
                <div class="flex justify-between gap-4 border-b border-border pb-2.5 last:border-0 last:pb-0">
                    <dt class="text-muted-foreground">{{ $label }}</dt>
                    <dd class="text-right font-medium">{{ $nilai }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Wajib ada, bukan pelengkap. Brevo menolak pengirim yang belum
             diverifikasi dengan 550, dan penolakan itu tidak terlihat di
             antarmuka mana pun — persis bentuk kegagalan notifikasi WhatsApp
             yang diam. --}}
        <form method="POST" action="{{ route('admin.exemptions.email.test') }}"
              class="max-w-2xl rounded-lg border border-border bg-card p-4" data-validasi>
            @csrf
            <label for="tes_email" class="mb-1 block text-sm font-medium">Kirim email tes</label>
            <div class="flex flex-wrap gap-2">
                <input id="tes_email" name="email" required maxlength="500" type="email" multiple inputmode="email"
                       value="{{ old('email', $emailAdmin) }}" placeholder="nama@contoh.id, admin@contoh.id"
                       class="min-w-48 flex-1 rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
                <button class="rounded-lg border border-border px-4 py-2 text-sm font-medium transition hover:bg-muted">
                    Kirim tes
                </button>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                Bisa memasukkan lebih dari satu email (pisahkan dengan koma). Dikirim seketika, bukan lewat antrean — email yang mengantre menjawab "berhasil" sebelum
                ada satu pun sambungan SMTP dibuka, dan itu kebalikan dari gunanya tombol ini.
                Kalau gagal, pesan galatnya menyebutkan langkah mana yang belum selesai.
            </p>
        </form>
    </x-section>

    {{-- ===================== Nomor istimewa ===================== --}}
    <x-section judul="Nomor istimewa"
               sub="Nomor milik perusahaan sendiri. Tidak menghitung kuota sesi workspace mana pun, tidak ikut dilepas saat ada langganan yang mati, dan pengirimannya tidak dihalangi status tagihan.">
        <form method="POST" action="{{ route('admin.exemptions.numbers.store') }}"
              class="mb-5 flex flex-wrap items-end gap-3 px-4 sm:px-0" data-validasi>
            @csrf
            <div class="min-w-44">
                <label for="phone" class="mb-1 block text-sm font-medium">Nomor</label>
                <input id="phone" name="phone" required maxlength="20" inputmode="tel" placeholder="08xxxxxxxxxx"
                       value="{{ old('phone') }}"
                       class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
            </div>
            <div class="min-w-48">
                <label for="nomor_email" class="mb-1 block text-sm font-medium">
                    Email pemilik <span class="font-normal text-muted-foreground">(opsional)</span>
                </label>
                <input id="nomor_email" name="email" type="email" maxlength="180" placeholder="nama@vexahostcloud.my.id"
                       value="{{ old('email') }}"
                       class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
            </div>
            <div class="min-w-48 flex-1">
                <label for="label" class="mb-1 block text-sm font-medium">Keterangan</label>
                <input id="label" name="label" required maxlength="80" placeholder="mis. Nomor notifikasi VexaHost"
                       value="{{ old('label') }}"
                       class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
            </div>
            <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90">
                Tambahkan
            </button>
        </form>

        <x-tabel :kepala="['Nomor' => '', 'Email pemilik' => '', 'Keterangan' => '', 'Ditambahkan' => '', 'Tindakan' => 'text-right']">
            @forelse ($nomorIstimewa as $nomor)
                <tr class="transition hover:bg-muted/40">
                    <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs sm:px-3">+{{ $nomor->phone }}</td>
                    <td class="px-4 py-2.5 text-muted-foreground sm:px-3">{{ $nomor->email ?: '—' }}</td>
                    <td class="px-4 py-2.5 sm:px-3">
                        {{ $nomor->label }}
                        @if ($nomor->note)
                            <span class="block text-xs text-muted-foreground">{{ $nomor->note }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-2.5 text-muted-foreground sm:px-3">
                        {{ $nomor->created_at->translatedFormat('j M Y') }}
                        <span class="block text-xs">{{ $nomor->pembuat?->email ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-right sm:px-3">
                        <form method="POST" action="{{ route('admin.exemptions.numbers.destroy', $nomor->id) }}"
                              data-konfirmasi="Keluarkan {{ $nomor->label }} dari daftar nomor istimewa? Setelah ini nomor itu menghitung kuota sesi seperti nomor pelanggan biasa.">
                            @csrf @method('DELETE')
                            <button class="text-xs text-destructive hover:underline">Keluarkan</button>
                        </form>
                    </td>
                </tr>
            @empty
                <x-kosong :kolom="5" judul="Belum ada nomor istimewa"
                          pesan="Nomor yang didaftarkan di sini tidak memakan jatah nomor pelanggan mana pun." />
            @endforelse
        </x-tabel>
    </x-section>

    {{-- ===================== Akun bebas ===================== --}}
    <x-section judul="Akun bebas berlangganan"
               sub="Seluruh workspace milik akun ini bebas dari penagihan, termasuk yang dibuat nanti. Ditandai per orang, bukan per workspace, supaya tidak ada yang perlu ingat menandainya lagi.">
        {{-- Bebaskan lewat alamat email, termasuk yang belum pernah mendaftar.
             Tanpa ini, membebaskan calon pelanggan berarti menunggu mereka
             mendaftar lalu mengingat untuk kembali menandainya — dan yang lupa
             ditandai akan tertagih seperti pelanggan biasa. --}}
        <form method="POST" action="{{ route('admin.exemptions.email') }}"
              class="mb-5 flex flex-wrap items-end gap-3 px-4 sm:px-0" data-validasi>
            @csrf
            <div class="min-w-56 flex-1">
                <label for="bebas_email" class="mb-1 block text-sm font-medium">Bebaskan alamat email</label>
                <input id="bebas_email" name="email" type="email" required maxlength="180"
                       placeholder="nama@perusahaan.co.id" value="{{ old('email') }}"
                       class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
                <p class="mt-1 text-xs text-muted-foreground">
                    Belum punya akun? Pembebasannya menunggu dan berlaku otomatis begitu alamat itu mendaftar.
                </p>
            </div>
            <div class="min-w-48 flex-1">
                <label for="bebas_note" class="mb-1 block text-sm font-medium">
                    Alasan <span class="font-normal text-muted-foreground">(opsional)</span>
                </label>
                <input id="bebas_note" name="note" maxlength="255" placeholder="mis. mitra strategis, akun internal"
                       value="{{ old('note') }}"
                       class="w-full rounded-lg border-border bg-background text-sm focus:border-primary focus:ring-primary">
            </div>
            <button class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90">
                Bebaskan
            </button>
        </form>

        @error('email')
            <p class="mb-4 px-4 text-sm text-destructive sm:px-0">{{ $message }}</p>
        @enderror

        @if ($pembebasanMenunggu->isNotEmpty())
            <div class="mb-5 rounded-lg border border-border bg-muted/30 px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Menunggu pemiliknya mendaftar
                </p>
                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach ($pembebasanMenunggu as $menunggu)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {{ $menunggu->email }}
                                @if ($menunggu->note)
                                    <span class="text-xs text-muted-foreground">· {{ $menunggu->note }}</span>
                                @endif
                            </span>
                            <form method="POST" action="{{ route('admin.exemptions.email.cancel', $menunggu->id) }}"
                                  data-konfirmasi="Batalkan pembebasan untuk {{ $menunggu->email }}?">
                                @csrf @method('DELETE')
                                <button class="text-xs text-destructive hover:underline">Batalkan</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-tabel :kepala="['Akun' => '', 'Workspace' => 'text-right', 'Tindakan' => 'text-right']">
            @forelse ($akunBebas as $akun)
                <tr class="transition hover:bg-muted/40">
                    <td class="px-4 py-2.5 sm:px-3">
                        <p class="font-medium">{{ $akun->name }}</p>
                        <p class="text-xs text-muted-foreground">{{ $akun->email }}</p>
                    </td>
                    <td class="px-4 py-2.5 text-right tabular-nums sm:px-3">{{ $akun->workspaces_count }}</td>
                    <td class="px-4 py-2.5 text-right sm:px-3">
                        <form method="POST" action="{{ route('admin.exemptions.users.toggle', $akun->id) }}"
                              data-konfirmasi="Cabut pembebasan untuk {{ $akun->email }}? Seluruh workspace miliknya kembali ditagih seperti pelanggan biasa.">
                            @csrf
                            <button class="text-xs text-destructive hover:underline">Cabut pembebasan</button>
                        </form>
                    </td>
                </tr>
            @empty
                <x-kosong :kolom="3" judul="Belum ada akun yang dibebaskan"
                          pesan="Bebaskan sebuah akun dari halaman Pengguna — tombolnya ada di baris akunnya." />
            @endforelse
        </x-tabel>
    </x-section>

    {{-- ===================== Workspace internal ===================== --}}
    @if ($workspaceBebas->isNotEmpty())
        <x-section judul="Workspace internal"
                   sub="Dibebaskan satu per satu lewat kolom is_internal, bukan lewat pemiliknya.">
            <x-tabel :kepala="['Workspace' => '', 'Slug' => '', 'Pemilik' => '']">
                @foreach ($workspaceBebas as $ws)
                    <tr class="transition hover:bg-muted/40">
                        <td class="px-4 py-2.5 sm:px-3">
                            <a href="{{ route('admin.workspaces.show', $ws->id) }}" class="font-medium hover:underline">{{ $ws->name }}</a>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs text-muted-foreground sm:px-3">{{ $ws->slug }}</td>
                        <td class="px-4 py-2.5 text-muted-foreground sm:px-3">{{ $ws->owner_email ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-tabel>
        </x-section>
    @endif
@endsection
