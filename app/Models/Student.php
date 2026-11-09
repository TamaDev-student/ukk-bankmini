<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_number',
        'student_name',
        'class_id',
        'original_class',
        'grade_level',
        'cohort_year',
        'graduation_year',
    ];

    protected $casts = [
        'cohort_year' => 'integer',
        'graduation_year' => 'integer',
    ];

    /**
     * Relasi ke data kelas resmi (jika sudah terkonfigurasi di sistem).
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Relasi ke data nasabah / rekening bank (jika sudah terdaftar).
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class, 'student_number', 'student_number');
    }

    /**
     * Relasi ke user akun nasabah melalui data Customer.
     * Hanya ada jika siswa telah terdaftar sebagai Customer yang terhubung ke User.
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            Customer::class,
            'student_number', // Foreign key on Customer table
            'id',             // Foreign key on User table
            'student_number', // Local key on Student table
            'user_id'         // Local key on Customer table
        );
    }

    /**
     * Status apakah siswa sudah terdaftar sebagai nasabah.
     * Sesuai ketentuan: hanya ditentukan berdasarkan keberadaan record Customer yang relevan.
     */
    public function getHasAccountAttribute(): bool
    {
        if ($this->relationLoaded('customer')) {
            return $this->customer !== null;
        }

        return $this->customer()->exists();
    }
}
