<?php

use App\Models\CashDepositScanToken;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

function qrLockCreateTeller(): array
{
    $suffix = Str::lower(Str::random(10));
    $teller = User::create([
        'name' => 'QR Teller',
        'username' => 'qr_teller_'.$suffix,
        'full_name' => 'QR Teller '.$suffix,
        'email' => 'qr_teller_'.$suffix.'@bank.com',
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

    return [$teller, $teller->createToken('test')->plainTextToken];
}

function qrLockCreateCustomer(string $status = 'active'): Customer
{
    $suffix = Str::upper(Str::random(10));
    $major = Major::firstOrCreate(['name' => 'QR Test Major', 'code' => 'QRT']);
    $schoolClass = SchoolClass::firstOrCreate([
        'name' => '10 QR Test',
        'major_id' => $major->id,
    ]);

    return Customer::create([
        'account_number' => 'QR'.$suffix,
        'student_number' => $suffix,
        'student_name' => 'QR Test Student',
        'class_id' => $schoolClass->id,
        'status' => $status,
        'security_pin' => Hash::make('123456'),
        'balance' => 0,
    ]);
}

function qrLockIssueToken(TestCase $test, string $authToken, Customer $customer)
{
    app('auth')->forgetGuards();

    return $test->postJson(
        '/api/teller/customers/'.$customer->account_number.'/scan-token',
        [],
        ['Authorization' => 'Bearer '.$authToken]
    );
}

function qrLockDeposit(TestCase $test, string $authToken, array $payload)
{
    app('auth')->forgetGuards();

    return $test->postJson(
        '/api/teller/deposit',
        $payload,
        ['Authorization' => 'Bearer '.$authToken]
    );
}

test('teller account lookup exposes only operational fields and rejects inactive accounts', function () {
    [, $authToken] = qrLockCreateTeller();
    $customer = qrLockCreateCustomer();

    $this->withToken($authToken)
        ->getJson('/api/teller/customers/'.$customer->account_number)
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'account_number', 'student_name', 'status', 'balance'],
        ])
        ->assertJsonPath('data.school_class.name', '10 QR Test')
        ->assertJsonPath('data.school_class.major.code', 'QRT')
        ->assertJsonMissingPath('data.security_pin')
        ->assertJsonMissingPath('data.student_number');

    $inactiveCustomer = qrLockCreateCustomer('blocked');
    $this->withToken($authToken)
        ->getJson('/api/teller/customers/'.$inactiveCustomer->account_number)
        ->assertForbidden()
        ->assertJsonPath('message', 'Rekening nasabah diblokir');
});

test('valid QR scan issues a short lived hashed token bound to the teller and customer', function () {
    [$teller, $authToken] = qrLockCreateTeller();
    $customer = qrLockCreateCustomer();

    $response = qrLockIssueToken($this, $authToken, $customer)
        ->assertOk()
        ->assertJsonPath('data.customer.id', $customer->id);
    $response->assertJsonMissingPath('data.customer.security_pin')
        ->assertJsonMissingPath('data.customer.student_number');

    $plainToken = $response->json('data.scan_token');
    expect($plainToken)->toMatch('/\A[a-f0-9]{64}\z/');

    $scanToken = CashDepositScanToken::query()->firstOrFail();
    expect($scanToken->token_hash)->toBe(hash('sha256', $plainToken))
        ->and($scanToken->token_hash)->not->toBe($plainToken)
        ->and($scanToken->customer_id)->toBe($customer->id)
        ->and($scanToken->user_id)->toBe($teller->id)
        ->and($scanToken->purpose)->toBe('cash_deposit')
        ->and($scanToken->expires_at->isFuture())->toBeTrue();
});

test('invalid or inactive QR accounts do not issue a deposit token', function () {
    [$teller, $authToken] = qrLockCreateTeller();
    $inactiveCustomer = qrLockCreateCustomer('blocked');

    $this->withToken($authToken)
        ->postJson('/api/teller/customers/UNKNOWN-QR-ACCOUNT/scan-token')
        ->assertNotFound();

    qrLockIssueToken($this, $authToken, $inactiveCustomer)
        ->assertForbidden()
        ->assertJsonPath('message', 'Rekening nasabah diblokir');

    expect(CashDepositScanToken::query()->count())->toBe(0);
});

test('deposit token is bound to the scanned customer and can only be used once', function () {
    [$teller, $authToken] = qrLockCreateTeller();
    $customer = qrLockCreateCustomer();
    $otherCustomer = qrLockCreateCustomer();
    $scanResponse = qrLockIssueToken($this, $authToken, $customer)->assertOk();
    $plainToken = $scanResponse->json('data.scan_token');

    qrLockDeposit($this, $authToken, [
        'customer_id' => $otherCustomer->id,
        'amount' => 10000,
        'scan_token' => $plainToken,
    ])->assertUnprocessable();

    expect(CashDepositScanToken::query()->firstOrFail()->used_at)->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);

    $payload = [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'scan_token' => $plainToken,
    ];

    qrLockDeposit($this, $authToken, $payload)
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id)
        ->assertJsonPath('data.transaction_type', 'deposit');

    qrLockDeposit($this, $authToken, $payload)->assertStatus(409);

    expect(CashDepositScanToken::query()->firstOrFail()->used_at)->not->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
});

test('deposit token cannot be used by another teller or for another purpose', function () {
    [$owner, $ownerToken] = qrLockCreateTeller();
    [$otherTeller, $otherTellerToken] = qrLockCreateTeller();
    $customer = qrLockCreateCustomer();
    $plainToken = qrLockIssueToken($this, $ownerToken, $customer)
        ->assertOk()
        ->json('data.scan_token');

    app('auth')->forgetGuards();
    $this->getJson('/api/user', [
        'Authorization' => 'Bearer '.$otherTellerToken,
    ])->assertOk()->assertJsonPath('id', $otherTeller->id);

    $payload = [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'scan_token' => $plainToken,
    ];

    qrLockDeposit($this, $otherTellerToken, $payload)->assertForbidden();

    $scanToken = CashDepositScanToken::query()->firstOrFail();
    expect($scanToken->used_at)->toBeNull();

    $scanToken->update(['purpose' => 'cash_withdrawal']);
    qrLockDeposit($this, $ownerToken, $payload)->assertUnprocessable();

    $scanToken->update([
        'purpose' => 'cash_deposit',
        'expires_at' => now()->subSecond(),
    ]);
    qrLockDeposit($this, $ownerToken, $payload)->assertStatus(410);

    expect($scanToken->fresh()->used_at)->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);
});

test('deposit token is not consumed when the scanned account is no longer active', function () {
    [, $authToken] = qrLockCreateTeller();
    $customer = qrLockCreateCustomer();
    $plainToken = qrLockIssueToken($this, $authToken, $customer)
        ->assertOk()
        ->json('data.scan_token');
    $payload = [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'scan_token' => $plainToken,
    ];

    $customer->update(['status' => 'blocked']);
    qrLockDeposit($this, $authToken, $payload)
        ->assertForbidden()
        ->assertJsonPath('message', 'Rekening nasabah diblokir');

    expect(CashDepositScanToken::query()->firstOrFail()->used_at)->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);

    $customer->update(['status' => 'active']);
    qrLockDeposit($this, $authToken, $payload)->assertCreated();
});

test('failed deposit rolls back scan token consumption and allows a safe retry', function () {
    [, $authToken] = qrLockCreateTeller();
    $customer = qrLockCreateCustomer();
    $plainToken = qrLockIssueToken($this, $authToken, $customer)
        ->assertOk()
        ->json('data.scan_token');
    $payload = [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'scan_token' => $plainToken,
    ];

    $service = Mockery::mock(TransactionService::class);
    $service->shouldReceive('deposit')
        ->once()
        ->andThrow(new RuntimeException('Simulated transaction failure'));
    app()->instance(TransactionService::class, $service);

    qrLockDeposit($this, $authToken, $payload)->assertServerError();

    expect(CashDepositScanToken::query()->firstOrFail()->used_at)->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);

    app()->forgetInstance(TransactionService::class);

    qrLockDeposit($this, $authToken, $payload)->assertCreated();

    expect(CashDepositScanToken::query()->firstOrFail()->used_at)->not->toBeNull()
        ->and(Transaction::query()->count())->toBe(1);
});
