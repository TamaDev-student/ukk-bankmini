<?php

use App\Http\Controllers\Api\TellerTransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SupervisorController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    //rute khusus teller untuk mendapatkan data nasabah
    Route::get('/teller/customers', [TellerTransactionController::class, 'customers']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/teller/daily-report', [TellerTransactionController::class, 'dailyreport']);

    // Rute khusus Teller
    Route::middleware('role:teller')->group(function () {
        Route::post('/teller/deposit', [TellerTransactionController::class, 'deposit']);
        Route::post('/teller/withdrawal', [TellerTransactionController::class, 'withdraw']);
        Route::get('/teller/customers/{accountNumber}', [TellerTransactionController::class, 'showCustomer']); //menapilkan detail nomor rekening nasabah
    });

    Route::middleware('role:supervisor')->group(function () {
        Route::get('/supervisor/daily-report', [SupervisorController::class, 'dailyReport']);
    });
});
/////////////////////////////////////////// SCAN QR ////////////////////////////////////////