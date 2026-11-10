<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CustomerRecoveryEmailOtpMail;
use App\Mail\PasswordResetOtpMail;
use App\Models\Customer;
use App\Models\CustomerRecoveryEmailChallenge;
use App\Models\PasswordResetOtp;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerStatementService;
use App\Services\FcmService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Display the authenticated customer's account information.
     */
    public function account(Request $request): JsonResponse
    {
        $user = $request->user();

        $customer = $user->customer()->first();

        if (! $customer) {
            return response()->json([
                'message' => 'No customer account is linked to this user.',
            ], 404);
        }

        $effectiveStatus = (
            $user->status === 'blocked' ||
            $customer->status === 'blocked'
        )
            ? 'blocked'
            : $customer->status;

        $hasRecoveryEmail = ! empty($user->email);

        $emailVerified = $hasRecoveryEmail &&
            $user->email_verified_at !== null;

        $pinRecoveryInProgress = $customer->pinResetRequests()
            ->where('status', 'pending')
            ->exists();
        if ($customer->security_pin !== null) {
            $pinRecoveryInProgress = $pinRecoveryInProgress
                || PasswordResetOtp::query()
                    ->where('user_id', $user->id)
                    ->where('purpose', 'pin_reset')
                    ->whereNull('consumed_at')
                    ->where(function ($query) {
                        $query->where('expires_at', '>', now())
                            ->orWhere('reset_token_expires_at', '>', now());
                    })
                    ->exists();
        }

        return response()->json([
            'message' => 'Customer account retrieved successfully.',
            'data' => [
                'account_number' => $customer->account_number,
                'student_number' => $customer->student_number,
                'student_name' => $customer->student_name,
                'class_name' => $customer->schoolClass?->name ?? '-',
                'balance' => $customer->balance,
                'status' => $effectiveStatus,
                'has_pin' => $customer->security_pin !== null,
                'pin_locked' => $customer->pin_locked_at !== null,
                'pin_recovery_in_progress' => $pinRecoveryInProgress,
                'must_change_pin' => (bool) ($customer->must_change_pin ?? false),
                'has_pending_pin_reset' => $customer->pinResetRequests()->where('status', 'pending')->exists(),
                'latest_pin_reset_status' => $customer->pinResetRequests()->latest()->value('status'),

                // Recovery Email Information
                'recovery_email' => $hasRecoveryEmail
                    ? $this->maskRecoveryEmail($user->email)
                    : null,

                'recovery_email_verified' => $emailVerified,
            ],
        ]);
    }

    /**
     * Mask email address for privacy.
     *
     * Example:
     * andi.pratama@gmail.com
     * becomes:
     *
     * and***@gmail.com
     */
    private function maskRecoveryEmail(?string $email): ?string
    {
        if (empty($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $email, 2);

        if ($localPart === '' || $domain === '') {
            return null;
        }

        $visibleLength = min(
            3,
            max(1, mb_strlen($localPart) - 1)
        );

        $maskedLocal = mb_substr($localPart, 0, $visibleLength);

        return $maskedLocal.'***@'.$domain;
    }

    /**
     * Display the authenticated customer's transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'No customer account is linked to this user.',
            ], 404);
        }

        $transactions = $customer->transactions()
            ->select([
                'id',
                'transaction_date',
                'transaction_type',
                'amount',
                'status',
                'rejection_reason',
            ])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Transaction history retrieved successfully.',
            'data' => $transactions,
        ]);
    }

    /**
     * Unduh laporan mutasi rekening nasabah dalam format dokumen PDF.
     */
    public function downloadStatement(
        Request $request,
        CustomerStatementService $statementService
    ): Response|JsonResponse {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'No customer account is linked to this user.',
            ], 404);
        }

        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();

        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
                'before_or_equal:'.$today,
            ],
        ], [
            'start_date.required' => 'Tanggal awal periode mutasi wajib diisi.',
            'start_date.date_format' => 'Format tanggal awal harus berupa YYYY-MM-DD.',
            'end_date.required' => 'Tanggal akhir periode mutasi wajib diisi.',
            'end_date.date_format' => 'Format tanggal akhir harus berupa YYYY-MM-DD.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.',
            'end_date.before_or_equal' => 'Tanggal akhir tidak boleh melebihi hari ini.',
        ]);

        return $statementService->generatePdfResponse(
            $customer,
            $validated['start_date'],
            $validated['end_date']
        );
    }

    /**
     * Display a transaction belonging to the authenticated customer.
     */
    public function transactionDetail(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'No customer account is linked to this user.',
            ], 404);
        }

        $transaction = $customer->transactions()
            ->select([
                'id',
                'transaction_date',
                'transaction_type',
                'amount',
                'status',
                'rejection_reason',
            ])
            ->whereKey($id)
            ->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Transaction not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Transaction details retrieved successfully.',
            'data' => $transaction,
        ]);
    }

    /**
     * Membatalkan transaksi pending milik nasabah yang sedang login.
     */
    public function cancelTransaction(
        Request $request,
        int $id,
        TransactionService $transactionService
    ): JsonResponse {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'Akun pengguna tidak terhubung dengan rekening nasabah.',
            ], 404);
        }

        $transaction = $transactionService->cancel(
            $id,
            $customer->id,
            $user->id
        );

        return response()->json([
            'message' => 'Transaksi berhasil dibatalkan.',
            'data' => [
                'id' => $transaction->id,
                'transaction_date' => $transaction->transaction_date,
                'transaction_type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'rejection_reason' => $transaction->rejection_reason,
                'cancelled_at' => $transaction->cancelled_at,
            ],
        ]);
    }

    /**
     * Mengonfirmasi transaksi menggunakan PIN nasabah.
     */
    public function confirmTransaction(
        Request $request,
        int $id,
        TransactionService $transactionService
    ): JsonResponse {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'Akun pengguna tidak terhubung dengan rekening nasabah.',
            ], 404);
        }

        if ($customer->pin_locked_at !== null) {
            return response()->json([
                'message' => 'PIN transaksi terkunci. Gunakan fitur Lupa PIN untuk memulihkan akses transaksi.',
                'pin_locked' => true,
            ], 403);
        }

        if ($customer->security_pin === null) {
            return response()->json([
                'message' => 'PIN transaksi belum dibuat. Buat PIN 6 digit melalui Profil sebelum mengonfirmasi transaksi.',
                'pin_setup_required' => true,
            ], 409);
        }

        // Periksa apakah rekening atau akun sedang diblokir.
        if ($customer->status === 'blocked' || $user->status === 'blocked') {
            return response()->json([
                'message' => 'Rekening atau akun Anda telah diblokir. Silakan hubungi Admin Bank Mini Sekolah.',
                'is_blocked' => true,
                'remaining_attempts' => 0,
            ], 403);
        }

        // Pastikan transaksi milik nasabah yang sedang login.
        $transaction = Transaction::query()
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Transaksi tidak ditemukan.',
            ], 404);
        }

        // Hanya transaksi pending yang dapat dikonfirmasi.
        if ($transaction->status !== 'pending') {
            return response()->json([
                'message' => 'Transaksi ini sudah diproses sebelumnya.',
            ], 422);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:6'],
        ]);

        $cacheKey = "customer_pin_failed_attempts_{$customer->id}";

        // Verifikasi PIN di backend.
        if (! Hash::check($validated['pin'], $customer->security_pin)) {
            $attempts = (int) Cache::get($cacheKey, 0) + 1;
            Cache::put($cacheKey, $attempts, now()->addHours(24));

            if ($attempts >= 3) {
                // Blokir rekening dan akun nasabah secara otomatis setelah 3x salah PIN
                $customer->update([
                    'status' => 'blocked',
                    'pin_locked_at' => now(),
                ]);
                Cache::forget($cacheKey);

                return response()->json([
                    'message' => 'PIN salah 3 kali dan terkunci. Tetap login, lalu gunakan fitur Lupa PIN untuk memulihkan akses transaksi.',
                    'remaining_attempts' => 0,
                    'is_blocked' => true,
                ], 403);
            }

            $remaining = 3 - $attempts;

            return response()->json([
                'message' => "PIN yang dimasukkan salah. Sisa percobaan: {$remaining} kali.",
                'remaining_attempts' => $remaining,
                'is_blocked' => false,
            ], 422);
        }

        // Jika PIN benar, reset penghitung kegagalan
        Cache::forget($cacheKey);

        // Service kembali memeriksa status dan kepemilikan
        // di dalam transaksi database yang terkunci.
        $transaction = $transactionService->approve(
            $id,
            $customer->id
        );

        // Kirim push notification FCM jika transaksi berhasil
        try {
            $formattedAmount = 'Rp'.number_format($transaction->amount, 0, ',', '.');
            if ($transaction->transaction_type === 'deposit') {
                app(FcmService::class)->sendToUser(
                    $user,
                    'Setoran Berhasil',
                    "Setoran Anda sebesar {$formattedAmount} berhasil diproses.",
                    [
                        'type' => 'transaction',
                        'transaction_id' => (string) $transaction->id,
                    ]
                );
            } elseif ($transaction->transaction_type === 'withdrawal') {
                app(FcmService::class)->sendToUser(
                    $user,
                    'Penarikan Berhasil',
                    "Penarikan Anda sebesar {$formattedAmount} berhasil diproses.",
                    [
                        'type' => 'transaction',
                        'transaction_id' => (string) $transaction->id,
                    ]
                );
            }
        } catch (\Throwable $fcmError) {
            report($fcmError);
        }

        return response()->json([
            'message' => 'Transaksi berhasil dikonfirmasi.',
            'data' => [
                'id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'approved_at' => $transaction->approved_at,
                'balance' => $customer->fresh()->balance,
            ],
        ]);
    }

    /**
     * Membuat PIN transaksi untuk nasabah yang sedang login.
     */
    public function createPin(Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json([
                'message' => 'Akun pengguna tidak terhubung dengan rekening nasabah.',
            ], 404);
        }

        if ($customer->status !== 'active' || $user->status !== 'active') {
            return response()->json([
                'message' => 'Akun atau rekening tidak aktif. Silakan hubungi Admin Bank Mini Sekolah.',
            ], 403);
        }

        if ($customer->pin_locked_at !== null) {
            return response()->json([
                'message' => 'PIN transaksi terkunci. Gunakan fitur Lupa PIN untuk memulihkan akses transaksi.',
            ], 409);
        }

        // PIN yang sudah ada hanya dapat diganti melalui pemulihan OTP.
        if ($customer->security_pin !== null) {
            return response()->json([
                'message' => 'PIN transaksi sudah pernah dibuat.',
            ], 409);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:6'],
            'pin_confirmation' => ['required', 'string', 'same:pin', 'digits:6'],
        ]);

        // Lindungi perubahan PIN dari request bersamaan.
        $created = DB::transaction(
            function () use ($customer, $validated) {
                $lockedCustomer = Customer::query()
                    ->whereKey($customer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedCustomer->status !== 'active' || $lockedCustomer->pin_locked_at !== null) {
                    return false;
                }

                // Periksa ulang setelah memperoleh lock.
                if ($lockedCustomer->security_pin !== null) {
                    return false;
                }

                $lockedCustomer->security_pin = Hash::make($validated['pin']);
                $lockedCustomer->must_change_pin = false;
                $lockedCustomer->save();

                Cache::forget("customer_pin_failed_attempts_{$customer->id}");

                return true;
            },
            3
        );

        if (! $created) {
            return response()->json([
                'message' => 'PIN tidak dapat dibuat. Periksa status rekening atau pastikan PIN belum pernah dibuat.',
            ], 409);
        }

        return response()->json([
            'message' => 'PIN transaksi berhasil dibuat.',
        ], 201);
    }

    public function requestPinResetOtp(Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json(['message' => 'Akun nasabah tidak ditemukan.'], 404);
        }

        if ($customer->security_pin === null) {
            return response()->json([
                'message' => 'PIN transaksi belum dibuat. Gunakan fitur Buat PIN pada Profil.',
            ], 409);
        }

        if (! $user->email || ! $user->email_verified_at) {
            return response()->json([
                'message' => 'Verifikasi email pribadi Anda sebelum mengatur ulang PIN.',
            ], 422);
        }

        $throttleKey = 'pin-reset-request:'.$user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return response()->json([
                'message' => 'Terlalu banyak permintaan OTP. Silakan coba lagi nanti.',
            ], 429);
        }
        RateLimiter::hit($throttleKey, 600);

        $recentOtp = PasswordResetOtp::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'pin_reset')
            ->where('created_at', '>', now()->subSeconds(60))
            ->first();

        if ($recentOtp) {
            return response()->json([
                'message' => 'Tunggu satu menit sebelum meminta OTP baru.',
            ], 429);
        }

        $otp = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($user, $otp) {
            PasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', 'pin_reset')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'reset_token' => null]);

            return PasswordResetOtp::create([
                'user_id' => $user->id,
                'purpose' => 'pin_reset',
                'otp_hash' => Hash::make($otp),
                'expires_at' => now()->addMinutes(5),
                'attempts' => 0,
            ]);
        });

        try {
            Mail::to($user->email)->send(new PasswordResetOtpMail(
                $otp,
                $user->full_name ?? $user->name,
                'pin_reset'
            ));
        } catch (\Throwable $exception) {
            report($exception);
            $challenge->update(['consumed_at' => now()]);

            return response()->json([
                'message' => 'OTP tidak dapat dikirim saat ini. Silakan coba lagi nanti.',
            ], 503);
        }

        app(ActivityLogService::class)->log(
            action: 'customer.pin_reset_requested',
            description: 'Nasabah meminta OTP untuk pemulihan PIN transaksi.',
            entityType: 'Customer',
            entityId: $customer->id,
            user: $user
        );

        return response()->json([
            'message' => 'Kode OTP telah dikirim ke email terverifikasi Anda.',
            'data' => ['recovery_email' => $this->maskRecoveryEmail($user->email)],
        ]);
    }

    public function verifyPinResetOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'digits:6'],
        ]);
        $user = $request->user();
        $throttleKey = 'pin-reset-verify:'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            return response()->json([
                'message' => 'Terlalu banyak percobaan OTP. Silakan coba lagi nanti.',
            ], 429);
        }
        RateLimiter::hit($throttleKey, 300);

        $result = DB::transaction(function () use ($user, $validated) {
            $challenge = PasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', 'pin_reset')
                ->whereNull('consumed_at')
                ->whereNull('reset_token')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->attempts >= 5) {
                if ($challenge) {
                    $challenge->update(['consumed_at' => now()]);
                }

                return ['status' => 422, 'message' => 'OTP tidak valid atau telah kedaluwarsa.'];
            }

            if (! Hash::check($validated['otp'], $challenge->otp_hash)) {
                $challenge->attempts++;
                if ($challenge->attempts >= 5) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();

                return ['status' => 422, 'message' => 'OTP salah atau telah kedaluwarsa.'];
            }

            $resetToken = Str::random(64);
            $challenge->update([
                'reset_token' => hash('sha256', $resetToken),
                'reset_token_expires_at' => now()->addMinutes(10),
            ]);

            return ['status' => 200, 'reset_token' => $resetToken];
        });

        if ($result['status'] === 200) {
            RateLimiter::clear($throttleKey);
            unset($result['status']);

            return response()->json([
                'message' => 'OTP berhasil diverifikasi.',
                'data' => $result,
            ]);
        }

        $status = $result['status'];
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function completePinReset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reset_token' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'string', 'digits:6'],
            'pin_confirmation' => ['required', 'string', 'same:pin', 'digits:6'],
        ]);
        $user = $request->user();
        $customer = $user->customer;

        if (! $customer) {
            return response()->json(['message' => 'Akun nasabah tidak ditemukan.'], 404);
        }

        $updated = DB::transaction(function () use ($user, $customer, $validated) {
            $challenge = PasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', 'pin_reset')
                ->where('reset_token', hash('sha256', $validated['reset_token']))
                ->whereNull('consumed_at')
                ->where('reset_token_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return false;
            }

            $lockedCustomer = Customer::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->first();
            if (
                ! $lockedCustomer
                || $lockedCustomer->security_pin === null
                || Hash::check($validated['pin'], $lockedCustomer->security_pin)
            ) {
                return false;
            }

            $lockedCustomer->security_pin = Hash::make($validated['pin']);
            $lockedCustomer->must_change_pin = false;
            if ($lockedCustomer->pin_locked_at !== null) {
                $lockedCustomer->status = 'active';
                $lockedCustomer->pin_locked_at = null;
            }
            $lockedCustomer->save();

            $challenge->update(['consumed_at' => now(), 'reset_token' => null]);

            return true;
        });

        if (! $updated) {
            return response()->json([
                'message' => 'Otorisasi reset tidak valid, kedaluwarsa, atau PIN baru tidak dapat digunakan.',
            ], 422);
        }

        Cache::forget("customer_pin_failed_attempts_{$customer->id}");
        app(ActivityLogService::class)->log(
            action: 'customer.pin_reset_completed',
            description: 'Nasabah berhasil memperbarui PIN transaksi setelah verifikasi OTP.',
            entityType: 'Customer',
            entityId: $customer->id,
            user: $user
        );

        return response()->json(['message' => 'PIN transaksi berhasil diperbarui.']);
    }

    /**
     * Meminta kode OTP untuk menambahkan atau mengubah email pemulihan nasabah.
     */
    public function requestRecoveryEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'nasabah') {
            return response()->json([
                'message' => 'Hanya nasabah yang dapat mengelola email pemulihan.',
            ], 403);
        }

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Password akun yang Anda masukkan salah.',
            ], 422);
        }

        $requestKey = 'recovery-email-request:'.$user->id;
        if (RateLimiter::tooManyAttempts($requestKey, 3)) {
            return response()->json(['message' => 'Terlalu banyak permintaan OTP. Silakan coba lagi nanti.'], 429);
        }
        $recentChallenge = CustomerRecoveryEmailChallenge::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subSeconds(60))
            ->exists();
        if ($recentChallenge) {
            return response()->json(['message' => 'Tunggu satu menit sebelum meminta OTP baru.'], 429);
        }
        RateLimiter::hit($requestKey, 600);

        // Batalkan challenge aktif sebelumnya yang belum dikonsumsi
        CustomerRecoveryEmailChallenge::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = CustomerRecoveryEmailChallenge::create([
            'user_id' => $user->id,
            'pending_email' => $validated['email'],
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        try {
            Mail::to($validated['email'])->send(
                new CustomerRecoveryEmailOtpMail($otp, $user->username)
            );
        } catch (\Throwable $e) {
            report($e);
            CustomerRecoveryEmailChallenge::where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return response()->json([
                'message' => 'Kode verifikasi tidak dapat dikirim saat ini. Silakan coba lagi nanti.',
            ], 503);
        }

        try {
            app(FcmService::class)->sendToUser(
                $user,
                'Kode Verifikasi Dikirim',
                'Kode verifikasi telah dikirim ke email pemulihan Anda. Silakan periksa kotak masuk untuk melanjutkan proses.',
                ['type' => 'otp']
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'Kode verifikasi email telah dikirimkan ke email baru Anda.',
            'data' => ['challenge_id' => $challenge->id],
        ]);
    }

    public function requestCurrentEmailVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'nasabah') {
            return response()->json(['message' => 'Hanya nasabah yang dapat mengelola email.'], 403);
        }
        if (! $user->email) {
            return response()->json(['message' => 'Email pribadi belum terdaftar.'], 422);
        }
        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email sudah terverifikasi.'], 409);
        }

        $key = 'recovery-email-request:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Terlalu banyak permintaan OTP. Silakan coba lagi nanti.'], 429);
        }
        $recent = CustomerRecoveryEmailChallenge::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subSeconds(60))
            ->exists();
        if ($recent) {
            return response()->json(['message' => 'Tunggu satu menit sebelum meminta OTP baru.'], 429);
        }
        RateLimiter::hit($key, 600);

        CustomerRecoveryEmailChallenge::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = CustomerRecoveryEmailChallenge::create([
            'user_id' => $user->id,
            'pending_email' => $user->email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        try {
            Mail::to($user->email)->send(
                new CustomerRecoveryEmailOtpMail($otp, $user->username)
            );
        } catch (\Throwable $exception) {
            report($exception);
            $challenge->update(['consumed_at' => now()]);

            return response()->json([
                'message' => 'Kode verifikasi tidak dapat dikirim saat ini. Silakan coba lagi nanti.',
            ], 503);
        }

        return response()->json([
            'message' => 'Kode verifikasi dikirim ke email terdaftar.',
            'data' => [
                'email' => $this->maskRecoveryEmail($user->email),
                'challenge_id' => $challenge->id,
            ],
        ]);
    }

    /**
     * Memverifikasi kode OTP dan memperbarui email pemulihan nasabah.
     */
    public function verifyRecoveryEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'nasabah') {
            return response()->json([
                'message' => 'Hanya nasabah yang dapat mengelola email pemulihan.',
            ], 403);
        }

        $validated = $request->validate([
            'otp' => ['required', 'string', 'digits:6'],
            'challenge_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $verifyKey = 'email-verification-verify:'.$user->id;
        if (RateLimiter::tooManyAttempts($verifyKey, 10)) {
            return response()->json(['message' => 'Terlalu banyak percobaan OTP. Silakan coba lagi nanti.'], 429);
        }
        RateLimiter::hit($verifyKey, 300);

        $result = DB::transaction(function () use ($user, $validated) {
            $challengeQuery = CustomerRecoveryEmailChallenge::query()
                ->where('user_id', $user->id)
                ->lockForUpdate();

            if (isset($validated['challenge_id'])) {
                $challengeQuery->whereKey($validated['challenge_id']);
            } else {
                $challengeQuery
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->latest();
            }

            $challenge = $challengeQuery->first();

            if (! $challenge) {
                return ['status' => 422, 'message' => 'Kode verifikasi tidak ditemukan atau sudah kedaluwarsa. Silakan ajukan kembali.'];
            }
            if ($challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
                return ['status' => 422, 'message' => 'Kode OTP ini sudah tidak berlaku. Minta kode baru dan gunakan kode dari email terbaru.'];
            }
            if ($challenge->attempts >= 5) {
                $challenge->update(['consumed_at' => now()]);

                return ['status' => 429, 'message' => 'Batas percobaan telah habis. Silakan ajukan kode verifikasi baru.'];
            }
            if (! Hash::check($validated['otp'], $challenge->otp_hash)) {
                $challenge->attempts++;
                if ($challenge->attempts >= 5) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();
                $remaining = max(0, 5 - $challenge->attempts);

                return [
                    'status' => 422,
                    'message' => "Kode verifikasi salah. Sisa percobaan: {$remaining} kali.",
                    'remaining_attempts' => $remaining,
                ];
            }

            $newEmail = $challenge->pending_email;
            if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
                $challenge->update(['consumed_at' => now()]);

                return ['status' => 422, 'message' => 'Email sudah digunakan oleh akun lain.'];
            }

            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $emailChanged = $lockedUser->email !== $newEmail;
            $lockedUser->email = $newEmail;
            $lockedUser->email_verified_at = now();
            $lockedUser->save();
            $challenge->update(['consumed_at' => now()]);

            if ($emailChanged) {
                PasswordResetOtp::query()
                    ->where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now(), 'reset_token' => null]);
            }

            return [
                'status' => 200,
                'data' => [
                    'email' => $this->maskRecoveryEmail($newEmail),
                    'is_verified' => true,
                ],
            ];
        });

        if ($result['status'] !== 200) {
            $status = $result['status'];
            unset($result['status']);

            return response()->json($result, $status);
        }
        RateLimiter::clear($verifyKey);

        return response()->json([
            'message' => 'Email pemulihan berhasil diverifikasi dan disimpan.',
            'data' => $result['data'],
        ]);
    }

    /**
     * Mendaftarkan FCM device token milik nasabah.
     */
    public function registerFcmToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'device_type' => ['nullable', 'string', 'max:50'],
        ]);

        app(FcmService::class)->registerToken(
            $user,
            $validated['token'],
            $validated['device_type'] ?? 'android'
        );

        return response()->json([
            'message' => 'Device token FCM berhasil didaftarkan.',
        ]);
    }

    /**
     * Menghapus FCM device token milik nasabah saat logout.
     */
    public function removeFcmToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'max:500'],
        ]);

        app(FcmService::class)->removeToken(
            $user,
            $validated['token'] ?? null
        );

        return response()->json([
            'message' => 'Device token FCM berhasil dihapus.',
        ]);
    }
}
