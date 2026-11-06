<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DailyReportService;
use App\Services\ExcelExportService;
use App\Services\TransactionReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TellerReportExportController extends Controller
{
    public function daily(
        Request $request,
        TransactionReportService $reportService,
        ExcelExportService $excelExportService
    ): Response {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $date = $validated['date'] ?? now('Asia/Jakarta')->toDateString();
        $report = $reportService->daily($date, $request->user()->id);
        $summary = $report['summary'];

        $summaryRows = [
            ['Laporan', 'Laporan Harian Teller'],
            ['Tanggal operasional', $date],
            ['Dibuat', now('Asia/Jakarta')->format('Y-m-d H:i:s')],
            ['Nama Teller', $request->user()->full_name ?? $request->user()->name],
            ['Total transaksi', $summary['total_transactions'] + $summary['pending_transactions'] + $summary['failed_transactions']],
            ['Transaksi berhasil', $summary['total_transactions']],
            ['Total setoran berhasil (Rp)', $summary['total_deposit']],
            ['Total penarikan berhasil (Rp)', $summary['total_withdrawal']],
            ['Transaksi pending', $summary['pending_transactions']],
            ['Transaksi gagal/ditolak', $summary['failed_transactions']],
        ];
        $transactionRows = [[
            'No.',
            'Tanggal dan waktu (Asia/Jakarta)',
            'Referensi transaksi',
            'Nomor rekening',
            'Nama nasabah',
            'Jenis transaksi',
            'Nominal (Rp)',
            'Status',
            'Teller',
        ]];

        foreach ($report['transactions'] as $index => $transaction) {
            $transactionRows[] = [
                $index + 1,
                $transaction->transaction_date
                    ->copy()
                    ->setTimezone('Asia/Jakarta')
                    ->format('Y-m-d H:i:s'),
                (string) $transaction->id,
                $transaction->customer?->account_number ?? '',
                $transaction->customer?->student_name ?? '',
                $transaction->transaction_type,
                (int) $transaction->amount,
                $transaction->status,
                $transaction->user?->full_name ?? $transaction->user?->name ?? '',
            ];
        }

        $contents = $excelExportService->create([
            'Ringkasan' => $summaryRows,
            'Transaksi' => $transactionRows,
        ]);

        return $this->xlsxResponse(
            $contents,
            'laporan-harian-'.$date.'.xlsx'
        );
    }

    public function cashReconciliation(
        Request $request,
        DailyReportService $dailyReportService,
        ExcelExportService $excelExportService
    ): Response {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $date = $validated['date'] ?? now('Asia/Jakarta')->toDateString();
        $reconciliation = $dailyReportService->getReconciliation(
            $request->user(),
            Carbon::parse($date, 'Asia/Jakarta')
        );
        $report = $reconciliation['export'];
        $reconciliationRows = [
            [$report['system_name']],
            [$report['title']],
            ['Tanggal operasional', $report['date']],
            ['Nama Teller', $report['teller_name']],
            ['Dibuat', $report['generated_at']],
            ['Status laporan', $report['report_status']],
            ['BAGIAN 1 — KAS AWAL'],
            ['Saldo awal sistem', $this->excelRupiah($report['opening_balance'])],
            ['Sumber saldo awal', $report['opening_balance_source']],
            ['Dicatat oleh', $report['opening_balance_recorded_by'] ?? 'Belum tersedia'],
            ['Waktu pencatatan', $report['opening_balance_recorded_at'] ?? 'Belum tersedia'],
            ['Uang fisik saat pembukaan', $this->excelRupiah($report['opening_physical_balance'])],
            ['Selisih uang awal', $this->formatSignedRupiah($report['opening_difference'])],
            ['BAGIAN 2 — TRANSAKSI HARI INI'],
            ['Setoran berhasil', $this->excelRupiah($report['total_deposit'])],
            ['Penarikan berhasil', $this->excelRupiah($report['total_withdrawal'])],
            ['Jumlah transaksi berhasil', $report['total_transactions']],
            ['Jumlah transaksi pending', $report['pending_transactions']],
            ['Jumlah transaksi gagal', $report['failed_transactions']],
            ['BAGIAN 3 — PERHITUNGAN SALDO AKHIR SISTEM'],
            ['Rumus', 'Saldo awal sistem + setoran berhasil - penarikan berhasil'],
            ['Saldo awal sistem', $this->excelRupiah($report['opening_balance'])],
            ['Setoran berhasil', '+'.$this->formatRupiah($report['total_deposit'])],
            ['Penarikan berhasil', '-'.$this->formatRupiah($report['total_withdrawal'])],
            ['Saldo seharusnya menurut sistem', $this->excelRupiah($report['system_balance'])],
            ['BAGIAN 4 — HITUNG UANG SAAT TUTUP KAS'],
            ['Saldo seharusnya menurut sistem', $this->excelRupiah($report['system_balance'])],
            ['Uang fisik saat tutup kas', $this->excelRupiah($report['physical_balance'])],
            ['Selisih uang akhir', $this->formatSignedRupiah($report['closing_difference'])],
            ['Status kecocokan kas', $report['cash_match_status']],
            ['BAGIAN 5 — STATUS PENUTUPAN'],
            ['Status rekonsiliasi', $report['reconciliation_status']],
            ['Status laporan', $report['report_status']],
            ['Status persetujuan Supervisor', $report['approval_status']],
            ['Supervisor penyetuju', $report['approver_name'] ?? 'Belum tersedia'],
            ['Waktu persetujuan', $report['approved_at'] ?? 'Belum tersedia'],
            ['Catatan penolakan', $report['rejection_reason'] ?? 'Tidak ada'],
        ];

        if ($report['previous_report'] !== null && $report['previous_report']['requires_review']) {
            $reconciliationRows[] = ['Pemeriksaan penutupan sebelumnya'];
            foreach ($report['previous_report']['review_reasons'] as $reason) {
                $reconciliationRows[] = [$reason];
            }
        }

        $transactionRows = [[
            'No.',
            'Tanggal dan waktu (Asia/Jakarta)',
            'Referensi transaksi',
            'Jenis transaksi',
            'Nominal (Rp)',
            'Status',
        ]];

        foreach ($report['transactions'] as $index => $transaction) {
            $transactionRows[] = [
                $index + 1,
                ! empty($transaction['date'])
                    ? Carbon::parse($transaction['date'])
                        ->setTimezone('Asia/Jakarta')
                        ->format('Y-m-d H:i:s')
                    : '',
                (string) ($transaction['reference'] ?? ''),
                $transaction['type'] ?? '',
                $this->excelRupiah($transaction['amount'] ?? null),
                $transaction['status'] ?? '',
            ];
        }

        $contents = $excelExportService->create([
            'Rekonsiliasi Kas' => $reconciliationRows,
            'Transaksi' => $transactionRows,
        ]);

        return $this->xlsxResponse(
            $contents,
            'rekonsiliasi-kas-'.$date.'.xlsx'
        );
    }

    private function formatRupiah(?int $amount): string
    {
        return $amount === null
            ? 'Belum dihitung'
            : 'Rp '.number_format($amount, 0, ',', '.');
    }

    private function excelRupiah(?int $amount): array|string
    {
        return $amount === null
            ? 'Belum dihitung'
            : ['type' => 'currency', 'value' => $amount];
    }

    private function formatSignedRupiah(?int $amount): string
    {
        if ($amount === null) {
            return 'Belum dihitung';
        }

        $prefix = $amount > 0 ? '+' : ($amount < 0 ? '-' : '');

        return $prefix.$this->formatRupiah(abs($amount));
    }

    private function xlsxResponse(string $contents, string $filename): Response
    {
        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
