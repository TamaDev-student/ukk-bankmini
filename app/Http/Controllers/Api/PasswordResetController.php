<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class PasswordResetController extends Controller
{
    protected ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    /**
     * Respon generik pemulihan password untuk mencegah enumerasi akun.
     */
    protected const GENERIC_FORGOT_MESSAGE = 'Jika akun memenuhi persyaratan pemulihan, instruksi selanjutnya dan kode OTP telah dikirimkan ke email yang terdaftar.';

    // =========================================================================
    // PORTAL WEB (Administrator, Teller, Supervisor)
    // =========================================================================

    /**
     * Meminta OTP pemulihan password untuk staf portal web.
     */
    public function forgotPasswordWeb(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
        ]);

        $username = trim($validated['username']);
        $throttleKey = 'forgot-web:'.Str::lower($username).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Terlalu banyak permintaan pemulihan. Silakan coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, 600);

        $allowedRoles = ['administrator', 'teller', 'supervisor'];
        $user = User::where('username', $username)
            ->whereIn('role', $allowedRoles)
            ->first();

        $this->processOtpGeneration($user);

        return response()->json([
            'message' => self::GENERIC_FORGOT_MESSAGE,
        ]);
    }

    /**
     * Verifikasi kode OTP untuk portal web.
     */
    public function verifyOtpWeb(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $username = trim($validated['username']);
        $throttleKey = 'verify-web:'.Str::lower($username).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Terlalu banyak percobaan verifikasi. Silakan coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, 300);

        $allowedRoles = ['administrator', 'teller', 'supervisor'];
        $user = User::where('username', $username)
            ->whereIn('role', $allowedRoles)
            ->first();

        return $this->processOtpVerification($user, $validated['otp'], $throttleKey);
    }

    /**
     * Simpan password baru untuk portal web setelah OTP terverifikasi.
     */
    public function resetPasswordWeb(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'reset_token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        $username = trim($validated['username']);
        $allowedRoles = ['administrator', 'teller', 'supervisor'];
        $user = User::where('username', $username)
            ->whereIn('role', $allowedRoles)
            ->first();

        return $this->processPasswordReset($user, $validated['reset_token'], $validated['password']);
    }

    // =========================================================================
    // CUSTOMER MOBILE (Nasabah)
    // =========================================================================

    /**
     * Meminta OTP pemulihan password untuk nasabah (menggunakan NIS / student_number).
     */
    public function forgotPasswordCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:50'],
        ]);

        $studentNumber = trim($validated['student_number']);
        $throttleKey = 'forgot-cust:'.Str::lower($studentNumber).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Terlalu banyak permintaan pemulihan. Silakan coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, 600);

        // Nasabah diidentifikasi melalui NIS yang tersimpan sebagai username pada user dengan role nasabah
        $user = User::where('username', $studentNumber)
            ->where('role', 'nasabah')
            ->first();

        $this->processOtpGeneration($user);

        return response()->json([
            'message' => self::GENERIC_FORGOT_MESSAGE,
        ]);
    }

    /**
     * Verifikasi kode OTP untuk nasabah.
     */
    public function verifyOtpCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:50'],
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $studentNumber = trim($validated['student_number']);
        $throttleKey = 'verify-cust:'.Str::lower($studentNumber).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Terlalu banyak percobaan verifikasi. Silakan coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, 300);

        $user = User::where('username', $studentNumber)
            ->where('role', 'nasabah')
            ->first();

        return $this->processOtpVerification($user, $validated['otp'], $throttleKey);
    }

    /**
     * Simpan password baru nasabah setelah OTP terverifikasi.
     */
    public function resetPasswordCustomer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_number' => ['required', 'string', 'max:50'],
            'reset_token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        $studentNumber = trim($validated['student_number']);
        $user = User::where('username', $studentNumber)
            ->where('role', 'nasabah')
            ->first();

        return $this->processPasswordReset($user, $validated['reset_token'], $validated['password']);
    }

    // =========================================================================
    // INTERNAL HELPER METHODS
    // =========================================================================

    /**
     * Memproses penerbitan OTP dan pengiriman email secara aman.
     */
    /**
     * Memproses penerbitan OTP dan pengiriman email secara aman.
     */
    protected function processOtpGeneration(?User $user, string $purpose = 'password_reset'): void
    {
        // Jangan menerbitkan OTP untuk akun yang tidak memenuhi syarat.
        if (
            ! $user ||
            empty($user->email) ||
            ($user->role === 'nasabah' && ! $user->email_verified_at) ||
            $user->status !== 'active'
        ) {
            return;
        }

        // Cegah penerbitan OTP berulang dalam 60 detik.
        $recentOtp = PasswordResetOtp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds(60))
            ->whereNull('consumed_at')
            ->first();

        if ($recentOtp) {
            return;
        }

        $challenge = null;

        try {
            // Generate OTP enam digit.
            $otp = (string) random_int(100000, 999999);

            // Simpan challenge terlebih dahulu di database.
            $challenge = DB::transaction(function () use ($user, $otp, $purpose) {
                PasswordResetOtp::where('user_id', $user->id)
                    ->where('purpose', $purpose)
                    ->whereNull('consumed_at')
                    ->update([
                        'consumed_at' => now(),
                    ]);

                return PasswordResetOtp::create([
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'otp_hash' => Hash::make($otp),
                    'expires_at' => now()->addMinutes(5),
                    'attempts' => 0,
                    'consumed_at' => null,
                ]);
            });

            // Kirim email setelah transaksi database berhasil.
            Mail::to($user->email)->send(
                new PasswordResetOtpMail(
                    $otp,
                    $user->full_name ?? $user->name,
                    $purpose
                )
            );

            // Beritahu pengguna melalui push notification FCM bahwa OTP telah dikirim ke email
            try {
                app(FcmService::class)->sendToUser(
                    $user,
                    'Kode Verifikasi Dikirim',
                    'Kode OTP pemulihan password telah dikirim ke email Anda. Silakan periksa kotak masuk email Anda.',
                    ['type' => 'otp']
                );
            } catch (Throwable $fcmError) {
                report($fcmError);
            }
        } catch (Throwable $e) {
            Log::error('OTP email delivery failed.', [
                'exception' => $e::class,
                'user_id' => $user->id,
                'purpose' => $purpose,
            ]);

            // Batalkan challenge apabila proses pengiriman gagal.
            if ($challenge instanceof PasswordResetOtp) {
                try {
                    $challenge->update([
                        'consumed_at' => now(),
                        'reset_token' => null,
                    ]);
                } catch (Throwable $cleanupException) {
                    Log::error('Failed to invalidate an undelivered OTP challenge.', [
                        'exception' => $cleanupException::class,
                        'challenge_id' => $challenge->id,
                    ]);
                }
            }
        }
    }

    /**
     * Memproses verifikasi OTP terhadap challenge aktif.
     */
    /**
     * Memproses verifikasi OTP terhadap challenge aktif.
     */
    protected function processOtpVerification(
        ?User $user,
        string $otpInput,
        string $throttleKey,
        string $purpose = 'password_reset'
    ): JsonResponse {
        if (! $user) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau telah kedaluwarsa. Silakan ajukan permintaan baru.',
            ], 400);
        }

        $result = DB::transaction(function () use ($user, $otpInput, $purpose) {
            // Kunci challenge agar perubahan bersamaan tidak saling menimpa.
            $challenge = PasswordResetOtp::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('reset_token')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return [
                    'status' => 400,
                    'message' => 'Kode OTP tidak valid atau telah kedaluwarsa. Silakan ajukan permintaan baru.',
                ];
            }

            // Maksimal lima percobaan.
            if ($challenge->attempts >= 5) {
                $challenge->update([
                    'consumed_at' => now(),
                ]);

                return [
                    'status' => 422,
                    'message' => 'Batas percobaan verifikasi telah tercapai. Silakan ajukan kode OTP baru.',
                    'remaining_attempts' => 0,
                ];
            }

            // Verifikasi OTP terhadap hash yang tersimpan.
            if (! Hash::check($otpInput, $challenge->otp_hash)) {
                $challenge->attempts++;
                $remaining = max(0, 5 - $challenge->attempts);

                if ($remaining === 0) {
                    $challenge->consumed_at = now();
                }

                $challenge->save();

                if ($remaining === 0) {
                    return [
                        'status' => 422,
                        'message' => 'Batas percobaan verifikasi telah tercapai. Silakan ajukan kode OTP baru.',
                        'remaining_attempts' => 0,
                    ];
                }

                return [
                    'status' => 422,
                    'message' => "Kode OTP salah. Sisa percobaan: {$remaining}.",
                    'remaining_attempts' => $remaining,
                ];
            }

            // Buat token pemulihan yang berlaku selama 15 menit.
            $resetToken = Str::random(64);

            // Simpan hash token, bukan token aslinya.
            $challenge->update([
                'reset_token' => hash('sha256', $resetToken),
                'reset_token_expires_at' => now()->addMinutes(15),
            ]);

            return [
                'status' => 200,
                'message' => 'Kode OTP berhasil diverifikasi.',
                'reset_token' => $resetToken,
            ];
        });

        if ($result['status'] === 200) {
            RateLimiter::clear($throttleKey);
        }

        $status = $result['status'];
        unset($result['status']);

        return response()->json($result, $status);
    }

    /**
     * Memproses update password baru dan invalidasi sesi/token lama.
     */
    /**
     * Memproses update password baru dan invalidasi sesi/token lama.
     */
    protected function processPasswordReset(
        ?User $user,
        string $resetToken,
        string $newPassword,
        string $purpose = 'password_reset'
    ): JsonResponse {
        if (! $user) {
            return response()->json([
                'message' => 'Sesi pemulihan tidak valid atau telah kedaluwarsa. Silakan ulangi proses pemulihan.',
            ], 400);
        }

        $success = DB::transaction(function () use ($user, $resetToken, $newPassword, $purpose) {
            // Cari token menggunakan hash dan kunci challenge.
            $challenge = PasswordResetOtp::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('reset_token', hash('sha256', $resetToken))
                ->whereNull('consumed_at')
                ->where('reset_token_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return false;
            }

            // Kunci akun untuk menghindari pembaruan bersamaan.
            $lockedUser = User::whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedUser) {
                return false;
            }

            // Model User menggunakan cast hashed untuk password.
            $lockedUser->password = $newPassword;
            $lockedUser->must_change_password = false;
            $lockedUser->temporary_password_expires_at = null;
            $lockedUser->save();

            // Konsumsi token sehingga tidak bisa digunakan kembali.
            $challenge->update([
                'consumed_at' => now(),
                'reset_token' => null,
            ]);

            // Cabut token Sanctum lama.
            $lockedUser->tokens()->delete();

            // Invalidate sesi web jika tabel sessions tersedia.
            DB::table('sessions')
                ->where('user_id', $lockedUser->id)
                ->delete();

            // Catat aktivitas tanpa memasukkan password atau token.
            $this->activityLogService->log(
                action: 'auth.password_reset',
                description: 'Pengguna berhasil mengatur ulang password melalui verifikasi OTP email.',
                entityType: 'User',
                entityId: $lockedUser->id,
                user: $lockedUser
            );

            return true;
        });

        if (! $success) {
            return response()->json([
                'message' => 'Sesi pemulihan tidak valid atau telah kedaluwarsa. Silakan ulangi proses pemulihan.',
            ], 400);
        }

        return response()->json([
            'message' => 'Password berhasil diperbarui. Silakan login menggunakan password baru.',
        ]);
    }
}
