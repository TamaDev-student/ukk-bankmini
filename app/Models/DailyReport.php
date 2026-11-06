<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReport extends Model
{
    protected $fillable = [
        'report_date',
        'teller_id',
        'status',

        // Rekap transaksi
        'total_deposit',
        'total_withdrawal',

        // Rekonsiliasi kas
        'last_approved_balance',
        'opening_balance',
        'opening_balance_source',
        'opening_balance_recorded_by',
        'opening_balance_recorded_at',
        'opening_balance_difference',
        'opening_physical_balance',
        'opening_difference',
        'system_balance',
        'physical_balance',
        'closing_difference',

        // Persetujuan laporan
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'locked_at',
        'auto_closed_at',
        'total_transactions',
        'pending_transactions',
        'pending_total',
        'failed_transactions',
        'failed_total',
        'transaction_snapshot',

        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'auto_closed_at' => 'datetime',

        // Nominal uang dan selisih harus dibaca sebagai integer.
        'total_deposit' => 'integer',
        'total_withdrawal' => 'integer',
        'last_approved_balance' => 'integer',
        'opening_balance' => 'integer',
        'opening_balance_recorded_at' => 'datetime',
        'opening_balance_difference' => 'integer',
        'opening_physical_balance' => 'integer',
        'opening_difference' => 'integer',
        'system_balance' => 'integer',
        'physical_balance' => 'integer',
        'closing_difference' => 'integer',
        'total_transactions' => 'integer',
        'pending_transactions' => 'integer',
        'pending_total' => 'integer',
        'failed_transactions' => 'integer',
        'failed_total' => 'integer',
        'transaction_snapshot' => 'array',
    ];

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function openingBalanceRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opening_balance_recorded_by');
    }
}
