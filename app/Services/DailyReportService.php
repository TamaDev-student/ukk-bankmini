<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class DailyReportService
{
    /**
     * Create a new class instance.
     */
    public function submit(User $teller, Carbon $date): DailyReport
    {
        $existingReport = DailyReport::where('teller_id', $teller->id)
            ->whereDate('report_date', $date)
            ->first();

        if ($existingReport) {
            throw new \Exception('Laporan harian untuk tanggal tersebut sudah dibuat.');
        }

        $transactions = Transaction::where('user_id', $teller->id)
            ->whereDate('transaction_date', $date)
            ->get();

        $totalDeposit = $transactions->where('transaction_type', 'deposit')
            ->sum('amount');

        $totalWithdrawal = $transactions->where('transaction_type', 'withdrawal')
            ->sum('amount');

        return DailyReport::create([
            'report_date' => $date->toDateString(),
            'teller_id' => $teller->id,
            'status' => 'submitted',
            'total_deposit' => $totalDeposit,
            'total_withdrawal' => $totalWithdrawal,
            'submitted_at' => now(),
        ]);
    }
}////////////////////////////////////////// MEMBUAT API SUBMIT DAILY REPORT //////////////////
