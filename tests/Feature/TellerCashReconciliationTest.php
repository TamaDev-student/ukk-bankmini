<?php

use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['bank.initial_cash_balance' => 100000]);
});

/*
|--------------------------------------------------------------------------
| Helper: Membuat Teller dengan token Sanctum.
|--------------------------------------------------------------------------
*/

function createTellerForReconciliation(int $index = 1): array
{
    $teller = User::create([
        'name' => "Teller Recon $index",
        'username' => "teller_recon_$index",
        'full_name' => "Teller Recon $index",
        'email' => "teller_recon_{$index}@bank.com",
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    $token = $teller->createToken('test')->plainTextToken;

    return [$teller, $token];
}

/*
|--------------------------------------------------------------------------
| Helper: Membuat transaksi deposit/withdrawal untuk Teller.
|--------------------------------------------------------------------------
*/

function createTestTransaction(
    int $tellerId,
    string $type,
    int $amount,
    ?string $date = null
): Transaction {
    // Buat customer sederhana jika diperlukan.
    $customer = Customer::first() ?? Customer::factory()->create();
    $transactionDate = $date ?? now()->toDateTimeString();

    return Transaction::create([
        'customer_id' => $customer->id,
        'user_id' => $tellerId,
        'transaction_date' => $transactionDate,
        'transaction_type' => $type,
        'amount' => $amount,
        'status' => 'approved',
        'approved_at' => $transactionDate,
    ]);
}

function createTransactionWithStatus(
    int $tellerId,
    string $type,
    int $amount,
    string $status
): Transaction {
    $transaction = createTestTransaction($tellerId, $type, $amount);
    $transaction->status = $status;
    $transaction->approved_at = $status === 'approved' ? now() : null;
    $transaction->rejected_at = in_array($status, ['rejected', 'failed'], true)
        ? now()
        : null;
    $transaction->save();

    return $transaction;
}

/*
|--------------------------------------------------------------------------
| 1. last_approved_balance dari system_balance approved terakhir
|--------------------------------------------------------------------------
*/

test('last_approved_balance diambil dari system_balance laporan approved terakhir', function () {
    [$teller, $token] = createTellerForReconciliation(101);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => '2026-10-20',
        'status' => 'approved',
        'total_deposit' => 100000,
        'total_withdrawal' => 50000,
        'opening_balance' => 0,
        'system_balance' => 50000,
        'physical_balance' => 48000,
        'closing_difference' => -2000,
        'submitted_at' => now(),
        'approved_by' => $teller->id,
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    $response = $this->getJson('/api/teller/cash-reconciliation?date=2026-10-21', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.last_approved_balance', 50000);
});

/*
|--------------------------------------------------------------------------
| 2. Laporan submitted/rejected tidak menjadi sumber last_approved_balance
|--------------------------------------------------------------------------
*/

test('laporan submitted atau rejected tidak dijadikan sumber last_approved_balance', function () {
    [$teller, $token] = createTellerForReconciliation(102);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => '2026-10-18',
        'status' => 'approved',
        'total_deposit' => 100000,
        'total_withdrawal' => 0,
        'opening_balance' => 0,
        'system_balance' => 100000,
        'physical_balance' => 100000,
        'closing_difference' => 0,
        'submitted_at' => now(),
        'approved_by' => $teller->id,
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    // Laporan submitted lebih baru.
    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => '2026-10-19',
        'status' => 'submitted',
        'total_deposit' => 200000,
        'total_withdrawal' => 0,
        'opening_balance' => 100000,
        'system_balance' => 300000,
        'physical_balance' => 300000,
        'submitted_at' => now(),
    ]);

    $response = $this->getJson('/api/teller/cash-reconciliation?date=2026-10-20', [
        'Authorization' => "Bearer $token",
    ]);

    // Harus tetap 100000 dari approved, bukan 300000 dari submitted.
    $response->assertStatus(200)
        ->assertJsonPath('data.last_approved_balance', 100000);
});

/*
|--------------------------------------------------------------------------
| 3. Teller tanpa laporan approved mendapat null
|--------------------------------------------------------------------------
*/

test('Teller tanpa laporan approved mendapat last_approved_balance null', function () {
    [$teller, $token] = createTellerForReconciliation(103);

    $response = $this->getJson('/api/teller/cash-reconciliation', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.last_approved_balance', null)
        ->assertJsonPath('data.report', null);
});

/*
|--------------------------------------------------------------------------
| 4-7. Selisih referensi (opening_balance_difference)
|--------------------------------------------------------------------------
*/

test('selisih kas awal nol jika fisik sama dengan saldo sistem terakhir', function () {
    [$teller, $token] = createTellerForReconciliation(104);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->subDay()->toDateString(),
        'status' => 'approved',
        'physical_balance' => 500000,
        'system_balance' => 500000,
        'submitted_at' => now(),
        'approved_by' => $teller->id,
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_physical_balance' => 500000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_balance', 500000)
        ->assertJsonPath('data.opening_difference', 0);
});

test('selisih kas awal negatif jika fisik kurang dari saldo sistem', function () {
    [$teller, $token] = createTellerForReconciliation(105);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->subDay()->toDateString(),
        'status' => 'approved',
        'physical_balance' => 500000,
        'system_balance' => 500000,
        'submitted_at' => now(),
        'approved_by' => $teller->id,
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_physical_balance' => 480000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_balance', 500000)
        ->assertJsonPath('data.opening_difference', -20000);
});

test('selisih kas awal positif jika fisik melebihi saldo sistem', function () {
    [$teller, $token] = createTellerForReconciliation(106);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->subDay()->toDateString(),
        'status' => 'approved',
        'physical_balance' => 500000,
        'system_balance' => 500000,
        'submitted_at' => now(),
        'approved_by' => $teller->id,
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_physical_balance' => 520000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_balance', 500000)
        ->assertJsonPath('data.opening_difference', 20000);
});

test('saldo awal hari pertama dicatat Teller dan atribusinya tersimpan', function () {
    [$teller, $token] = createTellerForReconciliation(107);
    config(['bank.initial_cash_balance' => 300000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 999999,
        'opening_physical_balance' => 300000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_balance', 999999)
        ->assertJsonPath('data.opening_balance_difference', null)
        ->assertJsonPath('data.opening_difference', -699999)
        ->assertJsonPath('data.last_approved_balance', null);

    $report = DailyReport::query()->where('teller_id', $teller->id)->firstOrFail();
    expect($report->opening_balance_recorded_by)->toBe($teller->id)
        ->and($report->opening_balance_recorded_at)->not->toBeNull();
});

test('saldo awal tidak diisi otomatis dan transaksi ditahan sampai kas awal dicatat', function () {
    [$teller, $token] = createTellerForReconciliation(127);
    $customer = Customer::factory()->create();

    $this->withToken($token)
        ->getJson('/api/teller/cash-reconciliation')
        ->assertOk()
        ->assertJsonPath('data.opening_balance', null)
        ->assertJsonPath('data.system_balance', null);

    expect(fn () => app(TransactionService::class)
        ->deposit($customer, 10000, $teller->id))
        ->toThrow(ValidationException::class);

    $this->withToken($token)->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_physical_balance' => 100000,
    ])->assertUnprocessable();
});

test('saldo awal hari pertama yang sudah dicatat tidak dapat diubah Teller', function () {
    [$teller, $token] = createTellerForReconciliation(128);
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 500000,
    ], $headers)->assertCreated();

    $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 600000,
        'opening_physical_balance' => 600000,
    ], $headers)->assertUnprocessable();
});

test('saldo hari berikutnya berasal dari penutupan otomatis dan peringatannya tetap tampil', function () {
    [$teller, $token] = createTellerForReconciliation(129);
    $yesterday = now('Asia/Jakarta')->subDay()->toDateString();
    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => $yesterday,
        'status' => 'auto_closed_unverified',
        'opening_balance' => 500000,
        'opening_balance_source' => 'first_day_teller',
        'system_balance' => 550000,
        'physical_balance' => null,
        'closing_difference' => null,
        'auto_closed_at' => now(),
        'locked_at' => now(),
    ]);

    $response = $this->withToken($token)->postJson(
        '/api/teller/cash-reconciliation/opening',
        [
            'date' => now('Asia/Jakarta')->toDateString(),
            'opening_physical_balance' => 545000,
        ]
    );

    $response->assertCreated()
        ->assertJsonPath('data.opening_balance', 550000)
        ->assertJsonPath('data.opening_difference', -5000);

    $this->withToken($token)
        ->getJson('/api/teller/cash-reconciliation')
        ->assertOk()
        ->assertJsonPath('data.previous_report.requires_review', true);

    $this->withToken($token)->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 600000,
        'opening_physical_balance' => 550000,
    ])->assertUnprocessable();
});

test('Supervisor dapat mengoreksi saldo awal kosong dari auto-close dengan audit', function () {
    [$teller] = createTellerForReconciliation(130);
    $supervisor = User::create([
        'name' => 'Supervisor Cash Correction',
        'username' => 'supervisor_cash_correction',
        'full_name' => 'Supervisor Cash Correction',
        'email' => 'supervisor_cash_correction@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'supervisor',
        'status' => 'active',
    ]);
    $supervisorToken = $supervisor->createToken('test')->plainTextToken;
    $date = now('Asia/Jakarta')->toDateString();

    expect(app(DailyReportService::class)
        ->autoCloseForDate(Carbon::parse($date, 'Asia/Jakarta')))->toBe(1);

    $report = DailyReport::query()
        ->where('teller_id', $teller->id)
        ->whereDate('report_date', $date)
        ->firstOrFail();
    expect($report->system_balance)->toBeNull();

    $this->withToken($supervisorToken)->postJson(
        "/api/supervisor/daily-reports/{$report->id}/correct-initial-cash",
        [
            'opening_balance' => 250000,
            'reason' => 'Mencatat modal awal yang belum diinput.',
        ]
    )->assertOk()
        ->assertJsonPath('data.opening_balance', 250000)
        ->assertJsonPath('data.system_balance', 250000);

    $this->assertDatabaseHas('daily_report_corrections', [
        'daily_report_id' => $report->id,
        'user_id' => $supervisor->id,
        'previous_opening_balance' => null,
        'corrected_opening_balance' => 250000,
    ]);
});

/*
|--------------------------------------------------------------------------
| 8-10. Selisih fisik awal (opening_difference)
|--------------------------------------------------------------------------
*/

test('opening_difference nol jika fisik sama dengan opening_balance', function () {
    [$teller, $token] = createTellerForReconciliation(108);
    config(['bank.initial_cash_balance' => 500000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 500000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_difference', 0);
});

test('opening_difference negatif jika fisik kurang dari opening_balance', function () {
    [$teller, $token] = createTellerForReconciliation(109);
    config(['bank.initial_cash_balance' => 500000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 490000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_difference', -10000);
});

test('opening_difference positif jika fisik lebih dari opening_balance', function () {
    [$teller, $token] = createTellerForReconciliation(110);
    config(['bank.initial_cash_balance' => 500000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 510000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.opening_difference', 10000);
});

/*
|--------------------------------------------------------------------------
| 11. Teller tetap bisa bertransaksi setelah selisih awal dicatat
|--------------------------------------------------------------------------
*/

test('Teller tetap dapat melakukan transaksi setelah selisih kas awal dicatat', function () {
    [$teller, $token] = createTellerForReconciliation(111);
    $customer = Customer::factory()->create();
    config(['bank.initial_cash_balance' => 500000]);

    $this->withToken($token)->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 490000,
    ])->assertStatus(201);

    $transaction = app(TransactionService::class)
        ->deposit($customer, 50000, $teller->id);

    expect($transaction->status)->toBe('pending');

    $this->withToken($token)
        ->getJson('/api/teller/cash-reconciliation')
        ->assertOk()
        ->assertJsonPath('data.report.opening_difference', -10000);
});

/*
|--------------------------------------------------------------------------
| 12-16. Perhitungan closing
|--------------------------------------------------------------------------
*/

test('system_balance dihitung benar: opening + deposit - withdrawal', function () {
    [$teller, $token] = createTellerForReconciliation(112);
    config(['bank.initial_cash_balance' => 500000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 500000,
    ], ['Authorization' => "Bearer $token"]);

    $reportId = $response->json('data.id');

    // Buat transaksi.
    createTestTransaction($teller->id, 'deposit', 200000);
    createTestTransaction($teller->id, 'withdrawal', 50000);

    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 650000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJsonPath('data.total_deposit', 200000)
        ->assertJsonPath('data.total_withdrawal', 50000)
        ->assertJsonPath('data.system_balance', 650000) // 500000 + 200000 - 50000
        ->assertJsonPath('data.closing_difference', 0);
});

test('closing_difference negatif dihitung benar', function () {
    [$teller, $token] = createTellerForReconciliation(113);
    config(['bank.initial_cash_balance' => 100000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $reportId = $response->json('data.id');

    createTestTransaction($teller->id, 'deposit', 50000);

    // Fisik kurang dari sistem.
    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 140000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJsonPath('data.system_balance', 150000) // 100000 + 50000
        ->assertJsonPath('data.closing_difference', -10000); // 140000 - 150000
});

test('closing_difference nol dihitung benar', function () {
    [$teller, $token] = createTellerForReconciliation(114);
    config(['bank.initial_cash_balance' => 100000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $reportId = $response->json('data.id');

    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJsonPath('data.closing_difference', 0);
});

test('closing_difference positif dihitung benar', function () {
    [$teller, $token] = createTellerForReconciliation(115);
    config(['bank.initial_cash_balance' => 100000]);

    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $reportId = $response->json('data.id');

    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 110000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJsonPath('data.closing_difference', 10000); // 110000 - 100000
});

test('perhitungan tidak terpengaruh oleh transaksi Teller lain', function () {
    [$teller1, $token1] = createTellerForReconciliation(116);
    [$teller2, $token2] = createTellerForReconciliation(117);
    config(['bank.initial_cash_balance' => 100000]);

    // Teller 1 membuat draft.
    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token1"]);

    $reportId = $response->json('data.id');

    // Transaksi milik Teller 1.
    createTestTransaction($teller1->id, 'deposit', 50000);

    // Transaksi milik Teller 2 — tidak boleh ikut terhitung.
    createTestTransaction($teller2->id, 'deposit', 999000);

    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 150000,
    ], ['Authorization' => "Bearer $token1"]);

    $response->assertStatus(200)
        ->assertJsonPath('data.total_deposit', 50000) // Hanya Teller 1
        ->assertJsonPath('data.system_balance', 150000);
});

/*
|--------------------------------------------------------------------------
| 17-18. Submit dan perhitungan ulang
|--------------------------------------------------------------------------
*/

test('submit menghitung ulang total dan saldo dari transaksi aktual', function () {
    [$teller, $token] = createTellerForReconciliation(118);
    config(['bank.initial_cash_balance' => 100000]);

    // Draft + closing.
    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $reportId = $response->json('data.id');

    createTestTransaction($teller->id, 'deposit', 50000);

    $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 150000,
    ], ['Authorization' => "Bearer $token"]);

    // Submit.
    $response = $this->postJson('/api/teller/submit-daily-report', [
        'date' => now()->toDateString(),
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.total_deposit', 50000)
        ->assertJsonPath('data.system_balance', 150000);
});

test('transaksi setelah closing tapi sebelum submit ikut terhitung', function () {
    [$teller, $token] = createTellerForReconciliation(119);
    config(['bank.initial_cash_balance' => 100000]);

    // Draft.
    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $reportId = $response->json('data.id');

    createTestTransaction($teller->id, 'deposit', 50000);

    // Closing: system_balance = 100000 + 50000 = 150000.
    $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $reportId,
        'physical_balance' => 200000,
    ], ['Authorization' => "Bearer $token"]);

    // Transaksi baru SETELAH closing.
    createTestTransaction($teller->id, 'deposit', 30000);

    // Submit harus menghitung ulang: 100000 + 80000 = 180000.
    $response = $this->postJson('/api/teller/submit-daily-report', [
        'date' => now()->toDateString(),
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.total_deposit', 80000)
        ->assertJsonPath('data.system_balance', 180000)  // Bukan 150000 dari closing
        ->assertJsonPath('data.physical_balance', 200000) // Tidak berubah
        ->assertJsonPath('data.closing_difference', 20000); // 200000 - 180000
});

/*
|--------------------------------------------------------------------------
| 19. Submit tanpa physical_balance
|--------------------------------------------------------------------------
*/

test('submit gagal jika physical_balance belum diisi', function () {
    [$teller, $token] = createTellerForReconciliation(120);
    config(['bank.initial_cash_balance' => 100000]);

    // Hanya opening, belum closing.
    $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $response = $this->postJson('/api/teller/submit-daily-report', [
        'date' => now()->toDateString(),
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Laporan belum lengkap. Periksa saldo awal dan penghitungan uang saat tutup kas.');
});

/*
|--------------------------------------------------------------------------
| 20. Tidak dapat submit berulang
|--------------------------------------------------------------------------
*/

test('laporan tidak dapat dikirim berulang saat status submitted', function () {
    [$teller, $token] = createTellerForReconciliation(121);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now()->toDateString(),
        'status' => 'submitted',
        'opening_balance' => 100000,
        'physical_balance' => 100000,
        'system_balance' => 100000,
        'submitted_at' => now(),
    ]);

    $response = $this->postJson('/api/teller/submit-daily-report', [
        'date' => now()->toDateString(),
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 21. Laporan approved tidak dapat diubah
|--------------------------------------------------------------------------
*/

test('laporan approved tidak dapat diubah melalui endpoint Teller', function () {
    [$teller, $token] = createTellerForReconciliation(122);

    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->toDateString(),
        'status' => 'approved',
        'opening_balance' => 500000,
        'physical_balance' => 500000,
        'system_balance' => 500000,
        'submitted_at' => now(),
        'approved_by' => $teller->id,
        'approved_at' => now(),
        'locked_at' => now(),
    ]);

    // Opening gagal.
    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 600000,
        'opening_physical_balance' => 600000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422);

    // Submit gagal.
    $response = $this->postJson('/api/teller/submit-daily-report', [
        'date' => now('Asia/Jakarta')->toDateString(),
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 22-24. Laporan rejected — perbaikan dan resubmit
|--------------------------------------------------------------------------
*/

test('laporan rejected dapat diperbaiki dan dikirim ulang', function () {
    [$teller, $token] = createTellerForReconciliation(123);

    $report = DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now()->toDateString(),
        'status' => 'rejected',
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
        'opening_difference' => 0,
        'physical_balance' => 90000,
        'system_balance' => 100000,
        'closing_difference' => -10000,
        'rejection_reason' => 'Selisih terlalu besar',
        'submitted_at' => now()->subHour(),
    ]);

    // Perbaiki opening.
    $response = $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now()->toDateString(),
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.submitted_at', null)
        ->assertJsonPath('data.rejection_reason', 'Selisih terlalu besar'); // Dipertahankan.

    // Perbaiki closing.
    $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $report->id,
        'physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token"])->assertStatus(200);

    // Submit ulang.
    $response = $this->postJson('/api/teller/submit-daily-report', [
        'date' => now()->toDateString(),
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.rejection_reason', null); // Di-clear saat submit.

    expect($response->json('data.submitted_at'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 25. Keamanan: Teller tidak bisa akses laporan Teller lain
|--------------------------------------------------------------------------
*/

test('Teller tidak dapat mengubah laporan milik Teller lain', function () {
    [$teller1, $token1] = createTellerForReconciliation(124);
    [$teller2, $token2] = createTellerForReconciliation(125);

    // Laporan milik Teller 2.
    $report = DailyReport::create([
        'teller_id' => $teller2->id,
        'report_date' => now()->toDateString(),
        'status' => 'draft',
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
    ]);

    // Teller 1 mencoba closing di laporan Teller 2.
    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $report->id,
        'physical_balance' => 100000,
    ], ['Authorization' => "Bearer $token1"]);

    $response->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| 26. Kompatibilitas laporan lama
|--------------------------------------------------------------------------
*/

test('laporan lama tanpa kolom rekonsiliasi tetap terbaca', function () {
    [$teller, $token] = createTellerForReconciliation(126);

    // Simulasi laporan lama — hanya kolom original.
    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => '2026-09-01',
        'status' => 'submitted',
        'total_deposit' => 200000,
        'total_withdrawal' => 100000,
        'submitted_at' => now(),
    ]);

    $response = $this->getJson('/api/teller/daily-reports', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.total_deposit', 200000)
        ->assertJsonPath('data.0.total_withdrawal', 100000);
});

test('rekonsiliasi hanya menghitung transaksi berhasil pada saldo sistem', function () {
    [$teller, $token] = createTellerForReconciliation(201);
    app(DailyReportService::class)->createOrUpdateDraft(
        $teller,
        Carbon::now('Asia/Jakarta'),
        ['opening_balance' => 100000, 'opening_physical_balance' => 100000]
    );

    createTransactionWithStatus($teller->id, 'deposit', 50000, 'approved');
    createTransactionWithStatus($teller->id, 'deposit', 25000, 'approved');
    createTransactionWithStatus($teller->id, 'withdrawal', 30000, 'approved');
    createTransactionWithStatus($teller->id, 'withdrawal', 20000, 'rejected');
    createTransactionWithStatus($teller->id, 'deposit', 10000, 'pending');

    $response = $this->withToken($token)
        ->getJson('/api/teller/cash-reconciliation');

    $response->assertOk()
        ->assertJsonPath('data.system_balance', 145000)
        ->assertJsonPath('data.transactions_summary.total_deposit', 75000)
        ->assertJsonPath('data.transactions_summary.total_withdrawal', 30000)
        ->assertJsonPath('data.transactions_summary.pending_transactions', 1)
        ->assertJsonPath('data.transactions_summary.failed_transactions', 1);
});

test('saldo sistem mengikuti skenario transaksi B sampai F', function () {
    $scenarios = [
        ['deposit-only', [['deposit', 50000, 'approved']], 150000],
        ['withdrawal-only', [['withdrawal', 30000, 'approved']], 70000],
        ['no-transactions', [], 100000],
        [
            'all-failed',
            [
                ['deposit', 50000, 'rejected'],
                ['withdrawal', 30000, 'failed'],
            ],
            100000,
        ],
        [
            'with-pending',
            [
                ['deposit', 50000, 'approved'],
                ['withdrawal', 20000, 'pending'],
            ],
            150000,
        ],
    ];

    foreach ($scenarios as $index => [$name, $transactions, $expectedBalance]) {
        [$teller] = createTellerForReconciliation(210 + $index);
        app(DailyReportService::class)->createOrUpdateDraft(
            $teller,
            Carbon::now('Asia/Jakarta'),
            ['opening_balance' => 100000, 'opening_physical_balance' => 100000]
        );

        foreach ($transactions as [$type, $amount, $status]) {
            createTransactionWithStatus($teller->id, $type, $amount, $status);
        }

        $result = app(DailyReportService::class)
            ->getReconciliation(
                $teller,
                Carbon::now('Asia/Jakarta')
            );

        expect($result['system_balance'])->toBe($expectedBalance, $name);
    }
});

test('laporan historis menggunakan snapshot setelah transaksi berubah', function () {
    [$teller, $token] = createTellerForReconciliation(220);
    $date = now('Asia/Jakarta')->toDateString();

    $opening = $this->withToken($token)->postJson(
        '/api/teller/cash-reconciliation/opening',
        [
            'date' => $date,
            'opening_balance' => 100000,
            'opening_physical_balance' => 100000,
        ]
    )->assertCreated();

    $transaction = createTransactionWithStatus(
        $teller->id,
        'deposit',
        50000,
        'approved'
    );

    $this->withToken($token)->postJson(
        '/api/teller/cash-reconciliation/closing',
        [
            'report_id' => $opening->json('data.id'),
            'physical_balance' => 150000,
        ]
    )->assertOk();

    $this->withToken($token)->postJson(
        '/api/teller/submit-daily-report',
        ['date' => $date]
    )->assertCreated();

    $transaction->update([
        'amount' => 90000,
        'status' => 'rejected',
        'rejected_at' => now(),
    ]);

    $this->withToken($token)
        ->getJson('/api/teller/cash-reconciliation?date='.$date)
        ->assertOk()
        ->assertJsonPath('data.system_balance', 150000)
        ->assertJsonPath('data.transactions_summary.total_deposit', 50000)
        ->assertJsonPath('data.transactions.0.amount', 50000)
        ->assertJsonPath('data.transactions.0.status', 'approved');
});

test('penutupan baru menolak tanggal kemarin', function () {
    [$teller, $token] = createTellerForReconciliation(221);
    $yesterday = now('Asia/Jakarta')->subDay()->toDateString();

    $this->withToken($token)->postJson(
        '/api/teller/cash-reconciliation/opening',
        [
            'date' => $yesterday,
            'opening_physical_balance' => 100000,
        ]
    )->assertUnprocessable();

    $this->assertDatabaseMissing('daily_reports', [
        'teller_id' => $teller->id,
        'report_date' => $yesterday,
    ]);
});

test('penutupan otomatis tidak mengarang kas fisik dan idempoten', function () {
    [$teller] = createTellerForReconciliation(222);
    app(DailyReportService::class)->createOrUpdateDraft(
        $teller,
        Carbon::now('Asia/Jakarta'),
        ['opening_balance' => 100000, 'opening_physical_balance' => 100000]
    );
    createTransactionWithStatus($teller->id, 'deposit', 50000, 'approved');
    createTransactionWithStatus($teller->id, 'withdrawal', 20000, 'pending');
    createTransactionWithStatus($teller->id, 'deposit', 10000, 'rejected');

    $service = app(DailyReportService::class);
    $today = Carbon::now('Asia/Jakarta');

    expect($service->autoCloseForDate($today))->toBe(1)
        ->and($service->autoCloseForDate($today))->toBe(0);

    $report = DailyReport::query()
        ->where('teller_id', $teller->id)
        ->whereDate('report_date', $today->toDateString())
        ->firstOrFail();

    expect($report->status)->toBe('auto_closed_unverified')
        ->and($report->system_balance)->toBe(150000)
        ->and($report->total_deposit)->toBe(50000)
        ->and($report->total_withdrawal)->toBe(0)
        ->and($report->pending_transactions)->toBe(1)
        ->and($report->failed_transactions)->toBe(1)
        ->and(count($report->transaction_snapshot))->toBe(3)
        ->and($report->opening_physical_balance)->toBe(100000)
        ->and($report->physical_balance)->toBeNull()
        ->and($report->opening_difference)->toBe(0)
        ->and($report->closing_difference)->toBeNull()
        ->and($report->auto_closed_at)->not->toBeNull();

    $export = $service
        ->getReconciliation($teller, $today)['export'];
    expect($export['physical_balance'])->toBeNull()
        ->and($export['closing_difference'])->toBeNull()
        ->and($export['cash_match_status'])->toBe('Belum dihitung')
        ->and($export['report_status'])->toBe('Penutupan Otomatis · Belum Diverifikasi');

    expect(fn () => app(TransactionService::class)
        ->deposit(Customer::factory()->create(), 10000, $teller->id))
        ->toThrow(ValidationException::class);
});

test('penutupan otomatis tidak menimpa laporan yang sudah dikirim', function () {
    [$teller] = createTellerForReconciliation(223);
    $report = DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->toDateString(),
        'status' => 'submitted',
        'opening_balance' => 100000,
        'opening_physical_balance' => 100000,
        'physical_balance' => 100000,
        'system_balance' => 100000,
        'closing_difference' => 0,
        'submitted_at' => now(),
    ]);

    expect(app(DailyReportService::class)
        ->autoCloseForDate(Carbon::now('Asia/Jakarta')))->toBe(0);

    expect($report->fresh()->status)->toBe('submitted')
        ->and($report->fresh()->physical_balance)->toBe(100000);
});
