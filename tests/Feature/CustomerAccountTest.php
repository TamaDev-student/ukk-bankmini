<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('nasabah can view their own account', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $user->id,
        'account_number' => '1234567890',
        'student_name' => $user->full_name,
        'balance' => 500000,
        'status' => 'active',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/customer/account');

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Customer account retrieved successfully.')
        ->assertJsonPath('data.account_number', $customer->account_number)
        ->assertJsonPath('data.student_name', $customer->student_name)
        ->assertJsonPath('data.balance', $customer->balance); // Ditutup dengan titik koma di sini

    $response->assertJsonPath('data.status', $customer->status);

});

test('non nasabah users cannot access the customer account endpoint', function () {
    $user = User::factory()->create([
        'role' => 'teller',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/customer/account')
        ->assertForbidden()
        ->assertJsonPath('message', 'Akses ditolak.');
});

test('unauthenticated users cannot access the customer account endpoint', function () {
    $this->getJson('/api/customer/account')
        ->assertUnauthorized();
});

test('nasabah without a linked customer account receives not found', function () {
    $user = User::factory()->create([
        'role' => 'nasabah',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/customer/account')
        ->assertNotFound()
        ->assertJsonPath(
            'message',
            'No customer account is linked to this user.'
        );
});
