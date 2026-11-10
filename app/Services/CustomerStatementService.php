<?php

namespace App\Services;

use App\Models\Customer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

class CustomerStatementService
{
    /**
     * Membangun data mutasi rekening nasabah berdasarkan rentang tanggal.
     *
     * @return array{
     *     customer: Customer,
     *     period: string,
     *     startDate: string,
     *     endDate: string,
     *     printedAt: string,
     *     openingBalance: int,
     *     totalDeposit: int,
     *     totalWithdrawal: int,
     *     closingBalance: int,
     *     items: array<int, array{
     *         id: int,
     *         date: string,
     *         description: string,
     *         debit: int,
     *         credit: int,
     *         balance: int
     *     }>
     * }
     */
    public function generateStatementData(Customer $customer, string $startDate, string $endDate): array
    {
        $startUtc = CarbonImmutable::parse($startDate, 'Asia/Jakarta')->startOfDay()->utc();
        $endUtc = CarbonImmutable::parse($endDate, 'Asia/Jakarta')->endOfDay()->utc();

        // 1. Saldo Awal: seluruh transaksi berhasil (approved) sebelum periode laporan
        $prevDeposits = (int) $customer->transactions()
            ->where('status', 'approved')
            ->where('transaction_type', 'deposit')
            ->where('transaction_date', '<', $startUtc)
            ->sum('amount');

        $prevWithdrawals = (int) $customer->transactions()
            ->where('status', 'approved')
            ->where('transaction_type', 'withdrawal')
            ->where('transaction_date', '<', $startUtc)
            ->sum('amount');

        $openingBalance = $prevDeposits - $prevWithdrawals;

        // 2. Transaksi dalam rentang tanggal
        $transactions = $customer->transactions()
            ->where('status', 'approved')
            ->where('transaction_date', '>=', $startUtc)
            ->where('transaction_date', '<=', $endUtc)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $runningBalance = $openingBalance;
        $totalDeposit = 0;
        $totalWithdrawal = 0;
        $items = [];

        foreach ($transactions as $tx) {
            $amount = (int) $tx->amount;
            if ($tx->transaction_type === 'deposit') {
                $debit = 0;
                $credit = $amount;
                $runningBalance += $amount;
                $totalDeposit += $amount;
                $desc = 'Setoran Tunai';
            } else {
                $debit = $amount;
                $credit = 0;
                $runningBalance -= $amount;
                $totalWithdrawal += $amount;
                $desc = 'Penarikan Tunai';
            }

            $items[] = [
                'id' => $tx->id,
                'date' => CarbonImmutable::parse($tx->transaction_date)->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
                'description' => $desc,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
            ];
        }

        $closingBalance = $openingBalance + $totalDeposit - $totalWithdrawal;

        $formattedStart = CarbonImmutable::parse($startDate, 'Asia/Jakarta')->format('d/m/Y');
        $formattedEnd = CarbonImmutable::parse($endDate, 'Asia/Jakarta')->format('d/m/Y');
        $printedAt = CarbonImmutable::now('Asia/Jakarta')->format('d/m/Y H:i').' WIB';

        return [
            'customer' => $customer->loadMissing('schoolClass'),
            'period' => "{$formattedStart} - {$formattedEnd}",
            'startDate' => $startDate,
            'endDate' => $endDate,
            'printedAt' => $printedAt,
            'openingBalance' => $openingBalance,
            'totalDeposit' => $totalDeposit,
            'totalWithdrawal' => $totalWithdrawal,
            'closingBalance' => $closingBalance,
            'items' => $items,
        ];
    }

    /**
     * Menghasilkan file PDF mutasi rekening untuk nasabah.
     */
    public function generatePdfResponse(Customer $customer, string $startDate, string $endDate): Response
    {
        $data = $this->generateStatementData($customer, $startDate, $endDate);

        $pdf = Pdf::loadView('pdf.statement', $data)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'sans-serif',
            ]);

        $accountNumber = preg_replace('/[^A-Za-z0-9_-]/', '', $customer->account_number);
        $filename = "Mutasi_{$accountNumber}_{$startDate}_{$endDate}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}

