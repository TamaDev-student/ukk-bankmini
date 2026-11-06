<?php

use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function tellerWithTokenForReportTest(): array
{
    static $counter = 0;
    $counter++;

    $teller = User::create([
        'name' => 'Teller '.$counter,
        'username' => 'teller_rep_'.$counter,
        'full_name' => 'Teller Rep '.$counter,
        'email' => 'teller_rep_'.$counter.'@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    $token = $teller->createToken('test')->plainTextToken;

    return [$teller, $token];
}

test('teller can get their daily reports', function () {
    [$teller, $token] = tellerWithTokenForReportTest();

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now()->toDateString(),
        'status' => 'submitted',
        'total_deposit' => 100000,
        'total_withdrawal' => 50000,
    ]);

    $response = $this->getJson('/api/teller/daily-reports', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Daftar laporan harian berhasil diambil.')
        ->assertJsonStructure([
            'message',
            'data' => [
                '*' => [
                    'id',
                    'teller_id',
                    'report_date',
                    'status',
                    'total_deposit',
                    'total_withdrawal',
                ],
            ],
        ]);
});

test('unauthenticated user cannot get daily reports', function () {
    $response = $this->getJson('/api/teller/daily-reports');
    $response->assertStatus(401);
});

test('non-teller user cannot get teller daily reports', function () {
    $admin = User::create([
        'name' => 'Admin Rep',
        'username' => 'admin_rep',
        'full_name' => 'Admin Rep',
        'email' => 'admin_rep@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->getJson('/api/teller/daily-reports', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(403);
});
