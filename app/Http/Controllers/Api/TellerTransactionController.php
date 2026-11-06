<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashDepositScanToken;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Services\ActivityLogService;
use App\Services\DailyReportService;
use App\Services\TransactionReportService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TellerTransactionController extends Controller
{
    /**
     * Melakukan setoran nasabah.
     */
    public function deposit(
        Request $request,
        TransactionService $service,
        ActivityLogService $activityLogService
    ) {
        $minDeposit = config('bank.min_deposit', 10000);
        $validate = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'integer', 'min:'.$minDeposit],
            'scan_token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i'],
        ], [
            'amount.min' => 'Minimal setoran adalah Rp'.number_format($minDeposit, 0, ',', '.').'.',
        ]);

        $tellerId = $request->user()->id;
        $transaction = DB::transaction(function () use (
            $validate,
            $tellerId,
            $service,
            $activityLogService
        ) {
            $scanToken = CashDepositScanToken::query()
                ->where('token_hash', hash('sha256', $validate['scan_token']))
                ->lockForUpdate()
                ->first();

            if (! $scanToken) {
                abort(422, 'Token scan QR tidak valid.');
            }

            if ((int) $scanToken->user_id !== (int) $tellerId) {
                abort(403, 'Token scan QR bukan milik Teller yang sedang login.');
            }

            if ($scanToken->purpose !== 'cash_deposit') {
                abort(422, 'Token scan QR tidak berlaku untuk transaksi setoran.');
            }

            if ($scanToken->used_at !== null) {
                abort(409, 'Token scan QR sudah pernah digunakan. Silakan pindai ulang.');
            }

            if ($scanToken->expires_at->isPast()) {
                abort(410, 'Token scan QR kedaluwarsa. Silakan pindai ulang.');
            }

            if ((int) $scanToken->customer_id !== (int) $validate['customer_id']) {
                abort(422, 'Nasabah tidak sesuai dengan hasil scan QR.');
            }

            $customer = Customer::query()
                ->findOrFail($scanToken->customer_id);

            if ($customer->status !== 'active') {
                abort(
                    403,
                    $customer->status === 'blocked'
                        ? 'Rekening nasabah diblokir'
                        : 'Rekening nasabah tidak aktif'
                );
            }

            $scanToken->used_at = now();
            $scanToken->save();

            $transaction = $service->deposit(
                $customer,
                $validate['amount'],
                $tellerId
            );

            $activityLogService->log(
                action: 'transaction.deposit',
                description: 'Melakukan setoran sebesar Rp'
                .number_format($validate['amount'], 0, ',', '.')
                .' ke rekening nasabah.',
                entityType: 'Transaction',
                entityId: $transaction->id,
            );

            return $transaction;
        }, 3);

        return response()->json([
            'message' => 'Permintaan setoran berhasil dibuat. Menunggu konfirmasi nasabah.',
            'data' => $transaction,
            'balance' => $transaction->customer()->value('balance'),
        ], 201);
    }

    /**
     * Melakukan penarikan nasabah.
     */
    public function withdraw(
        Request $request,
        TransactionService $service,
        ActivityLogService $activityLogService
    ) {
        $validate = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'integer', 'min:1'],

        ]);

        $customer = Customer::findOrFail($validate['customer_id']);

        if ($customer->pin_locked_at !== null) {
            return response()->json([
                'message' => 'PIN transaksi nasabah terkunci. Nasabah harus memulihkan PIN melalui OTP sebelum penarikan.',
                'pin_locked' => true,
            ], 403);
        }

        if ($customer->security_pin === null) {
            return response()->json([
                'message' => 'Nasabah belum membuat PIN transaksi. Minta nasabah membuat PIN melalui Profil sebelum penarikan.',
                'pin_setup_required' => true,
            ], 409);
        }

        if ($customer->status !== 'active') {
            return response()->json([
                'message' => $customer->status === 'blocked'
                    ? 'Rekening nasabah diblokir'
                    : 'Rekening nasabah tidak aktif',
            ], 403);
        }

        try {
            $transaction = $service->withdraw(
                $customer,
                $validate['amount'],
                $request->user()->id
            );

            // Catat aktivitas setelah penarikan berhasil diproses.
            $activityLogService->log(
                action: 'transaction.withdrawal',
                description: 'Melakukan penarikan sebesar Rp'
                .number_format($validate['amount'], 0, ',', '.')
                .' dari rekening nasabah.',
                entityType: 'Transaction',
                entityId: $transaction->id,
            );

            return response()->json([
                'message' => 'Permintaan penarikan berhasil dibuat. Menunggu konfirmasi nasabah.',
                'data' => $transaction,
                'balance' => $customer->fresh()->balance,
            ], 200);

        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 400);
        }
    }

    /**
     * Memeriksa status transaksi yang dibuat oleh Teller yang sedang login.
     */
    public function transactionStatus(Request $request, int $id)
    {
        $transaction = Transaction::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Transaksi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'message' => 'Status transaksi berhasil diambil.',
            'data' => [
                'id' => $transaction->id,
                'customer_id' => $transaction->customer_id,
                'transaction_type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'approved_at' => $transaction->approved_at,
                'rejected_at' => $transaction->rejected_at,
                'cancelled_at' => $transaction->cancelled_at,
                'rejection_reason' => $transaction->rejection_reason,
            ],
        ], 200);
    }

    /**
     * Menampilkan detail nasabah berdasarkan nomor rekening.
     */
    public function showCustomer(string $accountNumber)
    {
        $customer = Customer::query()
            ->where('account_number', $accountNumber)
            ->with('schoolClass.major:id,name,code')
            ->first([
                'id',
                'account_number',
                'student_name',
                'class_id',
                'status',
                'balance',
            ]);

        if (! $customer) {
            return response()->json([
                'message' => 'Rekening tidak ditemukan.',
            ], 404);
        }

        if ($customer->status !== 'active') {
            return response()->json([
                'message' => $customer->status === 'blocked'
                    ? 'Rekening nasabah diblokir'
                    : 'Rekening nasabah tidak aktif',
            ], 403);
        }

        return response()->json([
            'message' => 'Data nasabah berhasil ditemukan',
            'data' => $customer,
        ]);
    }

    /**
     * Memvalidasi hasil scan QR dan menerbitkan token setoran sekali pakai.
     */
    public function issueCashDepositScanToken(Request $request, string $accountNumber)
    {
        $token = bin2hex(random_bytes(32));
        $ttlMinutes = max(1, (int) config('bank.cash_deposit_scan_token_ttl_minutes', 5));

        [$customer, $expiresAt] = DB::transaction(function () use ($accountNumber, $request, $token, $ttlMinutes) {
            $customer = Customer::query()
                ->where('account_number', $accountNumber)
                ->lockForUpdate()
                ->first([
                    'id',
                    'account_number',
                    'student_name',
                    'status',
                    'balance',
                ]);

            if (! $customer) {
                abort(404, 'Rekening tidak ditemukan.');
            }

            if ($customer->status !== 'active') {
                abort(
                    403,
                    $customer->status === 'blocked'
                        ? 'Rekening nasabah diblokir'
                        : 'Rekening nasabah tidak aktif'
                );
            }

            $expiresAt = now()->addMinutes($ttlMinutes);

            CashDepositScanToken::create([
                'token_hash' => hash('sha256', $token),
                'customer_id' => $customer->id,
                'user_id' => $request->user()->id,
                'purpose' => 'cash_deposit',
                'expires_at' => $expiresAt,
            ]);

            return [$customer, $expiresAt];
        }, 3);

        return response()->json([
            'message' => 'QR nasabah berhasil divalidasi.',
            'data' => [
                'customer' => $customer,
                'scan_token' => $token,
                'expires_at' => $expiresAt->toISOString(),
            ],
        ], 200);
    }

    /**
     * Mengambil rekap transaksi harian.
     */
    public function dailyreport(
        Request $request,
        TransactionReportService $reportService
    ) {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $date = $validated['date'] ?? now('Asia/Jakarta')->toDateString();
        $report = $reportService->daily($date, $request->user()->id);

        return response()->json([
            'message' => 'Ringkasan transaksi harian Teller berhasil diambil.',
            'data' => array_merge(['date' => $date], $report, $report['summary']),
        ], 200);
    }

    /**
     * Mengambil data rekonsiliasi kas Teller.
     */
    public function cashReconciliation(
        Request $request,
        DailyReportService $service
    ) {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $date = Carbon::parse(
            $validated['date'] ?? now('Asia/Jakarta')->toDateString()
        );

        $data = $service->getReconciliation(
            $request->user(),
            $date
        );

        return response()->json([
            'message' => 'Data rekonsiliasi kas berhasil diambil.',
            'data' => $data,
        ], 200);
    }

    /**
     * Menyimpan atau memperbarui data kas awal Teller.
     */
    public function saveOpeningBalance(
        Request $request,
        DailyReportService $service
    ) {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'opening_balance' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],
            'opening_physical_balance' => [
                'required',
                'integer',
                'min:0',
            ],
        ]);

        $teller = $request->user();
        $date = Carbon::parse($validated['date']);

        // Menentukan apakah laporan pada tanggal tersebut sudah ada.
        $existingReport = DailyReport::query()
            ->where('teller_id', $teller->id)
            ->whereDate('report_date', $date->toDateString())
            ->exists();

        $report = $service->createOrUpdateDraft(
            $teller,
            $date,
            [
                ...array_intersect_key(
                    $validated,
                    ['opening_balance' => true]
                ),
                'opening_physical_balance' => $validated['opening_physical_balance'],
            ]
        );

        return response()->json([
            'message' => 'Data kas awal berhasil disimpan.',
            'data' => $report,
        ], $existingReport ? 200 : 201);
    }

    /**
     * Menyimpan kas fisik akhir dan menghitung selisih kas.
     */
    public function saveClosingBalance(
        Request $request,
        DailyReportService $service
    ) {
        $validated = $request->validate([
            'report_id' => ['required', 'integer', 'min:1'],
            'physical_balance' => [
                'required',
                'integer',
                'min:0',
            ],
        ]);

        $report = $service->closeReport(
            $request->user(),
            (int) $validated['report_id'],
            (int) $validated['physical_balance']
        );

        return response()->json([
            'message' => 'Data kas akhir berhasil disimpan.',
            'data' => $report,
        ], 200);
    }

    /**
     * Mengirim laporan harian Teller.
     */
    /**
     * Mengirim laporan harian Teller untuk proses approval.
     */
    public function submitDailyReport(
        Request $request,
        DailyReportService $service
    ) {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $report = $service->submit(
            $request->user(),
            Carbon::parse($validated['date'])
        );

        return response()->json([
            'message' => 'Laporan harian berhasil dikirim.',
            'data' => $report,
        ], 201);
    }

    /**
     * Mengambil daftar laporan harian milik Teller.
     */
    public function dailyReports(Request $request)
    {
        $teller = $request->user();

        $reports = DailyReport::where('teller_id', $teller->id)
            ->orderByDesc('report_date')
            ->get();

        return response()->json([
            'message' => 'Daftar laporan harian berhasil diambil.',
            'data' => $reports,
        ], 200);
    }
}
