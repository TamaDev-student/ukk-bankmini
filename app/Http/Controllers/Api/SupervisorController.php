<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Services\ActivityLogService;
use App\Services\DailyReportService;
use App\Services\TransactionReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SupervisorController extends Controller
{
    public function dailyReport(Request $request, TransactionReportService $reportService)
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $date = $validated['date'] ?? now('Asia/Jakarta')->toDateString();
        $report = $reportService->daily($date);

        return response()->json([
            'message' => 'Laporan harian supervisor berhasil diambil.',
            'data' => array_merge(['date' => $date], $report, $report['summary']),
        ], 200);
    }

    public function dailyReports()
    {
        $reports = DailyReport::with('teller')
            ->where('status', 'submitted')
            ->orderByDesc('report_date')
            ->get();

        return response()->json([
            'message' => 'Daftar laporan harian berhasil diambil.',
            'data' => $reports,
        ]);
    }

    public function approveDailyReport(
        $id,
        ActivityLogService $activityLogService
    ) {
        $report = DailyReport::findOrFail($id);

        if ($report->status !== 'submitted') {
            return response()->json([
                'message' => 'Laporan tidak dapat disetujui.',
            ], 422);
        }

        $report->update([
            'status' => 'approved',
            'approved_by' => request()->user()->id,
            'approved_at' => now(),
            'locked_at' => now(),
        ]);

        // Catat aktivitas persetujuan laporan
        $activityLogService->log(
            action: 'daily_report.approve',
            description: 'Menyetujui laporan harian tanggal '
                .$report->report_date
                .' milik Teller ID '
                .$report->teller_id,
            entityType: 'DailyReport',
            entityId: $report->id,
        );

        return response()->json([
            'message' => 'Laporan harian berhasil disetujui.',
            'data' => $report->fresh('teller'),
        ]);
    }

    public function rejectDailyReport(
        Request $request,
        $id,
        ActivityLogService $activityLogService
    ) {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3'],
        ]);

        $report = DailyReport::findOrFail($id);

        if ($report->status !== 'submitted') {
            return response()->json([
                'message' => 'Laporan tidak dapat ditolak.',
            ], 422);
        }

        $report->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
            'approved_by' => null,
            'approved_at' => null,
            'locked_at' => null,
        ]);

        // Catat aktivitas penolakan laporan
        $activityLogService->log(
            action: 'daily_report.reject',
            description: 'Menolak laporan harian tanggal '
                .$report->report_date
                .' milik Teller ID '
                .$report->teller_id
                .'. Alasan: '
                .$request->rejection_reason,
            entityType: 'DailyReport',
            entityId: $report->id,
        );

        return response()->json([
            'message' => 'Laporan berhasil ditolak.',
            'data' => $report->fresh('teller'),
        ], 200);
    }

    public function showDailyReport($id, DailyReportService $dailyReportService)
    {
        $report = DailyReport::with([
            'teller:id,username,full_name,role',
            'approver:id,username,full_name,role',
        ])->findOrFail($id);

        $reconciliation = $dailyReportService->getReconciliation(
            $report->teller,
            Carbon::parse($report->report_date->toDateString(), 'Asia/Jakarta')
        );

        return response()->json([
            'message' => 'Detail laporan harian berhasil diambil.',
            'data' => [
                'report' => $report,
                'transactions' => $reconciliation['transactions'],
                ...$reconciliation['transactions_summary'],
            ],
        ], 200);
    }

    public function correctInitialCashBalance(
        Request $request,
        int $id,
        DailyReportService $dailyReportService
    ) {
        $validated = $request->validate([
            'opening_balance' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $report = $dailyReportService->correctFirstDayOpeningBalance(
            $request->user(),
            $id,
            (int) $validated['opening_balance'],
            $validated['reason']
        );

        return response()->json([
            'message' => 'Koreksi saldo awal berhasil dicatat dalam riwayat audit.',
            'data' => $report,
        ], 200);
    }
}
