<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Mutasi Rekening - {{ $customer->account_number }}</title>
    <style>
        @page {
            margin: 15mm 12mm 15mm 12mm;
            size: a4 portrait;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }

        .header-table {
            width: 100%;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }

        .header-logo-text {
            font-size: 18px;
            font-weight: bold;
            color: #1e3a8a;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .header-subtext {
            font-size: 9.5px;
            color: #4b5563;
            margin-top: 2px;
        }

        .doc-title {
            text-align: right;
            vertical-align: middle;
        }

        .doc-title-main {
            font-size: 14px;
            font-weight: bold;
            color: #111827;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .doc-title-sub {
            font-size: 9.5px;
            color: #6b7280;
            margin-top: 3px;
        }

        /* Customer Info & Period Box */
        .info-table {
            width: 100%;
            margin-bottom: 14px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px 10px;
        }

        .info-col {
            vertical-align: top;
            width: 50%;
        }

        .info-row {
            margin-bottom: 4px;
        }

        .info-label {
            display: inline-block;
            width: 110px;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .info-value {
            font-weight: 600;
            color: #0f172a;
            font-size: 11px;
        }

        /* Summary Box */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        .summary-cell {
            border: 1px solid #cbd5e1;
            padding: 7px 10px;
            text-align: center;
            background-color: #f1f5f9;
        }

        .summary-label {
            font-size: 9px;
            text-transform: uppercase;
            color: #475569;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .summary-amount {
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
        }

        .summary-highlight {
            background-color: #e0f2fe;
            border-color: #7dd3fc;
        }

        .summary-highlight .summary-amount {
            color: #0369a1;
        }

        /* Transaction Table */
        .tx-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 10px;
        }

        .tx-table thead {
            display: table-header-group;
        }

        .tx-table th {
            background-color: #1e3a8a;
            color: #ffffff;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9px;
            padding: 7px 6px;
            border: 1px solid #1e3a8a;
            letter-spacing: 0.3px;
        }

        .tx-table td {
            padding: 6px 6px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .tx-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-debit {
            color: #b91c1c;
            font-weight: 600;
        }

        .text-credit {
            color: #15803d;
            font-weight: 600;
        }

        .text-balance {
            font-weight: bold;
            color: #0f172a;
        }

        .empty-row td {
            text-align: center;
            padding: 24px;
            color: #64748b;
            font-style: italic;
            background-color: #ffffff !important;
        }

        /* Footer */
        .footer-table {
            width: 100%;
            margin-top: 18px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 8px;
            font-size: 9px;
            color: #64748b;
        }

        .footer-note {
            width: 70%;
            vertical-align: top;
        }

        .footer-count {
            width: 30%;
            text-align: right;
            vertical-align: top;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <!-- Header Perusahaan / Bank Mini -->
    <table class="header-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align: middle;">
                <div class="header-logo-text">Bank Mini SMKN 1 Bantul</div>
                <div class="header-subtext">Layanan Perbankan Sekolah & Laboratorium Mini Bank</div>
                <div class="header-subtext">Jl. Parangtritis KM. 11, Sabdodadi, Bantul, D.I. Yogyakarta 55715</div>
            </td>
            <td class="doc-title">
                <div class="doc-title-main">Laporan Mutasi Rekening</div>
                <div class="doc-title-sub">Periode: {{ $period }}</div>
            </td>
        </tr>
    </table>

    <!-- Informasi Nasabah dan Dokumen -->
    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="info-col">
                <div class="info-row">
                    <span class="info-label">Nama Nasabah</span>
                    <span class="info-value">: {{ $customer->student_name }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">No. Rekening</span>
                    <span class="info-value">: {{ $customer->account_number }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">NIS</span>
                    <span class="info-value">: {{ $customer->student_number ?? '-' }}</span>
                </div>
            </td>
            <td class="info-col">
                <div class="info-row">
                    <span class="info-label">Kelas</span>
                    <span class="info-value">: {{ $customer->schoolClass?->name ?? '-' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Tanggal Cetak</span>
                    <span class="info-value">: {{ $printedAt }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status Rekening</span>
                    <span class="info-value">: {{ strtoupper($customer->status) }}</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Ringkasan Saldo Periode -->
    <table class="summary-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="summary-cell" style="width: 25%;">
                <div class="summary-label">Saldo Awal</div>
                <div class="summary-amount">Rp {{ number_format($openingBalance, 0, ',', '.') }}</div>
            </td>
            <td class="summary-cell" style="width: 25%;">
                <div class="summary-label">Total Setoran (+)</div>
                <div class="summary-amount" style="color: #15803d;">Rp {{ number_format($totalDeposit, 0, ',', '.') }}</div>
            </td>
            <td class="summary-cell" style="width: 25%;">
                <div class="summary-label">Total Penarikan (-)</div>
                <div class="summary-amount" style="color: #b91c1c;">Rp {{ number_format($totalWithdrawal, 0, ',', '.') }}</div>
            </td>
            <td class="summary-cell summary-highlight" style="width: 25%;">
                <div class="summary-label">Saldo Akhir</div>
                <div class="summary-amount">Rp {{ number_format($closingBalance, 0, ',', '.') }}</div>
            </td>
        </tr>
    </table>

    <!-- Tabel Rincian Transaksi -->
    <table class="tx-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th style="width: 4%;" class="text-center">No</th>
                <th style="width: 17%;" class="text-center">Tanggal & Waktu</th>
                <th style="width: 11%;" class="text-center">ID Tx</th>
                <th style="width: 24%;">Keterangan</th>
                <th style="width: 14%;" class="text-right">Debit (Rp)</th>
                <th style="width: 14%;" class="text-right">Kredit (Rp)</th>
                <th style="width: 16%;" class="text-right">Saldo (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $index => $item)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center">{{ $item['date'] }}</td>
                    <td class="text-center">TX#{{ str_pad($item['id'], 5, '0', STR_PAD_LEFT) }}</td>
                    <td>{{ $item['description'] }}</td>
                    <td class="text-right {{ $item['debit'] > 0 ? 'text-debit' : '' }}">
                        {{ $item['debit'] > 0 ? number_format($item['debit'], 0, ',', '.') : '-' }}
                    </td>
                    <td class="text-right {{ $item['credit'] > 0 ? 'text-credit' : '' }}">
                        {{ $item['credit'] > 0 ? number_format($item['credit'], 0, ',', '.') : '-' }}
                    </td>
                    <td class="text-right text-balance">
                        {{ number_format($item['balance'], 0, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="7">Tidak terdapat transaksi pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Footer Catatan Sistem -->
    <table class="footer-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="footer-note">
                * Dokumen ini diterbitkan secara otomatis dan elektronik oleh Sistem E-Teller Bank Mini SMKN 1 Bantul.<br>
                * Informasi mutasi rekening sah dan mengikat tanpa memerlukan tanda tangan basah pejabat bank.
            </td>
            <td class="footer-count">
                Total Transaksi: {{ count($items) }}<br>
                Halaman 1
            </td>
        </tr>
    </table>

</body>
</html>

