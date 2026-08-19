<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\AccountingJournal;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function deposit(Customer $customer, int $amount, int $userId): Transaction
    {
        return DB::transaction(function () use ($customer, $amount, $userId) {
            $customer->increment('balance', $amount); //saldo bertambah

            $transaction = Transaction::create([
                'customer_id' => $customer->id,
                'user_id' => $userId,
                'transaction_date' => now(),
                'transaction_type' => 'deposit', //tipe transaksi penambahan saldo
                'amount' => $amount,
            ]);

            $transaction->accountingJournals()->create([
                'account_code' => '111',
                'position' => 'debit',
                'amount' => $amount,
            ]);

            $transaction->accountingJournals()->create([
                'account_code' => '211',
                'position' => 'credit',
                'amount' => $amount,
            ]);

            return $transaction;
        });
    }
    public function withdraw(Customer $customer, int $amount, int $userId): Transaction
    {
        return DB::transaction(function () use ($customer, $amount, $userId) {
            if ($customer->balance - $amount < 10000) {
                throw new \Exception('Saldo tidak mencukupi');
            }
            $customer->decrement('balance', $amount); // saldo berkurang

            $transaction = Transaction::create([
                'customer_id' => $customer->id, // id nasabah
                'user_id' => $userId, // id user
                'transaction_date' => now(), // tanggal transaksi
                'transaction_type' => 'withdrawal', // tipe transaksi penarikan tunai
                'amount' => $amount, // jumlah transaksi
            ]);
            $transaction->accountingJournals()->create([
                'account_code' => '211',
                'position' => 'debit',
                'amount' => $amount,
            ]);
            $transaction->accountingJournals()->create([
                'account_code' => '111',
                'position' => 'credit',
                'amount' => $amount,
            ]);
            return $transaction;
        });
    }
}