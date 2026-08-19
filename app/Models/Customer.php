<?php

namespace App\Models;

use App\Models\Transaction; // dari model transaction

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany; //relasi one to many
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'account_number', //nomor rekening
        'student_number', //nis
        'student_name', //nama
        'class_id', //kelas
        'status', //status
        'security_pin', //pin
        'balance' //saldo
    ];
    protected $casts = [
        'balance' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->HasMany(Transaction::class); //setiap customer bisa melakukan banyak transaksi
    }
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

}
