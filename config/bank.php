<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Aturan Bisnis Bank Mini Sekolah
    |--------------------------------------------------------------------------
    |
    | Konfigurasi terpusat untuk batasan transaksi nasabah.
    |
    */

    // Minimal nominal setoran tunai nasabah (Rp10.000).
    'min_deposit' => (int) env('BANK_MIN_DEPOSIT', 10000),

    // Saldo mengendap minimal yang wajib tersisa setelah penarikan (Rp10.000).
    'min_holding_balance' => (int) env('BANK_MIN_HOLDING_BALANCE', 10000),

    // Masa berlaku token scan QR setoran tunai, dalam menit.
    'cash_deposit_scan_token_ttl_minutes' => (int) env('BANK_CASH_DEPOSIT_SCAN_TOKEN_TTL_MINUTES', 5),

    // Batas penutupan operasional dalam format 24 jam (Asia/Jakarta).
    'cash_close_time' => env('BANK_CASH_CLOSE_TIME', '23:59'),

];
