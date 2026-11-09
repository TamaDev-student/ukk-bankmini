<?php

namespace App\Models;

// dari model transaction

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model; // relasi one to many
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_number', // nomor rekening
        'student_number', // nis
        'student_name', // nama
        'class_id', // kelas
        'status', // status
        'security_pin', // pin
        'must_change_pin', // wajib buat PIN baru setelah reset
        'pin_locked_at',
        'balance', // saldo
        'user_id',
    ];

    protected $casts = [
        'balance' => 'integer',
        'must_change_pin' => 'boolean',
        'pin_locked_at' => 'datetime',
    ];

    protected $hidden = [
        'security_pin',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class); // setiap customer bisa melakukan banyak transaksi
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pinResetRequests(): HasMany
    {
        return $this->hasMany(PinResetRequest::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_number', 'student_number');
    }
}
