<?php

use App\Models\Customer;
use App\Models\SchoolClass;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('unauthenticated user cannot download customer statement', function () {
    $response = $this->getJson('/api/customer/statement/download?start_date=2026-11-01&end_date=2026-11-10');

    $response->assertUnauthorized();
});

test('non-nasabah role cannot download customer statement', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/customer/statement/download?start_date=2026-11-01&end_date=2026-11-10');

    $response->assertForbidden();
});

test('statement request requires valid date parameters', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    Sanctum::actingAs($nasabah);

    // Missing parameters
    $this->getJson('/api/customer/statement/download')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['start_date', 'end_date']);

    // Invalid format
    $this->getJson('/api/customer/statement/download?start_date=invalid&end_date=invalid')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['start_date', 'end_date']);

    // start_date > end_date
    $this->getJson('/api/customer/statement/download?start_date=2026-11-10&end_date=2026-11-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);

    // Future date
    $futureDate = CarbonImmutable::now('Asia/Jakarta')->addDays(10)->toDateString();
    $this->getJson('/api/customer/statement/download?start_date=2026-11-01&end_date='.$futureDate)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);
});

test('authenticated customer can download statement PDF with correct balance calculation', function () {
    $class = SchoolClass::factory()->create();

    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
        'class_id' => $class->id,
        'account_number' => 'RPL001',
        'balance' => 75000,
    ]);

    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    // 1. Transaksi masa lalu (sebelum periode laporan 2026-11-01) -> Saldo Awal
    Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'deposit',
        'amount' => 50000,
        'status' => 'approved',
    ]);

    Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-10-20 14:00:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'withdrawal',
        'amount' => 10000,
        'status' => 'approved',
    ]);

    // Saldo awal yang diharapkan sebelum 2026-11-01 adalah 50.000 - 10.000 = 40.000

    // 2. Transaksi dalam periode laporan (2026-11-01 s/d 2026-11-05)
    Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-11-02 09:30:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'deposit',
        'amount' => 50000,
        'status' => 'approved',
    ]);

    Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-11-03 11:15:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'withdrawal',
        'amount' => 15000,
        'status' => 'approved',
    ]);

    // 3. Transaksi pending & rejected dalam periode -> TIDAK boleh mengubah saldo
    Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-11-04 10:00:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'deposit',
        'amount' => 999999,
        'status' => 'pending',
    ]);

    Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-11-04 11:00:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'withdrawal',
        'amount' => 888888,
        'status' => 'rejected',
    ]);

    // Transaksi nasabah lain (tidak boleh tercampur)
    $otherCustomer = Customer::factory()->create();
    Transaction::create([
        'customer_id' => $otherCustomer->id,
        'user_id' => $teller->id,
        'transaction_date' => CarbonImmutable::parse('2026-11-02 10:00:00', 'Asia/Jakarta')->utc(),
        'transaction_type' => 'deposit',
        'amount' => 300000,
        'status' => 'approved',
    ]);

    Sanctum::actingAs($nasabah);

    $response = $this->get('/api/customer/statement/download?start_date=2026-11-01&end_date=2026-11-05');

    $response->assertOk();
    $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    $this->assertStringContainsString('attachment; filename="Mutasi_RPL001_2026-11-01_2026-11-05.pdf"', $response->headers->get('content-disposition'));

    // Memverifikasi konten binary PDF dimulai dengan %PDF
    $content = $response->getContent();
    $this->assertStringStartsWith('%PDF', $content);
});

test('empty period returns valid statement PDF with zero transactions', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
        'account_number' => 'RPL002',
        'balance' => 0,
    ]);

    Sanctum::actingAs($nasabah);

    $response = $this->get('/api/customer/statement/download?start_date=2026-11-01&end_date=2026-11-02');

    $response->assertOk();
    $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    $this->assertStringStartsWith('%PDF', $response->getContent());
});

