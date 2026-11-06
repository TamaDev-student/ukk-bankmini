<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminCustomerController;
use App\Http\Controllers\Api\AdminCustomerImportController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminTransactionReportController;
use App\Http\Controllers\Api\AdminTransactionReportExportController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerPasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\SupervisorController;
use App\Http\Controllers\Api\TellerReportExportController;
use App\Http\Controllers\Api\TellerTransactionController;
use App\Http\Controllers\Api\WebAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile Token-Based Authentication
|--------------------------------------------------------------------------
|
| Login untuk klien yang menggunakan autentikasi token.
| Kontrak endpoint mobile yang sudah ada tetap dipertahankan.
|
*/

Route::post('/login', [AuthController::class, 'login']);

// Konfigurasi aturan bisnis bank mini untuk frontend
Route::get('/config/bank', function () {
    return response()->json([
        'min_deposit' => config('bank.min_deposit', 10000),
        'min_holding_balance' => config('bank.min_holding_balance', 10000),
    ]);
});

/*
|--------------------------------------------------------------------------
| Web SPA Session Authentication
|--------------------------------------------------------------------------
|
| Frontend React menggunakan session cookie dan CSRF cookie.
| Endpoint web tidak menerbitkan Bearer Token.
|
*/

// Login web tidak memerlukan session autentikasi sebelumnya.
Route::post('/web/login', [WebAuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Password Reset via Email OTP
|--------------------------------------------------------------------------
|
| Jalur pemulihan password untuk staf (Portal Web) dan nasabah (Mobile).
| Tidak memerlukan autentikasi aktif, namun dibatasi role secara ketat.
|
*/

// Portal Web (Administrator, Teller, Supervisor)
Route::post('/web/forgot-password', [PasswordResetController::class, 'forgotPasswordWeb'])
    ->middleware('throttle:5,1');
Route::post('/web/verify-reset-otp', [PasswordResetController::class, 'verifyOtpWeb'])
    ->middleware('throttle:10,1');
Route::post('/web/reset-password', [PasswordResetController::class, 'resetPasswordWeb'])
    ->middleware('throttle:5,1');

// Customer Mobile (Nasabah via NIS)
Route::post('/customer/forgot-password', [PasswordResetController::class, 'forgotPasswordCustomer'])
    ->middleware('throttle:5,1');
Route::post('/customer/verify-reset-otp', [PasswordResetController::class, 'verifyOtpCustomer'])
    ->middleware('throttle:10,1');
Route::post('/customer/reset-password', [PasswordResetController::class, 'resetPasswordCustomer'])
    ->middleware('throttle:5,1');

Route::middleware(['auth:web', 'account.active'])->group(function () {

    // Logout web dan informasi pengguna yang sedang login.
    Route::post('/web/logout', [WebAuthController::class, 'logout']);

    Route::get('/web/user', [WebAuthController::class, 'user']);

    /*
    |--------------------------------------------------------------------------
    | Activity Log untuk Frontend Web
    |--------------------------------------------------------------------------
    |
    | Hanya administrator dan supervisor yang dapat membaca Activity Log.
    |
    */

    Route::middleware('role:administrator,supervisor')->group(function () {

        Route::get('/web/activity-logs', [
            ActivityLogController::class,
            'index',
        ]);

        Route::get('/web/activity-logs/{id}', [
            ActivityLogController::class,
            'show',
        ]);

    });

});

/*
|--------------------------------------------------------------------------
| Operational & Token-Based API
|--------------------------------------------------------------------------
|
| Menggunakan auth:sanctum untuk mempertahankan autentikasi token
| dan kompatibilitas dengan klien yang didukung Sanctum.
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | General Authentication
    |--------------------------------------------------------------------------
    */

    // Logout untuk klien token (dapat diakses meski akun diblokir agar sesi lokal dapat ditutup).
    Route::post('/logout', [AuthController::class, 'logout']);

    // Mengambil informasi pengguna yang sedang terautentikasi.
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('account.active');

    /*
    |--------------------------------------------------------------------------
    | Staff Operational Routes (Teller, Supervisor, Administrator)
    |--------------------------------------------------------------------------
    |
    | Seluruh rute operasional staf memerlukan akun pengguna yang aktif.
    |
    */

    Route::middleware('account.active')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Teller Routes
        |--------------------------------------------------------------------------
        */

        Route::middleware('role:teller')->group(function () {

            // Setoran.
            Route::post(
                '/teller/deposit',
                [TellerTransactionController::class, 'deposit']
            );

            // Penarikan.
            Route::post(
                '/teller/withdrawal',
                [TellerTransactionController::class, 'withdraw']
            );

            // Detail nasabah berdasarkan nomor rekening.
            Route::get(
                '/teller/customers/{accountNumber}',
                [TellerTransactionController::class, 'showCustomer']
            );

            // Memvalidasi QR rekening dan menerbitkan token setoran sekali pakai.
            Route::post(
                '/teller/customers/{accountNumber}/scan-token',
                [TellerTransactionController::class, 'issueCashDepositScanToken']
            );

            // Rekap laporan harian teller.
            Route::get(
                '/teller/daily-report',
                [TellerTransactionController::class, 'dailyreport']
            );
            Route::get(
                '/teller/daily-report/export',
                [TellerReportExportController::class, 'daily']
            );

            // Mengirim laporan harian.
            Route::post(
                '/teller/submit-daily-report',
                [TellerTransactionController::class, 'submitDailyReport']
            );

            // Daftar laporan harian.
            Route::get(
                '/teller/daily-reports',
                [TellerTransactionController::class, 'dailyReports']
            );

            // Rekonsiliasi kas teller
            Route::get('/teller/cash-reconciliation', [TellerTransactionController::class, 'cashReconciliation']);
            Route::get(
                '/teller/cash-reconciliation/export',
                [TellerReportExportController::class, 'cashReconciliation']
            );
            // menyimpan kas awal
            Route::post('/teller/cash-reconciliation/opening', [TellerTransactionController::class, 'saveOpeningBalance']);
            // menyimpan kas akhir fisik
            Route::post('/teller/cash-reconciliation/closing', [TellerTransactionController::class, 'saveClosingBalance']);

            // Memeriksa status transaksi milik Teller.
            Route::get(
                '/teller/transactions/{id}/status',
                [TellerTransactionController::class, 'transactionStatus']
            );

        });

        /*
        |--------------------------------------------------------------------------
        | Supervisor Routes
        |--------------------------------------------------------------------------
        */

        Route::middleware('role:supervisor')->group(function () {

            // Laporan harian.
            Route::get(
                '/supervisor/daily-report',
                [SupervisorController::class, 'dailyReport']
            );

            // Daftar laporan harian.
            Route::get(
                '/supervisor/daily-reports',
                [SupervisorController::class, 'dailyReports']
            );

            // Menyetujui laporan.
            Route::post(
                '/supervisor/daily-reports/{id}/approve',
                [SupervisorController::class, 'approveDailyReport']
            );

            // Menolak laporan.
            Route::post(
                '/supervisor/daily-reports/{id}/reject',
                [SupervisorController::class, 'rejectDailyReport']
            );

            // Detail laporan.
            Route::get(
                '/supervisor/daily-reports/{id}',
                [SupervisorController::class, 'showDailyReport']
            );
            Route::post(
                '/supervisor/daily-reports/{id}/correct-initial-cash',
                [SupervisorController::class, 'correctInitialCashBalance']
            );

            // Laporan transaksi harian atau bulanan.
            Route::get(
                '/supervisor/reports/transactions',
                AdminTransactionReportController::class
            );
            Route::get(
                '/supervisor/reports/transactions/export',
                AdminTransactionReportExportController::class
            );

        });

        /*
        |--------------------------------------------------------------------------
        | Accounting Journal & Activity Log
        |--------------------------------------------------------------------------
        |
        | Hanya administrator dan supervisor yang dapat mengaksesnya.
        | Endpoint lama /api/activity-logs tetap dipertahankan.
        |
        */

        Route::middleware('role:administrator,supervisor')->group(function () {

            // Jurnal akuntansi.
            Route::get(
                '/supervisor/journals',
                [AccountingController::class, 'journals']
            );
            Route::get(
                '/supervisor/journals/export',
                [AccountingController::class, 'export']
            );

            // Daftar Activity Log.
            Route::get(
                '/activity-logs',
                [ActivityLogController::class, 'index']
            );

            // Detail Activity Log.
            Route::get(
                '/activity-logs/{id}',
                [ActivityLogController::class, 'show']
            );

        });

        /*
        |--------------------------------------------------------------------------
        | Administrator Routes
        |--------------------------------------------------------------------------
        |
        | UC-02: Manajemen pengguna.
        | UC-03: Manajemen rekening nasabah.
        |
        */

        Route::middleware('role:administrator')->group(function () {

            // Dashboard administrator.
            Route::get(
                '/admin/dashboard',
                [AdminDashboardController::class, 'index']
            );

            // Daftar pengguna.
            Route::get(
                '/admin/users',
                [AdminUserController::class, 'index']
            );

            // Menambahkan pengguna.
            Route::post(
                '/admin/users',
                [AdminUserController::class, 'store']
            );

            // Detail pengguna.
            Route::get(
                '/admin/users/{id}',
                [AdminUserController::class, 'show']
            );

            // Memperbarui pengguna.
            Route::put(
                '/admin/users/{id}',
                [AdminUserController::class, 'update']
            );

            // Menghapus pengguna.
            Route::delete(
                '/admin/users/{id}',
                [AdminUserController::class, 'destroy']
            );

            // Daftar rekening nasabah.
            Route::get(
                '/admin/customers',
                [AdminCustomerController::class, 'index']
            );

            // Membuat rekening nasabah.
            Route::post(
                '/admin/customers',
                [AdminCustomerController::class, 'store']
            );

            Route::post(
                '/admin/customers/{id}/temporary-password',
                [AdminCustomerController::class, 'issueTemporaryPassword']
            )->middleware('throttle:5,1');

            Route::get(
                '/admin/customers/pin-reset-history',
                [AdminCustomerController::class, 'pinResetHistory']
            );

            // Detail rekening nasabah.
            Route::get(
                '/admin/customers/{id}',
                [AdminCustomerController::class, 'show']
            );

            // Memperbarui rekening nasabah.
            Route::put(
                '/admin/customers/{id}',
                [AdminCustomerController::class, 'update']
            );

            // Import nasabah Excel, hanya untuk Administrator.
            Route::get(
                '/admin/customers/import/template',
                [AdminCustomerImportController::class, 'template']
            );
            Route::post(
                '/admin/customers/import/preview',
                [AdminCustomerImportController::class, 'preview']
            );
            Route::post(
                '/admin/customers/import/confirm',
                [AdminCustomerImportController::class, 'confirm']
            );

            // Laporan transaksi harian atau bulanan.
            Route::get(
                '/admin/reports/transactions',
                AdminTransactionReportController::class
            );
            Route::get(
                '/admin/reports/transactions/export',
                AdminTransactionReportExportController::class
            );

            // Daftar kelas dan jurusan untuk form pembukaan rekening.
            Route::get(
                '/admin/classes',
                [AdminCustomerController::class, 'classes']
            );

        });

    }); // Menutup Route::middleware('account.active') untuk staff operational routes.

    /*
    |--------------------------------------------------------------------------
    | Customer / Nasabah Routes
    |--------------------------------------------------------------------------
    |
    | Rute read-only (informasi rekening dan riwayat mutasi) tetap dapat
    | diakses ketika rekening diblokir agar nasabah dapat melihat status
    | pemblokiran secara transparan dan melakukan logout. Rute transaksional
    | (pembuatan PIN dan konfirmasi transaksi) membutuhkan rekening aktif.
    |
    */

    Route::middleware(['role:nasabah', 'password.change.required'])->group(function () {

        Route::post(
            '/customer/password/first-change',
            [CustomerPasswordController::class, 'firstChange']
        )->middleware('throttle:5,1')->name('customer.password.first-change');

        // Informasi rekening nasabah (status 'active' atau 'blocked').
        Route::get(
            '/customer/account',
            [CustomerController::class, 'account']
        );

        // Daftar transaksi nasabah.
        Route::get(
            '/customer/transactions',
            [CustomerController::class, 'transactions']
        );

        // Detail transaksi nasabah.
        Route::get(
            '/customer/transactions/{id}',
            [CustomerController::class, 'transactionDetail']
        );

        // Pembatalan transaksi pending milik nasabah yang sedang login.
        Route::post(
            '/customer/transactions/{id}/cancel',
            [CustomerController::class, 'cancelTransaction']
        )->middleware('throttle:15,1');

        // Pengelolaan Email Pemulihan Nasabah
        Route::post(
            '/customer/recovery-email/request',
            [CustomerController::class, 'requestRecoveryEmail']
        )->middleware('throttle:5,1');

        Route::post(
            '/customer/recovery-email/verify',
            [CustomerController::class, 'verifyRecoveryEmail']
        )->middleware('throttle:10,1');
        Route::post(
            '/customer/recovery-email/verify-request',
            [CustomerController::class, 'requestCurrentEmailVerification']
        )->middleware('throttle:5,1');

        Route::post(
            '/customer/pin-reset/otp',
            [CustomerController::class, 'requestPinResetOtp']
        )->middleware('throttle:5,1');
        Route::post(
            '/customer/pin-reset/verify',
            [CustomerController::class, 'verifyPinResetOtp']
        )->middleware('throttle:10,1');
        Route::post(
            '/customer/pin-reset/complete',
            [CustomerController::class, 'completePinReset']
        )->middleware('throttle:5,1');

        // Pengelolaan Device Token FCM Nasabah
        Route::post(
            '/customer/fcm-token',
            [CustomerController::class, 'registerFcmToken']
        );

        Route::delete(
            '/customer/fcm-token',
            [CustomerController::class, 'removeFcmToken']
        );

        // Rute transaksional nasabah (memerlukan akun & rekening aktif).
        Route::middleware('account.active')->group(function () {

            // Pembuatan PIN transaksi nasabah.
            Route::post(
                '/customer/pin',
                [CustomerController::class, 'createPin']
            )->middleware('throttle:5,1');

            // Konfirmasi transaksi teller menggunakan PIN.
            Route::post(
                '/customer/transactions/{id}/confirm',
                [CustomerController::class, 'confirmTransaction']
            )->middleware('throttle:15,1');

        });

    });

});
