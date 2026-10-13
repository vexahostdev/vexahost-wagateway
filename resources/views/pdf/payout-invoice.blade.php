<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $payout->payout_number }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            color: #1e293b;
            line-height: 1.5;
            padding: 30px;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #0f172a;
        }
        .company-sub {
            font-size: 11px;
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .company-meta {
            font-size: 10px;
            color: #64748b;
            margin-top: 5px;
            line-height: 1.4;
        }
        .invoice-title {
            font-size: 11px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .invoice-number {
            font-family: monospace;
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 3px;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: bold;
            margin-top: 6px;
        }
        .badge-success {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .badge-warning {
            background-color: #fef3c7;
            color: #b45309;
            border: 1px solid #fcd34d;
        }
        .badge-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }
        .info-table {
            width: 100%;
            margin-bottom: 25px;
        }
        .info-box {
            width: 48%;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            vertical-align: top;
        }
        .info-box-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }
        .info-row {
            margin-bottom: 4px;
            font-size: 11px;
        }
        .info-label {
            color: #64748b;
            width: 90px;
            display: inline-block;
        }
        .info-val {
            font-weight: bold;
            color: #0f172a;
        }
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .breakdown-table th {
            background-color: #f1f5f9;
            border-top: 1px solid #cbd5e1;
            border-bottom: 2px solid #cbd5e1;
            padding: 8px 10px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            text-align: left;
        }
        .breakdown-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .total-box {
            width: 100%;
            margin-top: 10px;
            margin-bottom: 25px;
        }
        .total-card {
            width: 320px;
            margin-left: auto;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background-color: #f8fafc;
            padding: 12px 16px;
        }
        .total-row {
            width: 100%;
            margin-bottom: 6px;
            font-size: 11px;
        }
        .total-row-highlight {
            border-top: 2px solid #cbd5e1;
            padding-top: 8px;
            margin-top: 8px;
            font-size: 14px;
            font-weight: bold;
            color: #2563eb;
        }
        .terbilang-text {
            font-size: 10px;
            font-style: italic;
            color: #64748b;
            text-align: right;
            margin-top: 5px;
        }
        .footer-table {
            width: 100%;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
            margin-top: 20px;
        }
        .seal-card {
            border: 1px solid #bfdbfe;
            background-color: #eff6ff;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
            width: 260px;
            margin-left: auto;
        }
        .seal-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1d4ed8;
            letter-spacing: 0.5px;
        }
        .seal-company {
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }
        .seal-hash {
            font-family: monospace;
            font-size: 8px;
            color: #64748b;
            margin-top: 3px;
        }
    </style>
</head>
<body>

    {{-- HEADER KORPORAT --}}
    <table class="header-table">
        <tr>
            <td style="vertical-align: top;">
                <div class="company-name">VexaHost WA Gateway</div>
                <div class="company-sub">PT DESTINARA CHAKRAWALA ARTHA</div>
                <div class="company-meta">
                    Layanan WhatsApp API Gateway &amp; Enterprise Messaging Platform<br>
                    Email: {{ \App\Support\MailIdentity::contactEmail() }} &bull; Web: https://wa.vexahostcloud.my.id
                </div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                <div class="invoice-title">INVOICE PENCAIRAN KOMISI</div>
                <div class="invoice-number">{{ $payout->payout_number }}</div>
                <div style="font-size: 10px; color: #64748b; margin-top: 3px;">
                    Tanggal: {{ $payout->created_at->format('d/m/Y, H:i') }} WIB
                </div>
                <div>
                    @if ($payout->isPaid())
                        <span class="badge badge-success">DITRANSFER / LUNAS</span>
                    @elseif ($payout->isPending())
                        <span class="badge badge-warning">MENUNGGU TRANSFER ADMIN</span>
                    @else
                        <span class="badge badge-danger">DITOLAK</span>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- DUA KOTAK IDENTITAS & REKENING --}}
    <table class="info-table">
        <tr>
            <td class="info-box">
                <div class="info-box-title">Penerima Komisi (Mitra)</div>
                <div class="info-row"><span class="info-label">Nama:</span> <span class="info-val">{{ $payout->user?->name }}</span></div>
                <div class="info-row"><span class="info-label">Email:</span> <span class="info-val">{{ $payout->user?->email }}</span></div>
                <div class="info-row"><span class="info-label">WhatsApp:</span> <span class="info-val">{{ $payout->referralCode?->whatsapp_number ?? ($payout->user?->phone ?? '—') }}</span></div>
                <div class="info-row"><span class="info-label">Kode Referal:</span> <span class="info-val" style="font-family: monospace;">{{ $payout->referralCode?->code ?? '—' }}</span></div>
            </td>
            <td style="width: 4%;"></td>
            <td class="info-box">
                <div class="info-box-title">Rekening Tujuan Pencairan</div>
                <div class="info-row"><span class="info-label">Bank / Wallet:</span> <span class="info-val">{{ $payout->bank_name }}</span></div>
                <div class="info-row"><span class="info-label">No. Rekening:</span> <span class="info-val" style="font-family: monospace;">{{ $payout->bank_account_number }}</span></div>
                <div class="info-row"><span class="info-label">Atas Nama:</span> <span class="info-val">{{ $payout->bank_account_name }}</span></div>
                <div class="info-row">
                    <span class="info-label">Waktu Bayar:</span>
                    <span class="info-val">{{ $payout->paid_at ? $payout->paid_at->format('d/m/Y H:i') : '1x24 Jam Kerja' }}</span>
                </div>
            </td>
        </tr>
    </table>

    {{-- TABEL RINCIAN KEUANGAN --}}
    <table class="breakdown-table">
        <thead>
            <tr>
                <th style="width: 55%;">Keterangan Transaksi</th>
                <th style="width: 20%; text-align: center;">Tarif / Dasar</th>
                <th style="width: 25%; text-align: right;">Jumlah (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong>Pencairan Komisi Penjualan Kemitraan (Reseller)</strong><br>
                    <span style="font-size: 10px; color: #64748b;">
                        Akumulasi bagi hasil transaksi langganan klien downline kode promo {{ $payout->referralCode?->code }} yang berstatus approved.
                    </span>
                </td>
                <td style="text-align: center; color: #64748b;">Akumulasi Komisi</td>
                <td style="text-align: right; font-weight: bold;">Rp {{ number_format($payout->amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td>
                    <strong>Biaya Administrasi &amp; Penanganan Pemrosesan</strong><br>
                    <span style="font-size: 10px; color: #64748b;">
                        Biaya pemrosesan transfer perbankan sesuai Syarat &amp; Ketentuan resmi kemitraan VexaHost ({{ $payout->fee_percent }}%).
                    </span>
                </td>
                <td style="text-align: center; color: #64748b;">{{ $payout->fee_percent }}% dari nominal</td>
                <td style="text-align: right; font-weight: bold; color: #dc2626;">- Rp {{ number_format($payout->fee_amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- KOTAK TOTAL & TERBILANG --}}
    <table class="total-box">
        <tr>
            <td style="vertical-align: top;">
                @if ($payout->notes)
                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; max-width: 320px;">
                        <span style="font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase;">Catatan Mitra:</span>
                        <p style="font-size: 10px; color: #334155; margin-top: 2px;">{{ $payout->notes }}</p>
                    </div>
                @endif
                @if ($payout->admin_notes)
                    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 12px; max-width: 320px; margin-top: 8px;">
                        <span style="font-size: 10px; font-weight: bold; color: #166534; text-transform: uppercase;">Catatan Finance Admin:</span>
                        <p style="font-size: 10px; color: #14532d; margin-top: 2px;">{{ $payout->admin_notes }}</p>
                    </div>
                @endif
            </td>
            <td style="vertical-align: top;">
                <div class="total-card">
                    <table style="width: 100%;">
                        <tr class="total-row">
                            <td style="color: #64748b;">Total Komisi Bruto</td>
                            <td style="text-align: right; font-weight: bold;">Rp {{ number_format($payout->amount, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="total-row">
                            <td style="color: #64748b;">Biaya Administrasi ({{ $payout->fee_percent }}%)</td>
                            <td style="text-align: right; font-weight: bold; color: #dc2626;">- Rp {{ number_format($payout->fee_amount, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="total-row-highlight">
                            <td>Total Ditransfer (Net)</td>
                            <td style="text-align: right; font-family: monospace;">Rp {{ number_format($payout->net_amount, 0, ',', '.') }}</td>
                        </tr>
                    </table>
                    <div class="terbilang-text">
                        Terbilang: {{ ucwords(\Illuminate\Support\Str::headline(\NumberFormatter::create('id_ID', \NumberFormatter::SPELLOUT)?->format($payout->net_amount) ?? 'rupiah')) }} Rupiah
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- FOOTER & SEGEL KEAMANAN DIGITAL --}}
    <table class="footer-table">
        <tr>
            <td style="vertical-align: bottom; font-size: 9px; color: #94a3b8; line-height: 1.4;">
                Dokumen elektronik ini diterbitkan secara otomatis dan sah oleh sistem VexaHost WA Gateway.<br>
                Diakui secara legal berdasarkan UU ITE Republik Indonesia Pasal 5 ayat 1.<br>
                Dicetak pada {{ now()->format('d/m/Y, H:i') }} WIB.
            </td>
            <td style="text-align: right; vertical-align: bottom;">
                <div class="seal-card">
                    <div class="seal-title">&#10003; DOKUMEN ELEKTRONIK SAH</div>
                    <div class="seal-company">PT DESTINARA CHAKRAWALA ARTHA</div>
                    <div class="seal-hash">SHA256: {{ $shaHash }}</div>
                </div>
            </td>
        </tr>
    </table>

</body>
</html>
