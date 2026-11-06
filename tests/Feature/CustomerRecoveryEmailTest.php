<?php

use App\Mail\CustomerRecoveryEmailOtpMail;
use App\Models\Customer;
use App\Models\CustomerRecoveryEmailChallenge;
use App\Models\FcmDeviceToken;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function setupCustomerForRecoveryTest(): array
{
    static $counter = 2000;
    $counter++;
    $major = Major::firstOrCreate(['name' => 'TKJ', 'code' => 'TKJ_'.$counter]);
    $class = SchoolClass::firstOrCreate([
        'name' => 'XII TKJ '.$counter,
        'major_id' => $major->id,
    ]);

    $nis = 'NIS_REC_'.$counter;
    $password = 'password123';

    $user = User::create([
        'name' => 'Nasabah Recovery '.$counter,
        'username' => $nis,
        'full_name' => 'Nasabah Recovery Full '.$counter,
        'email' => 'old_email_'.$counter.'@test.com',
        'email_verified_at' => now(),
        'password' => Hash::make($password),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();

    $customer = Customer::create([
        'user_id' => $user->id,
        'account_number' => 'REK_REC_'.$counter,
        'student_number' => $nis,
        'student_name' => 'Nasabah Recovery '.$counter,
        'class_id' => $class->id,
        'status' => 'active',
        'security_pin' => Hash::make('112233'),
        'balance' => 100000,
    ]);

    $token = $user->createToken('test')->plainTextToken;

    return [$customer, $user, $token, $password];
}

test('nasabah dapat meminta OTP untuk mengganti email pemulihan dengan password yang benar', function () {
    Mail::fake();
    [$customer, $user, $token, $password] = setupCustomerForRecoveryTest();

    $newEmail = 'baru_'.rand(1000, 9999).'@domain.com';

    $response = $this->postJson('/api/customer/recovery-email/request', [
        'email' => $newEmail,
        'password' => $password,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Kode verifikasi email telah dikirimkan ke email baru Anda.',
        ]);

    $challengeId = $response->json('data.challenge_id');
    $challenge = CustomerRecoveryEmailChallenge::where('user_id', $user->id)
        ->whereNull('consumed_at')
        ->first();

    expect($challenge)->not->toBeNull()
        ->and($challengeId)->toBe($challenge->id);
    expect($challenge->pending_email)->toBe($newEmail);
});

test('nasabah dapat memverifikasi email dari data akun tanpa mengganti alamat tujuan request', function () {
    Mail::fake();
    [$customer, $user, $token] = setupCustomerForRecoveryTest();
    $user->forceFill(['email_verified_at' => null])->save();
    $this->getJson('/api/customer/account', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $response = $this->postJson('/api/customer/recovery-email/verify-request', [
        'email' => 'attacker@example.test',
    ], ['Authorization' => 'Bearer '.$token]);
    $response->assertOk()
        ->assertJsonStructure(['data' => ['challenge_id']]);

    $challenge = CustomerRecoveryEmailChallenge::where('user_id', $user->id)
        ->whereNull('consumed_at')
        ->firstOrFail();
    expect($challenge->pending_email)->toBe($user->email);
    Mail::assertSent(CustomerRecoveryEmailOtpMail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('kode OTP yang dikirim untuk verifikasi email terdaftar dapat langsung diverifikasi', function () {
    Mail::fake();
    [$customer, $user, $token] = setupCustomerForRecoveryTest();
    $user->forceFill(['email_verified_at' => null])->save();

    $request = $this->postJson('/api/customer/recovery-email/verify-request', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonStructure(['data' => ['challenge_id']]);

    $mail = Mail::sent(CustomerRecoveryEmailOtpMail::class)->first();
    expect($mail)->not->toBeNull()
        ->and($mail->otp)->toMatch('/^\d{6}$/');

    $challenge = CustomerRecoveryEmailChallenge::query()
        ->where('user_id', $user->id)
        ->whereNull('consumed_at')
        ->firstOrFail();

    expect(Hash::check($mail->otp, $challenge->otp_hash))->toBeTrue();

    $this->postJson('/api/customer/recovery-email/verify', [
        'otp' => $mail->otp,
        'challenge_id' => $request->json('data.challenge_id'),
    ], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('data.is_verified', true);
});

test('kode OTP untuk permintaan lain tidak menggantikan OTP yang sedang diverifikasi', function () {
    Mail::fake();
    [$customer, $user, $token, $password] = setupCustomerForRecoveryTest();
    $user->forceFill(['email_verified_at' => null])->save();
    $headers = ['Authorization' => 'Bearer '.$token];

    $request = $this->postJson('/api/customer/recovery-email/verify-request', [], $headers)
        ->assertOk();

    $this->postJson('/api/customer/recovery-email/request', [
        'email' => 'other_'.rand(1000, 9999).'@domain.com',
        'password' => $password,
    ], $headers)->assertStatus(429);

    $mail = Mail::sent(CustomerRecoveryEmailOtpMail::class)->first();
    expect($mail)->not->toBeNull();

    $this->postJson('/api/customer/recovery-email/verify', [
        'otp' => $mail->otp,
        'challenge_id' => $request->json('data.challenge_id'),
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_verified', true);
});

test('permintaan email pemulihan ditolak jika password akun salah', function () {
    Mail::fake();
    [$customer, $user, $token] = setupCustomerForRecoveryTest();

    $response = $this->postJson('/api/customer/recovery-email/request', [
        'email' => 'valid_email@test.com',
        'password' => 'wrong_password',
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Password akun yang Anda masukkan salah.',
        ]);
});

test('nasabah berhasil memverifikasi OTP dan email pada tabel users diperbarui', function () {
    Mail::fake();
    [$customer, $user, $token, $password] = setupCustomerForRecoveryTest();

    $newEmail = 'verified_'.rand(1000, 9999).'@domain.com';
    $otp = '654321';

    CustomerRecoveryEmailChallenge::create([
        'user_id' => $user->id,
        'pending_email' => $newEmail,
        'otp_hash' => Hash::make($otp),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
    ]);

    $response = $this->postJson('/api/customer/recovery-email/verify', [
        'otp' => $otp,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Email pemulihan berhasil diverifikasi dan disimpan.',
            'data' => [
                'is_verified' => true,
            ],
        ]);

    $user->refresh();
    expect($user->email)->toBe($newEmail);
    expect($user->email_verified_at)->not->toBeNull();

    // Pastikan challenge telah dikonsumsi
    $challenge = CustomerRecoveryEmailChallenge::where('user_id', $user->id)->first();
    expect($challenge->consumed_at)->not->toBeNull();
});

test('verifikasi OTP ditolak jika kode salah', function () {
    Mail::fake();
    [$customer, $user, $token] = setupCustomerForRecoveryTest();

    $otp = '123456';
    CustomerRecoveryEmailChallenge::create([
        'user_id' => $user->id,
        'pending_email' => 'target@test.com',
        'otp_hash' => Hash::make($otp),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
    ]);

    $response = $this->postJson('/api/customer/recovery-email/verify', [
        'otp' => '999999',
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['message', 'remaining_attempts']);
});

test('nasabah dapat mendaftarkan dan menghapus token FCM', function () {
    [$customer, $user, $token] = setupCustomerForRecoveryTest();

    $deviceToken = 'sample_fcm_token_'.rand(1000, 9999);

    // Register token
    $resRegister = $this->postJson('/api/customer/fcm-token', [
        'token' => $deviceToken,
        'device_type' => 'android',
    ], ['Authorization' => "Bearer $token"]);

    $resRegister->assertStatus(200);

    expect(FcmDeviceToken::where('user_id', $user->id)->where('token', $deviceToken)->exists())->toBeTrue();

    // Delete token
    $resDelete = $this->deleteJson('/api/customer/fcm-token', [
        'token' => $deviceToken,
    ], ['Authorization' => "Bearer $token"]);

    $resDelete->assertStatus(200);

    expect(FcmDeviceToken::where('user_id', $user->id)->where('token', $deviceToken)->exists())->toBeFalse();
});
