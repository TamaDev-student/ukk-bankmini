<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPStan\Reflection\SignatureMap\FunctionSignature;

class DailyReport extends Model
{
    protected $fillable = [
        'report_date',
        'teller_id',
        'status',
        'total_deposit',
        'total_withdrawal',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'locked_at',
        'created_at',
        'updated_at',
    ];
    protected $casts = [
        'report_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',

    ];

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_id');
    }
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
        // relasi one to one untuk user yang acc

    }
}
