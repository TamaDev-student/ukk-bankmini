<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bank:auto-close-teller-reports')
    ->dailyAt(config('bank.cash_close_time'))
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
