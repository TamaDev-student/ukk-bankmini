<?php

use App\Mail\PasswordResetOtpMail;
use App\Models\Customer;
use App\Models\Major;
use App\Models\PasswordResetOtp;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function createAdminForTest(): array
{
    static $idx = 100;
    $idx++;
    $admin = User::create([
        'name' => 'Admin Test '.$idx,
        'username' => 'adm_'.$idx,
        'full_name' => 'Admin Full '.$idx,
        'email' => 'admin_'.$idx.'@bankmini.test',
        'password' => Hash::make('password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);
    $token = $admin->createToken('test')->plainTextToken;

    return [$admin, $token];
}

function createClassForTest(): SchoolClass
{
    $major = Major::firstOrCreate(['name' => 'Rekayasa Perangkat Lunak', 'code' => 'RPL']);

    return SchoolClass::firstOrCreate(['name' => 'XII RPL 1', 'major_id' => $major->id]);
}

test('pembuatan rekening nasabah mewajibkan email', function () {
    [$admin, $token] = createAdminForTest();
    $schoolClass = createClassForTest();
    $nis = 'NIS_NO_EMAIL_'.rand(1000, 9999);

    $response = $this->postJson('/api/admin/customers', [
        'student_number' => $nis,
        'student_name' => 'Siswa Tanpa Email',
        'class_id' => $schoolClass->id,
        'opening_balance' => 15000,
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    $user = User::where('username', $nis)->first();
    expect($user)->toBeNull();
});

test('rekening nasabah dapat dibuat dengan email', function () {
    [$admin, $token] = createAdminForTest();
    $schoolClass = createClassForTest();
    $nis = 'NIS_WITH_EMAIL_'.rand(1000, 9999);
    $email = 'siswa_'.$nis.'@sekolah.test';

    $response = $this->postJson('/api/admin/customers', [
        'student_number' => $nis,
        'student_name' => 'Siswa Beremail',
        'class_id' => $schoolClass->id,
        'opening_balance' => 15000,
        'email' => $email,
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(201);
    $user = User::where('username', $nis)->first();
    expect($user)->not->toBeNull();
    expect($user->email)->toBe(mb_strtolower($email));
    expect($user->email_verified_at)->toBeNull();
});

test('admin dapat mengedit dan mengosongkan email nasabah', function () {
    [$admin, $token] = createAdminForTest();
    $schoolClass = createClassForTest();
    $nis = 'NIS_EDIT_'.rand(1000, 9999);

    $createRes = $this->postJson('/api/admin/customers', [
        'student_number' => $nis,
        'student_name' => 'Siswa Update',
        'class_id' => $schoolClass->id,
        'opening_balance' => 15000,
        'email' => 'initial_'.$nis.'@sekolah.test',
    ], [
        'Authorization' => "Bearer $token",
    ]);
    $createRes->assertStatus(201);
    $customerId = $createRes->json('data.customer.id');

    // Update email baru
    $newEmail = 'updated_'.$nis.'@sekolah.test';
    $updateRes = $this->putJson("/api/admin/customers/{$customerId}", [
        'email' => $newEmail,
    ], [
        'Authorization' => "Bearer $token",
    ]);
    $updateRes->assertStatus(200);
    $user = User::where('username', $nis)->first();
    expect($user->email)->toBe(mb_strtolower($newEmail));
    expect($user->email_verified_at)->toBeNull();

    // Email wajib dipertahankan pada akun Customer Mobile.
    $clearRes = $this->putJson("/api/admin/customers/{$customerId}", [
        'email' => null,
    ], [
        'Authorization' => "Bearer $token",
    ]);
    $clearRes->assertStatus(422)->assertJsonValidationErrors(['email']);
    $user->refresh();
    expect($user->email)->toBe(mb_strtolower($newEmail));
    expect($user->email_verified_at)->toBeNull();
});

test('staf portal web dapat meminta OTP dan menerima email', function () {
    $teller = User::create([
        'name' => 'Teller Satu',
        'username' => 'teller_otp_1',
        'full_name' => 'Teller Satu Full',
        'email' => 'teller1@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ]);
    $teller->forceFill(['email_verified_at' => now()])->save();

    $response = $this->postJson('/api/web/forgot-password', [
        'username' => 'teller_otp_1',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Jika akun memenuhi persyaratan pemulihan, instruksi selanjutnya dan kode OTP telah dikirimkan ke email yang terdaftar.');

    Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($teller) {
        return $mail->hasTo($teller->email);
    });

    $otpRecord = PasswordResetOtp::where('user_id', $teller->id)->latest()->first();
    expect($otpRecord)->not->toBeNull();
    expect($otpRecord->consumed_at)->toBeNull();
    expect($otpRecord->attempts)->toBe(0);
});

test('nasabah tidak dapat menggunakan endpoint pemulihan staf web', function () {
    $nasabah = User::create([
        'name' => 'Nasabah Satu',
        'username' => '10001',
        'full_name' => 'Nasabah Satu Full',
        'email' => 'nasabah1@bankmini.test',
        'password' => Hash::make('10001'),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    // Respon tetap generik (anti-enumeration)
    $response = $this->postJson('/api/web/forgot-password', [
        'username' => '10001',
    ]);
    $response->assertStatus(200);

    // Namun email TIDAK dikirim dan OTP TIDAK dibuat
    Mail::assertNotSent(PasswordResetOtpMail::class);
    expect(PasswordResetOtp::where('user_id', $nasabah->id)->count())->toBe(0);
});

test('nasabah dapat meminta OTP melalui endpoint customer menggunakan NIS', function () {
    $nis = '10002';
    $nasabah = User::create([
        'name' => 'Nasabah Dua',
        'username' => $nis,
        'full_name' => 'Nasabah Dua Full',
        'email' => 'nasabah2@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    $response = $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ]);

    $response->assertStatus(200);

    Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($nasabah) {
        return $mail->hasTo($nasabah->email);
    });
});

test('kegagalan pengiriman OTP membatalkan challenge dan tidak mencatat detail SMTP', function () {
    $nis = '10004';
    $nasabah = User::create([
        'name' => 'Nasabah SMTP Gagal',
        'username' => $nis,
        'full_name' => 'Nasabah SMTP Gagal',
        'email' => 'nasabah-smtp-failed@example.test',
        'email_verified_at' => now(),
        'password' => Hash::make('LegacyPass123'),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    Mail::shouldReceive('to')
        ->once()
        ->with($nasabah->email)
        ->andThrow(new RuntimeException('synthetic SMTP secret'));
    Log::spy();

    $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ])->assertOk();

    $challenge = PasswordResetOtp::where('user_id', $nasabah->id)->latest()->firstOrFail();
    expect($challenge->consumed_at)->not->toBeNull();
    Log::shouldHaveReceived('error')->once()->with(
        'OTP email delivery failed.',
        [
            'exception' => RuntimeException::class,
            'user_id' => $nasabah->id,
            'purpose' => 'password_reset',
        ]
    );
});

test('nasabah tanpa email tidak menerima OTP tetapi respon tetap generik', function () {
    $nis = '10003';
    $nasabah = User::create([
        'name' => 'Nasabah Tanpa Email',
        'username' => $nis,
        'full_name' => 'Nasabah Tanpa Email Full',
        'email' => null,
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ]);

    $response->assertStatus(200);
    Mail::assertNotSent(PasswordResetOtpMail::class);
    expect(PasswordResetOtp::where('user_id', $nasabah->id)->count())->toBe(0);
});

test('nasabah dengan email belum terverifikasi tidak menerima OTP pemulihan password', function () {
    $nasabah = User::create([
        'name' => 'Nasabah Belum Verifikasi',
        'username' => '10009',
        'full_name' => 'Nasabah Belum Verifikasi',
        'email' => 'unverified10009@bankmini.test',
        'password' => Hash::make('10009'),
        'role' => 'nasabah',
        'status' => 'active',
    ]);

    $this->postJson('/api/customer/forgot-password', [
        'student_number' => '10009',
    ])->assertOk();

    Mail::assertNotSent(PasswordResetOtpMail::class);
    expect(PasswordResetOtp::where('user_id', $nasabah->id)->count())->toBe(0);
});

test('verifikasi OTP dan reset password portal web berhasil', function () {
    $admin = User::create([
        'name' => 'Super Admin',
        'username' => 'admin_reset_test',
        'full_name' => 'Super Admin Full',
        'email' => 'admin_reset@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make('oldpassword123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);
    $admin->forceFill(['email_verified_at' => now()])->save();

    // Request OTP
    $this->postJson('/api/web/forgot-password', [
        'username' => 'admin_reset_test',
    ])->assertStatus(200);

    // Dapatkan OTP yang dikirim
    $otpRecord = PasswordResetOtp::where('user_id', $admin->id)->latest()->first();
    expect($otpRecord)->not->toBeNull();

    // Verifikasi OTP salah -> ditolak
    $wrongRes = $this->postJson('/api/web/verify-reset-otp', [
        'username' => 'admin_reset_test',
        'otp' => '000000',
    ]);
    $wrongRes->assertStatus(422)
        ->assertJsonPath('remaining_attempts', 4);

    // Update record dengan OTP yang kita ketahui untuk tes
    $testOtp = '654321';
    $otpRecord->update(['otp_hash' => Hash::make($testOtp)]);

    // Verifikasi OTP benar -> dapat token
    $verifyRes = $this->postJson('/api/web/verify-reset-otp', [
        'username' => 'admin_reset_test',
        'otp' => $testOtp,
    ]);
    $verifyRes->assertStatus(200);
    $resetToken = $verifyRes->json('reset_token');
    expect($resetToken)->not->toBeNull();

    // Reset password dengan password baru
    $resetRes = $this->postJson('/api/web/reset-password', [
        'username' => 'admin_reset_test',
        'reset_token' => $resetToken,
        'password' => 'NewPassword123#',
        'password_confirmation' => 'NewPassword123#',
    ]);
    $resetRes->assertStatus(200);

    // Cek bahwa password lama gagal login dan password baru berhasil
    $admin->refresh();
    expect(Hash::check('oldpassword123', $admin->password))->toBeFalse();
    expect(Hash::check('NewPassword123#', $admin->password))->toBeTrue();

    // Token reset tidak dapat digunakan kembali
    $reuseRes = $this->postJson('/api/web/reset-password', [
        'username' => 'admin_reset_test',
        'reset_token' => $resetToken,
        'password' => 'AnotherPassword123#',
        'password_confirmation' => 'AnotherPassword123#',
    ]);
    $reuseRes->assertStatus(400);
});

test('verifikasi OTP dan reset password nasabah mobile berhasil', function () {
    $nis = '10004';
    $nasabah = User::create([
        'name' => 'Nasabah Mobile Test',
        'username' => $nis,
        'full_name' => 'Nasabah Mobile Full',
        'email' => 'nasabah_mobile@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    // Request OTP
    $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ])->assertStatus(200);

    $testOtp = '112233';
    $otpRecord = PasswordResetOtp::where('user_id', $nasabah->id)->latest()->first();
    $otpRecord->update(['otp_hash' => Hash::make($testOtp)]);

    // Verifikasi OTP
    $verifyRes = $this->postJson('/api/customer/verify-reset-otp', [
        'student_number' => $nis,
        'otp' => $testOtp,
    ]);
    $verifyRes->assertStatus(200);
    $resetToken = $verifyRes->json('reset_token');

    // Reset password
    $resetRes = $this->postJson('/api/customer/reset-password', [
        'student_number' => $nis,
        'reset_token' => $resetToken,
        'password' => 'NewMobilePass123',
        'password_confirmation' => 'NewMobilePass123',
    ]);
    $resetRes->assertStatus(200);

    $nasabah->refresh();
    expect(Hash::check($nis, $nasabah->password))->toBeFalse();
    expect(Hash::check('NewMobilePass123', $nasabah->password))->toBeTrue();
});

test('OTP kedaluwarsa ditolak', function () {
    $nis = '10005';
    $nasabah = User::create([
        'name' => 'Nasabah Expired Test',
        'username' => $nis,
        'full_name' => 'Nasabah Expired Full',
        'email' => 'expired_otp@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    // Request OTP
    $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ])->assertStatus(200);

    $otpRecord = PasswordResetOtp::where('user_id', $nasabah->id)->latest()->first();
    $testOtp = '223344';
    $otpRecord->update([
        'otp_hash' => Hash::make($testOtp),
        'expires_at' => now()->subMinute(), // Kadaluwarsa
    ]);

    $verifyRes = $this->postJson('/api/customer/verify-reset-otp', [
        'student_number' => $nis,
        'otp' => $testOtp,
    ]);

    $verifyRes->assertStatus(400);
});

test('OTP salah lima kali membuat challenge tidak dapat digunakan dan OTP benar setelahnya tetap ditolak', function () {
    $nis = '10006';
    $nasabah = User::create([
        'name' => 'Nasabah Max Attempts Test',
        'username' => $nis,
        'full_name' => 'Nasabah Max Attempts Full',
        'email' => 'max_attempts@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ])->assertStatus(200);

    $correctOtp = '556677';
    $otpRecord = PasswordResetOtp::where('user_id', $nasabah->id)->latest()->first();
    $otpRecord->update(['otp_hash' => Hash::make($correctOtp)]);

    // Coba salah 4 kali
    for ($i = 1; $i <= 4; $i++) {
        $res = $this->postJson('/api/customer/verify-reset-otp', [
            'student_number' => $nis,
            'otp' => '000000',
        ]);
        $res->assertStatus(422)
            ->assertJsonPath('remaining_attempts', 5 - $i);
    }

    // Percobaan ke-5 salah -> batas tercapai
    $res5 = $this->postJson('/api/customer/verify-reset-otp', [
        'student_number' => $nis,
        'otp' => '000000',
    ]);
    $res5->assertStatus(422)
        ->assertJsonPath('remaining_attempts', 0);

    // Coba dengan OTP benar setelah batas tercapai -> tetap ditolak
    $resCorrectAfterLimit = $this->postJson('/api/customer/verify-reset-otp', [
        'student_number' => $nis,
        'otp' => $correctOtp,
    ]);
    $resCorrectAfterLimit->assertStatus(400);
});

test('reset token kedaluwarsa ditolak', function () {
    $nis = '10007';
    $nasabah = User::create([
        'name' => 'Nasabah Expired Token',
        'username' => $nis,
        'full_name' => 'Nasabah Expired Token Full',
        'email' => 'token_expired@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    $rawToken = 'sample_reset_token_expired_123';
    PasswordResetOtp::create([
        'user_id' => $nasabah->id,
        'otp_hash' => Hash::make('123456'),
        'reset_token' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(5),
        'reset_token_expires_at' => now()->subMinute(), // Kadaluwarsa
        'attempts' => 0,
        'consumed_at' => null,
    ]);

    $response = $this->postJson('/api/customer/reset-password', [
        'student_number' => $nis,
        'reset_token' => $rawToken,
        'password' => 'NewSecretPassword123',
        'password_confirmation' => 'NewSecretPassword123',
    ]);

    $response->assertStatus(400);
});

test('OTP lama tidak dapat digunakan setelah challenge sebelumnya digantikan', function () {
    $nis = '10008';
    $nasabah = User::create([
        'name' => 'Nasabah Replaced OTP',
        'username' => $nis,
        'full_name' => 'Nasabah Replaced OTP Full',
        'email' => 'replaced_otp@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make($nis),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    // Challenge 1
    $oldOtp = '111222';
    $challenge1 = PasswordResetOtp::create([
        'user_id' => $nasabah->id,
        'otp_hash' => Hash::make($oldOtp),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
        'consumed_at' => null,
    ]);
    DB::table('password_reset_otps')->where('id', $challenge1->id)->update([
        'created_at' => now()->subSeconds(70), // Melewati 60s cooldown
    ]);

    // Challenge 2 diminta
    $this->postJson('/api/customer/forgot-password', [
        'student_number' => $nis,
    ])->assertStatus(200);

    // Challenge 1 harus sudah dikonsumsi
    $challenge1->refresh();
    expect($challenge1->consumed_at)->not->toBeNull();

    // Verifikasi menggunakan OTP lama harus gagal
    $response = $this->postJson('/api/customer/verify-reset-otp', [
        'student_number' => $nis,
        'otp' => $oldOtp,
    ]);
    $response->assertStatus(422); // Gagal mencocokkan OTP baru
});

test('token autentikasi lama dicabut dan password baru dapat digunakan untuk login', function () {
    $nis = '10009';
    $nasabah = User::create([
        'name' => 'Nasabah Token Invalidate',
        'username' => $nis,
        'full_name' => 'Nasabah Token Invalidate Full',
        'email' => 'token_inv@bankmini.test',
        'email_verified_at' => now(),
        'password' => Hash::make('old_pass_123'),
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $nasabah->forceFill(['email_verified_at' => now()])->save();

    // Buat token autentikasi mobile aktif
    $oldDeviceToken = $nasabah->createToken('MobileDevice')->plainTextToken;
    expect($nasabah->tokens()->count())->toBe(1);

    // Siapkan reset token
    $rawToken = 'test_reset_token_sanctum_invalidation';
    PasswordResetOtp::create([
        'user_id' => $nasabah->id,
        'otp_hash' => Hash::make('123456'),
        'reset_token' => hash('sha256', $rawToken),
        'expires_at' => now()->addMinutes(5),
        'reset_token_expires_at' => now()->addMinutes(15),
        'attempts' => 0,
        'consumed_at' => null,
    ]);

    // Eksekusi reset password
    $res = $this->postJson('/api/customer/reset-password', [
        'student_number' => $nis,
        'reset_token' => $rawToken,
        'password' => 'NewBrandPass2026!',
        'password_confirmation' => 'NewBrandPass2026!',
    ]);
    $res->assertStatus(200);

    // Token Sanctum lama harus telah dihapus
    expect($nasabah->tokens()->count())->toBe(0);

    // Login dengan password lama harus ditolak (401)
    $oldLogin = $this->postJson('/api/login', [
        'username' => $nis,
        'password' => 'old_pass_123',
    ]);
    $oldLogin->assertStatus(401);

    // Login dengan password baru harus berhasil (200)
    $newLogin = $this->postJson('/api/login', [
        'username' => $nis,
        'password' => 'NewBrandPass2026!',
    ]);
    $newLogin->assertStatus(200)
        ->assertJsonStructure(['token', 'user']);
});
