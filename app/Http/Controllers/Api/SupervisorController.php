<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class SupervisorController extends Controller
{
    public function dailyReport(Request $request)
    {
        $date = $request->input('date', now()->toDateString());

        $transactions = Transaction::with([
            'customer:id,account_number,student_number,student_name,class_id,status,balance',
            'user:id,username,full_name,role',
        ])
            ->whereDate('transaction_date', $date)
            ->orderBy('transaction_date')
            ->get();

        $totalDeposit = $transactions
            ->where('transaction_type', 'deposit')
            ->sum('amount');

        $totalWithdrawal = $transactions
            ->where('transaction_type', 'withdrawal')
            ->sum('amount');

        return response()->json([
            'message' => 'Laporan harian supervisor berhasil diambil.',
            'data' => [
                'date' => $date,
                'total_transactions' => $transactions->count(),
                'total_deposit' => $totalDeposit,
                'total_withdrawal' => $totalWithdrawal,
                'transactions' => $transactions,
            ],
        ], 200);
    }

}
