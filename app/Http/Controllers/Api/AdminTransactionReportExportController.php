<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Services\ExcelExportService;
use App\Services\TransactionReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTransactionReportExportController extends Controller
{
    public function __invoke(
        Request $request,
        TransactionReportService $reportService,
        ExcelExportService $excelExportService
    ): Response {
        $validated = $request->validate([
            'period' => ['required', 'in:daily,monthly'],
            'date' => ['required_if:period,daily', 'date_format:Y-m-d'],
            'month' => ['required_if:period,monthly', 'date_format:Y-m'],
        ]);

        $report = $validated['period'] === 'daily'
            ? $reportService->daily($validated['date'])
            : $reportService->monthly($validated['month']);

        $summary = $report['summary'];
        $summaryRows = [
            ['Laporan', $validated['period'] === 'daily' ? 'Laporan Transaksi Harian' : 'Laporan Transaksi Bulanan'],
            ['Periode', $report['from'].' - '.$report['to']],
            ['Dibuat', now('Asia/Jakarta')->format('Y-m-d H:i:s')],
            ['Total transaksi', $summary['total_transactions'] + $summary['pending_transactions'] + $summary['failed_transactions']],
            ['Jumlah transaksi berhasil', $summary['total_transactions']],
            ['Total setoran berhasil (Rp)', $summary['total_deposit']],
            ['Total penarikan berhasil (Rp)', $summary['total_withdrawal']],
            ['Transaksi pending', $summary['pending_transactions']],
            ['Nominal pending (Rp)', $summary['pending_total']],
            ['Transaksi gagal/ditolak', $summary['failed_transactions']],
            ['Nominal gagal/ditolak (Rp)', $summary['failed_total']],
        ];

        $transactionRows = [[
            'No.',
            'Tanggal dan waktu transaksi (Asia/Jakarta)',
            'Nomor referensi',
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
                $transaction->id,
                $transaction->customer?->account_number ?? '',
                $transaction->customer?->student_name ?? '',
                $transaction->transaction_type,
                (int) $transaction->amount,
                $transaction->status,
                $transaction->user?->full_name ?? $transaction->user?->name ?? '',
            ];
        }

        $sheets = [
            'Ringkasan' => $summaryRows,
        ];

        if ($validated['period'] === 'monthly') {
            $dailyRows = [[
                'Tanggal operasional',
                'Transaksi berhasil',
                'Setoran berhasil (Rp)',
                'Penarikan berhasil (Rp)',
                'Pending',
                'Gagal/ditolak',
            ]];
            foreach ($report['daily_breakdown'] as $day) {
                $dailyRows[] = [
                    $day['date'],
                    $day['total_transactions'],
                    $day['total_deposit'],
                    $day['total_withdrawal'],
                    $day['pending_transactions'],
                    $day['failed_transactions'],
                ];
            }
            $sheets['Ringkasan Harian'] = $dailyRows;
        }

        $sheets['Transaksi'] = $transactionRows;

        $cashRows = [[
            'Tanggal laporan',
            'Teller',
            'Status laporan',
            'Saldo awal kas (Rp)',
            'Total setoran berhasil (Rp)',
            'Total penarikan berhasil (Rp)',
            'Saldo akhir sistem (Rp)',
            'Kas fisik (Rp)',
            'Selisih (Rp)',
        ]];
        $cashReports = DailyReport::query()
            ->with('teller:id,username,full_name')
            ->whereDate('report_date', '>=', $report['from'])
            ->whereDate('report_date', '<=', $report['to'])
            ->orderBy('report_date')
            ->orderBy('teller_id')
            ->get();

        foreach ($cashReports as $cashReport) {
            $cashRows[] = [
                $cashReport->report_date->toDateString(),
                $cashReport->teller?->full_name ?? $cashReport->teller?->username ?? '',
                $cashReport->status,
                (int) $cashReport->opening_balance,
                (int) $cashReport->total_deposit,
                (int) $cashReport->total_withdrawal,
                (int) $cashReport->system_balance,
                $cashReport->physical_balance === null ? null : (int) $cashReport->physical_balance,
                $cashReport->closing_difference === null ? null : (int) $cashReport->closing_difference,
            ];
        }
        $sheets['Tutup Kas'] = $cashRows;

        $filenamePeriod = $validated['period'] === 'daily'
            ? $validated['date']
            : $validated['month'];
        $contents = $excelExportService->create($sheets);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="laporan-transaksi-'.$filenamePeriod.'.xlsx"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
