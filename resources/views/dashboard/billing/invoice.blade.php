@extends('layouts.checkout')
@section('title', 'Checkout Tagihan ' . $invoice->number)

@section('content')
    @php
        $selesai = $invoice->isPaid() || $invoice->isOverdue() || $invoice->status === 'canceled';

        /*
         | Metode mana yang boleh ditawarkan. Yang tidak dikonfigurasi tidak
         | pernah muncul sebagai pilihan — menawarkan cara bayar yang belum ada
         | di baliknya persis kesalahan yang dulu dibuat driver `fonnte` pada
         | halaman pembuatan sesi: pelanggan memilih, lalu menemui jalan buntu.
         */
        $daftarRekeningTujuan = (isset($bankAccounts) && $bankAccounts->isNotEmpty()) 
            ? $bankAccounts 
            : (filled($bank['account_number'] ?? null) ? collect([ (object) [
                'id' => 0,
                'bank_name' => $bank['name'] ?? 'Transfer Bank',
                'account_number' => $bank['account_number'],
                'account_holder' => $bank['account_holder'] ?? 'VexaHost',
                'type' => 'bank',
                'instructions' => null,
            ]]) : collect());

        $punyaBank = $daftarRekeningTujuan->isNotEmpty();

        $metode = [];
        if ($xenditAvailable ?? true) {
            $metode['xendit'] = [
                'label' => 'Bayar Otomatis (Instan)',
                'badge' => 'Otomatis',
                'ket' => 'QRIS, Virtual Account Multi-Bank (Mandiri, BNI, BRI, Permata, dll), E-Wallet & Retail',
            ];
        } elseif ($mayarAvailable ?? false) {
            $metode['mayar'] = [
                'label' => 'Bayar Otomatis (Mayar)',
                'badge' => 'Otomatis',
                'ket' => 'QRIS, Virtual Account & E-Wallet via Mayar',
            ];
        }
        if ($qrisPayload) {
            $metode['qris'] = [
                'label' => 'QRIS (Manual)',
                'badge' => 'Verifikasi Admin',
                'ket' => 'Scan QRIS manual dan konfirmasi atau unggah bukti transfer',
            ];
        }
        if ($punyaBank) {
            $metode['bank'] = [
                'label' => 'Transfer Bank (Manual)',
                'badge' => 'Verifikasi Admin',
                'ket' => 'Transfer via ATM, Mobile Banking, Internet Banking rekening resmi',
            ];
        }
        $metodeAwal = ($xenditAvailable ?? true) ? 'xendit' : (($mayarAvailable ?? false) ? 'mayar' : ($qrisPayload ? 'qris' : ($punyaBank ? 'bank' : '')));

        $pilihanBank = (!empty($daftarBank) && is_array($daftarBank)) ? $daftarBank : \App\Support\DaftarBank::all();
        $daftarProvinsi = (!empty($daftarProvinsi) && is_array($daftarProvinsi)) ? $daftarProvinsi : \App\Support\WilayahIndonesia::provinsi();
        $daftarKota = $daftarKota ?? [];
        $daftarKecamatan = $daftarKecamatan ?? [];
    @endphp

    {{-- ===================== Keadaan Akhir (Paid, Overdue, Canceled) =====================
         Tagihan yang sudah lunas, kedaluwarsa, atau dibatalkan tidak lagi memerlukan form checkout.
         Menampilkan layar konfirmasi/tanda terima mandiri yang bersih dan profesional.
         ===================================================================================== --}}
    @if ($selesai)
        <div class="mx-auto max-w-xl py-6 sm:py-12">
            <div class="overflow-hidden rounded-2xl border border-border bg-card p-6 text-center shadow-lg sm:p-10">
                
                @if ($invoice->isPaid())
                    {{-- Status: Lunas / Sukses --}}
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-primary/10 text-primary ring-8 ring-primary/5">
                        <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                    </div>

                    <h1 class="mt-6 text-2xl font-bold tracking-tight text-foreground sm:text-3xl">Pembayaran diterima</h1>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Tagihan <span class="font-semibold text-foreground">{{ $invoice->number }}</span> telah lunas pada 
                        <span class="font-medium text-foreground">{{ $invoice->paid_at ? $invoice->paid_at->translatedFormat('j F Y, H:i') : now()->translatedFormat('j F Y, H:i') }}</span>.
                        Layanan langganan workspace Anda sudah aktif.
                    </p>

                    {{-- Ringkasan Tanda Terima --}}
                    <div class="mt-8 rounded-xl border border-border/80 bg-muted/30 p-4 text-left text-sm">
                        <div class="flex items-center justify-between py-1.5 text-xs text-muted-foreground">
                            <span>Nomor Tagihan</span>
                            <span class="font-mono font-medium text-foreground">{{ $invoice->number }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1.5 text-xs text-muted-foreground">
                            <span>Paket Berlangganan</span>
                            <span class="font-medium text-foreground">{{ $plan->name() }} &middot; {{ $invoice->periodLabel() }}</span>
                        </div>
                        <div class="flex items-center justify-between py-1.5 text-xs text-muted-foreground">
                            <span>Workspace</span>
                            <span class="font-medium text-foreground">{{ ($currentWorkspace ?? $invoice->workspace)->name ?? '-' }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-border pt-2 font-medium">
                            <span class="text-foreground">Total Pembayaran</span>
                            <span class="text-base font-bold text-primary">Rp {{ number_format($invoice->total, 0, ',', '.') }}</span>
                        </div>
                    </div>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
                        <a href="{{ route('dashboard') }}" 
                           class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90">
                            <span>Buka Dashboard</span>
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                        <a href="{{ route('billing.history') }}" 
                           class="inline-flex items-center justify-center rounded-xl border border-border bg-card px-5 py-3 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground">
                            Riwayat Tagihan
                        </a>
                    </div>

                @elseif ($invoice->isOverdue())
                    {{-- Status: Kedaluwarsa --}}
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-destructive/10 text-destructive ring-8 ring-destructive/5">
                        <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>

                    <h1 class="mt-6 text-2xl font-bold tracking-tight text-foreground">Batas waktu pembayaran sudah lewat</h1>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Tagihan ini tidak berlaku lagi. Terbitkan tagihan baru dari halaman paket dengan kode unik yang terbarukan.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
                        <a href="{{ route('billing.plans') }}" 
                           class="inline-flex items-center justify-center rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90">
                            Pilih Paket Baru
                        </a>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center justify-center rounded-xl border border-border bg-card px-5 py-3 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground">
                            Kembali ke Ringkasan
                        </a>
                    </div>

                @else
                    {{-- Status: Dibatalkan --}}
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-muted text-muted-foreground ring-8 ring-muted/50">
                        <svg class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="15" y1="9" x2="9" y2="15"/>
                            <line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                    </div>

                    <h1 class="mt-6 text-2xl font-bold tracking-tight text-foreground">Tagihan ini dibatalkan</h1>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Tagihan <span class="font-medium text-foreground">{{ $invoice->number }}</span> telah dibatalkan dan tidak memerlukan tindakan lebih lanjut.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
                        <a href="{{ route('billing.plans') }}" 
                           class="inline-flex items-center justify-center rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90">
                            Pilih Paket
                        </a>
                        <a href="{{ route('billing.index') }}" 
                           class="inline-flex items-center justify-center rounded-xl border border-border bg-card px-5 py-3 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground">
                            Kembali ke Ringkasan
                        </a>
                    </div>
                @endif

            </div>
        </div>

    @else

    {{-- ===================== Standalone Full-Page Checkout =====================
         Tata Letak Baru:
         - KIRI ATAS: Paket & Termasuk di Paket Ini
         - KIRI BAWAH: Data Pelanggan / Data Penagihan
         - KANAN ATAS: Ringkasan Biaya, Pilih Metode & Munculnya Pembayaran (QRIS/Bank)
         - KANAN BAWAH (Di bawah Payment): Kirim Bukti Pembayaran
         ========================================================================== --}}
    <script>
        window.checkoutInvoiceData = {
            metodeAwal: @json($metodeAwal),
            xenditPaymentUrl: @json($xenditPaymentUrl ?? ''),
            billingLengkap: @json((bool)$penagihan['lengkap']),
            xenditEndpoint: @json(route('billing.invoice.xendit', $invoice->id)),
            mayarEndpoint: @json(route('billing.invoice.mayar', $invoice->id)),
            saveBillingEndpoint: @json(route('billing.details', $invoice->id)),
            csrfToken: @json(csrf_token())
        };

        window.formDataPelangganData = {
            tipePelanggan: @json(old('billing_type', $penagihan['type'] ?? 'individu')),
            provinsi: @json(old('billing_province', $penagihan['province'] ?? '')),
            kota: @json(old('billing_city', $penagihan['city'] ?? '')),
            kecamatan: @json(old('billing_district', $penagihan['district'] ?? '')),
            daftarKota: @json($daftarKota ?? []),
            daftarKecamatan: @json($daftarKecamatan ?? [])
        };

        function checkoutInvoice(cfg) {
            const config = cfg || window.checkoutInvoiceData || {};
            return {
                mobileSummaryOpen: false,
                metode: config.metodeAwal || 'xendit',
                copiedRekening: false,
                copiedTotal: false,
                paymentLoading: false,
                paymentUrl: (config.xenditPaymentUrl && String(config.xenditPaymentUrl).includes('xendit')) ? config.xenditPaymentUrl : '',
                billingLengkap: Boolean(config.billingLengkap),
                init() {
                    if (this.metode === 'qris') {
                        this.$nextTick(() => {
                            if (window.renderQris) window.renderQris();
                        });
                    }
                    this.$watch('metode', (val) => {
                        if (val === 'qris') {
                            this.$nextTick(() => {
                                if (window.renderQris) window.renderQris();
                            });
                        }
                    });
                },
                salinTeks(teks, idTarget) {
                    navigator.clipboard.writeText(teks).then(() => {
                        this[idTarget] = true;
                        setTimeout(() => { this[idTarget] = false; }, 2000);
                    });
                },
                tandaiFormKosong() {
                    const form = document.querySelector('#form-data-pelanggan form');
                    if (!form) return [];

                    form.querySelectorAll('.border-destructive').forEach((el) => {
                        el.classList.remove('border-destructive', 'ring-2', 'ring-destructive/30', 'bg-destructive/5');
                    });

                    const tipeInput = form.querySelector('[name="billing_type"]');
                    const tipe = tipeInput ? tipeInput.value : 'individu';
                    const isBadan = tipe === 'badan';

                    const fields = [
                        { name: 'billing_name', label: isBadan ? 'Nama PIC' : 'Nama Lengkap' },
                        { name: 'billing_email', label: 'Email Penagihan' },
                        { name: 'billing_phone', label: 'Nomor WhatsApp' },
                        { name: 'billing_bank_name', label: 'Pilih Bank' },
                        { name: 'billing_bank_account', label: 'Nomor Rekening' },
                        { name: 'billing_bank_holder', label: isBadan ? 'Nama Pemilik Rekening Perusahaan' : 'Nama Pemilik Rekening' },
                        { name: 'billing_province', label: 'Provinsi' },
                        { name: 'billing_city', label: 'Kota atau Kabupaten' },
                        { name: 'billing_district', label: 'Kecamatan' },
                        { name: 'billing_address', label: isBadan ? 'Alamat Kantor atau Gedung' : 'Alamat Tempat Tinggal' }
                    ];

                    if (isBadan) {
                        fields.unshift({ name: 'billing_company', label: 'Nama Perusahaan' });
                    }

                    let firstEmpty = null;
                    let missingLabels = [];

                    fields.forEach((item) => {
                        const input = form.querySelector('[name="' + item.name + '"]');
                        if (input && (!input.value || !input.value.trim())) {
                            input.classList.add('border-destructive', 'ring-2', 'ring-destructive/30', 'bg-destructive/5');

                            const clearMarker = () => {
                                if (input.value && input.value.trim()) {
                                    input.classList.remove('border-destructive', 'ring-2', 'ring-destructive/30', 'bg-destructive/5');
                                    input.removeEventListener('input', clearMarker);
                                    input.removeEventListener('change', clearMarker);
                                }
                            };
                            input.addEventListener('input', clearMarker);
                            input.addEventListener('change', clearMarker);

                            if (!firstEmpty) {
                                firstEmpty = input;
                            }
                            missingLabels.push(item.label);
                        }
                    });

                    if (firstEmpty) {
                        firstEmpty.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setTimeout(() => {
                            try { firstEmpty.focus(); } catch (e) {}
                        }, 400);
                    } else {
                        const el = document.getElementById('form-data-pelanggan');
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }

                    return missingLabels;
                },
                simpanDataPelangganDanLanjutkan(onSuccess) {
                    const form = document.querySelector('#form-data-pelanggan form');
                    if (!form) {
                        if (onSuccess) onSuccess();
                        return;
                    }

                    const missing = this.tandaiFormKosong();
                    if (missing && missing.length > 0) {
                        const htmlContent = '<div class="text-xs text-muted-foreground leading-relaxed">' +
                            '<p>Mohon lengkapi data penagihan di sebelah kiri sebelum melanjutkan pembayaran.</p>' +
                            '<p class="mt-2 text-[11px] text-muted-foreground">Kolom yang belum diisi: <strong class="text-foreground">' +
                            missing.slice(0, 3).join(', ') + (missing.length > 3 ? ' (' + (missing.length - 3) + ' lainnya)' : '') +
                            '</strong></p>' +
                            '<p class="mt-2 font-medium text-destructive">Kolom yang wajib diisi telah diberi tanda batas merah.</p>' +
                            '</div>';

                        if (window.beriTahu) {
                            window.beriTahu({
                                icon: 'warning',
                                title: 'Data Pelanggan Belum Lengkap',
                                html: htmlContent,
                                confirmButtonText: 'Lengkapi Data'
                            });
                        } else if (window.Swal) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Data Pelanggan Belum Lengkap',
                                html: htmlContent,
                                confirmButtonColor: '#2563eb',
                                confirmButtonText: 'Lengkapi Data'
                            });
                        }
                        return;
                    }

                    this.saveBillingLoading = true;
                    const formData = new FormData(form);

                    fetch(config.saveBillingEndpoint || form.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': config.csrfToken,
                        }
                    })
                    .then(r => r.json().then(data => ({ status: r.status, ok: r.ok, body: data })))
                    .then(res => {
                        if (res.ok && res.body.status === 'ok') {
                            this.billingLengkap = true;
                            if (onSuccess) {
                                onSuccess();
                            } else {
                                this.saveBillingLoading = false;
                                if (window.beriTahu) {
                                    window.beriTahu({
                                        icon: 'success',
                                        title: 'Data Tersimpan',
                                        text: 'Data pelanggan berhasil disimpan. Anda sekarang dapat melanjutkan pembayaran.',
                                        confirmButtonText: 'Lanjutkan'
                                    });
                                }
                            }
                        } else {
                            this.saveBillingLoading = false;
                            const pesanError = (res.body && res.body.message) ? res.body.message : 'Gagal menyimpan data pelanggan. Periksa kembali form Anda.';

                            if (res.body && res.body.errors) {
                                Object.keys(res.body.errors).forEach(fieldName => {
                                    const inp = form.querySelector('[name="' + fieldName + '"]');
                                    if (inp) {
                                        inp.classList.add('border-destructive', 'ring-2', 'ring-destructive/30', 'bg-destructive/5');
                                        inp.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                });
                            }

                            if (window.beriTahu) {
                                window.beriTahu({
                                    icon: 'error',
                                    title: 'Periksa Isian Form',
                                    text: pesanError,
                                    confirmButtonText: 'Perbaiki'
                                });
                            } else if (window.Swal) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Periksa Isian Form',
                                    text: pesanError,
                                    confirmButtonColor: '#2563eb'
                                });
                            }
                        }
                    })
                    .catch(err => {
                        this.saveBillingLoading = false;
                        if (window.beriTahu) {
                            window.beriTahu({
                                icon: 'error',
                                title: 'Kendala Jaringan',
                                text: 'Gagal menghubungi server untuk menyimpan data penagihan.'
                            });
                        }
                    });
                },
                bayarMayar() {
                    this.bayarXendit();
                },
                bayarXendit() {
                    if (!this.billingLengkap) {
                        this.simpanDataPelangganDanLanjutkan(() => {
                            this.bayarXendit();
                        });
                        return;
                    }

                    if (this.paymentUrl && (this.paymentUrl.includes('xendit') || this.paymentUrl.includes('mayar'))) {
                        window.location.href = this.paymentUrl;
                        return;
                    }

                    this.paymentLoading = true;
                    const endpoint = (this.metode === 'mayar' && config.mayarEndpoint) ? config.mayarEndpoint : config.xenditEndpoint;
                    fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': config.csrfToken,
                            'Accept': 'application/json',
                        }
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'ok' && res.payment_url) {
                            this.paymentUrl = res.payment_url;
                            window.location.href = res.payment_url;
                        } else if (res.status === 'already_paid') {
                            window.location.reload();
                        } else if (res.status === 'incomplete_billing') {
                            this.paymentLoading = false;
                            this.billingLengkap = false;
                            this.tandaiFormKosong();
                            if (window.Swal) {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Data Pelanggan Belum Lengkap',
                                    html: '<div class="text-xs text-muted-foreground leading-relaxed">' +
                                          '<p>' + (res.message || 'Lengkapi data pelanggan terlebih dahulu.') + '</p>' +
                                          '<p class="mt-2 font-medium text-destructive">Kolom yang wajib diisi telah diberi tanda batas merah.</p>' +
                                          '</div>',
                                    confirmButtonColor: '#2563eb',
                                    confirmButtonText: 'Lengkapi Data'
                                });
                            } else if (window.beriTahu) {
                                window.beriTahu({
                                    icon: 'warning',
                                    title: 'Data Pelanggan Belum Lengkap',
                                    text: res.message || 'Lengkapi data pelanggan terlebih dahulu.'
                                });
                            }
                        } else {
                            this.paymentLoading = false;
                            const pesanError = res.message || 'Gagal memuat sesi pembayaran. Silakan coba beberapa saat lagi.';
                            if (window.Swal) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal Memuat Pembayaran',
                                    text: pesanError,
                                    confirmButtonColor: '#2563eb'
                                });
                            } else if (window.beriTahu) {
                                window.beriTahu({
                                    icon: 'error',
                                    title: 'Gagal Memuat Pembayaran',
                                    text: pesanError
                                });
                            }
                        }
                    })
                    .catch(err => {
                        this.paymentLoading = false;
                        if (window.Swal) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Kendala Jaringan',
                                text: 'Terjadi kendala jaringan saat menghubungi server pembayaran.',
                                confirmButtonColor: '#2563eb'
                            });
                        } else if (window.beriTahu) {
                            window.beriTahu({
                                icon: 'error',
                                title: 'Kendala Jaringan',
                                text: 'Terjadi kendala jaringan saat menghubungi server pembayaran.'
                            });
                        }
                    });
                }
            };
        }

        function formDataPelanggan(cfg) {
            const config = cfg || window.formDataPelangganData || {};
            return {
                tipePelanggan: config.tipePelanggan || 'individu',
                provinsi: config.provinsi || '',
                kota: config.kota || '',
                kecamatan: config.kecamatan || '',
                daftarKota: Array.isArray(config.daftarKota) ? config.daftarKota : [],
                daftarKecamatan: Array.isArray(config.daftarKecamatan) ? config.daftarKecamatan : [],
                wilayahData: null,
                async init() {
                    try {
                        const res = await fetch('/data/wilayah.json');
                        if (res.ok) {
                            this.wilayahData = await res.json();
                            if (this.provinsi && (!this.daftarKota || this.daftarKota.length === 0)) {
                                this.updateKotaList();
                            }
                            if (this.kota && (!this.daftarKecamatan || this.daftarKecamatan.length === 0)) {
                                this.updateKecamatanList();
                            }
                        }
                    } catch (e) {
                        console.warn('Gagal memuat data wilayah:', e);
                    }
                },
                updateKotaList() {
                    if (this.wilayahData && this.provinsi && this.wilayahData[this.provinsi]) {
                        this.daftarKota = Object.keys(this.wilayahData[this.provinsi]);
                    } else {
                        this.daftarKota = [];
                    }
                },
                updateKecamatanList() {
                    if (this.wilayahData && this.provinsi && this.kota && this.wilayahData[this.provinsi] && this.wilayahData[this.provinsi][this.kota]) {
                        this.daftarKecamatan = this.wilayahData[this.provinsi][this.kota];
                    } else {
                        this.daftarKecamatan = [];
                    }
                },
                onProvinsiChange() {
                    this.kota = '';
                    this.kecamatan = '';
                    this.daftarKecamatan = [];
                    this.updateKotaList();
                },
                onKotaChange() {
                    this.kecamatan = '';
                    this.updateKecamatanList();
                }
            };
        }

        window.checkoutInvoice = checkoutInvoice;
        window.formDataPelanggan = formDataPelanggan;

        document.addEventListener('alpine:init', () => {
            Alpine.data('checkoutInvoice', checkoutInvoice);
            Alpine.data('formDataPelanggan', formDataPelanggan);
        });
    </script>

    <div x-data="checkoutInvoice(window.checkoutInvoiceData)" class="space-y-6 pb-28 sm:pb-0">
        {{-- Tombol Navigasi Kembali --}}
        <div>
            <a href="{{ route('billing.history') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3.5 py-2 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground transition shadow-xs">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Kembali ke Riwayat Tagihan</span>
            </a>
        </div>

        {{-- Judul Halaman & Status Bar --}}
        <div class="flex flex-col gap-3 border-b border-border/70 pb-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-primary">Tagihan Pembayaran</span>
                    <span class="text-border">&bull;</span>
                    <span class="font-mono text-xs font-medium text-muted-foreground">{{ $invoice->number }}</span>
                </div>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-foreground sm:text-3xl">Selesaikan Langganan</h1>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/40 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-900 dark:text-amber-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                    Menunggu Pembayaran
                </span>
                @if ($invoice->due_at)
                    <span class="text-xs text-muted-foreground">
                        Batas: {{ $invoice->due_at->translatedFormat('j M Y, H:i') }}
                    </span>
                @endif
            </div>
        </div>

        {{-- Grid 2 Kolom --}}
        <div class="grid items-start gap-6 lg:grid-cols-[1.55fr_1fr]">

            {{-- ===================== SISI KIRI ===================== --}}
            <div class="space-y-6">

                {{-- KIRI 1: Paket & Termasuk di Paket Ini --}}
                <section class="overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-xs transition sm:p-6">
                    <div class="flex items-start justify-between gap-4 border-b border-border/80 pb-4">
                        <div>
                            <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                                {{ $invoice->period === 'yearly' ? 'Tahunan (Hemat 2 Bulan)' : 'Langganan Bulanan' }}
                            </span>
                            <h2 class="mt-2 text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                                Paket {{ $plan->name() }}
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground sm:text-sm">
                                {{ $plan->tagline() }}
                            </p>
                        </div>

                        <div class="text-right">
                            <span class="text-[11px] uppercase tracking-wider text-muted-foreground font-medium">Harga</span>
                            <p class="text-lg font-bold text-foreground sm:text-xl tabular-nums">
                                Rp {{ number_format($invoice->amount, 0, ',', '.') }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">{{ $invoice->periodLabel() }}</p>
                        </div>
                    </div>

                    {{-- Termasuk di paket ini --}}
                    <div class="pt-4">
                        <p class="text-xs font-bold uppercase tracking-wider text-foreground">
                            Termasuk di paket ini:
                        </p>
                        <ul class="mt-3 space-y-2 text-xs sm:text-sm text-muted-foreground">
                            @foreach ($plan->features() as $fitur)
                                <li class="flex items-start gap-2.5">
                                    <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full bg-primary/10 text-primary mt-0.5">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                    </span>
                                    <span class="leading-relaxed">{{ $fitur }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
                {{-- KIRI 2: Data Pelanggan / Data Penagihan --}}
                <section id="form-data-pelanggan" class="overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-xs transition sm:p-6"
                         x-data="formDataPelanggan(window.formDataPelangganData)">
                    <div class="flex items-start gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border/80 pb-3.5">
                                <div>
                                    <h2 class="text-base font-bold text-foreground">
                                        <span x-text="tipePelanggan === 'badan' ? 'Data Penagihan Badan Usaha' : 'Data Pelanggan Individu'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Data Penagihan Badan Usaha' : 'Data Pelanggan Individu' }}</span>
                                    </h2>
                                    <p class="mt-0.5 text-xs text-muted-foreground" x-text="tipePelanggan === 'badan' ? 'Data resmi penagihan perusahaan untuk kwitansi, pengembalian dana, dan verifikasi akun.' : 'Data resmi penagihan pelanggan untuk kwitansi, pengembalian dana, dan verifikasi akun.'">
                                        {{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Data resmi penagihan perusahaan untuk kwitansi, pengembalian dana, dan verifikasi akun.' : 'Data resmi penagihan pelanggan untuk kwitansi, pengembalian dana, dan verifikasi akun.' }}
                                    </p>
                                </div>
                                <template x-if="billingLengkap">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 px-2.5 py-0.5 text-[11px] font-semibold">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                        Lengkap
                                    </span>
                                </template>
                                <template x-if="!billingLengkap">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 px-2.5 py-0.5 text-[11px] font-semibold">
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        Wajib Diisi
                                    </span>
                                </template>
                            </div>

                            @if ($bolehBayar)
                                <form method="POST" action="{{ route('billing.details', $invoice->id) }}" 
                                      @submit.prevent="simpanDataPelangganDanLanjutkan()"
                                      data-validasi novalidate class="mt-4 space-y-4">
                                    @csrf

                                    {{-- 1. PILIHAN TIPE PELANGGAN: INDIVIDU / BADAN USAHA --}}
                                    <div>
                                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                             Jenis Pelanggan <span class="text-destructive">*</span>
                                        </label>
                                        <input type="hidden" name="billing_type" :value="tipePelanggan">
                                        <div class="grid grid-cols-2 gap-2">
                                            <button type="button" 
                                                    @click="tipePelanggan = 'individu'"
                                                    class="inline-flex items-center justify-center gap-2 rounded-xl border py-2.5 px-3 text-xs font-bold transition select-none"
                                                    :class="tipePelanggan === 'individu' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary shadow-xs' : 'border-border bg-card text-muted-foreground hover:bg-muted/50'">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                                <span>Individu</span>
                                            </button>
                                            <button type="button" 
                                                    @click="tipePelanggan = 'badan'"
                                                    class="inline-flex items-center justify-center gap-2 rounded-xl border py-2.5 px-3 text-xs font-bold transition select-none"
                                                    :class="tipePelanggan === 'badan' ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary shadow-xs' : 'border-border bg-card text-muted-foreground hover:bg-muted/50'">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>
                                                <span>Badan Usaha</span>
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Kolom Perusahaan jika Badan Usaha --}}
                                    <div x-show="tipePelanggan === 'badan'" x-cloak class="space-y-1">
                                        <label for="billing_company" class="block text-xs font-semibold uppercase tracking-wider text-foreground">
                                            Nama Perusahaan <span class="text-destructive">*</span>
                                        </label>
                                        <input id="billing_company" name="billing_company" type="text" maxlength="150"
                                               value="{{ old('billing_company', $penagihan['company'] ?? '') }}"
                                               placeholder="Nama perusahaan..."
                                               class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                    </div>

                                    {{-- 2. KONTAK PENANGGUNG JAWAB --}}
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="billing_name" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                <span x-text="tipePelanggan === 'badan' ? 'Nama PIC' : 'Nama Lengkap'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Nama PIC' : 'Nama Lengkap' }}</span> <span class="text-destructive">*</span>
                                            </label>
                                            <input id="billing_name" name="billing_name" type="text" required maxlength="120"
                                                   value="{{ old('billing_name', $penagihan['nama']) }}"
                                                   :placeholder="tipePelanggan === 'badan' ? 'Nama PIC...' : 'Nama lengkap...'"
                                                   placeholder="{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Nama PIC...' : 'Nama lengkap...' }}"
                                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        </div>

                                        <div>
                                            <label for="billing_email" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                Email Penagihan <span class="text-destructive">*</span>
                                            </label>
                                            <input id="billing_email" name="billing_email" type="email" required maxlength="180"
                                                   value="{{ old('billing_email', $penagihan['email']) }}"
                                                   placeholder="Email penagihan..."
                                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label for="billing_phone" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                Nomor WhatsApp Aktif <span class="text-destructive">*</span>
                                            </label>
                                            <input id="billing_phone" name="billing_phone" type="tel" required maxlength="20" inputmode="tel"
                                                   placeholder="08123456789..."
                                                   value="{{ old('billing_phone', $penagihan['telepon']) }}"
                                                   class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                            <p class="mt-1 text-[11px] text-muted-foreground">
                                                Notifikasi pembayaran lunas &amp; pengingat akan dikirim ke nomor ini.
                                            </p>
                                        </div>
                                    </div>

                                    {{-- 3. DATA REKENING BANK PELANGGAN (WAJIB) --}}
                                    <div class="rounded-xl border border-border/70 bg-muted/20 p-3.5 space-y-3">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                                                <span x-text="tipePelanggan === 'badan' ? 'Informasi Rekening Bank Perusahaan' : 'Informasi Rekening Bank Pribadi'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Informasi Rekening Bank Perusahaan' : 'Informasi Rekening Bank Pribadi' }}</span>
                                            </h3>
                                        </div>

                                        <div>
                                            <label for="billing_bank_name" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                Pilih Bank <span class="text-destructive">*</span>
                                            </label>
                                            <select id="billing_bank_name" name="billing_bank_name" required
                                                    class="w-full rounded-xl border border-input bg-background px-3 py-2 text-xs sm:text-sm text-foreground transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                                <option value="">Pilih bank...</option>
                                                @foreach ($pilihanBank as $b)
                                                    <option value="{{ $b }}" @selected(old('billing_bank_name', $penagihan['bank_name'] ?? '') === $b)>{{ $b }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label for="billing_bank_account" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    Nomor Rekening <span class="text-destructive">*</span>
                                                </label>
                                                <input id="billing_bank_account" name="billing_bank_account" type="text" required maxlength="50" inputmode="numeric"
                                                       value="{{ old('billing_bank_account', $penagihan['bank_account'] ?? '') }}"
                                                       placeholder="Nomor rekening..."
                                                       class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm font-mono transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                            </div>

                                            <div>
                                                <label for="billing_bank_holder" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    <span x-text="tipePelanggan === 'badan' ? 'Nama Pemilik Rekening Perusahaan' : 'Nama Pemilik Rekening Pribadi'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Nama Pemilik Rekening Perusahaan' : 'Nama Pemilik Rekening Pribadi' }}</span> <span class="text-destructive">*</span>
                                                </label>
                                                <input id="billing_bank_holder" name="billing_bank_holder" type="text" required maxlength="120"
                                                       value="{{ old('billing_bank_holder', $penagihan['bank_holder'] ?? '') }}"
                                                       :placeholder="tipePelanggan === 'badan' ? 'Nama rekening perusahaan...' : 'Nama pemilik rekening...'"
                                                       placeholder="{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Nama rekening perusahaan...' : 'Nama pemilik rekening...' }}"
                                                       class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                            </div>
                                        </div>
                                    </div>

                                    {{-- 4. ALAMAT DOMISILI / PERUSAHAAN DENGAN DROPDOWN BERJENJANG --}}
                                    <div class="rounded-xl border border-border/70 bg-muted/20 p-3.5 space-y-3">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-foreground">
                                                <span x-text="tipePelanggan === 'badan' ? 'Alamat Kantor Perusahaan' : 'Alamat Domisili Tempat Tinggal'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Alamat Kantor Perusahaan' : 'Alamat Domisili Tempat Tinggal' }}</span>
                                            </h3>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-2">
                                            {{-- Provinsi --}}
                                            <div>
                                                <label for="billing_province" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    Provinsi <span class="text-destructive">*</span>
                                                </label>
                                                <select id="billing_province" name="billing_province" required
                                                        x-model="provinsi"
                                                        @change="onProvinsiChange()"
                                                        class="w-full rounded-xl border border-input bg-background px-3 py-2 text-xs sm:text-sm text-foreground transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                                    <option value="">Pilih provinsi...</option>
                                                    @foreach ($daftarProvinsi as $prov)
                                                        <option value="{{ $prov }}" @selected(old('billing_province', $penagihan['province'] ?? '') === $prov)>{{ $prov }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            {{-- Kota / Kabupaten (Cascading Dropdown) --}}
                                            <div>
                                                <label for="billing_city" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    Kota atau Kabupaten <span class="text-destructive">*</span>
                                                </label>
                                                <select id="billing_city" name="billing_city" required
                                                        x-model="kota"
                                                        @change="onKotaChange()"
                                                        :disabled="!provinsi"
                                                        class="w-full rounded-xl border border-input bg-background px-3 py-2 text-xs sm:text-sm text-foreground transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-60 disabled:cursor-not-allowed">
                                                    <option value="" x-text="!provinsi ? 'Pilih provinsi...' : 'Pilih kota...'">Pilih kota...</option>
                                                    <template x-for="k in daftarKota" :key="k">
                                                        <option :value="k" x-text="k" :selected="k === kota"></option>
                                                    </template>
                                                </select>
                                            </div>

                                            {{-- Kecamatan (Cascading Dropdown) --}}
                                            <div>
                                                <label for="billing_district" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    Kecamatan <span class="text-destructive">*</span>
                                                </label>
                                                <select id="billing_district" name="billing_district" required
                                                        x-model="kecamatan"
                                                        :disabled="!kota"
                                                        class="w-full rounded-xl border border-input bg-background px-3 py-2 text-xs sm:text-sm text-foreground transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-60 disabled:cursor-not-allowed">
                                                    <option value="" x-text="!kota ? 'Pilih kota...' : 'Pilih kecamatan...'">Pilih kecamatan...</option>
                                                    <template x-for="kec in daftarKecamatan" :key="kec">
                                                        <option :value="kec" x-text="kec" :selected="kec === kecamatan"></option>
                                                    </template>
                                                </select>
                                            </div>

                                            {{-- Kode Pos --}}
                                            <div>
                                                <label for="billing_postal_code" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    Kode Pos <span class="font-normal lowercase text-muted-foreground">(opsional)</span>
                                                </label>
                                                <input id="billing_postal_code" name="billing_postal_code" type="text" maxlength="10" inputmode="numeric"
                                                       value="{{ old('billing_postal_code', $penagihan['postal_code'] ?? '') }}"
                                                       placeholder="Kode pos..."
                                                       class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                            </div>

                                            {{-- Alamat Lengkap --}}
                                            <div class="sm:col-span-2">
                                                <label for="billing_address" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-foreground">
                                                    <span x-text="tipePelanggan === 'badan' ? 'Alamat Lengkap Kantor atau Gedung' : 'Alamat Lengkap Tempat Tinggal'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Alamat Lengkap Kantor atau Gedung' : 'Alamat Lengkap Tempat Tinggal' }}</span> <span class="text-destructive">*</span>
                                                </label>
                                                <textarea id="billing_address" name="billing_address" rows="2" required maxlength="500"
                                                          :placeholder="tipePelanggan === 'badan' ? 'Jalan, nomor kantor atau gedung...' : 'Jalan, nomor rumah, RT RW...'"
                                                          placeholder="{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Jalan, nomor kantor atau gedung...' : 'Jalan, nomor rumah, RT RW...' }}"
                                                          class="w-full rounded-xl border border-input bg-background px-3.5 py-2 text-xs sm:text-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">{{ old('billing_address', $penagihan['address'] ?? '') }}</textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pt-1 flex items-center justify-between">
                                        <button type="submit" 
                                                class="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-xs font-semibold text-primary-foreground shadow-xs transition hover:opacity-90 active:scale-[0.98]">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                            <span x-text="tipePelanggan === 'badan' ? 'Simpan Data Perusahaan' : 'Simpan Data Pelanggan'">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Simpan Data Perusahaan' : 'Simpan Data Pelanggan' }}</span>
                                        </button>
                                        <span x-show="!billingLengkap" class="text-[11px] text-amber-600 dark:text-amber-400 font-medium">
                                            * Wajib disimpan untuk membuka pembayaran
                                        </span>
                                    </div>
                                </form>
                            @else
                                <div class="mt-4 rounded-xl border border-border/80 bg-muted/30 p-4 text-xs space-y-2">
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <span class="text-muted-foreground">Jenis Pelanggan:</span>
                                            <p class="font-semibold text-foreground capitalize">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Badan Usaha' : 'Individu' }}</p>
                                        </div>
                                        @if (!empty($penagihan['company']))
                                            <div>
                                                <span class="text-muted-foreground">Nama Perusahaan:</span>
                                                <p class="font-semibold text-foreground">{{ $penagihan['company'] }}</p>
                                            </div>
                                        @endif
                                        <div>
                                            <span class="text-muted-foreground">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Nama PIC:' : 'Nama Lengkap:' }}</span>
                                            <p class="font-medium text-foreground">{{ $penagihan['nama'] }}</p>
                                        </div>
                                        <div>
                                            <span class="text-muted-foreground">Email Penagihan:</span>
                                            <p class="font-medium text-foreground">{{ $penagihan['email'] }}</p>
                                        </div>
                                        <div>
                                            <span class="text-muted-foreground">Nomor WhatsApp:</span>
                                            <p class="font-mono text-foreground">{{ $penagihan['telepon'] ?? '-' }}</p>
                                        </div>
                                        <div>
                                            <span class="text-muted-foreground">Rekening Bank:</span>
                                            <p class="font-medium text-foreground">{{ $penagihan['bank_name'] ?? '-' }} ({{ $penagihan['bank_account'] ?? '-' }})</p>
                                        </div>
                                        <div class="col-span-2">
                                            <span class="text-muted-foreground">{{ ($penagihan['type'] ?? 'individu') === 'badan' ? 'Alamat Kantor Perusahaan:' : 'Alamat Domisili Tempat Tinggal:' }}</span>
                                            <p class="font-medium text-foreground">
                                                {{ implode(', ', array_filter([$penagihan['address'] ?? null, $penagihan['district'] ?? null, $penagihan['city'] ?? null, $penagihan['province'] ?? null, $penagihan['postal_code'] ?? null])) ?: '-' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3.5 border-t border-border/80 pt-3 text-xs text-muted-foreground flex justify-between">
                                <span>Workspace Penerima:</span>
                                <span class="font-semibold text-foreground">{{ ($currentWorkspace ?? $invoice->workspace)->name ?? '-' }}</span>
                            </div>
                        </div>
                    </div>
                </section>

            </div>

            {{-- ===================== SISI KANAN ===================== --}}
            <div class="lg:sticky lg:top-6 lg:self-start">

                {{-- KANAN: SATU KARTU TERPADU (Ringkasan Biaya, Cara Pembayaran & Kirim Bukti Pembayaran) --}}
                <section class="flex flex-col rounded-2xl border border-border bg-card shadow-sm transition">
                    
                    <div class="p-4 sm:p-5 space-y-4">
                        
                        {{-- Header Ringkasan Biaya (Hanya Desktop - Pada Mobile sudah ada di Floating Bottom Summary) --}}
                        <div class="hidden lg:block border-b border-border/80 pb-3">
                            <div class="flex items-center justify-between">
                                <h2 class="text-sm font-bold text-foreground sm:text-base">Ringkasan &amp; Pilih Pembayaran</h2>
                                <span class="font-mono text-xs font-semibold text-muted-foreground">{{ $invoice->number }}</span>
                            </div>

                            {{-- Rincian Biaya --}}
                            <div class="mt-3 space-y-1.5 text-xs">
                                <div class="flex justify-between text-muted-foreground">
                                    <span>Subtotal Paket ({{ $plan->name() }})</span>
                                    <span class="font-medium text-foreground tabular-nums">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</span>
                                </div>

                                @if ($invoice->tax_amount > 0)
                                    <div class="flex justify-between text-muted-foreground">
                                        <span>PPN {{ rtrim(rtrim(number_format(config('billing.tax_percent'), 2, ',', '.'), '0'), ',') }}%</span>
                                        <span class="font-medium text-foreground tabular-nums">Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}</span>
                                    </div>
                                @endif

                                {{-- Dua baris terpisah, bukan satu total.
                                     Pelanggan yang memakai kode referal berhak
                                     tahu berapa yang datang dari kodenya, dan
                                     resellernya berhak tahu itu juga —
                                     satu angka gabungan membuat keduanya
                                     mustahil diperiksa. --}}
                                @if ($invoice->intro_discount_amount > 0)
                                    <div class="flex justify-between text-muted-foreground">
                                        <span>Promo pembelian pertama</span>
                                        <span class="font-medium text-primary tabular-nums">−Rp {{ number_format($invoice->intro_discount_amount, 0, ',', '.') }}</span>
                                    </div>
                                @endif

                                @if ($invoice->referralDiscount() > 0)
                                    <div class="flex justify-between text-muted-foreground">
                                        <span>Potongan referal{{ $invoice->referralCode ? ' ('.$invoice->referralCode->code.')' : '' }}</span>
                                        <span class="font-medium text-primary tabular-nums">−Rp {{ number_format($invoice->referralDiscount(), 0, ',', '.') }}</span>
                                    </div>
                                @endif

                                @if ($invoice->unique_code > 0)
                                    <div class="flex justify-between text-muted-foreground">
                                        <span>Kode Unik Verifikasi</span>
                                        <span class="font-mono font-bold text-amber-800 dark:text-amber-300 tabular-nums">+Rp {{ number_format($invoice->unique_code, 0, ',', '.') }}</span>
                                    </div>
                                @endif

                                <div class="flex items-baseline justify-between border-t border-border/80 pt-2.5">
                                    <div>
                                        <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Total Pembayaran</span>
                                        @if ($invoice->unique_code > 0)
                                            <p class="text-[10px] text-muted-foreground">Termasuk kode unik</p>
                                        @endif
                                    </div>
                                    <p class="text-xl font-bold tracking-tight text-primary tabular-nums sm:text-2xl">
                                        Rp {{ number_format($invoice->total, 0, ',', '.') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Keadaan jika belum ada metode pembayaran aktif --}}
                        @if ($metode === [])
                            <div class="rounded-xl border border-destructive/20 bg-destructive/10 p-3 text-xs text-destructive">
                                Belum ada metode pembayaran yang aktif. Hubungi tim kami untuk menyelesaikan pembayaran tagihan ini.
                            </div>
                        @endif

                        {{-- PILIH CARA PEMBAYARAN (Hanya jika ada lebih dari 1 metode pembayaran) --}}
                        @if (count($metode) > 1)
                            <div>
                                <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Pilih Cara Pembayaran:
                                </label>
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach ($metode as $kunci => $m)
                                        <label class="flex cursor-pointer items-center justify-between gap-2 rounded-xl border p-2.5 transition text-xs select-none"
                                               :class="metode === '{{ $kunci }}' ? 'border-primary bg-primary/5 ring-1 ring-primary font-bold shadow-xs text-foreground' : 'border-border bg-card hover:bg-muted/50 text-muted-foreground'">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <input type="radio" name="metode_bayar" value="{{ $kunci }}" x-model="metode"
                                                       @checked($metodeAwal === $kunci)
                                                       class="h-3.5 w-3.5 text-primary focus:ring-primary border-input">
                                                <span class="truncate font-semibold">{{ $m['label'] }}</span>
                                            </div>
                                            <span class="shrink-0 rounded bg-muted/80 px-1.5 py-0.5 text-[9px] font-medium text-muted-foreground">{{ $m['badge'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Petunjuk: Jika belum memilih metode pembayaran --}}
                            <div x-show="!metode" @if($metodeAwal) x-cloak @endif class="rounded-xl border border-dashed border-border bg-muted/20 p-4 text-center text-xs text-muted-foreground">
                                <svg class="mx-auto h-6 w-6 text-muted-foreground/60 mb-1.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                    <rect width="20" height="14" x="2" y="5" rx="2"/>
                                    <line x1="2" y1="10" x2="22" y2="10"/>
                                </svg>
                                <p class="font-medium text-foreground">Silakan pilih cara pembayaran di atas</p>
                                <p class="mt-0.5 text-[11px]">Pilih QRIS atau Transfer bank untuk memunculkan detail pembayaran.</p>
                            </div>
                        @endif

                        {{-- MUNCULNYA PILIHAN PEMBAYARAN (Hanya setelah metode dipilih) --}}
                        
                        {{-- METODE: Otomatis (Instant Online) --}}
                        @if (($xenditAvailable ?? true) || ($mayarAvailable ?? false))
                            <div x-show="metode === 'xendit' || metode === 'mayar'" @if($metodeAwal !== 'xendit' && $metodeAwal !== 'mayar') x-cloak @endif class="space-y-4 {{ count($metode) > 1 ? 'border-t border-border/80 pt-3.5' : 'lg:border-t lg:border-border/80 lg:pt-3.5' }}">
                                <div class="rounded-xl border border-primary/20 bg-primary/5 p-4 text-xs text-foreground space-y-3">
                                    <div class="flex items-start gap-2.5">
                                        <div class="rounded-lg bg-primary/10 p-2 text-primary shrink-0">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                            </svg>
                                        </div>
                                        <div class="space-y-1">
                                            <h4 class="font-bold text-foreground">Pembayaran Instan &amp; Otomatis</h4>
                                            <p class="text-muted-foreground text-[11px] leading-relaxed">
                                                Dukung QRIS (BCA, Mandiri, GoPay, OVO, Dana, ShopeePay), Virtual Account Bank otomatis (Mandiri, BNI, BRI, Permata, dll), serta gerai retail.
                                                Layanan aktif otomatis dalam hitungan detik setelah pembayaran berhasil.
                                            </p>
                                        </div>
                                    </div>

                                    {{-- Catatan Keamanan / Penjelasan Nama Mitra Gateway --}}
                                    <div class="rounded-xl border border-blue-500/25 bg-blue-500/5 p-3 text-[11px] text-foreground leading-relaxed">
                                        <div class="flex items-center gap-1.5 font-bold text-blue-600 dark:text-blue-400 mb-1">
                                            <svg class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                            </svg>
                                            <span>Keamanan Transaksi Terjamin</span>
                                        </div>
                                        <p class="text-muted-foreground text-[11px] leading-relaxed">
                                            Sistem pembayaran kami diproses secara resmi melalui payment gateway berlisensi Bank Indonesia. Transaksi Anda diverifikasi langsung 24 jam dan terhubung aman dengan sistem VexaHost.
                                        </p>
                                    </div>

                                    <div class="flex items-center justify-between rounded-lg border border-border/60 bg-background/80 px-3.5 py-2.5 text-xs">
                                        <span class="text-muted-foreground">Total yang akan dibayar:</span>
                                        <span class="font-bold text-primary text-sm">Rp {{ number_format($invoice->total, 0, ',', '.') }}</span>
                                    </div>

                                    <div class="pt-1 space-y-2">
                                        <button type="button" 
                                                @click="bayarXendit()"
                                                :disabled="paymentLoading"
                                                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3.5 text-xs sm:text-sm font-bold text-primary-foreground shadow-md active:scale-[0.98] transition-all hover:opacity-95 disabled:opacity-50 cursor-pointer">
                                            <template x-if="paymentLoading">
                                                <svg class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                </svg>
                                            </template>
                                            <span x-text="paymentLoading ? 'Memproses Pembayaran...' : 'Bayar Sekarang'">Bayar Sekarang</span>
                                            <svg x-show="!paymentLoading" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                                        </button>
                                    </div>

                                    {{-- Bank & Saluran Pembayaran (Tanpa Background) --}}
                                    <div class="pt-2 border-t border-border/40 space-y-2">
                                        <p class="text-[10px] text-center font-medium text-muted-foreground">
                                            Didukung berbagai pilihan pembayaran resmi:
                                        </p>
                                        <div class="flex flex-wrap items-center justify-center gap-x-3.5 gap-y-2 px-1">
                                            @php
                                                $daftarMetodeLanding = [
                                                    ['label' => 'QRIS', 'file' => 'qris.svg'],
                                                    ['label' => 'Bank BCA', 'file' => 'bca.svg'],
                                                    ['label' => 'Bank Mandiri', 'file' => 'mandiri.svg'],
                                                    ['label' => 'Bank BNI', 'file' => 'bni.svg'],
                                                    ['label' => 'Bank BRI', 'file' => 'bri.svg'],
                                                    ['label' => 'Bank Permata', 'file' => 'permata.svg'],
                                                    ['label' => 'Bank BSI', 'file' => 'bsi.svg'],
                                                    ['label' => 'CIMB Niaga', 'file' => 'cimb.svg'],
                                                    ['label' => 'Bank Sahabat Sampoerna', 'file' => 'bss.svg'],
                                                    ['label' => 'OVO', 'file' => 'ovo.svg'],
                                                    ['label' => 'ShopeePay', 'file' => 'shopeepay.svg'],
                                                    ['label' => 'AstraPay', 'file' => 'astrapay.svg'],
                                                    ['label' => 'Indomaret', 'file' => 'indomaret.svg'],
                                                    ['label' => 'Alfamart', 'file' => 'alfamart.svg'],
                                                    ['label' => 'Akulaku PayLater', 'file' => 'akulaku.svg'],
                                                ];
                                            @endphp
                                            @foreach ($daftarMetodeLanding as $metodeItem)
                                                <img src="{{ asset('images/payments/' . $metodeItem['file']) }}"
                                                     alt="{{ $metodeItem['label'] }}"
                                                     title="{{ $metodeItem['label'] }}"
                                                     loading="lazy"
                                                     class="h-3.5 sm:h-4 w-auto max-w-[58px] object-contain opacity-75 hover:opacity-100 transition-opacity dark:brightness-125">
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- METODE: QRIS --}}
                        @if ($qrisPayload)
                            <div x-show="metode === 'qris'" @if($metodeAwal !== 'qris') x-cloak @endif class="space-y-3 {{ count($metode) > 1 ? 'border-t border-border/80 pt-3.5' : 'lg:border-t lg:border-border/80 lg:pt-3.5' }}">
                                
                                {{-- Panel QR Code (HANYA QRIS SAJA) --}}
                                <div class="flex flex-col items-center justify-center rounded-xl border border-border bg-muted/30 p-4 text-center">
                                    <div class="inline-flex items-center justify-center rounded-xl bg-white p-3 shadow-xs ring-1 ring-black/5">
                                        <canvas data-qris="{{ $qrisPayload }}" width="260" height="260"
                                                class="mx-auto block aspect-square"
                                                style="width: 180px !important; height: 180px !important; max-width: 100%; aspect-ratio: 1 / 1;"></canvas>
                                    </div>

                                    <p id="qris-fallback-msg" class="hidden mt-2 text-xs text-destructive font-medium">
                                        Kode QR gagal dimuat. Silakan muat ulang halaman atau pilih transfer bank di atas.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- METODE: Transfer Bank / VA --}}
                        @if ($punyaBank)
                            <div x-show="metode === 'bank'" @if($metodeAwal !== 'bank') x-cloak @endif 
                                 x-data="{ bankIndex: 0 }" 
                                 class="space-y-3 {{ count($metode) > 1 ? 'border-t border-border/80 pt-3.5' : 'lg:border-t lg:border-border/80 lg:pt-3.5' }}">
                                
                                {{-- Jika ada lebih dari 1 rekening/VA, tampilkan pemilih rekening --}}
                                @if ($daftarRekeningTujuan->count() > 1)
                                    <div>
                                        <label class="mb-1.5 block text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                            Pilih Bank atau Rekening Tujuan:
                                        </label>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($daftarRekeningTujuan as $idx => $item)
                                                <button type="button" @click="bankIndex = {{ $idx }}"
                                                        class="rounded-lg px-2.5 py-1.5 text-xs font-semibold transition border select-none flex items-center gap-1.5"
                                                        :class="bankIndex === {{ $idx }} ? 'border-primary bg-primary/10 text-primary ring-1 ring-primary shadow-xs' : 'border-border bg-card text-muted-foreground hover:bg-muted/80'">
                                                    <span>{{ $item->bank_name }}</span>
                                                    @if(isset($item->type) && $item->type === 'va')
                                                        <span class="rounded bg-purple-500/20 px-1 py-0.2 text-[9px] font-bold text-purple-700 dark:text-purple-300">VA</span>
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @foreach ($daftarRekeningTujuan as $idx => $item)
                                    <div x-show="bankIndex === {{ $idx }}" @if($idx !== 0) x-cloak @endif class="space-y-3">
                                        <div class="overflow-hidden rounded-xl border border-border bg-muted/30 p-3.5">
                                            <div class="flex items-center justify-between border-b border-border/80 pb-2.5">
                                                <div>
                                                    <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Transfer bank atau Rekening Tujuan:</span>
                                                    <p class="text-sm font-bold text-foreground">{{ $item->bank_name }}</p>
                                                </div>
                                                <span class="rounded px-2 py-0.5 text-[10px] font-semibold {{ (isset($item->type) && $item->type === 'va') ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400' : 'bg-primary/10 text-primary' }}">
                                                    {{ (isset($item->type) && $item->type === 'va') ? 'Virtual Account' : 'Akun Resmi' }}
                                                </span>
                                            </div>

                                            <div class="mt-2.5 space-y-2.5">
                                                {{-- Nomor Rekening / VA --}}
                                                <div>
                                                    <span class="text-[11px] text-muted-foreground">
                                                        {{ (isset($item->type) && $item->type === 'va') ? 'Nomor Virtual Account:' : 'Nomor Rekening:' }}
                                                    </span>
                                                    <div class="mt-1 flex items-center justify-between rounded-lg border border-border bg-background px-3 py-2">
                                                        <span class="font-mono text-sm font-bold tracking-wider text-foreground sm:text-base">
                                                            {{ $item->account_number }}
                                                        </span>
                                                        <button type="button" 
                                                                @click="salinTeks('{{ $item->account_number }}', 'copiedRekening')"
                                                                class="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1 text-xs font-semibold text-foreground shadow-xs transition hover:bg-muted active:scale-95">
                                                            <svg x-show="!copiedRekening" class="h-3.5 w-3.5 text-primary" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                                            <svg x-show="copiedRekening" x-cloak class="h-3.5 w-3.5 text-primary" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                            <span x-text="copiedRekening ? 'Tersalin!' : 'Salin'" class="text-[11px]">Salin</span>
                                                        </button>
                                                    </div>
                                                </div>

                                                {{-- Atas Nama --}}
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="text-muted-foreground">Atas Nama:</span>
                                                    <span class="font-semibold text-foreground">{{ $item->account_holder }}</span>
                                                </div>

                                                @if (filled($item->instructions ?? null))
                                                    <div class="rounded-lg bg-background/80 p-2 text-[11px] text-muted-foreground border border-border/60">
                                                        {{ $item->instructions }}
                                                    </div>
                                                @endif

                                                {{-- Jumlah Transfer --}}
                                                <div class="border-t border-border/80 pt-2 flex items-center justify-between">
                                                    <div>
                                                        <span class="text-[11px] text-muted-foreground">Jumlah Transfer:</span>
                                                        <p class="text-base font-bold tracking-tight text-primary sm:text-lg tabular-nums">
                                                            Rp {{ number_format($invoice->total, 0, ',', '.') }}
                                                        </p>
                                                    </div>
                                                    <button type="button" 
                                                            @click="salinTeks('{{ $invoice->total }}', 'copiedTotal')"
                                                            class="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2.5 py-1 text-xs font-semibold text-foreground shadow-xs transition hover:bg-muted active:scale-95">
                                                        <span x-text="copiedTotal ? 'Tersalin!' : 'Salin Jumlah'" class="text-[11px]">Salin Jumlah</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                                {{-- Panduan Transfer --}}
                                <div class="rounded-xl border border-border/80 bg-card p-3 text-xs">
                                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Panduan Transfer:</h3>
                                    <ol class="mt-1.5 space-y-1 text-[11px] leading-relaxed text-muted-foreground">
                                        <li class="flex items-start gap-1.5">
                                            <span class="font-bold text-primary">1.</span>
                                            <span>Transfer tepat hingga 3 digit terakhir untuk verifikasi otomatis.</span>
                                        </li>
                                        <li class="flex items-start gap-1.5">
                                            <span class="font-bold text-primary">2.</span>
                                            <span>Simpan tangkapan layar bukti transfer yang berhasil.</span>
                                        </li>
                                    </ol>
                                </div>
                            </div>
                        @endif

                        {{-- Peringatan Kode Unik (Hanya jika metode manual dipilih) --}}
                        @if ($invoice->unique_code > 0 && $metode !== [])
                            <div x-show="metode && metode !== 'xendit'" @if(!$metodeAwal || $metodeAwal === 'xendit') x-cloak @endif class="flex gap-2.5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-950 dark:text-amber-100 shadow-xs">
                                <svg class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                                <div class="leading-relaxed">
                                    <strong class="font-bold text-amber-950 dark:text-white">Bayar tepat sampai angka terakhirnya.</strong>
                                    Tiga digit terakhir (<span class="font-mono font-extrabold underline decoration-amber-500 underline-offset-2 text-amber-950 dark:text-amber-200">{{ str_pad($invoice->unique_code, 3, '0', STR_PAD_LEFT) }}</span>)
                                    adalah kode unik tagihan ini untuk mengenali kiriman dana Anda secara cepat.
                                </div>
                            </div>
                        @endif

                        {{-- ===================== BAGIAN: VERIFIKASI PEMBAYARAN (Alur Instan ala Payment Gateway) ===================== --}}
                        @if ($bolehBayar)
                            <div class="border-t border-border/80 pt-4 space-y-3.5">
                                {{-- Jika tagihan sudah pernah dikonfirmasi pelanggan dan sedang diperiksa --}}
                                @if ($invoice->isAwaitingVerification())
                                    <div class="rounded-xl border border-primary/30 bg-primary/10 p-3 space-y-2">
                                        <div class="flex items-center gap-2 text-xs font-semibold text-primary">
                                            <span class="relative flex h-2 w-2">
                                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary opacity-60"></span>
                                                <span class="relative inline-flex h-2 w-2 rounded-full bg-primary"></span>
                                            </span>
                                            <span>Pembayaran Anda sedang dalam proses verifikasi</span>
                                        </div>
                                        <p class="text-[11px] text-muted-foreground">
                                            Sistem sedang memeriksa mutasi pembayaran Anda secara otomatis.
                                        </p>
                                        <a href="{{ route('billing.verifying', $invoice->id) }}" 
                                            class="inline-flex items-center justify-center gap-1.5 w-full rounded-lg bg-primary px-3 py-2 text-xs font-bold text-primary-foreground shadow-xs transition hover:opacity-90">
                                            <span>Buka Layar Status Verifikasi</span>
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                                        </a>
                                    </div>
                                @else
                                    {{-- Form Konfirmasi "Saya Sudah Bayar" (Hanya untuk metode manual) --}}
                                    <div x-show="metode !== 'xendit'" class="space-y-3.5">
                                        <div class="flex items-center gap-2">
                                            <span class="grid h-5 w-5 place-items-center rounded-md bg-primary text-[11px] font-bold text-primary-foreground">
                                                ✓
                                            </span>
                                            <h2 class="text-sm font-bold text-foreground sm:text-base">Konfirmasi Pembayaran</h2>
                                        </div>
                                        <p class="text-xs leading-relaxed text-muted-foreground">
                                            Selesaikan transfer atau scan QRIS di atas sesuai nominal tagihan, lalu tekan tombol di bawah untuk verifikasi transaksi Anda.
                                        </p>

                                        <form method="POST" action="{{ route('billing.invoice.confirm', $invoice->id) }}" class="space-y-2.5"
                                              @submit.prevent="if (!billingLengkap) { simpanDataPelangganDanLanjutkan(() => { $el.submit(); }); } else { $el.submit(); }">
                                            @csrf
                                            <input type="hidden" name="metode_bayar" :value="metode">

                                            <button type="submit" 
                                                    class="group relative inline-flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-primary px-4 py-3 text-sm font-bold text-primary-foreground shadow-md transition-all hover:opacity-95 hover:shadow-lg active:scale-[0.98]">
                                                <svg class="h-4 w-4 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path d="M20 6 9 17l-5-5"/>
                                                </svg>
                                                <span>Saya Sudah Bayar</span>
                                            </button>

                                            <div class="flex items-center justify-center gap-1.5 text-[11px] text-muted-foreground text-center">
                                                <svg class="h-3.5 w-3.5 text-primary/80" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                                </svg>
                                                <span>Verifikasi pembayaran diproses otomatis dan aman</span>
                                            </div>
                                        </form>
                                    </div>
                                @endif

                                {{-- Batalkan Tagihan & Bantuan WhatsApp --}}
                                @if ($invoice->isPending() && $bolehBayar)
                                    <div class="border-t border-border/80 pt-3 flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted-foreground">
                                        @if (! $invoice->isAwaitingVerification())
                                            <form method="POST" action="{{ route('billing.invoice.cancel', $invoice->id) }}"
                                                  data-konfirmasi="Apakah Anda yakin ingin membatalkan tagihan ini?">
                                                @csrf
                                                <button type="submit" class="hover:text-destructive hover:underline">
                                                    Batalkan tagihan ini
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-[10px] text-muted-foreground">Tagihan dalam proses verifikasi</span>
                                        @endif

                                        <a href="https://wa.me/{{ \App\Support\KontakWhatsApp::nomor() }}?text={{ rawurlencode('Halo Admin VexaHost, saya ingin menanyakan perihal tagihan ' . $invoice->number . ' sebesar Rp ' . number_format($invoice->total, 0, ',', '.') . '. Mohon bantuannya.') }}"
                                           target="_blank" class="font-medium text-primary hover:underline inline-flex items-center gap-1">
                                            <span>Butuh Bantuan? Hubungi Admin</span>
                                        </a>
                                    </div>
                                @endif

                            </div>
                        @endif

                    </div>
                </section>

            </div>

        </div>

        {{-- ========================================================
             MOBILE FLOATING BOTTOM NAV & INVOICE SUMMARY (< lg)
             ======================================================== --}}
        <!-- Backdrop Overlay saat Bottom Summary Dibuka -->
        <div x-show="mobileSummaryOpen" x-cloak
             x-transition:enter="transition-opacity ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="mobileSummaryOpen = false"
             class="lg:hidden fixed inset-0 bg-slate-900/40 dark:bg-black/60 backdrop-blur-2xs z-40"></div>

        <!-- Floating Bottom Nav Bar & Expandable Drawer Container -->
        <div class="lg:hidden fixed bottom-3 sm:bottom-4 inset-x-3 sm:inset-x-4 z-50 max-w-lg mx-auto pointer-events-none">
            <div class="pointer-events-auto bg-card/95 backdrop-blur-md border border-border/90 rounded-2xl shadow-xl shadow-slate-900/15 dark:shadow-black/40 overflow-hidden transition-all duration-300">
                
                <!-- Expanded Content Drawer (Buka / Tutup) -->
                <div x-show="mobileSummaryOpen" x-cloak
                     x-transition:enter="transition-all ease-out duration-250"
                     x-transition:enter-start="opacity-0 -translate-y-2 max-h-0"
                     x-transition:enter-end="opacity-100 translate-y-0 max-h-[75vh]"
                     x-transition:leave="transition-all ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 max-h-[75vh]"
                     x-transition:leave-end="opacity-0 -translate-y-2 max-h-0"
                     class="border-b border-border/80 overflow-hidden flex flex-col">
                    
                    <!-- Drawer Top Bar & Header -->
                    <div class="px-4 pt-2.5 pb-2.5 bg-muted/40 border-b border-border/70 flex items-center justify-between shrink-0">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-primary"></span>
                            <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">Ringkasan Tagihan</h3>
                            <span class="font-mono text-[10px] font-semibold text-muted-foreground">{{ $invoice->number }}</span>
                        </div>
                        <button type="button" @click="mobileSummaryOpen = false"
                                class="w-7 h-7 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted/70 transition-colors flex items-center justify-center cursor-pointer"
                                aria-label="Tutup Rincian">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <!-- Drawer Scrollable Content -->
                    <div class="p-4 space-y-3.5 max-h-[55vh] overflow-y-auto text-xs bg-card">
                        <!-- Paket Layanan -->
                        <div class="bg-muted/30 p-3 rounded-xl border border-border/70 space-y-2">
                            <div class="flex justify-between items-start gap-2">
                                <div>
                                    <span class="text-[10px] text-muted-foreground uppercase tracking-wider font-semibold block">Paket Langganan</span>
                                    <span class="font-bold text-foreground text-sm block">Paket {{ $plan->name() }}</span>
                                    <span class="text-[11px] text-muted-foreground italic">{{ $plan->tagline() }}</span>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="font-bold text-foreground text-xs block tabular-nums">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</span>
                                    <span class="text-[10px] text-muted-foreground">{{ $invoice->period === 'yearly' ? 'Tahunan' : 'Bulanan' }}</span>
                                </div>
                            </div>

                            <div class="border-t border-border/60 pt-2 flex items-center justify-between text-[11px] text-muted-foreground">
                                <span>Workspace:</span>
                                <span class="font-semibold text-foreground">{{ ($currentWorkspace ?? $invoice->workspace)->name ?? '-' }}</span>
                            </div>
                        </div>

                        <!-- Rincian Biaya -->
                        <div class="space-y-2 pt-1 text-xs">
                            <div class="flex justify-between text-muted-foreground">
                                <span>Subtotal Paket</span>
                                <span class="font-medium text-foreground tabular-nums">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</span>
                            </div>

                            @if ($invoice->tax_amount > 0)
                                <div class="flex justify-between text-muted-foreground">
                                    <span>PPN {{ rtrim(rtrim(number_format(config('billing.tax_percent'), 2, ',', '.'), '0'), ',') }}%</span>
                                    <span class="font-medium text-foreground tabular-nums">Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}</span>
                                </div>
                            @endif

                            @if ($invoice->intro_discount_amount > 0)
                                <div class="flex justify-between text-muted-foreground">
                                    <span>Promo pembelian pertama</span>
                                    <span class="font-medium text-primary tabular-nums">−Rp {{ number_format($invoice->intro_discount_amount, 0, ',', '.') }}</span>
                                </div>
                            @endif

                            @if ($invoice->referralDiscount() > 0)
                                <div class="flex justify-between text-muted-foreground">
                                    <span>Potongan referal{{ $invoice->referralCode ? ' ('.$invoice->referralCode->code.')' : '' }}</span>
                                    <span class="font-medium text-primary tabular-nums">−Rp {{ number_format($invoice->referralDiscount(), 0, ',', '.') }}</span>
                                </div>
                            @endif

                            @if ($invoice->unique_code > 0)
                                <div class="flex justify-between text-muted-foreground">
                                    <span>Kode Unik Verifikasi</span>
                                    <span class="font-mono font-bold text-amber-600 dark:text-amber-400 tabular-nums">+Rp {{ number_format($invoice->unique_code, 0, ',', '.') }}</span>
                                </div>
                            @endif

                            <div class="flex items-baseline justify-between border-t border-border/80 pt-2.5">
                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Total Pembayaran</span>
                                    @if ($invoice->unique_code > 0)
                                        <p class="text-[10px] text-muted-foreground">Termasuk kode unik</p>
                                    @endif
                                </div>
                                <p class="text-lg font-bold tracking-tight text-primary tabular-nums sm:text-xl">
                                    Rp {{ number_format($invoice->total, 0, ',', '.') }}
                                </p>
                            </div>
                        </div>

                        <!-- Tombol Tutup Rincian -->
                        <button type="button" @click="mobileSummaryOpen = false"
                                class="w-full py-2.5 rounded-xl bg-muted/60 hover:bg-muted active:bg-muted/80 text-foreground text-xs font-semibold transition-colors text-center cursor-pointer">
                            Tutup Rincian
                        </button>
                    </div>
                </div>

                <!-- Persistent Collapsed Floating Bar (Tampil di Bawah, Floating & Klik untuk Buka) -->
                <button type="button"
                        @click="mobileSummaryOpen = !mobileSummaryOpen"
                        class="w-full px-4 py-3 flex items-center justify-between bg-card hover:bg-muted/30 active:bg-muted/50 transition-colors text-left select-none cursor-pointer">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="w-8 h-8 rounded-xl bg-foreground text-background flex items-center justify-center shrink-0 shadow-xs">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-bold text-foreground truncate">Ringkasan Tagihan</span>
                                <span class="text-[10px] font-semibold px-1.5 py-0.2 rounded bg-muted text-muted-foreground border border-border/80 shrink-0">
                                    {{ $plan->name() }}
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground block truncate">
                                {{ $invoice->period === 'yearly' ? 'Langganan 1 Tahun' : 'Langganan 1 Bulan' }} &middot; {{ ($currentWorkspace ?? $invoice->workspace)->name ?? '-' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0 pl-2">
                        <div class="text-right">
                            <span class="text-[10px] text-muted-foreground block leading-tight">Total</span>
                            <span class="font-mono font-bold text-primary text-sm tabular-nums">Rp {{ number_format($invoice->total, 0, ',', '.') }}</span>
                        </div>
                        <div class="w-7 h-7 rounded-lg bg-muted flex items-center justify-center text-muted-foreground transition-transform duration-200"
                             :class="mobileSummaryOpen ? 'rotate-180 bg-muted/80 text-foreground' : ''">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                            </svg>
                        </div>
                    </div>
                </button>

            </div>
        </div>

    </div>
    @endif
@endsection

@if ($qrisPayload)
    @push('scripts')
        <script src="{{ asset('js/qrcode.min.js') }}"></script>
        <script>
            (function() {
                var maxAttempts = 50; // 5 detik

                function renderQris(attempt) {
                    attempt = typeof attempt === 'number' ? attempt : 0;
                    var canvases = document.querySelectorAll('canvas[data-qris]');
                    if (!canvases.length) return;
                    var fallbackMsg = document.getElementById('qris-fallback-msg');

                    if (typeof window.QRCode === 'undefined' || !window.QRCode.toCanvas) {
                        if (attempt >= maxAttempts) {
                            console.error('Pustaka QRCode tidak dapat dimuat.');
                            if (fallbackMsg) fallbackMsg.classList.remove('hidden');
                            return;
                        }
                        setTimeout(function() { renderQris(attempt + 1); }, 100);
                        return;
                    }

                    canvases.forEach(function(el) {
                        var payload = el.getAttribute('data-qris');
                        if (!payload) return;
                        window.QRCode.toCanvas(el, payload, {
                            width: 260,
                            margin: 1,
                            color: {
                                dark: '#000000',
                                light: '#ffffff'
                            }
                        }, function(error) {
                            if (error) {
                                console.error('Gagal merender QRIS:', error);
                                if (fallbackMsg) fallbackMsg.classList.remove('hidden');
                            } else {
                                el.style.width = '160px';
                                el.style.height = '160px';
                                if (fallbackMsg) fallbackMsg.classList.add('hidden');
                            }
                        });
                    });
                }

                window.renderQris = renderQris;

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', renderQris);
                } else {
                    renderQris();
                }
            })();
        </script>
    @endpush
@endif
