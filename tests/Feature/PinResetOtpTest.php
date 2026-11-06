<?php

use App\Mail\PasswordResetOtpMail;
use App\Models\Customer;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function pinResetAccount(bool $verified = true): array
{
    $user = User::create([
        'name' => 'Nasabah PIN',
        'username' => 'pin_reset_'.uniqid(),
        'full_name' => 'Nasabah PIN',
        'email' => 'pin_'.uniqid().'@example.test',
        'email_verified_at' => $verified ? now() : null,
        'password' => 'LoginPassword123!',
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    if ($verified) {
        $user->forceFill(['email_verified_at' => now()])->save();
    }
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => Hash::make('123456'),
        'status' => 'active',
    ]);

    return [$user, $customer, $user->createToken('test')->plainTextToken];
}

test('PIN reset OTP requires a verified email and is tied to pin_reset purpose', function () {
    [$unverified, , $unverifiedToken] = pinResetAccount(false);
    $this->postJson('/api/customer/pin-reset/otp', [], [
        'Authorization' => 'Bearer '.$unverifiedToken,
    ])->assertUnprocessable();
    expect(PasswordResetOtp::where('user_id', $unverified->id)->count())->toBe(0);

    [$user, , $token] = pinResetAccount();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Auth::forgetGuards();
    $accountResponse = $this->getJson('/api/customer/account', [
        'Authorization' => 'Bearer '.$token,
    ]);
    expect($accountResponse->json('data.recovery_email_verified'))->toBeTrue();
    Auth::forgetGuards();
    $otpResponse = $this->postJson('/api/customer/pin-reset/otp', [], [
        'Authorization' => 'Bearer '.$token,
    ]);
    $otpResponse->assertOk();

    $challenge = PasswordResetOtp::where('user_id', $user->id)->firstOrFail();
    expect($challenge->purpose)->toBe('pin_reset');
    expect($challenge->otp_hash)->not->toBe('123456');
    Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email) && $mail->purpose === 'pin_reset';
    });
});

test('password reset OTP cannot authorize a PIN reset', function () {
    [$user, , $token] = pinResetAccount();
    $challenge = PasswordResetOtp::create([
        'user_id' => $user->id,
        'purpose' => 'password_reset',
        'otp_hash' => Hash::make('123789'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
    ]);

    $this->postJson('/api/customer/pin-reset/verify', [
        'otp' => '123789',
    ], ['Authorization' => 'Bearer '.$token])->assertUnprocessable();
    expect($challenge->fresh()->reset_token)->toBeNull();

    $pinChallenge = PasswordResetOtp::create([
        'user_id' => $user->id,
        'purpose' => 'pin_reset',
        'otp_hash' => Hash::make('123790'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
    ]);

    $this->postJson('/api/customer/verify-reset-otp', [
        'student_number' => $user->username,
        'otp' => '123790',
    ])->assertUnprocessable();
    expect($pinChallenge->fresh()->reset_token)->toBeNull();
});

test('nasabah dapat mengganti PIN satu kali setelah OTP benar', function () {
    [$user, $customer, $token] = pinResetAccount();
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->postJson('/api/customer/pin-reset/otp', [], $headers)->assertOk();
    $challenge = PasswordResetOtp::where('user_id', $user->id)->firstOrFail();
    $challenge->update(['otp_hash' => Hash::make('654321')]);

    $verified = $this->postJson('/api/customer/pin-reset/verify', [
        'otp' => '654321',
    ], $headers)->assertOk();
    $resetToken = $verified->json('data.reset_token');
    expect($resetToken)->toBeString()->not->toBeEmpty();

    $this->postJson('/api/customer/pin-reset/complete', [
        'reset_token' => $resetToken,
        'pin' => '123456',
        'pin_confirmation' => '123456',
    ], $headers)->assertUnprocessable();

    $this->postJson('/api/customer/pin-reset/complete', [
        'reset_token' => $resetToken,
        'pin' => '987654',
        'pin_confirmation' => '987654',
    ], $headers)->assertOk();

    $customer->refresh();
    expect(Hash::check('987654', $customer->security_pin))->toBeTrue();
    expect(Hash::check('123456', $customer->security_pin))->toBeFalse();

    $this->postJson('/api/customer/pin-reset/complete', [
        'reset_token' => $resetToken,
        'pin' => '567890',
        'pin_confirmation' => '567890',
    ], $headers)->assertUnprocessable();

    $admin = User::create([
        'name' => 'Admin Pengawas',
        'username' => 'pin_history_admin',
        'full_name' => 'Admin Pengawas',
        'email' => 'pin-history-admin@example.test',
        'password' => 'AdminPassword123!',
        'role' => 'administrator',
        'status' => 'active',
    ]);
    Auth::forgetGuards();
    $history = $this->getJson('/api/admin/customers/pin-reset-history', [
        'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
    ])->assertOk();
    expect($history->json('data.0.status'))->toBe('Berhasil');
    $historyJson = json_encode($history->json());
    expect($historyJson)->not->toContain('654321');
    foreach (['otp', 'otp_hash', 'security_pin', 'password', 'reset_token'] as $sensitiveField) {
        expect($historyJson)->not->toContain('"'.$sensitiveField.'"');
    }
});

test('berhasilnya reset PIN membuka kunci PIN tanpa mengganti password login', function () {
    [$user, $customer, $token] = pinResetAccount();
    $customer->update([
        'status' => 'blocked',
        'pin_locked_at' => now(),
    ]);
    $originalPassword = $user->password;
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->postJson('/api/customer/pin-reset/otp', [], $headers)->assertOk();
    $challenge = PasswordResetOtp::where('user_id', $user->id)->firstOrFail();
    $challenge->update(['otp_hash' => Hash::make('111222')]);
    $resetToken = $this->postJson('/api/customer/pin-reset/verify', [
        'otp' => '111222',
    ], $headers)->assertOk()->json('data.reset_token');

    expect($customer->fresh()->status)->toBe('blocked');
    expect($customer->fresh()->pin_locked_at)->not->toBeNull();

    $this->postJson('/api/customer/pin-reset/complete', [
        'reset_token' => 'invalid-reset-token',
        'pin' => '654321',
        'pin_confirmation' => '654321',
    ], $headers)->assertUnprocessable();
    expect($customer->fresh()->status)->toBe('blocked');
    expect($customer->fresh()->pin_locked_at)->not->toBeNull();

    $this->postJson('/api/customer/pin-reset/complete', [
        'reset_token' => $resetToken,
        'pin' => '654321',
        'pin_confirmation' => '654321',
    ], $headers)->assertOk();

    expect($customer->fresh()->status)->toBe('active');
    expect($customer->fresh()->pin_locked_at)->toBeNull();
    expect($user->fresh()->password)->toBe($originalPassword);
});
