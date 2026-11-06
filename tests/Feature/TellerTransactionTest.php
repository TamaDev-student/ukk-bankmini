<?php

use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Journal;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

function tellerWithTokenForTransactionTest(): array
{
    static $counter = 0;
    $counter++;

    $teller = User::create([
        'name' => 'Teller '.$counter,
        'username' => 'teller_txn_'.$counter,
        'full_name' => 'Teller Txn '.$counter,
        'email' => 'teller_txn_'.$counter.'@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ]);
    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->toDateString(),
        'status' => 'draft',
        'opening_balance' => 100000,
        'opening_balance_source' => 'first_day_teller',
        'opening_balance_recorded_by' => $teller->id,
        'opening_balance_recorded_at' => now(),
        'opening_physical_balance' => 100000,
        'opening_difference' => 0,
    ]);

    $token = $teller->createToken('test')->plainTextToken;

    return [$teller, $token];
}

function setupCustomerForTransaction()
{
    $major = Major::firstOrCreate(['name' => 'Test Major', 'code' => 'TST']);
    $schoolClass = SchoolClass::firstOrCreate(['name' => '10 TST', 'major_id' => $major->id]);

    $customer = Customer::create([
        'account_number' => 'TST001',
        'student_number' => '123456789',
        'student_name' => 'Test Student',
        'class_id' => $schoolClass->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 0,
    ]);

    return $customer;
}

function issueScanTokenForTransactionTest(TestCase $test, string $authToken, Customer $customer): string
{
    app('auth')->forgetGuards();

    $response = $test->postJson(
        '/api/teller/customers/'.$customer->account_number.'/scan-token',
        [],
        ['Authorization' => 'Bearer '.$authToken]
    );

    $response->assertOk();

    return $response->json('data.scan_token');
}

test('teller can deposit and journals are recorded', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();

    $response = $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 50000,
        'scan_token' => issueScanTokenForTransactionTest($this, $token, $customer),
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('message', 'Setoran berhasil')
        ->assertJsonPath('balance', 50000);

    expect($customer->fresh()->balance)->toBe(50000);

    $transaction = Transaction::where('customer_id', $customer->id)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(50000);
    expect($transaction->transaction_type)->toBe('deposit');

    $journals = Journal::where('transaction_id', $transaction->id)->get();
    expect($journals)->toHaveCount(2);

    $debitJournal = $journals->where('type', 'debit')->first();
    expect($debitJournal->account_name)->toBe('Kas');
    expect($debitJournal->amount)->toBe(50000);

    $creditJournal = $journals->where('type', 'credit')->first();
    expect($creditJournal->account_name)->toBe('Tabungan Nasabah');
    expect($creditJournal->amount)->toBe(50000);
});

test('teller can withdraw with valid pin and sufficient balance', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update(['balance' => 60000]);

    $response = $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 20000,
        'pin' => '123456',
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Penarikan berhasil')
        ->assertJsonPath('balance', 40000);

    expect($customer->fresh()->balance)->toBe(40000);

    $transaction = Transaction::where('customer_id', $customer->id)->where('transaction_type', 'withdrawal')->first();
    expect($transaction)->not->toBeNull();

    $journals = Journal::where('transaction_id', $transaction->id)->get();
    expect($journals)->toHaveCount(2);

    $debitJournal = $journals->where('type', 'debit')->first();
    expect($debitJournal->account_name)->toBe('Tabungan Nasabah');
    expect($debitJournal->amount)->toBe(20000);

    $creditJournal = $journals->where('type', 'credit')->first();
    expect($creditJournal->account_name)->toBe('Kas');
    expect($creditJournal->amount)->toBe(20000);
});

test('withdrawal is rejected without a PIN or when the PIN is locked', function () {
    [, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update([
        'security_pin' => null,
        'balance' => 60000,
    ]);

    $headers = ['Authorization' => 'Bearer '.$token];
    $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 20000,
    ], $headers)
        ->assertStatus(409)
        ->assertJsonPath('pin_setup_required', true);

    expect(Transaction::where('customer_id', $customer->id)->count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(60000);

    $customer->update([
        'security_pin' => Hash::make('123456'),
        'pin_locked_at' => now(),
        'status' => 'blocked',
    ]);
    $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 20000,
    ], $headers)
        ->assertForbidden()
        ->assertJsonPath('pin_locked', true);

    expect(Transaction::where('customer_id', $customer->id)->count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(60000);
});

test('withdrawal rejected if balance falls below 10000', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update(['balance' => 25000]);

    $response = $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 20000,
        'pin' => '123456',
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'Saldo tidak mencukupi');

    // Balance shouldn't change
    expect($customer->fresh()->balance)->toBe(25000);

    // No transaction created
    $transactionCount = Transaction::where('customer_id', $customer->id)->count();
    expect($transactionCount)->toBe(0);
});

test('withdrawal rejected with invalid pin', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update(['balance' => 50000]);

    $response = $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'pin' => '000000', // wrong pin
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'PIN keamanan tidak valid');

    expect($customer->fresh()->balance)->toBe(50000);
});

test('multiple deposits accumulate balance correctly', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 30000,
        'scan_token' => issueScanTokenForTransactionTest($this, $token, $customer),
    ], ['Authorization' => "Bearer $token"])->assertStatus(201);

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 20000,
        'scan_token' => issueScanTokenForTransactionTest($this, $token, $customer),
    ], ['Authorization' => "Bearer $token"])->assertStatus(201);

    expect($customer->fresh()->balance)->toBe(50000);

    $transactionCount = Transaction::where('customer_id', $customer->id)->count();
    expect($transactionCount)->toBe(2);

    $journalCount = Journal::whereIn(
        'transaction_id',
        Transaction::where('customer_id', $customer->id)->pluck('id')
    )->count();
    expect($journalCount)->toBe(4); // 2 journals per deposit
});

test('failed withdrawal leaves no partial state in balance transaction or journal', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update(['balance' => 15000]);

    $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 10000, // would leave 5000 < 10000 minimum
        'pin' => '123456',
    ], ['Authorization' => "Bearer $token"])->assertStatus(400);

    expect($customer->fresh()->balance)->toBe(15000);
    expect(Transaction::where('customer_id', $customer->id)->count())->toBe(0);
    expect(Journal::count())->toBe(0);
});

test('non-teller user cannot perform deposit', function () {
    $admin = User::create([
        'name' => 'Admin Txn',
        'username' => 'admin_txn_dep',
        'full_name' => 'Admin Txn Dep',
        'email' => 'admin_txn_dep@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);
    $token = $admin->createToken('test')->plainTextToken;
    $customer = setupCustomerForTransaction();

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 50000,
    ], ['Authorization' => "Bearer $token"])->assertStatus(403);

    expect($customer->fresh()->balance)->toBe(0);
});

test('non-teller user cannot perform withdrawal', function () {
    $admin = User::create([
        'name' => 'Admin Txn W',
        'username' => 'admin_txn_wdr',
        'full_name' => 'Admin Txn Wdr',
        'email' => 'admin_txn_wdr@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);
    $token = $admin->createToken('test')->plainTextToken;
    $customer = setupCustomerForTransaction();
    $customer->update(['balance' => 50000]);

    $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'pin' => '123456',
    ], ['Authorization' => "Bearer $token"])->assertStatus(403);

    expect($customer->fresh()->balance)->toBe(50000);
});

test('unauthenticated user cannot perform deposit', function () {
    $customer = setupCustomerForTransaction();

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 50000,
    ])->assertStatus(401);
});

test('withdrawal exactly at minimum balance boundary succeeds', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update(['balance' => 30000]);

    // Withdraw 20000, leaving exactly 10000 (minimum)
    $response = $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 20000,
        'pin' => '123456',
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Penarikan berhasil')
        ->assertJsonPath('balance', 10000);

    expect($customer->fresh()->balance)->toBe(10000);
});

test('transaction records the correct teller user_id', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 50000,
        'scan_token' => issueScanTokenForTransactionTest($this, $token, $customer),
    ], ['Authorization' => "Bearer $token"])->assertStatus(201);

    $transaction = Transaction::where('customer_id', $customer->id)->first();
    expect($transaction->user_id)->toBe($teller->id);
});

test('deposit rejected for blocked customer account', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $scanToken = issueScanTokenForTransactionTest($this, $token, $customer);
    $customer->update(['status' => 'blocked', 'balance' => 50000]);

    $response = $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'scan_token' => $scanToken,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Rekening nasabah diblokir');

    expect($customer->fresh()->balance)->toBe(50000);
    expect(Transaction::where('customer_id', $customer->id)->count())->toBe(0);
});

test('withdrawal rejected for blocked customer account', function () {
    [$teller, $token] = tellerWithTokenForTransactionTest();
    $customer = setupCustomerForTransaction();
    $customer->update(['status' => 'blocked', 'balance' => 50000]);

    $response = $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'pin' => '123456',
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Rekening nasabah diblokir');

    expect($customer->fresh()->balance)->toBe(50000);
    expect(Transaction::where('customer_id', $customer->id)->count())->toBe(0);
});
