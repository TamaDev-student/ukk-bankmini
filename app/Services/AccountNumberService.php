<?php

namespace App\Services;

use App\Exceptions\AccountCapacityExceededException;
use App\Models\Customer;
use App\Models\Major;
use App\Models\SchoolClass;
use Exception;

class AccountNumberService
{
    /**
     * Generate a unique account number based on the major code.
     * Format: {major.code}{3_digit_sequence}
     *
     * @throws Exception
     */
    public function generateAccountNumber(int $classId): string
    {
        $class = SchoolClass::findOrFail($classId);

        $major = Major::where('id', $class->major_id)  // Mengunci data jurusan selama transaksi database berlangsung.
            ->lockForUpdate()
            ->first();

        if (! $major) {
            throw new Exception('Major not found for the given class.');
        }

        $majorCode = $major->code;
        if (empty($majorCode)) {
            throw new Exception(
                "Major code is not set for major: {$major->name}"
            );
        }

        // mencari nomor rekening terakhir untuk jurusan tersebut.
        // Mencari nomor rekening terakhir untuk jurusan tersebut.
        $latestCustomer = Customer::where(
            'account_number',
            'like',
            $majorCode.'%'
        )
            ->whereRaw(
                'LENGTH(account_number) = ?',
                [strlen($majorCode) + 3]
            )
            ->orderBy('account_number', 'desc')
            ->lockForUpdate() // lockforupdate
            ->first();

        $sequence = 1; // Nomor urut dimulai dari 1.

        if ($latestCustomer) {
            // Mengambil bagian angka dari nomor rekening terakhir.
            $latestSequenceStr = substr(
                $latestCustomer->account_number,
                strlen($majorCode)
            );

            if (is_numeric($latestSequenceStr)) {
                $sequence = (int) $latestSequenceStr + 1;
            }
        }

        // Maksimal tiga digit, yaitu 001 sampai 999.
        if ($sequence > 999) {
            throw new AccountCapacityExceededException(
                "Kapasitas nomor rekening untuk jurusan {$major->name} sudah penuh (maksimal 999)."
            );
        }

        $sequenceStr = str_pad( // mengubah nomor urut menjadi tiga digit.
            $sequence,
            3,
            '0',
            STR_PAD_LEFT
        );

        // menggabungkan kode jurusan dan nomor urut.
        return $majorCode.$sequenceStr;

    }
}
