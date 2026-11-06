<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TemporaryPasswordService
{
    public const VALIDITY_DAYS = 7;

    /**
     * Generate an independently random credential, avoiding duplicates in a batch.
     *
     * @param  array<int, string>  $alreadyIssued
     */
    public function generate(array $alreadyIssued = []): string
    {
        do {
            $password = Str::random(32);
        } while (in_array($password, $alreadyIssued, true));

        return $password;
    }

    public function expiresAt(): CarbonImmutable
    {
        return now()->addDays(self::VALIDITY_DAYS);
    }
}
