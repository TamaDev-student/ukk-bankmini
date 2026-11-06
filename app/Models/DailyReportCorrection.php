<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReportCorrection extends Model
{
    protected $fillable = [
        'daily_report_id',
        'user_id',
        'previous_opening_balance',
        'corrected_opening_balance',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_opening_balance' => 'integer',
            'corrected_opening_balance' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
