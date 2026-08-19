<?php

namespace App\Models;

use App\Models\Customer;
use App\Models\User;
use App\Models\AccountingJournal;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class); //setiap transaksi pasti dilakukan oleh seorang user
    }

    protected $fillable = [
        'customer_id', // id nasabah
        'user_id', // id user
        'transaction_date', //tanggal transaksi
        'transaction_type', //tipe transaksi
        'amount', //jumlah transaksi
    ];
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class); //setiap transaksi pasti dilakukan oleh seorang customer
    }

    public function accountingJournals(): HasMany
    {
        return $this->hasMany(AccountingJournal::class); //setiap transaksi pasti punya lebih dari 1 jurnal
    }
}
