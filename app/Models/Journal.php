<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    protected $fillable = [
        'transaction_id', // id transaksi
        'account_name', // nama no rekening
        'type', // tipe transaksi
        'amount', // jumlah transaksi

    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
