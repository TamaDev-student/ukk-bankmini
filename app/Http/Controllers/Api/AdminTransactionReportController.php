<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TransactionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransactionReportController extends Controller
{
    public function __invoke(Request $request, TransactionReportService $reportService): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'in:daily,monthly'],
            'date' => ['required_if:period,daily', 'date_format:Y-m-d'],
            'month' => ['required_if:period,monthly', 'date_format:Y-m'],
        ]);

        $report = $validated['period'] === 'daily'
            ? $reportService->daily($validated['date'])
            : $reportService->monthly($validated['month']);

        return response()->json([
            'message' => 'Laporan transaksi berhasil diambil.',
            'data' => $report,
        ]);
    }
}
