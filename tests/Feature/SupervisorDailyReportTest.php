<?php

use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function createUserForSupervisorReportTest(string $role): User
{
    static $counter = 0;
    $counter++;

    return User::create([
        'name' => ucfirst($role) . ' Test ' . $counter,
        'username' => $role . '_sup_report_' . $counter,
        'full_name' => ucfirst($role) . ' Supervisor Report ' . $counter,
        'email' => $role . '_sup_report_' . $counter . '@bank.com',
        'password' => Hash::make('password123'),
        'role' => $role,
        'status' => 'active',
    ]);
}

function createDailyReportForSupervisorTest(
    User $teller,
    string $status = 'submitted'
): DailyReport {
    static $counter = 0;
    $counter++;

    return DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now()->subDays($counter)->toDateString(),
        'status' => $status,
        'total_deposit' => 100000,
        'total_withdrawal' => 50000,
        'submitted_at' => now(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 500000,
        'system_balance' => 550000,
        'physical_balance' => 550000,
        'closing_difference' => 0,
    ]);
}

test('supervisor dapat melihat daftar laporan yang menunggu persetujuan', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $submittedReport = createDailyReportForSupervisorTest($teller, 'submitted');
    createDailyReportForSupervisorTest($teller, 'approved');
    createDailyReportForSupervisorTest($teller, 'rejected');

    $token = $supervisor->createToken('test')->plainTextToken;

    $response = $this->getJson('/api/supervisor/daily-reports', [
        'Authorization' => "Bearer {$token}",
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Daftar laporan harian berhasil diambil.')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $submittedReport->id)
        ->assertJsonPath('data.0.status', 'submitted');
});

test('supervisor dapat melihat detail laporan harian', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($teller);

    $token = $supervisor->createToken('test')->plainTextToken;

    $response = $this->getJson(
        "/api/supervisor/daily-reports/{$report->id}",
        ['Authorization' => "Bearer {$token}"]
    );

    $response->assertOk()
        ->assertJsonPath('data.report.id', $report->id)
        ->assertJsonPath('data.report.teller_id', $teller->id)
        ->assertJsonStructure([
            'message',
            'data' => [
                'report',
                'transactions',
                'total_transactions',
                'total_deposit',
                'total_withdrawal',
            ],
        ]);
});

test('supervisor dapat menyetujui laporan submitted', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($teller);
    $token = $supervisor->createToken('test')->plainTextToken;

    $response = $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/approve",
        [],
        ['Authorization' => "Bearer {$token}"]
    );

    $response->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by', $supervisor->id);

    $report->refresh();

    expect($report->status)->toBe('approved');
    expect($report->approved_by)->toBe($supervisor->id);
    expect($report->approved_at)->not->toBeNull();
    expect($report->locked_at)->not->toBeNull();
});

test('supervisor dapat menolak laporan dengan alasan yang valid', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($teller);
    $token = $supervisor->createToken('test')->plainTextToken;

    $response = $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/reject",
        ['rejection_reason' => 'Selisih saldo perlu diperiksa'],
        ['Authorization' => "Bearer {$token}"]
    );

    $response->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath(
            'data.rejection_reason',
            'Selisih saldo perlu diperiksa'
        );

    $report->refresh();

    expect($report->status)->toBe('rejected');
    expect($report->approved_by)->toBeNull();
    expect($report->approved_at)->toBeNull();
    expect($report->locked_at)->toBeNull();
});

test('penolakan laporan wajib memiliki alasan minimal tiga karakter', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($teller);
    $token = $supervisor->createToken('test')->plainTextToken;

    $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/reject",
        ['rejection_reason' => ''],
        ['Authorization' => "Bearer {$token}"]
    )->assertUnprocessable()
        ->assertJsonValidationErrors('rejection_reason');

    $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/reject",
        ['rejection_reason' => 'ab'],
        ['Authorization' => "Bearer {$token}"]
    )->assertUnprocessable()
        ->assertJsonValidationErrors('rejection_reason');

    expect($report->fresh()->status)->toBe('submitted');
});

test('laporan yang sudah diproses tidak dapat disetujui kembali', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($teller, 'approved');
    $token = $supervisor->createToken('test')->plainTextToken;

    $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/approve",
        [],
        ['Authorization' => "Bearer {$token}"]
    )->assertUnprocessable()
        ->assertJsonPath('message', 'Laporan tidak dapat disetujui.');

    expect($report->fresh()->status)->toBe('approved');
});

test('laporan yang sudah diproses tidak dapat ditolak kembali', function () {
    $supervisor = createUserForSupervisorReportTest('supervisor');
    $teller = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($teller, 'rejected');
    $token = $supervisor->createToken('test')->plainTextToken;

    $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/reject",
        ['rejection_reason' => 'Perlu diperiksa ulang'],
        ['Authorization' => "Bearer {$token}"]
    )->assertUnprocessable()
        ->assertJsonPath('message', 'Laporan tidak dapat ditolak.');

    expect($report->fresh()->status)->toBe('rejected');
});

test('teller tidak dapat menyetujui laporan harian', function () {
    $teller = createUserForSupervisorReportTest('teller');
    $reportOwner = createUserForSupervisorReportTest('teller');

    $report = createDailyReportForSupervisorTest($reportOwner);
    $token = $teller->createToken('test')->plainTextToken;

    $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/approve",
        [],
        ['Authorization' => "Bearer {$token}"]
    )->assertForbidden();

    expect($report->fresh()->status)->toBe('submitted');
});

test('pengguna tanpa autentikasi tidak dapat memproses persetujuan laporan', function () {
    $teller = createUserForSupervisorReportTest('teller');
    $report = createDailyReportForSupervisorTest($teller);

    $this->postJson(
        "/api/supervisor/daily-reports/{$report->id}/approve"
    )->assertUnauthorized();

    expect($report->fresh()->status)->toBe('submitted');
});