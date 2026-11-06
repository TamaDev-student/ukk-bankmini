<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function recordOpeningDeposit(
        Customer $customer,
        int $amount,
        int $userId
    ): Transaction {
        $minDeposit = config('bank.min_deposit', 10000);
        if ($amount < $minDeposit) {
            throw ValidationException::withMessages([
                'amount' => ['Minimal setoran adalah Rp'.number_format($minDeposit, 0, ',', '.').'.'],
            ]);
        }

        return DB::transaction(function () use ($customer, $amount, $userId) {
            $lockedCustomer = Customer::query()
                ->lockForUpdate()
                ->findOrFail($customer->id);

            if ($lockedCustomer->status !== 'active') {
                throw ValidationException::withMessages([
                    'customer' => ['Rekening nasabah tidak aktif.'],
                ]);
            }

            $now = now();
            $transaction = Transaction::create([
                'customer_id' => $lockedCustomer->id,
                'user_id' => $userId,
                'transaction_date' => $now,
                'transaction_type' => 'deposit',
                'amount' => $amount,
                'status' => 'approved',
                'approved_at' => $now,
            ]);

            $lockedCustomer->increment('balance', $amount);
            $transaction->journals()->createMany([
                [
                    'account_name' => 'Kas',
                    'type' => 'debit',
                    'amount' => $amount,
                ],
                [
                    'account_name' => 'Tabungan Nasabah',
                    'type' => 'credit',
                    'amount' => $amount,
                ],
            ]);

            return $transaction;
        }, 3);
    }

    /**
     * Membuat transaksi setoran berstatus pending.
     *
     * Saldo belum berubah dan jurnal belum dibuat.
     */
    public function deposit(
        Customer $customer,
        int $amount,
        int $userId
    ): Transaction {
        $minDeposit = config('bank.min_deposit', 10000);
        if ($amount < $minDeposit) {
            throw ValidationException::withMessages([
                'amount' => ['Minimal setoran adalah Rp'.number_format($minDeposit, 0, ',', '.').'.'],
            ]);
        }

        return $this->createPendingTransaction(
            $customer,
            $amount,
            $userId,
            'deposit'
        );
    }

    /**
     * Membuat transaksi penarikan berstatus pending.
     *
     * Saldo akan diperiksa dan dikurangi saat konfirmasi.
     */
    public function withdraw(
        Customer $customer,
        int $amount,
        int $userId
    ): Transaction {
        $minHoldingBalance = config('bank.min_holding_balance', 10000);
        if ($customer->balance - $amount < $minHoldingBalance) {
            throw ValidationException::withMessages([
                'amount' => [
                    'Saldo tidak mencukupi. Penarikan harus menyisakan saldo minimal Rp'
                    .number_format($minHoldingBalance, 0, ',', '.')
                    .' (saldo mengendap).',
                ],
            ]);
        }

        return $this->createPendingTransaction(
            $customer,
            $amount,
            $userId,
            'withdrawal'
        );
    }

    /**
     * Membuat transaksi baru tanpa mengubah saldo.
     */
    private function createPendingTransaction(
        Customer $customer,
        int $amount,
        int $userId,
        string $type
    ): Transaction {
        if ($amount < 1) {
            throw ValidationException::withMessages([
                'amount' => ['Nominal transaksi harus lebih dari nol.'],
            ]);
        }

        if ($type === 'deposit') {
            $minDeposit = config('bank.min_deposit', 10000);
            if ($amount < $minDeposit) {
                throw ValidationException::withMessages([
                    'amount' => ['Minimal setoran adalah Rp'.number_format($minDeposit, 0, ',', '.').'.'],
                ]);
            }
        }

        return DB::transaction(function () use ($customer, $amount, $userId, $type) {
            $this->lockTellerAndEnsureCanTransact($userId);

            $lockedCustomer = Customer::query()
                ->lockForUpdate()
                ->findOrFail($customer->id);

            if ($type === 'withdrawal' && $lockedCustomer->pin_locked_at !== null) {
                throw ValidationException::withMessages([
                    'customer' => [
                        'PIN transaksi nasabah terkunci. Pulihkan PIN melalui OTP sebelum penarikan.',
                    ],
                ]);
            }

            if ($type === 'withdrawal' && $lockedCustomer->security_pin === null) {
                throw ValidationException::withMessages([
                    'customer' => [
                        'Nasabah belum membuat PIN transaksi. Buat PIN sebelum penarikan.',
                    ],
                ]);
            }

            if ($lockedCustomer->status !== 'active') {
                throw ValidationException::withMessages([
                    'customer' => ['Rekening nasabah tidak aktif.'],
                ]);
            }

            if ($type === 'withdrawal') {
                $minHolding = config('bank.min_holding_balance', 10000);
                if ($lockedCustomer->balance - $amount < $minHolding) {
                    throw ValidationException::withMessages([
                        'amount' => [
                            'Saldo tidak mencukupi. Penarikan harus menyisakan saldo minimal Rp'
                            .number_format($minHolding, 0, ',', '.')
                            .' (saldo mengendap).',
                        ],
                    ]);
                }

                $hasPendingWithdrawal = Transaction::query()
                    ->where('customer_id', $lockedCustomer->id)
                    ->where('transaction_type', 'withdrawal')
                    ->where('status', 'pending')
                    ->exists();

                if ($hasPendingWithdrawal) {
                    throw ValidationException::withMessages([
                        'transaction' => [
                            'Rekening ini masih memiliki penarikan '
                            .'yang menunggu konfirmasi nasabah. '
                            .'Selesaikan transaksi tersebut terlebih dahulu.',
                        ],
                    ]);
                }
            }

            return Transaction::create([
                'customer_id' => $lockedCustomer->id,
                'user_id' => $userId,
                'transaction_date' => now(),
                'transaction_type' => $type,
                'amount' => $amount,
                'status' => 'pending',
            ]);
        }, 3);
    }

    /**
     * Mengonfirmasi transaksi menggunakan PIN nasabah.
     *
     * Pemanggil wajib memverifikasi PIN dan memastikan transaksi
     * merupakan milik nasabah yang sedang login.
     */
    public function approve(
        int $transactionId,
        int $customerId
    ): Transaction {
        $tellerId = Transaction::query()
            ->whereKey($transactionId)
            ->value('user_id');

        if ($tellerId === null) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaksi tidak ditemukan.'],
            ]);
        }

        return DB::transaction(function () use ($transactionId, $customerId, $tellerId) {
            User::query()
                ->whereKey($tellerId)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transactionId);

            if ($transaction->customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Transaksi tidak ditemukan untuk nasabah ini.',
                    ],
                ]);
            }

            if ($transaction->status !== 'pending') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Transaksi ini sudah diproses sebelumnya.',
                    ],
                ]);
            }

            $customer = Customer::query()
                ->lockForUpdate()
                ->findOrFail($customerId);

            if ($customer->status !== 'active') {
                throw ValidationException::withMessages([
                    'customer' => ['Rekening nasabah tidak aktif.'],
                ]);
            }

            /*
             * Jika transaksi penarikan, saldo harus mencukupi
             * dan menyisakan minimal Rp10.000.
             */
            $minHoldingBalance = config('bank.min_holding_balance', 10000);
            if (
                $transaction->transaction_type === 'withdrawal'
                && $customer->balance - $transaction->amount < $minHoldingBalance
            ) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Saldo tidak mencukupi. Saldo minimal '
                        .'Rp'.number_format($minHoldingBalance, 0, ',', '.')
                        .' harus tetap tersedia.',
                    ],
                ]);
            }

            /*
             * Terapkan perubahan saldo hanya setelah transaksi
             * dinyatakan sah.
             */
            if ($transaction->transaction_type === 'deposit') {
                $customer->increment('balance', $transaction->amount);

                // Debit Kas.
                $transaction->journals()->create([
                    'account_name' => 'Kas',
                    'type' => 'debit',
                    'amount' => $transaction->amount,
                ]);

                // Kredit Tabungan Nasabah.
                $transaction->journals()->create([
                    'account_name' => 'Tabungan Nasabah',
                    'type' => 'credit',
                    'amount' => $transaction->amount,
                ]);
            } elseif ($transaction->transaction_type === 'withdrawal') {
                $customer->decrement('balance', $transaction->amount);

                // Debit Tabungan Nasabah.
                $transaction->journals()->create([
                    'account_name' => 'Tabungan Nasabah',
                    'type' => 'debit',
                    'amount' => $transaction->amount,
                ]);

                // Kredit Kas.
                $transaction->journals()->create([
                    'account_name' => 'Kas',
                    'type' => 'credit',
                    'amount' => $transaction->amount,
                ]);
            } else {
                throw ValidationException::withMessages([
                    'transaction' => ['Jenis transaksi tidak valid.'],
                ]);
            }

            $transaction->status = 'approved';
            $transaction->approved_at = now();
            $transaction->rejected_at = null;
            $transaction->rejection_reason = null;
            $transaction->save();

            return $transaction->fresh();
        }, 3);
    }

    /**
     * Menolak transaksi yang masih pending.
     *
     * Saldo dan jurnal tidak berubah.
     */
    public function reject(
        int $transactionId,
        int $customerId,
        ?string $reason = null
    ): Transaction {
        $tellerId = Transaction::query()
            ->whereKey($transactionId)
            ->value('user_id');

        if ($tellerId === null) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaksi tidak ditemukan.'],
            ]);
        }

        return DB::transaction(function () use ($transactionId, $customerId, $reason, $tellerId) {
            User::query()
                ->whereKey($tellerId)
                ->lockForUpdate()
                ->firstOrFail();

            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transactionId);

            if ($transaction->customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Transaksi tidak ditemukan untuk nasabah ini.',
                    ],
                ]);
            }

            if ($transaction->status !== 'pending') {
                throw ValidationException::withMessages([
                    'transaction' => [
                        'Hanya transaksi pending yang dapat ditolak.',
                    ],
                ]);
            }

            $transaction->status = 'rejected';
            $transaction->rejected_at = now();
            $transaction->rejection_reason = $reason;
            $transaction->save();

            return $transaction->fresh();
        }, 3);
    }

    /**
     * Membatalkan transaksi pending tanpa mengubah saldo atau jurnal.
     */
    public function cancel(
        int $transactionId,
        int $customerId,
        int $userId
    ): Transaction {
        return DB::transaction(function () use ($transactionId, $customerId, $userId) {
            $transaction = Transaction::query()
                ->whereKey($transactionId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                abort(404, 'Transaksi tidak ditemukan.');
            }

            if ($transaction->status !== 'pending') {
                abort(409, 'Hanya transaksi berstatus pending yang dapat dibatalkan.');
            }

            $transaction->status = 'cancelled';
            $transaction->cancelled_at = now();
            $transaction->cancelled_by = $userId;
            $transaction->save();

            return $transaction->fresh();
        }, 3);
    }

    /**
     * Mengunci teller dan memastikan laporan hari ini
     * belum dikirim atau dikunci.
     */
    private function lockTellerAndEnsureCanTransact(
        int $userId
    ): void {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();

        $report = DailyReport::query()
            ->where('teller_id', $userId)
            ->whereDate('report_date', now('Asia/Jakarta')->toDateString())
            ->lockForUpdate()
            ->first();

        if (
            $report === null
            || $report->opening_balance === null
            || $report->opening_physical_balance === null
        ) {
            throw ValidationException::withMessages([
                'transaction' => [
                    'Catat saldo awal sistem dan uang fisik saat pembukaan sebelum memulai transaksi.',
                ],
            ]);
        }

        if (
            in_array(
                $report->status,
                ['submitted', 'approved', 'auto_closed', 'auto_closed_unverified'],
                true
            )
            || $report->locked_at !== null
        ) {
            throw ValidationException::withMessages([
                'transaction' => [
                    'Transaksi tidak dapat dilakukan karena laporan '
                    .'harian sudah dikirim atau dikunci.',
                ],
            ]);
        }
    }
}
