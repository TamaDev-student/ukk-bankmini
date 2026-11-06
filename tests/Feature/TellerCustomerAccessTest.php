<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function tellerWithTokenForCustomerAccessTest(): array
{
    static $counter = 0;
    $counter++;

    $teller = User::create([
        'name' => 'Teller Access '.$counter,
        'username' => 'teller_acc_'.$counter,
        'full_name' => 'Teller Access '.$counter,
        'email' => 'teller_acc_'.$counter.'@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    return [$teller, $teller->createToken('test')->plainTextToken];
}

test('teller cannot access the former full customers list endpoint', function () {
    [, $token] = tellerWithTokenForCustomerAccessTest();

    $this->withToken($token)
        ->getJson('/api/teller/customers')
        ->assertNotFound();
});

test('unauthenticated requests cannot access the former customers list endpoint', function () {
    $this->getJson('/api/teller/customers')->assertNotFound();
});

test('administrator cannot use the former teller customers list endpoint', function () {
    $admin = User::create([
        'name' => 'Admin Acc',
        'username' => 'admin_acc',
        'full_name' => 'Admin Acc',
        'email' => 'admin_acc@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->getJson('/api/teller/customers')
        ->assertNotFound();
});
