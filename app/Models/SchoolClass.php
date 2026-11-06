<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'name',
        'major_id', // jurusan

    ];

    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'class_id', 'id');
    }
}
