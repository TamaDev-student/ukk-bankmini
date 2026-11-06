<?php

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('nasabah can view their own transaction history', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    $olderTransaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-10-10',
        'transaction_type' => 'deposit',
        'amount' => 100000,
    ]);

    $newerTransaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-10-11',
        'transaction_type' => 'withdrawal',
        'amount' => 25000,
    ]);

    Sanctum::actingAs($nasabah);

    $response = $this->getJson('/api/customer/transactions');

    $response
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Transaction history retrieved successfully.'
        )
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newerTransaction->id)
        ->assertJsonPath('data.0.amount', 25000)
        ->assertJsonPath('data.1.id', $olderTransaction->id)
        ->assertJsonPath('data.1.amount', 100000);
});

test('riwayat nasabah mencakup transaksi pending, ditolak, dan gagal beserta alasan aman', function () {
    $nasabah = User::factory()->create(['role' => 'nasabah']);
    $customer = Customer::factory()->create(['user_id' => $nasabah->id]);
    $teller = User::factory()->create(['role' => 'teller']);

    $pending = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-11-03 10:00:00',
        'transaction_type' => 'deposit',
        'amount' => 50000,
        'status' => 'pending',
    ]);

    $customerActivity = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $nasabah->id,
        'transaction_date' => '2026-11-03 09:00:00',
        'transaction_type' => 'deposit',
        'amount' => 10000,
        'status' => 'approved',
    ]);

    $rejected = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-11-03 11:00:00',
        'transaction_type' => 'withdrawal',
        'amount' => 25000,
        'status' => 'rejected',
        'rejection_reason' => 'Saldo tidak mencukupi.',
    ]);

    $failed = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-11-03 12:00:00',
        'transaction_type' => 'withdrawal',
        'amount' => 30000,
        'status' => 'failed',
        'rejection_reason' => 'Transaksi tidak dapat diproses.',
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.id', $failed->id)
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.rejection_reason', 'Transaksi tidak dapat diproses.')
        ->assertJsonPath('data.1.id', $rejected->id)
        ->assertJsonPath('data.1.status', 'rejected')
        ->assertJsonPath('data.1.rejection_reason', 'Saldo tidak mencukupi.')
        ->assertJsonPath('data.2.id', $pending->id)
        ->assertJsonPath('data.2.status', 'pending')
        ->assertJsonPath('data.3.id', $customerActivity->id)
        ->assertJsonPath('data.3.status', 'approved');

    $this->getJson("/api/customer/transactions/{$failed->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $failed->id)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.rejection_reason', 'Transaksi tidak dapat diproses.');
});

test('transaksi penarikan yang ditolak tidak mengubah saldo atau dapat disetujui ulang', function () {
    $customer = Customer::factory()->create(['balance' => 50000]);
    $teller = User::factory()->create(['role' => 'teller']);
    $transaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => now(),
        'transaction_type' => 'withdrawal',
        'amount' => 20000,
        'status' => 'pending',
    ]);

    $rejected = app(TransactionService::class)->reject(
        $transaction->id,
        $customer->id,
        'Permintaan dibatalkan nasabah.'
    );

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('Permintaan dibatalkan nasabah.')
        ->and($customer->fresh()->balance)->toBe(50000)
        ->and($rejected->journals()->count())->toBe(0);

    expect(fn () => app(TransactionService::class)->approve(
        $transaction->id,
        $customer->id
    ))->toThrow(ValidationException::class);

    expect($customer->fresh()->balance)->toBe(50000)
        ->and($transaction->fresh()->status)->toBe('rejected');
});

test('nasabah cannot view transactions belonging to another customer', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $otherNasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    $otherCustomer = Customer::factory()->create([
        'user_id' => $otherNasabah->id,
    ]);

    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    $ownTransaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-10-11',
        'transaction_type' => 'deposit',
        'amount' => 100000,
    ]);

    $otherTransaction = Transaction::create([
        'customer_id' => $otherCustomer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-10-11',
        'transaction_type' => 'withdrawal',
        'amount' => 50000,
    ]);

    Sanctum::actingAs($nasabah);

    $response = $this->getJson('/api/customer/transactions');

    $response
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownTransaction->id)
        ->assertJsonMissing([
            'id' => $otherTransaction->id,
        ]);
});

test('nasabah without a linked customer receives not found', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson('/api/customer/transactions')
        ->assertNotFound()
        ->assertJsonPath(
            'message',
            'No customer account is linked to this user.'
        );
});

test('non nasabah users cannot access transaction history', function () {
    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    Sanctum::actingAs($teller);

    $this->getJson('/api/customer/transactions')
        ->assertForbidden()
        ->assertJsonPath('message', 'Akses ditolak.');
});

test('unauthenticated users cannot access transaction history', function () {
    $this->getJson('/api/customer/transactions')
        ->assertUnauthorized();
});

test('nasabah with no transactions receives an empty history', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Transaction history retrieved successfully.'
        )
        ->assertJsonCount(0, 'data');
});
test('nasabah can view their own transaction details', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    $transaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-10-11',
        'transaction_type' => 'deposit',
        'amount' => 100000,
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson(
        "/api/customer/transactions/{$transaction->id}"
    )
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Transaction details retrieved successfully.'
        )
        ->assertJsonPath('data.id', $transaction->id)
        ->assertJsonPath('data.transaction_type', 'deposit')
        ->assertJsonPath('data.amount', 100000);
});

test('nasabah cannot view another customers transaction details', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $otherNasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    $otherCustomer = Customer::factory()->create([
        'user_id' => $otherNasabah->id,
    ]);

    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    $otherTransaction = Transaction::create([
        'customer_id' => $otherCustomer->id,
        'user_id' => $teller->id,
        'transaction_date' => '2026-10-11',
        'transaction_type' => 'deposit',
        'amount' => 200000,
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson(
        "/api/customer/transactions/{$otherTransaction->id}"
    )
        ->assertNotFound()
        ->assertJsonPath('message', 'Transaction not found.');
});

test('nasabah receives not found for a nonexistent transaction', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    Customer::factory()->create([
        'user_id' => $nasabah->id,
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson('/api/customer/transactions/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Transaction not found.');
});

test('nasabah without a linked customer cannot view transaction details', function () {
    $nasabah = User::factory()->create([
        'role' => 'nasabah',
    ]);

    Sanctum::actingAs($nasabah);

    $this->getJson('/api/customer/transactions/1')
        ->assertNotFound()
        ->assertJsonPath(
            'message',
            'No customer account is linked to this user.'
        );
});

test('non nasabah users cannot access transaction details', function () {
    $teller = User::factory()->create([
        'role' => 'teller',
    ]);

    Sanctum::actingAs($teller);

    $this->getJson('/api/customer/transactions/1')
        ->assertForbidden()
        ->assertJsonPath('message', 'Akses ditolak.');
});

test('unauthenticated users cannot access transaction details', function () {
    $this->getJson('/api/customer/transactions/1')
        ->assertUnauthorized();
});

test('nasabah dapat membatalkan transaksi pending tanpa mengubah saldo atau membuat jurnal', function () {
    $nasabah = User::factory()->create(['role' => 'nasabah']);
    $customer = Customer::factory()->create([
        'user_id' => $nasabah->id,
        'balance' => 75000,
    ]);
    $teller = User::factory()->create(['role' => 'teller']);
    $transaction = Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $teller->id,
        'transaction_date' => now(),
        'transaction_type' => 'withdrawal',
        'amount' => 25000,
        'status' => 'pending',
    ]);

    Sanctum::actingAs($nasabah);

    $this->postJson("/api/customer/transactions/{$transaction->id}/cancel")
        ->assertOk()
        ->assertJsonPath('message', 'Transaksi berhasil dibatalkan.')
        ->assertJsonPath('data.id', $transaction->id)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonStructure(['data' => ['cancelled_at']]);

    expect($transaction->fresh()->status)->toBe('cancelled')
        ->and($transaction->fresh()->cancelled_by)->toBe($nasabah->id)
        ->and($transaction->fresh()->cancelled_at)->not->toBeNull()
        ->and($customer->fresh()->balance)->toBe(75000)
        ->and($transaction->journals()->count())->toBe(0);

    $this->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'cancelled');

    expect(fn () => app(TransactionService::class)->approve(
        $transaction->id,
        $customer->id
    ))->toThrow(ValidationException::class);
});

test('pembatalan ditolak untuk transaksi yang sudah final', function () {
    $nasabah = User::factory()->create(['role' => 'nasabah']);
    $customer = Customer::factory()->create(['user_id' => $nasabah->id]);
    $teller = User::factory()->create(['role' => 'teller']);

    foreach (['approved', 'rejected', 'failed', 'cancelled'] as $status) {
        $transaction = Transaction::create([
            'customer_id' => $customer->id,
            'user_id' => $teller->id,
            'transaction_date' => now(),
            'transaction_type' => 'deposit',
            'amount' => 12000,
            'status' => $status,
        ]);

        Sanctum::actingAs($nasabah);

        $this->postJson("/api/customer/transactions/{$transaction->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Hanya transaksi berstatus pending yang dapat dibatalkan.'
            );

        expect($transaction->fresh()->status)->toBe($status);
    }
});

test('nasabah tidak dapat membatalkan transaksi milik nasabah lain', function () {
    $nasabah = User::factory()->create(['role' => 'nasabah']);
    $otherNasabah = User::factory()->create(['role' => 'nasabah']);
    $otherCustomer = Customer::factory()->create([
        'user_id' => $otherNasabah->id,
    ]);
    $teller = User::factory()->create(['role' => 'teller']);
    $transaction = Transaction::create([
        'customer_id' => $otherCustomer->id,
        'user_id' => $teller->id,
        'transaction_date' => now(),
        'transaction_type' => 'deposit',
        'amount' => 12000,
        'status' => 'pending',
    ]);

    Sanctum::actingAs($nasabah);

    $this->postJson("/api/customer/transactions/{$transaction->id}/cancel")
        ->assertNotFound();

    expect($transaction->fresh()->status)->toBe('pending');
});

test('pembatalan transaksi memerlukan autentikasi', function () {
    $this->postJson('/api/customer/transactions/1/cancel')
        ->assertUnauthorized();
});
