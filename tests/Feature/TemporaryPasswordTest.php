<?php

use App\Models\Customer;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function temporaryPasswordAdmin(): array
{
    $user = User::create([
        'name' => 'Administrator Password Test',
        'username' => 'admin-temporary-password',
        'full_name' => 'Administrator Password Test',
        'email' => 'temporary-password-admin@example.test',
        'password' => 'AdminPass123',
        'role' => 'administrator',
        'status' => 'active',
    ]);

    return [$user, $user->createToken('test')->plainTextToken];
}

function temporaryPasswordCustomer(array $overrides = []): User
{
    $attributes = array_merge([
        'name' => 'Nasabah Password Test',
        'username' => 'customer-temporary-password',
        'full_name' => 'Nasabah Password Test',
        'email' => 'customer-temporary-password@example.test',
        'password' => 'TemporaryPass123',
        'role' => 'nasabah',
        'status' => 'active',
    ], $overrides);
    $mustChangePassword = $attributes['must_change_password'] ?? true;
    $expiresAt = $attributes['temporary_password_expires_at'] ?? now()->addDays(7);
    unset($attributes['must_change_password'], $attributes['temporary_password_expires_at']);

    $user = User::create($attributes);
    $user->forceFill([
        'must_change_password' => $mustChangePassword,
        'temporary_password_expires_at' => $expiresAt,
    ])->save();

    return $user;
}

test('login pertama mewajibkan ganti password dan mempertahankan PIN transaksi', function () {
    $user = temporaryPasswordCustomer();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $pinHash = $customer->security_pin;
    $token = $user->createToken('test')->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->getJson('/api/customer/account', $headers)
        ->assertForbidden()
        ->assertJsonPath('must_change_password', true);

    $this->postJson('/api/customer/password/first-change', [
        'password' => 'Weakpass1',
        'password_confirmation' => 'Weakpass1',
    ], $headers)->assertUnprocessable();

    $this->postJson('/api/customer/password/first-change', [
        'password' => 'PermanentPass123',
        'password_confirmation' => 'PermanentPass123',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('must_change_password', false);

    $user->refresh();
    expect($user->must_change_password)->toBeFalse();
    expect($user->temporary_password_expires_at)->toBeNull();
    expect(Hash::check('PermanentPass123', $user->password))->toBeTrue();
    expect(Hash::check('TemporaryPass123', $user->password))->toBeFalse();
    expect($customer->fresh()->security_pin)->toBe($pinHash);

    $this->getJson('/api/customer/account', $headers)->assertOk();
    $this->postJson('/api/login', [
        'username' => $user->username,
        'password' => 'TemporaryPass123',
    ])->assertUnauthorized();
    $this->postJson('/api/login', [
        'username' => $user->username,
        'password' => 'PermanentPass123',
    ])->assertOk()->assertJsonPath('user.must_change_password', false);
});

test('password sementara kedaluwarsa tidak dapat digunakan dan admin dapat menerbitkan ulang', function () {
    [$admin, $adminToken] = temporaryPasswordAdmin();
    $user = temporaryPasswordCustomer([
        'username' => 'expired-temporary-password',
        'email' => 'expired-temporary-password@example.test',
        'temporary_password_expires_at' => now()->subSecond(),
    ]);
    $customer = Customer::factory()->create(['user_id' => $user->id]);

    $this->postJson('/api/login', [
        'username' => $user->username,
        'password' => 'TemporaryPass123',
    ])->assertForbidden()
        ->assertJsonPath('temporary_password_expired', true);

    $response = $this->postJson(
        "/api/admin/customers/{$customer->id}/temporary-password",
        [],
        ['Authorization' => 'Bearer '.$adminToken]
    )->assertOk();

    $temporaryPassword = $response->json('data.temporary_password');
    expect($temporaryPassword)->toBeString()->not->toBe($user->username);
    $user->refresh();
    expect(Hash::check($temporaryPassword, $user->password))->toBeTrue();
    expect($user->must_change_password)->toBeTrue();
    expect($user->temporary_password_expires_at->isFuture())->toBeTrue();

    $this->postJson('/api/login', [
        'username' => $user->username,
        'password' => $temporaryPassword,
    ])->assertOk()->assertJsonPath('user.must_change_password', true);
});

test('pembuatan akun menghasilkan password acak satu kali dan mempertahankan akun lama', function () {
    [$admin, $adminToken] = temporaryPasswordAdmin();
    $major = Major::firstOrCreate(
        ['code' => 'RPL'],
        ['name' => 'Rekayasa Perangkat Lunak']
    );
    $schoolClass = SchoolClass::firstOrCreate([
        'name' => 'X RPL 1',
        'major_id' => $major->id,
    ]);
    $headers = ['Authorization' => 'Bearer '.$adminToken];

    $create = fn (string $studentNumber) => $this->postJson('/api/admin/customers', [
        'student_number' => $studentNumber,
        'student_name' => 'Siswa '.$studentNumber,
        'class_id' => $schoolClass->id,
        'opening_balance' => 10000,
        'email' => strtolower($studentNumber).'@example.test',
    ], $headers);

    $first = $create('TEMP-ACCOUNT-1')->assertCreated();
    $second = $create('TEMP-ACCOUNT-2')->assertCreated();
    $firstPassword = $first->json('data.login.temporary_password');
    $secondPassword = $second->json('data.login.temporary_password');

    expect($firstPassword)->toBeString()->not->toBe('TEMP-ACCOUNT-1');
    expect($secondPassword)->toBeString()->not->toBe('TEMP-ACCOUNT-2');
    expect($firstPassword)->not->toBe($secondPassword);
    expect($first->json('data.login.expires_at'))->not->toBeNull();
    expect($first->getContent())->not->toContain('password_hash');

    foreach (['TEMP-ACCOUNT-1' => $firstPassword, 'TEMP-ACCOUNT-2' => $secondPassword] as $username => $password) {
        $user = User::where('username', $username)->firstOrFail();
        expect($user->password)->not->toBe($password);
        expect(Hash::check($password, $user->password))->toBeTrue();
        expect($user->must_change_password)->toBeTrue();
        expect($user->temporary_password_expires_at->isFuture())->toBeTrue();
    }

    $legacy = User::create([
        'name' => 'Nasabah Lama',
        'username' => 'legacy-customer',
        'full_name' => 'Nasabah Lama',
        'email' => 'legacy-customer@example.test',
        'password' => 'LegacyPass123',
        'role' => 'nasabah',
        'status' => 'active',
    ]);

    $this->postJson('/api/login', [
        'username' => $legacy->username,
        'password' => 'LegacyPass123',
    ])->assertOk()->assertJsonPath('user.must_change_password', false);
});
