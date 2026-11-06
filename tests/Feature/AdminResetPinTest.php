<?php

use App\Models\Customer;
use App\Models\PasswordResetOtp;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

test('legacy admin PIN reset and approval endpoints are unavailable', function () {
    $admin = User::factory()->create([
        'role' => 'administrator',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create();
    Sanctum::actingAs($admin);

    $this->postJson("/api/admin/customers/{$customer->id}/reset-pin", [
        'pin' => '889900',
        'pin_confirmation' => '889900',
    ])->assertNotFound();

    $this->getJson('/api/admin/pin-reset-requests')->assertNotFound();
    $this->postJson('/api/admin/pin-reset-requests/1/approve')->assertNotFound();
    $this->postJson('/api/admin/pin-reset-requests/1/reject', [
        'admin_notes' => 'Not applicable',
    ])->assertNotFound();

    expect(Hash::check('123456', $customer->fresh()->security_pin))->toBeTrue();
});

test('legacy PIN reset requests cannot be submitted for administrator approval', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/customer/pin-reset-request', [
        'reason' => 'Lupa PIN transaksi',
    ])->assertNotFound();

    expect($customer->pinResetRequests()->count())->toBe(0);
});

test('the initial PIN endpoint cannot replace an existing PIN even with a legacy reset flag', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => Hash::make('123456'),
        'must_change_pin' => true,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/customer/pin', [
        'pin' => '889900',
        'pin_confirmation' => '889900',
    ])->assertStatus(409);

    expect(Hash::check('123456', $customer->fresh()->security_pin))->toBeTrue();
    expect(Hash::check('889900', $customer->fresh()->security_pin))->toBeFalse();
});

test('a customer can still create an initial PIN when none exists', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => null,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/customer/pin', [
        'pin' => '889900',
        'pin_confirmation' => '889900',
    ])->assertCreated();

    expect(Hash::check('889900', $customer->fresh()->security_pin))->toBeTrue();
});

test('initial PIN setup validates digits and confirmation and uses authenticated customer identity', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => null,
    ]);
    $otherCustomer = Customer::factory()->create(['security_pin' => null]);
    Sanctum::actingAs($user);

    $this->postJson('/api/customer/pin', [
        'pin' => '12345',
        'pin_confirmation' => '12345',
    ])->assertUnprocessable();
    $this->postJson('/api/customer/pin', [
        'pin' => '1234567',
        'pin_confirmation' => '1234567',
    ])->assertUnprocessable();
    $this->postJson('/api/customer/pin', [
        'pin' => '123456',
        'pin_confirmation' => '654321',
    ])->assertUnprocessable();

    $this->postJson('/api/customer/pin', [
        'customer_id' => $otherCustomer->id,
        'pin' => '123456',
        'pin_confirmation' => '123456',
    ])->assertCreated();

    expect(Hash::check('123456', $customer->fresh()->security_pin))->toBeTrue()
        ->and($otherCustomer->fresh()->security_pin)->toBeNull();
});

test('account status distinguishes missing PIN, locked PIN, and active PIN without exposing its hash', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => null,
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/customer/account')
        ->assertOk()
        ->assertJsonPath('data.has_pin', false)
        ->assertJsonPath('data.pin_locked', false)
        ->assertJsonPath('data.pin_recovery_in_progress', false)
        ->assertJsonMissingPath('data.security_pin');

    $customer->update([
        'security_pin' => Hash::make('123456'),
        'pin_locked_at' => now(),
        'status' => 'blocked',
    ]);
    PasswordResetOtp::create([
        'user_id' => $user->id,
        'purpose' => 'pin_reset',
        'otp_hash' => Hash::make('111222'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
    ]);

    $this->getJson('/api/customer/account')
        ->assertOk()
        ->assertJsonPath('data.has_pin', true)
        ->assertJsonPath('data.pin_locked', true)
        ->assertJsonPath('data.pin_recovery_in_progress', true)
        ->assertJsonMissingPath('data.security_pin');

    $customer->update([
        'pin_locked_at' => null,
        'status' => 'active',
    ]);
    $this->getJson('/api/customer/account')
        ->assertOk()
        ->assertJsonPath('data.has_pin', true)
        ->assertJsonPath('data.pin_locked', false);
});

test('PIN reset OTP cannot be used for a customer without a previously created PIN', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
        'email' => 'pin-setup-only@example.test',
        'email_verified_at' => now(),
    ]);
    Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => null,
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/customer/pin-reset/otp')
        ->assertStatus(409)
        ->assertJsonPath('message', 'PIN transaksi belum dibuat. Gunakan fitur Buat PIN pada Profil.');

    expect(PasswordResetOtp::where('user_id', $user->id)->count())->toBe(0);
});

test('a customer without a PIN cannot confirm a withdrawal transaction', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => null,
    ]);
    $teller = User::factory()->create([
        'role' => 'teller',
        'status' => 'active',
    ]);
    $transaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => now(),
        'transaction_type' => 'withdrawal',
        'amount' => 20000,
        'status' => 'pending',
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/customer/transactions/{$transaction->id}/confirm", [
        'pin' => '123456',
    ])
        ->assertStatus(409)
        ->assertJsonPath('pin_setup_required', true);

    expect($transaction->fresh()->status)->toBe('pending')
        ->and($customer->fresh()->balance)->toBe(500000);
});

test('a customer with an active PIN can confirm a pending withdrawal', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
        'status' => 'active',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'security_pin' => Hash::make('123456'),
        'balance' => 60000,
    ]);
    $teller = User::factory()->create([
        'role' => 'teller',
        'status' => 'active',
    ]);
    $transaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => now(),
        'transaction_type' => 'withdrawal',
        'amount' => 20000,
        'status' => 'pending',
    ]);
    Sanctum::actingAs($user);

    $this->postJson("/api/customer/transactions/{$transaction->id}/confirm", [
        'pin' => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.balance', 40000);

    expect($customer->fresh()->balance)->toBe(40000)
        ->and($transaction->fresh()->status)->toBe('approved');
});
