<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TellerTransactionController extends Controller
{
    public function deposit(Request $request, TransactionService $service)
    {
        $validate = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'], //customer id wajib diisi integer dan ada di tabel customer
            'amount' => ['required', 'integer', 'min:1'], //jumlah wajib diisi integer dan minimal 1
        ]);

        $customer = Customer::findOrFail($validate['customer_id']);

        $transaction = $service->deposit(
            $customer,
            $validate['amount'],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Setoran berhasil',
            'data' => $transaction,
            'balance' => $customer->fresh()->balance,
        ], 201);

    }
    public function withdraw(Request $request, TransactionService $service)
    {
        $validate = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'pin' => ['required', 'string'],
        ]);

        // penarikan wajib pakai PIN
        $customer = Customer::findOrFail($validate['customer_id']);

        //verifikasi pin nasabah
        if (!Hash::check($validate['pin'], $customer->security_pin)) {
            return response()->json([
                'message' => 'PIN keamanan tidak valid',
            ], 403);
        }
        try {
            $transaction = $service->withdraw(
                $customer,
                $validate['amount'],
                $request->user()->id
            );
            return response()->json([
                'message' => 'Penarikan berhasil',
                'data' => $transaction,
                'balance' => $customer->fresh()->balance,
            ], 200);
        } catch (\Exception $exception) {

            // nangkap error jika saldo ngga cukup (aturan minimum saldo)
            return response()->json([
                'message' => $exception->getMessage(),
            ], 400);
        }
    }
    public function customers()
    {
        $customers = Customer::with(['SchoolClass.major'])->get([
            'id',
            'account_number', // nomor rekening bank
            'student_number',
            'class_id',
            'status',
            'balance',
        ]);
        return response()->json([
            'message' => 'Data nasabah berhasil diambil',
            'data' => $customers,
        ], 200);
    }
    public function showCustomer(string $accountNumber) //menampilkan detail nasabah berdasarkan nomor rekening
    {
        $customer = Customer::with([
            'SchoolClass.major'
        ])->where('account_number', $accountNumber)->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Rekening tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'message' => 'Data nasabah berhasil ditemukan',
            'data' => $customer,
        ]);
    }
    public function dailyreport(Request $request)
    {
        $date = $request->input('date', now()->toDateString());

        $transactions = Transaction::with([
            'customer:id,account_number,student_number,student_name,class_id,status,balance',
            'user:id,username,full_name,role',
        ])
            ->whereDate('transaction_date', $date)->orderBy('transaction_date', )->get();

        $totalDeposit = $transactions->where('transaction_type', 'deposit')->sum('amount');

        $totalWithdrawal = $transactions->where('transaction_type', 'withdrawal')->sum('amount');

        return response()->json([
            'message' => 'Laporan harian berhasil dibuat.',
            'data' => [
                'date' => $date,
                'total_transactions' => $transactions->count(),
                'total_deposit' => $totalDeposit,
                'total_withdrawal' => $totalWithdrawal,
                'transactions' => $transactions,
            ],
        ], 200);
    }
    public function submitDailyReport(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
        ]);
        $teller = $request->user();

        $service = new \App\Services\DailyReportService();

        $report = $service->submit(
            $teller,
            \Carbon\Carbon::parse($request->date)
        );

        return response()->json([
            'message' => 'Laporan harian berhasil dikirim.',
            'data' => $report,
        ], 201);
    }
}
//////////////////// otw nyambungin ke api.php ///////////////