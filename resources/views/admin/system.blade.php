@extends('layouts.admin')
@section('title', 'Sistem')

@section('content')
    @php
        $persenKapasitas = $kapasitas['batas'] > 0 ? min(100, round($kapasitas['hidup'] / $kapasitas['batas'] * 100)) : 0;

        /*
         | Daftar hal yang bisa berhenti bekerja TANPA gejala. Ketiganya punya
         | bentuk kegagalan yang sama: tidak ada galat, tidak ada keluhan, dan
         | tidak ada yang menyadarinya sampai ada pelanggan yang dirugikan.
        */
        $pemeriksaan = [
            [
                'nama' => 'Engine WhatsApp',
                'baik' => $engine['terjangkau'],
                'kabar' => $engine['pesan'],
                'akibat' => 'Semua sesi berhenti mengirim, dan dashboard tetap menampilkannya hijau sampai penyelaras berikutnya berjalan.',
            ],
            [
                'nama' => 'Pemberitahuan WhatsApp',
                'baik' => $notifikasi['siap'],
                'kabar' => $notifikasi['siap']
                    ? 'Sesi pengirim siap.'
                    : 'Tidak ada sesi pengirim. Isi BILLING_NOTIFY_WORKSPACE_ID dan pastikan salah satu nomornya terhubung.',
                'akibat' => 'Pelanggan tidak dikabari saat pembayarannya lunas, kuotanya habis, atau nomornya terputus.',
            ],
            [
                'nama' => 'Nomor admin',
                'baik' => filled($notifikasi['nomorAdmin']),
                'kabar' => filled($notifikasi['nomorAdmin']) ? $notifikasi['nomorAdmin'] : 'BILLING_ADMIN_PHONE kosong.',
                'akibat' => 'Tidak ada yang memberi tahu tim saat bukti pembayaran baru masuk — dan tagihan hanya lunas kalau ada yang membukanya.',
            ],
            [
                'nama' => 'Email keluar',
                'baik' => $email['siap'],
                'kabar' => $email['siap']
                    ? 'Lewat '.($email['host'] ?: $email['mailer']).', dari '.$email['dari'].'.'
                    : ($email['mailer'] === 'log'
                        ? 'MAIL_MAILER masih log — isi email ditulis ke berkas log.'
                        : 'MAIL_HOST atau MAIL_FROM_ADDRESS kosong.'),
                'akibat' => 'Tidak ada satu pun email yang benar-benar terkirim; pemulihan kata sandi dan '
                    .'pemberitahuan tiket cuma janji. Laravel menerima MAIL_MAILER=log tanpa keluhan '
                    .'apa pun, jadi dari dalam aplikasi "terkirim" dan "ditulis ke log" tampak sama '
                    .'persis. Uji dengan tombol kirim email tes di halaman Pengecualian.',
            ],
            [
                'nama' => 'QRIS',
                'baik' => $pembayaran['qris'],
                'kabar' => $pembayaran['qris']
                    ? 'Payload sah atas nama '.$pembayaran['merchant'].'.'
                    : 'Belum diisi atau CRC-nya tidak cocok.',
                'akibat' => 'Halaman pembayaran diam-diam jatuh ke transfer bank; kalau rekening juga kosong, tidak ada cara membayar sama sekali.',
            ],
            [
                'nama' => 'Rekening bank',
                'baik' => (bool) $pembayaran['bank'],
                'kabar' => $pembayaran['bank']
                    ? $pembayaran['bank']['name'].' · '.$pembayaran['bank']['account_number']
                    : 'Belum diisi.',
                'akibat' => 'Paket tahunan bisa melampaui batas QRIS per transaksi, dan tanpa rekening tidak ada jalan lain.',
            ],
            [
                'nama' => 'Antrean bergerak',
                'baik' => ! $antrean['macet'],
                'kabar' => $antrean['macet']
                    ? $antrean['menunggu'].' menunggu, tidak ada yang terkirim sejak '
                        .($antrean['terakhirBergerak']?->diffForHumans() ?? 'entah kapan').'.'
                    : ($antrean['menunggu'] === 0
                        ? 'Kosong.'
                        : $antrean['menunggu'].' menunggu dan bergerak normal.'),
                'akibat' => 'Worker mati berarti pesan diam berstatus Mengantre selamanya — tanpa galat, '
                    .'tanpa baris log baru. Pengirimnya mengira gateway lambat; penerimanya tidak menerima apa-apa. '
                    .'Dianggap macet kalau tidak ada pesan yang terkirim selama '.$antrean['ambang'].' menit '
                    .'sementara masih ada pekerjaan menunggu — panjangnya antrean sendiri bukan tanda kerusakan, '
                    .'broadcast besar memang wajar berjam-jam.',
            ],
            [
                'nama' => 'Sambungan HTTPS',
                'baik' => $keamanan['https'] || ! $keamanan['wajib_aman'],
                'kabar' => $keamanan['https']
                    ? 'Halaman ini dibuka lewat HTTPS.'
                    : 'Halaman ini dibuka lewat HTTP polos.',
                'akibat' => 'Kata sandi, cookie sesi, dan API key pelanggan lewat jaringan apa adanya — '
                    .'siapa pun di jaringan yang sama bisa membacanya dan masuk sebagai orang itu. '
                    .'Di lokal ini wajar; di produksi berarti sertifikat Coolify belum terpasang.',
            ],
            [
                'nama' => 'Cookie sesi terkunci',
                'baik' => ($keamanan['cookie_secure'] && $keamanan['sesi_terenkripsi']) || ! $keamanan['wajib_aman'],
                'kabar' => ($keamanan['cookie_secure'] ? 'Secure menyala' : 'SESSION_SECURE_COOKIE mati')
                    .', '.($keamanan['sesi_terenkripsi'] ? 'isi sesi terenkripsi.' : 'SESSION_ENCRYPT mati.'),
                'akibat' => 'Tanpa Secure, satu permintaan http:// sebelum redirect sudah cukup membocorkan '
                    .'sesi admin. Tanpa enkripsi, isi sesi tersimpan apa adanya di tabel sessions — '
                    .'siapa pun yang bisa membaca database bisa menyamar jadi pengguna mana pun. '
                    .'Keduanya sengaja dimatikan di lokal: cookie secure di http://127.0.0.1 membuat login '
                    .'gagal tanpa pesan apa pun.',
            ],
            [
                'nama' => 'Header keamanan',
                'baik' => $keamanan['header'],
                'kabar' => $keamanan['header']
                    ? 'HSTS, X-Frame-Options, nosniff, dan Permissions-Policy terpasang.'
                    : 'HeaderKeamanan tidak terdaftar di middleware global.',
                'akibat' => 'Dashboard bisa dibingkai halaman lain dan tombolnya ditumpangi tanpa disadari '
                    .'pengguna, dan browser tidak pernah tahu bahwa alamat ini seharusnya selalu HTTPS.',
            ],
            [
                'nama' => 'Mode debug',
                'baik' => $keamanan['debug_mati'],
                'kabar' => $keamanan['debug_mati'] ? 'APP_DEBUG mati.' : 'APP_DEBUG menyala.',
                'akibat' => 'Setiap halaman galat memamerkan seluruh isi environment kepada pengunjung — '
                    .'kata sandi database, secret HMAC engine, dan kredensial Google sekaligus.',
            ],
            [
                'nama' => 'Saldo cocok dengan buku besar',
                'baik' => $saldo->isEmpty(),
                'kabar' => $saldo->isEmpty()
                    ? 'Seluruh workspace pay as you go cocok.'
                    : $saldo->count().' workspace tidak cocok: '
                        .$saldo->map(fn ($w) => $w->name.' (saldo '.number_format($w->balance, 0, ',', '.')
                            .', buku besar '.number_format($w->buku_besar, 0, ',', '.').')')->join('; '),
                'akibat' => 'Saldo yang tidak bisa direkonsiliasi adalah uang pelanggan yang tidak bisa '
                    .'dipertanggungjawabkan. Selisihnya tidak menghasilkan galat apa pun — yang '
                    .'menemukannya akan jadi pelanggan yang merasa saldonya berkurang sendiri, dan '
                    .'saat itu tidak ada cara tahu sejak kapan atau berapa. Periksa '
                    .'balance_transactions workspace itu; tiap baris menyimpan sisa saldo '
                    .'sesudahnya, jadi tempat rantainya putus bisa ditelusuri.',
            ],
            [
                'nama' => 'Job gagal',
                'baik' => $antrean['gagal'] === 0,
                'kabar' => $antrean['gagal'] === 0
                    ? 'Tidak ada yang gagal.'
                    : $antrean['gagal'].' job gagal menumpuk.',
                'akibat' => 'Job yang gagal tidak dicoba lagi sendiri; pesannya tidak akan pernah terkirim.',
            ],
        ];

        $bermasalah = collect($pemeriksaan)->reject(fn ($p) => $p['baik'])->count();
    @endphp

    {{-- ===================== Ikhtisar kesehatan ===================== --}}
    <x-section :judul="$bermasalah === 0 ? 'Semua pemeriksaan lolos' : $bermasalah.' hal perlu dibereskan'"
             sub="Semua yang di bawah ini bisa berhenti bekerja tanpa satu pun galat yang terlihat."
             @class(['mb-5', 'border-destructive' => $bermasalah > 0])>
        <table class="w-full min-w-[52rem] text-sm">
            <tbody class="divide-y divide-border">
                @foreach ($pemeriksaan as $p)
                    <tr>
                        <td class="w-8 px-5 py-3">
                            @if ($p['baik'])
                                <span class="grid h-6 w-6 place-items-center rounded-full bg-primary/10 text-primary">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                </span>
                            @else
                                <span class="grid h-6 w-6 place-items-center rounded-full bg-destructive/10 text-destructive">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01"/></svg>
                                </span>
                            @endif
                        </td>
                        <td class="px-2 py-3 font-medium">{{ $p['nama'] }}</td>
                        <td class="px-5 py-3 text-muted-foreground">{{ $p['kabar'] }}</td>
                        <td class="px-5 py-3">
                            @unless ($p['baik'])
                                <span class="text-xs leading-relaxed text-destructive">{{ $p['akibat'] }}</span>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-section>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- ===================== Kapasitas ===================== --}}
        <x-section judul="Kapasitas sesi" sub="Batas yang menentukan berapa pelanggan bisa dilayani sama sekali." rapat>
            <p class="text-3xl font-semibold tabular-nums">
                {{ $kapasitas['hidup'] }}<span class="text-lg font-normal text-muted-foreground">/{{ $kapasitas['batas'] }}</span>
            </p>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full {{ $persenKapasitas >= 90 ? 'bg-destructive' : ($persenKapasitas >= 70 ? 'bg-amber-500' : 'bg-primary') }}"
                     style="width: {{ $persenKapasitas }}%"></div>
            </div>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Sesi berjalan di atas engine Baileys via WebSocket murni tanpa browser headless.
                Batas maksimal dikendalikan engine lewat <code>WA_MAX_SESSIONS</code>, bukan oleh Laravel — begitu penuh,
                pelanggan berikutnya yang membayar tidak akan bisa menautkan nomornya sampai ada slot kosong.
            </p>

            @if ($engine['terjangkau'])
                <div class="mt-3 space-y-1.5 text-sm text-muted-foreground">
                    <p>
                        Sesi aktif di engine:
                        <strong class="text-foreground">{{ $engine['sesi'] ?? 0 }} total</strong>
                        (<span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ $engine['connected'] ?? 0 }} terhubung</span>,
                        <span>{{ $engine['connecting'] ?? 0 }} menghubungkan</span>,
                        <span>{{ $engine['qr'] ?? 0 }} menunggu scan QR</span>)
                    </p>
                    @if (isset($engine['memory']['rss_mb']))
                        <p>
                            Memori Node.js engine:
                            <strong class="text-foreground">{{ $engine['memory']['rss_mb'] }} MB RSS</strong>
                            <span class="text-xs">({{ $engine['memory']['heap_used_mb'] ?? '-' }} MB heap terpakai / {{ $engine['memory']['heap_total_mb'] ?? '-' }} MB total)</span>
                            @if (isset($engine['uptime_seconds']))
                                &middot; Uptime: <strong class="text-foreground">{{ \Carbon\CarbonInterval::seconds($engine['uptime_seconds'])->cascade()->forHumans(['short' => true]) }}</strong>
                            @endif
                        </p>
                    @endif
                </div>
            @else
                <p class="mt-3 text-sm text-destructive">
                    Engine tidak terjangkau ({{ $engine['pesan'] }}).
                </p>
            @endif

            <p class="mt-3 text-sm text-muted-foreground">
                Slot dijanjikan ke langganan berbayar:
                <strong>{{ $kapasitas['dijanjikan'] }}</strong>/{{ $kapasitas['batas'] }}.
                @if ($kapasitas['menunggu'] > 0)
                    <strong class="text-destructive">{{ $kapasitas['menunggu'] }} permintaan di daftar
                    tunggu</strong>@if ($kapasitas['menunggu_terlama'] ?? null), yang terlama sejak
                        {{ \Illuminate\Support\Carbon::parse($kapasitas['menunggu_terlama'])->diffForHumans() }}@endif.
                    Ini pelanggan yang mau membayar dan kita tolak — angka inilah yang menjawab
                    kapan kapasitas perlu ditambah.
                @else
                    Tidak ada yang menunggu.
                @endif
            </p>

            <p class="mt-2 text-sm text-muted-foreground">
                Pesan gagal 24 jam terakhir: <strong class="{{ $pesanGagal24Jam > 0 ? 'text-destructive' : '' }}">{{ number_format($pesanGagal24Jam) }}</strong>
            </p>
        </x-section>

        {{-- ===================== Irama siklus ===================== --}}
        <x-section judul="Irama penagihan" sub="Angka yang menentukan kapan pelanggan ditagih dan kapan layanannya berhenti." rapat>
            <dl class="space-y-2.5 text-sm">
                @foreach ([
                    'Tagihan terbit sebelum habis' => $siklus['terbit_sebelum'].' hari',
                    'Batas bayar tiap tagihan' => $siklus['tenggat_tagihan'].' hari',
                    'Masa tenggang sebelum sesi dilepas' => $siklus['masa_tenggang'].' hari',
                    'Pengingat dikirim H-' => $siklus['pengingat'],
                    'Jeda antar pesan (anti-ban)' => $siklus['jeda_kirim'],
                    'PPN' => $pembayaran['pajak'] > 0 ? $pembayaran['pajak'].'%' : 'tidak dipungut',
                ] as $label => $nilai)
                    <div class="flex justify-between gap-4 border-b border-border pb-2.5 last:border-0 last:pb-0">
                        <dt class="text-muted-foreground">{{ $label }}</dt>
                        <dd class="text-right font-medium">{{ $nilai }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-section>
    </div>

    {{-- ===================== Paket ===================== --}}
    <x-section judul="Paket yang sedang berlaku"
             sub="Dibaca dari config/plans.php — sumber yang sama dengan halaman harga dan penegakan batas."
             class="mt-5">
        <table class="w-full min-w-[52rem] text-sm">
            <thead class="border-y border-border bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th class="px-5 py-2.5 font-medium">Paket</th>
                    <th class="px-5 py-2.5 text-right font-medium">Bulanan</th>
                    <th class="px-5 py-2.5 text-right font-medium">Tahunan</th>
                    <th class="px-5 py-2.5 text-right font-medium">Nomor</th>
                    <th class="px-5 py-2.5 text-right font-medium">Pesan/bln</th>
                    <th class="px-5 py-2.5 text-right font-medium">API key</th>
                    <th class="px-5 py-2.5 text-right font-medium">Retensi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($paket as $p)
                    <tr>
                        <td class="px-5 py-2.5 font-medium">{{ $p->name() }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">Rp {{ number_format($p->price('monthly'), 0, ',', '.') }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">Rp {{ number_format($p->price('yearly'), 0, ',', '.') }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ $p->limits()['max_sessions'] }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ number_format($p->limits()['monthly_message_quota']) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ $p->maxApiKeys() === 0 ? '∞' : $p->maxApiKeys() }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums">{{ $p->messageRetentionDays() }} hari</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-section>

    {{-- ===================== Identitas & Pengirim Email ===================== --}}
    <x-section judul="Identitas & Pengirim Email"
               sub="Konfigurasi pengirim email dan alamat penerima notifikasi admin. Kosongkan kolom untuk menggunakan nilai bawaan .env."
               class="mt-5" rapat>
        <form method="POST" action="{{ route('admin.system.mail.update') }}" class="space-y-4 max-w-4xl">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="mail_noreply_address" class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
                        Alamat Pengirim Noreply
                    </label>
                    <input type="email" id="mail_noreply_address" name="mail_noreply_address"
                           value="{{ old('mail_noreply_address', $identitasEmail['noreply_address']) }}"
                           placeholder="{{ $identitasEmail['defaults']['noreply_address'] }}"
                           class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <p class="mt-1 text-xs text-muted-foreground">Wajib memakai domain terverifikasi ({{ implode(', ', $identitasEmail['allowed_domains']) }}).</p>
                    @error('mail_noreply_address')
                        <p class="mt-1 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="mail_noreply_name" class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
                        Nama Pengirim Noreply
                    </label>
                    <input type="text" id="mail_noreply_name" name="mail_noreply_name"
                           value="{{ old('mail_noreply_name', $identitasEmail['noreply_name']) }}"
                           placeholder="{{ $identitasEmail['defaults']['noreply_name'] }}"
                           class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    @error('mail_noreply_name')
                        <p class="mt-1 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="mail_support_address" class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
                        Alamat Pengirim Support
                    </label>
                    <input type="email" id="mail_support_address" name="mail_support_address"
                           value="{{ old('mail_support_address', $identitasEmail['support_address']) }}"
                           placeholder="{{ $identitasEmail['defaults']['support_address'] }}"
                           class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <p class="mt-1 text-xs text-muted-foreground">Dipakai untuk tiket dan balasan. Wajib domain terverifikasi.</p>
                    @error('mail_support_address')
                        <p class="mt-1 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="mail_support_name" class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
                        Nama Pengirim Support
                    </label>
                    <input type="text" id="mail_support_name" name="mail_support_name"
                           value="{{ old('mail_support_name', $identitasEmail['support_name']) }}"
                           placeholder="{{ $identitasEmail['defaults']['support_name'] }}"
                           class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    @error('mail_support_name')
                        <p class="mt-1 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="mail_reply_to_address" class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
                        Alamat Reply-To
                    </label>
                    <input type="email" id="mail_reply_to_address" name="mail_reply_to_address"
                           value="{{ old('mail_reply_to_address', $identitasEmail['reply_to_address']) }}"
                           placeholder="{{ $identitasEmail['defaults']['reply_to_address'] }}"
                           class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    <p class="mt-1 text-xs text-muted-foreground">Alamat tujuan saat klien membalas email (noreply maupun support).</p>
                    @error('mail_reply_to_address')
                        <p class="mt-1 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="mail_admin_addresses" class="block text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
                        Daftar Email Admin / Tim
                    </label>
                    <textarea id="mail_admin_addresses" name="mail_admin_addresses" rows="3"
                              placeholder="{{ $identitasEmail['defaults']['admin_addresses'] }}"
                              class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary font-mono">{{ old('mail_admin_addresses', $identitasEmail['admin_addresses']) }}</textarea>
                    <p class="mt-1 text-xs text-muted-foreground">Dipisah dengan koma atau baris baru. Alert admin dikirim ke seluruh alamat ini.</p>
                    @error('mail_admin_addresses')
                        <p class="mt-1 text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90">
                    Simpan Pengaturan Email
                </button>
            </div>
        </form>

        {{-- Uji Kirim Email --}}
        <div class="mt-6 border-t border-border pt-6 max-w-4xl">
            <h4 class="text-sm font-semibold text-foreground mb-2">Uji Kirim Email Tes</h4>
            <p class="text-xs text-muted-foreground mb-4">Kirim email percobaan secara sinkron untuk memverifikasi sambungan SMTP Brevo dan identitas pengirim.</p>
            <form method="POST" action="{{ route('admin.system.mail.test') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="w-36">
                    <label for="test_type" class="block text-xs font-medium text-muted-foreground mb-1">Identitas</label>
                    <select id="test_type" name="type" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                        <option value="noreply">Noreply</option>
                        <option value="support">Support</option>
                    </select>
                </div>
                <div class="flex-1 min-w-48">
                    <label for="test_email" class="block text-xs font-medium text-muted-foreground mb-1">Email Tujuan</label>
                    <input type="email" id="test_email" name="email" required
                           value="{{ old('email', auth()->user()?->email ?? '') }}" placeholder="nama@domain.com"
                           class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                </div>
                <button type="submit" class="rounded-lg border border-border bg-muted/60 px-4 py-2 text-sm font-medium hover:bg-muted transition">
                    Kirim Email Tes
                </button>
            </form>
        </div>
    </x-section>

    {{-- ===================== Lingkungan ===================== --}}
    <x-section judul="Lingkungan" class="mt-5" rapat>
        <dl class="grid gap-x-8 gap-y-2.5 text-sm sm:grid-cols-2">
            @foreach ([
                'Tahap' => $lingkungan['app_env'],
                'Zona waktu' => $lingkungan['zona_waktu'],
                'PHP' => $lingkungan['php'],
                'Laravel' => $lingkungan['laravel'],
            ] as $label => $nilai)
                <div class="flex justify-between gap-4 border-b border-border pb-2.5">
                    <dt class="text-muted-foreground">{{ $label }}</dt>
                    <dd class="font-medium">{{ $nilai }}</dd>
                </div>
            @endforeach

            <div class="flex justify-between gap-4 border-b border-border pb-2.5">
                <dt class="text-muted-foreground">APP_DEBUG</dt>
                <dd>
                    @if ($lingkungan['app_debug'] && $lingkungan['app_env'] === 'production')
                        {{-- Halaman galat menampilkan isi environment, termasuk
                             kata sandi database dan secret HMAC. --}}
                        <x-badge warna="merah">menyala di produksi</x-badge>
                    @else
                        <span class="font-medium">{{ $lingkungan['app_debug'] ? 'menyala' : 'mati' }}</span>
                    @endif
                </dd>
            </div>
        </dl>
    </x-section>
@endsection
