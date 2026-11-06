<?php

use App\Models\CashDepositScanToken;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Journal;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CustomerImportService;
use App\Services\TransactionReportService;
use App\Services\TransactionService;
use Database\Seeders\ClassRosterSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

function excelEntries(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, $contents);
    $archive = new ZipArchive;

    try {
        expect($archive->open($path))->toBeTrue();
        $entries = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            $entries[$name] = $archive->getFromIndex($index);
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                expect(simplexml_load_string(
                    $entries[$name],
                    SimpleXMLElement::class,
                    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
                ))->toBeInstanceOf(SimpleXMLElement::class);
            }
        }

        return $entries;
    } finally {
        if ($archive->status === ZipArchive::ER_OK) {
            $archive->close();
        }
        @unlink($path);
    }
}

function reportingUser(string $role, string $username): array
{
    $user = User::create([
        'name' => $username,
        'username' => $username,
        'full_name' => $username,
        'email' => $username.'@example.test',
        'password' => Hash::make('test-password'),
        'role' => $role,
        'status' => 'active',
    ]);

    return [$user, $user->createToken('test')->plainTextToken];
}

function transactionForReport(
    int $customerId,
    int $tellerId,
    string $type,
    int $amount,
    string $status,
    string $at
): Transaction {
    return Transaction::create([
        'customer_id' => $customerId,
        'user_id' => $tellerId,
        'transaction_date' => $at,
        'transaction_type' => $type,
        'amount' => $amount,
        'status' => $status,
    ]);
}

test('transaksi yang dibatalkan tetap muncul dalam rekonsiliasi teller tanpa dihitung sebagai transaksi berhasil', function () {
    [$teller] = reportingUser('teller', 'cancelled_report_teller');
    $customer = Customer::factory()->create();
    $transaction = transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        20000,
        'cancelled',
        now()->subDay()->toDateTimeString()
    );
    $transaction->update(['cancelled_at' => now()]);

    $date = now('Asia/Jakarta')->toDateString();
    $report = app(TransactionReportService::class)->reconciliation(
        $date,
        $teller->id
    );

    expect($report['transactions']->pluck('id')->all())
        ->toContain($transaction->id)
        ->and($report['summary']['total_transactions'])->toBe(0)
        ->and($report['summary']['pending_transactions'])->toBe(0)
        ->and($report['summary']['failed_transactions'])->toBe(0);
});

test('admin daily and monthly reports aggregate approved transactions only in Jakarta dates', function () {
    [$admin, $token] = reportingUser('administrator', 'report_admin');
    [$teller] = reportingUser('teller', 'report_teller');
    $customer = Customer::factory()->create(['status' => 'blocked']);

    transactionForReport($customer->id, $teller->id, 'deposit', 20000, 'approved', '2026-03-01 17:05:00');
    transactionForReport($customer->id, $teller->id, 'withdrawal', 5000, 'approved', '2026-03-01 18:05:00');
    transactionForReport($customer->id, $teller->id, 'deposit', 40000, 'rejected', '2026-03-01 19:05:00');
    transactionForReport($customer->id, $teller->id, 'withdrawal', 25000, 'pending', '2026-03-01 20:05:00');
    transactionForReport($customer->id, $teller->id, 'deposit', 3000, 'approved', '2026-03-10 17:05:00');

    $headers = ['Authorization' => 'Bearer '.$token];
    $daily = $this->getJson('/api/admin/reports/transactions?period=daily&date=2026-03-02', $headers);
    $daily->assertOk()
        ->assertJsonPath('data.summary.total_transactions', 2)
        ->assertJsonPath('data.summary.total_deposit', 20000)
        ->assertJsonPath('data.summary.total_withdrawal', 5000)
        ->assertJsonPath('data.summary.pending_transactions', 1)
        ->assertJsonPath('data.summary.failed_transactions', 1)
        ->assertJsonPath('data.transactions.0.customer.status', 'blocked');

    $monthly = $this->getJson('/api/admin/reports/transactions?period=monthly&month=2026-03', $headers);
    $monthly->assertOk()
        ->assertJsonPath('data.summary.total_transactions', 3)
        ->assertJsonPath('data.summary.total_deposit', 23000)
        ->assertJsonPath('data.daily_breakdown.0.date', '2026-03-02')
        ->assertJsonPath('data.daily_breakdown.0.total_deposit', 20000)
        ->assertJsonPath('data.daily_breakdown.0.total_withdrawal', 5000)
        ->assertJsonPath('data.daily_breakdown.1.date', '2026-03-11');
    expect(array_sum(array_column($monthly->json('data.daily_breakdown'), 'total_transactions')))
        ->toBe($monthly->json('data.summary.total_transactions'));
    expect(array_sum(array_column($monthly->json('data.daily_breakdown'), 'total_deposit')))
        ->toBe($monthly->json('data.summary.total_deposit'));
    expect(array_sum(array_column($monthly->json('data.daily_breakdown'), 'total_withdrawal')))
        ->toBe($monthly->json('data.summary.total_withdrawal'));
});

test('only administrator can access transaction reports', function () {
    [, $token] = reportingUser('teller', 'report_access_teller');

    $this->getJson('/api/admin/reports/transactions?period=daily&date=2026-03-02', [
        'Authorization' => 'Bearer '.$token,
    ])->assertForbidden();
});

test('supervisor can view and export daily and monthly transaction reports', function () {
    [, $token] = reportingUser('supervisor', 'report_supervisor');
    [$teller] = reportingUser('teller', 'report_supervisor_teller');
    $customer = Customer::factory()->create();

    transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        15000,
        'approved',
        '2026-03-01 17:05:00'
    );

    $headers = ['Authorization' => 'Bearer '.$token];

    $this->getJson(
        '/api/supervisor/reports/transactions?period=daily&date=2026-03-02',
        $headers
    )->assertOk()
        ->assertJsonPath('data.summary.total_transactions', 1)
        ->assertJsonPath('data.summary.total_deposit', 15000);

    $this->getJson(
        '/api/supervisor/reports/transactions?period=monthly&month=2026-03',
        $headers
    )->assertOk()
        ->assertJsonPath('data.summary.total_transactions', 1)
        ->assertJsonPath('data.daily_breakdown.0.date', '2026-03-02');

    $this->get(
        '/api/supervisor/reports/transactions/export?period=daily&date=2026-03-02',
        $headers
    )->assertOk()
        ->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
});

test('daily and monthly transaction exports match approved summaries and include negative cash', function () {
    [$admin, $token] = reportingUser('administrator', 'excel_report_admin');
    [$teller] = reportingUser('teller', 'excel_report_teller');
    $customer = Customer::factory()->create([
        'status' => 'blocked',
        'account_number' => 'EXL001',
        'student_name' => 'Nasabah Nonaktif',
    ]);

    transactionForReport($customer->id, $teller->id, 'deposit', 25000, 'approved', '2026-03-01 17:05:00');
    transactionForReport($customer->id, $teller->id, 'withdrawal', 5000, 'approved', '2026-03-01 18:05:00');
    transactionForReport($customer->id, $teller->id, 'deposit', 100000, 'pending', '2026-03-01 19:05:00');
    transactionForReport($customer->id, $teller->id, 'withdrawal', 20000, 'rejected', '2026-03-01 20:05:00');
    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => '2026-03-02',
        'status' => 'submitted',
        'opening_balance' => 0,
        'total_withdrawal' => 75000,
        'system_balance' => -75000,
        'physical_balance' => 0,
    ]);

    $headers = ['Authorization' => 'Bearer '.$token];
    $daily = $this->get(
        '/api/admin/reports/transactions/export?period=daily&date=2026-03-02',
        $headers
    );
    $daily->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('content-disposition', 'attachment; filename="laporan-transaksi-2026-03-02.xlsx"');
    $dailyEntries = excelEntries($daily->getContent());
    expect($dailyEntries)->toHaveKey('xl/workbook.xml');
    expect($dailyEntries)->toHaveKey('xl/worksheets/sheet1.xml');
    expect($dailyEntries['xl/worksheets/sheet1.xml'])->toContain('Jumlah transaksi berhasil');
    expect($dailyEntries['xl/worksheets/sheet1.xml'])->toContain('<v>2</v>');
    expect($dailyEntries['xl/worksheets/sheet1.xml'])->toContain('<v>25000</v>');
    expect($dailyEntries['xl/worksheets/sheet1.xml'])->toContain('<v>5000</v>');
    expect($dailyEntries['xl/worksheets/sheet2.xml'])->toContain('pending');
    expect($dailyEntries['xl/worksheets/sheet2.xml'])->toContain('rejected');
    expect($dailyEntries['xl/worksheets/sheet2.xml'])->toContain('Nasabah Nonaktif');
    expect($dailyEntries['xl/worksheets/sheet3.xml'])->toContain('<v>-75000</v>');

    $monthly = $this->get(
        '/api/admin/reports/transactions/export?period=monthly&month=2026-03',
        $headers
    );
    $monthly->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="laporan-transaksi-2026-03.xlsx"');
    $monthlyEntries = excelEntries($monthly->getContent());
    expect($monthlyEntries['xl/workbook.xml'])->toContain('Ringkasan Harian');
    expect($monthlyEntries['xl/worksheets/sheet1.xml'])->toContain('<v>2</v>');
    expect($monthlyEntries['xl/worksheets/sheet1.xml'])->toContain('<v>25000</v>');
    expect($monthlyEntries['xl/worksheets/sheet2.xml'])->toContain('2026-03-02');
    expect($monthlyEntries['xl/worksheets/sheet4.xml'])->toContain('<v>-75000</v>');
});

test('transaction export is unavailable to users without administrator role', function () {
    [, $token] = reportingUser('supervisor', 'excel_report_supervisor');

    $this->get(
        '/api/admin/reports/transactions/export?period=daily&date=2026-03-02',
        ['Authorization' => 'Bearer '.$token]
    )->assertForbidden();
});

test('accounting journal export uses posted journal rows and preserves debit credit balance', function () {
    [, $token] = reportingUser('supervisor', 'excel_journal_supervisor');
    [$teller] = reportingUser('teller', 'excel_journal_teller');
    $customer = Customer::factory()->create(['balance' => 0]);
    $posted = transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        15000,
        'pending',
        now()->toDateTimeString()
    );
    app(TransactionService::class)->approve($posted->id, $customer->id);

    $pending = transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        9000,
        'pending',
        now()->toDateTimeString()
    );
    Journal::create([
        'transaction_id' => $pending->id,
        'account_name' => 'Invalid pending row',
        'type' => 'debit',
        'amount' => 9000,
    ]);

    $response = $this->get('/api/supervisor/journals/export', [
        'Authorization' => 'Bearer '.$token,
    ]);
    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $entries = excelEntries($response->getContent());
    expect($entries['xl/workbook.xml'])->toContain('Jurnal');
    expect($entries['xl/worksheets/sheet1.xml'])->toContain('<v>15000</v>');
    expect($entries['xl/worksheets/sheet1.xml'])->toContain('<v>0</v>');
    expect($entries['xl/worksheets/sheet2.xml'])->toContain('Kas');
    expect($entries['xl/worksheets/sheet2.xml'])->toContain('Tabungan Nasabah');
    expect($entries['xl/worksheets/sheet2.xml'])->not->toContain('Invalid pending row');
    expect(substr_count($entries['xl/worksheets/sheet2.xml'], '<row '))->toBe(3);
    $worksheet = simplexml_load_string($entries['xl/worksheets/sheet2.xml']);
    expect(array_values(array_map(
        static fn (SimpleXMLElement $element) => $element->getName(),
        iterator_to_array($worksheet->children())
    )))->toBe(['sheetViews', 'cols', 'sheetData']);

    $apiRows = $this->getJson('/api/supervisor/journals', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->json('data');
    expect(count($apiRows))->toBe(2);
    expect(array_sum(array_map(
        static fn ($row) => $row['type'] === 'debit' ? $row['amount'] : 0,
        $apiRows
    )))->toBe(array_sum(array_map(
        static fn ($row) => $row['type'] === 'credit' ? $row['amount'] : 0,
        $apiRows
    )));
});

test('accounting journal export is forbidden to teller', function () {
    [, $token] = reportingUser('teller', 'excel_journal_teller_forbidden');

    $this->get('/api/supervisor/journals/export', [
        'Authorization' => 'Bearer '.$token,
    ])->assertForbidden();
});

test('accounting journal list shows the newest transaction first', function () {
    [, $token] = reportingUser('supervisor', 'journal_order_supervisor');
    [$teller] = reportingUser('teller', 'journal_order_teller');
    $customer = Customer::factory()->create();

    $older = transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        10000,
        'approved',
        '2026-03-02 10:00:00'
    );
    $newer = transactionForReport(
        $customer->id,
        $teller->id,
        'withdrawal',
        10000,
        'approved',
        '2026-03-02 11:00:00'
    );

    foreach ([$older, $newer] as $transaction) {
        Journal::create([
            'transaction_id' => $transaction->id,
            'account_name' => 'Kas',
            'type' => 'debit',
            'amount' => 10000,
        ]);
        Journal::create([
            'transaction_id' => $transaction->id,
            'account_name' => 'Tabungan Nasabah',
            'type' => 'credit',
            'amount' => 10000,
        ]);
    }

    $rows = $this->getJson('/api/supervisor/journals', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()->json('data');

    expect(array_column($rows, 'transaction_id'))->toBe([
        $newer->id,
        $newer->id,
        $older->id,
        $older->id,
    ]);
});

test('cash closing stores negative system balance without changing customer balances', function () {
    [$teller, $token] = reportingUser('teller', 'negative_cash_teller');
    $customer = Customer::factory()->create(['balance' => 50000]);
    $date = now('Asia/Jakarta')->toDateString();
    $report = DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => $date,
        'status' => 'draft',
        'opening_balance' => 0,
        'opening_physical_balance' => 0,
    ]);
    transactionForReport($customer->id, $teller->id, 'withdrawal', 75000, 'approved', now()->toDateTimeString());

    $response = $this->postJson('/api/teller/cash-reconciliation/closing', [
        'report_id' => $report->id,
        'physical_balance' => 0,
    ], ['Authorization' => 'Bearer '.$token]);

    $response->assertOk()->assertJsonPath('data.system_balance', -75000);
    expect($customer->fresh()->balance)->toBe(50000);
});

test('teller report exports include the selected date, all statuses, and reconciliation fields', function () {
    [$teller, $token] = reportingUser('teller', 'teller_report_export');
    $customer = Customer::factory()->create([
        'account_number' => 'TELLERXLSX1',
        'student_name' => 'Nasabah Laporan',
    ]);
    $date = now('Asia/Jakarta')->toDateString();
    $transactionDate = now('Asia/Jakarta')
        ->setTime(10, 0)
        ->utc()
        ->toDateTimeString();

    transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        25000,
        'approved',
        $transactionDate
    );
    transactionForReport(
        $customer->id,
        $teller->id,
        'withdrawal',
        10000,
        'pending',
        $transactionDate
    );
    transactionForReport(
        $customer->id,
        $teller->id,
        'deposit',
        5000,
        'rejected',
        $transactionDate
    );
    DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => $date,
        'status' => 'submitted',
        'opening_balance' => 100000,
        'opening_physical_balance' => 99000,
        'opening_difference' => -1000,
        'total_deposit' => 25000,
        'total_withdrawal' => 0,
        'system_balance' => 125000,
        'physical_balance' => 124000,
        'closing_difference' => -1000,
        'rejection_reason' => null,
    ]);

    $this->get('/api/teller/daily-report/export?date='.$date)->assertUnauthorized();

    $headers = ['Authorization' => 'Bearer '.$token];
    $daily = $this->get(
        '/api/teller/daily-report/export?date='.$date,
        $headers
    );
    $daily->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('content-disposition', 'attachment; filename="laporan-harian-'.$date.'.xlsx"');
    $dailySheets = excelEntries($daily->getContent());
    expect($dailySheets['xl/worksheets/sheet1.xml'])->toContain('Laporan Harian Teller');
    expect($dailySheets['xl/worksheets/sheet1.xml'])->toContain('<v>1</v>');
    expect($dailySheets['xl/worksheets/sheet2.xml'])->toContain('Nasabah Laporan');
    expect($dailySheets['xl/worksheets/sheet2.xml'])->toContain('pending');
    expect($dailySheets['xl/worksheets/sheet2.xml'])->toContain('rejected');

    $reconciliation = $this->get(
        '/api/teller/cash-reconciliation/export?date='.$date,
        $headers
    );
    $reconciliation->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('content-disposition', 'attachment; filename="rekonsiliasi-kas-'.$date.'.xlsx"');
    $reconciliationSheets = excelEntries($reconciliation->getContent());
    expect($reconciliationSheets['xl/styles.xml'])->toContain('&quot;Rp&quot; #,##0');
    expect($reconciliationSheets['xl/worksheets/sheet1.xml'])->toContain('Selisih uang awal');
    expect($reconciliationSheets['xl/worksheets/sheet1.xml'])->toContain('Menunggu persetujuan');
    expect($reconciliationSheets['xl/worksheets/sheet1.xml'])->toContain('Saldo seharusnya menurut sistem');
    expect($reconciliationSheets['xl/worksheets/sheet1.xml'])->toContain('-Rp 1.000');
    expect($reconciliationSheets['xl/worksheets/sheet1.xml'])->toContain('s="3"');
    expect($reconciliationSheets['xl/worksheets/sheet2.xml'])->toContain('Pending');
    expect($reconciliationSheets['xl/worksheets/sheet2.xml'])->toContain('Setoran');
    expect($reconciliationSheets['xl/worksheets/sheet2.xml'])->toContain('Berhasil');

});

test('teller report export endpoints require the teller role', function () {
    [, $adminToken] = reportingUser('administrator', 'teller_report_export_forbidden');

    $this->get('/api/teller/daily-report/export', [
        'Authorization' => 'Bearer '.$adminToken,
    ])->assertForbidden();
    $this->get('/api/teller/cash-reconciliation/export', [
        'Authorization' => 'Bearer '.$adminToken,
    ])->assertForbidden();
});

test('customer import template downloads a valid xlsx for administrators', function () {
    [, $token] = reportingUser('administrator', 'customer_template_admin');
    app(ClassRosterSeeder::class)->run();
    $temporaryFilesBefore = glob(storage_path('app/customer-template-*')) ?: [];

    $response = $this->get('/api/admin/customers/import/template', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('content-disposition', 'attachment; filename="template-import-nasabah.xlsx"');

    $entries = excelEntries($response->getContent());
    expect($entries)->toHaveKey('xl/workbook.xml');
    expect($entries)->toHaveKey('xl/worksheets/sheet2.xml')
        ->and($entries['xl/workbook.xml'])->toContain('Referensi Kelas');
    $worksheet = $entries['xl/worksheets/sheet1.xml'];
    expect(CustomerImportService::HEADERS)->toBe([
        'NIS',
        'Nama Lengkap Siswa',
        'Tingkat Kelas',
        'Jurusan',
        'Kelas',
        'Email Pribadi',
        'Setoran Awal (Rp)',
    ]);
    foreach (CustomerImportService::HEADERS as $header) {
        expect($worksheet)->toContain($header);
    }
    expect($worksheet)->not->toContain('class_id');
    expect($worksheet)->not->toContain('student_name')
        ->not->toContain('grade_level')
        ->not->toContain('major_name')
        ->not->toContain('opening_balance');
    expect($worksheet)->toContain('style="1"');
    $referenceSheet = simplexml_load_string($entries['xl/worksheets/sheet2.xml']);
    $referenceSheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    expect($referenceSheet->xpath('//x:sheetData/x:row'))->toHaveCount(49);
    expect($entries['xl/worksheets/sheet2.xml'])->toContain('X RPL 1');
    expect($entries['xl/worksheets/sheet2.xml'])->toContain('Rekayasa Perangkat Lunak');
    expect($entries)->toHaveKey('xl/worksheets/sheet3.xml')
        ->and($entries['xl/worksheets/sheet3.xml'])->toContain('Petunjuk Pengisian');
    expect($entries['xl/styles.xml'])->toContain('formatCode="@"');
    expect($entries['xl/styles.xml'])->toContain('formatCode="[$Rp-421] #,##0"');
    expect(glob(storage_path('app/customer-template-*')) ?: [])
        ->toBe($temporaryFilesBefore);
});

test('empty customer import template returns a readable validation error', function () {
    [, $token] = reportingUser('administrator', 'empty_import_admin');
    $workbook = app(CustomerImportService::class)->template();

    $response = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('template.xlsx', $workbook),
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'Template belum berisi data nasabah.');
});

test('customer import rejects old template headers with the expected header list', function () {
    [, $token] = reportingUser('administrator', 'old_import_header_admin');
    $workbook = app(CustomerImportService::class)->template([
        ['nis', 'student_name', 'grade_level', 'major_name', 'rombel', 'email', 'opening_balance'],
        ['123456', 'Siswa Lama', 'X', 'Rekayasa Perangkat Lunak', '1', 'old@example.test', '10000'],
    ]);

    $response = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('old-template.xlsx', $workbook),
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath(
            'errors.file.0',
            'Kolom wajib harus berurutan: NIS, Nama Lengkap Siswa, Tingkat Kelas, Jurusan, Kelas, Email Pribadi, Setoran Awal (Rp).'
        );
});

test('customer import template keeps NIS as text and opening balance as a Rupiah number', function () {
    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['001234', 'Siswa Uji', 'X', 'Rekayasa Perangkat Lunak', '1', 'siswa@example.test', '10000'],
    ]);
    $entries = excelEntries($workbook);

    expect($entries['xl/worksheets/sheet1.xml'])
        ->toContain('<c r="A2" s="1" t="inlineStr">')
        ->toContain('<c r="G2" s="2"><v>10000</v></c>');

    $path = tempnam(sys_get_temp_dir(), 'mapped-import-');
    file_put_contents($path, $workbook);
    try {
        $rows = app(CustomerImportService::class)->parse($path);
        $mapped = app(CustomerImportService::class)->mapRow($rows[1]['cells']);

        expect($mapped)->toMatchArray([
            'student_number' => '001234',
            'student_name' => 'Siswa Uji',
            'grade_level' => 'X',
            'major_name' => 'Rekayasa Perangkat Lunak',
            'rombel' => '1',
            'email' => 'siswa@example.test',
            'opening_balance' => '10000',
        ]);
    } finally {
        @unlink($path);
    }
});

test('customer import rejects invalid and oversized uploads with field-specific errors', function () {
    [, $token] = reportingUser('administrator', 'invalid_import_upload_admin');
    $headers = [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ];

    $invalidFile = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('not-excel.xlsx', 'not an xlsx archive'),
    ], $headers);
    $invalidFile->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'File bukan XLSX yang valid.');

    $oversizedFile = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent(
            'oversized.xlsx',
            str_repeat('x', 5 * 1024 * 1024 + 1)
        ),
    ], $headers);
    $oversizedFile->assertUnprocessable();
    expect($oversizedFile->json('errors.file'))
        ->toContain('Ukuran file maksimal 5 MB.');

    $wrongExtension = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('not-excel.csv', 'nis,student_name'),
    ], $headers);
    $wrongExtension->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'File harus menggunakan ekstensi .xlsx.');
});

test('xlsx import preview and confirmation create valid accounts without exposing credentials', function () {
    [, $token] = reportingUser('administrator', 'import_admin');
    $major = Major::factory()->create([
        'name' => 'Rekayasa Perangkat Lunak',
        'code' => 'RPL',
    ]);
    $schoolClass = SchoolClass::factory()->create([
        'name' => 'X RPL 1',
        'major_id' => $major->id,
    ]);
    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['0012345678', 'Siswa Import', 'X', $major->name, '1', 'import1@example.test', '10000'],
        ['0012345678', 'Siswa Duplikat', 'X', $major->name, '1', 'import2@example.test', '10000'],
        ['0012345679', 'Siswa Invalid', 'X', $major->name, '1', 'import3@example.test', '9999'],
    ]);
    $file = UploadedFile::fake()->createWithContent('nasabah.xlsx', $workbook);

    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    $preview = $this->post('/api/admin/customers/import/preview', [
        'file' => $file,
    ], $headers);
    $preview->assertOk()
        ->assertJsonPath('data.checked_rows', 3)
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.failed_rows', 2)
        ->assertJsonPath('data.duplicate_rows', 1)
        ->assertJsonPath('data.rows_to_import.0.nis', '0012345678')
        ->assertJsonPath('data.rows_to_import.0.class_id', $schoolClass->id)
        ->assertJsonPath('data.preview_rows.0.grade_level', 'X')
        ->assertJsonPath('data.preview_rows.0.major_name', $major->name)
        ->assertJsonPath('data.preview_rows.0.rombel', '1')
        ->assertJsonPath('data.preview_rows.0.status', 'valid');

    $confirmed = $this->postJson('/api/admin/customers/import/confirm', [
        'import_token' => $preview->json('data.import_token'),
    ], $headers);
    $confirmed->assertOk()
        ->assertJsonPath('data.imported_rows', 1)
        ->assertJsonPath('data.failed_rows', 2)
        ->assertJsonPath('data.duplicate_rows', 1)
        ->assertJsonCount(1, 'data.initial_credentials')
        ->assertJsonMissingPath('data.password');

    $customer = Customer::where('student_number', '0012345678')->firstOrFail();
    expect($customer->class_id)->toBe($schoolClass->id);
    $temporaryPassword = $confirmed->json('data.initial_credentials.0.temporary_password');
    expect($customer->balance)->toBe(10000);
    expect($customer->transactions()->first()->status)->toBe('approved');
    expect($customer->transactions()->first()->journals)->toHaveCount(2);
    expect($customer->transactions()->first()->journals->sum('amount'))->toBe(20000);
    expect($customer->user->username)->toBe('0012345678');
    expect($customer->user->password)->not->toBe('0012345678');
    expect(Hash::check($temporaryPassword, $customer->user->password))->toBeTrue();
    expect($customer->user->must_change_password)->toBeTrue();
    expect($customer->user->temporary_password_expires_at->isFuture())->toBeTrue();
    expect($confirmed->getContent())->not->toContain('security_pin');
});

test('xlsx import resolves grade major and rombel combinations to existing classes', function () {
    [, $token] = reportingUser('administrator', 'class_mapping_import_admin');
    $classDefinitions = [
        ['RPL', 'Rekayasa Perangkat Lunak', 'X RPL 1', 'X', '1'],
        ['TKJ', 'Teknik Komputer dan Jaringan', 'XI TKJ 2', 'XI', '2'],
        ['DKV', 'Desain Komunikasi Visual', 'XII DKV 1', 'XII', '1'],
    ];
    $rows = [CustomerImportService::HEADERS];
    $expectedClassIds = [];

    foreach ($classDefinitions as $index => [$code, $name, $className, $grade, $rombel]) {
        $major = Major::factory()->create(['name' => $name, 'code' => $code]);
        $schoolClass = SchoolClass::factory()->create([
            'name' => $className,
            'major_id' => $major->id,
        ]);
        $expectedClassIds[] = $schoolClass->id;
        $rows[] = [
            '00123456'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            'Siswa '.$code,
            $grade,
            $name,
            $rombel,
            strtolower($code).'@example.test',
            '10000',
        ];
    }

    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    $preview = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent(
            'classes.xlsx',
            app(CustomerImportService::class)->template($rows)
        ),
    ], $headers);
    $preview->assertOk()
        ->assertJsonPath('data.valid_rows', 3)
        ->assertJsonPath('data.preview_rows.0.class_id', $expectedClassIds[0])
        ->assertJsonPath('data.preview_rows.1.class_id', $expectedClassIds[1])
        ->assertJsonPath('data.preview_rows.2.class_id', $expectedClassIds[2]);

    $confirmed = $this->postJson('/api/admin/customers/import/confirm', [
        'import_token' => $preview->json('data.import_token'),
    ], $headers);
    $confirmed->assertOk()->assertJsonPath('data.imported_rows', 3);

    foreach ($rows as $index => $row) {
        if ($index === 0) {
            continue;
        }
        $customer = Customer::where('student_number', $row[0])->firstOrFail();
        expect($customer->class_id)->toBe($expectedClassIds[$index - 1]);
        expect($customer->user->email_verified_at)->toBeNull();
    }
});

test('xlsx import explains missing class major and invalid rombel', function () {
    [, $token] = reportingUser('administrator', 'class_validation_import_admin');
    $major = Major::factory()->create([
        'name' => 'Rekayasa Perangkat Lunak',
        'code' => 'RPL',
    ]);
    SchoolClass::factory()->create([
        'name' => 'X RPL 1',
        'major_id' => $major->id,
    ]);
    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['ROW-CLASS-1', 'Kelas belum ada', 'XII', $major->name, '9', 'class@example.test', '10000'],
        ['ROW-CLASS-2', 'Jurusan belum ada', 'X', 'Jurusan Tidak Ada', '1', 'major@example.test', '10000'],
        ['ROW-CLASS-3', 'Rombel tidak valid', 'X', $major->name, 'A', 'rombel@example.test', '10000'],
        ['ROW-CLASS-4', 'Kelas belum terdaftar', 'X', $major->name, '2', 'missing-class@example.test', '10000'],
    ]);

    $response = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('invalid-class.xlsx', $workbook),
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.valid_rows', 0)
        ->assertJsonPath('data.failed_rows', 4)
        ->assertJsonPath('data.preview_rows.0.status', 'invalid')
        ->assertJsonPath('data.preview_rows.0.messages.0', 'Jurusan Rekayasa Perangkat Lunak hanya memiliki kelas 1 sampai 2.')
        ->assertJsonPath('data.preview_rows.1.messages.0', 'Jurusan Jurusan Tidak Ada tidak ditemukan dalam daftar jurusan sistem.')
        ->assertJsonPath('data.preview_rows.2.messages.0', 'Kelas harus berupa nomor rombel positif, misalnya 1.')
        ->assertJsonPath('data.preview_rows.3.messages.0', 'Kelas X Rekayasa Perangkat Lunak 2 belum tersedia atau tercatat lebih dari sekali. Silakan periksa data kelas di sistem.');
});

test('xlsx import preview rejects NIS already registered in the database', function () {
    [, $token] = reportingUser('administrator', 'registered_nis_import_admin');
    $major = Major::factory()->create(['name' => 'Jaringan Komputer', 'code' => 'TKJ']);
    $schoolClass = SchoolClass::factory()->create([
        'name' => 'XI TKJ 2',
        'major_id' => $major->id,
    ]);
    Customer::factory()->create(['student_number' => '0098765432']);

    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['0098765432', 'Siswa NIS Terdaftar', 'XI', $major->name, '2', 'registered-nis@example.test', '10000'],
    ]);
    $response = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('registered-nis.xlsx', $workbook),
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.valid_rows', 0)
        ->assertJsonPath('data.failed_rows', 1)
        ->assertJsonPath('data.duplicate_rows', 1)
        ->assertJsonPath('data.issues.0.row', 2)
        ->assertJsonPath('data.issues.0.messages.0', 'NIS sudah terdaftar pada sistem.');
});

test('xlsx import reports row numbers for invalid customer data', function () {
    [, $token] = reportingUser('administrator', 'invalid_import_rows_admin');
    $major = Major::factory()->create(['name' => 'RPL', 'code' => 'RPL']);
    SchoolClass::factory()->create([
        'name' => 'X RPL 1',
        'major_id' => $major->id,
    ]);
    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['', '   ', 'X', $major->name, '1', 'invalid-email', '9999'],
    ]);

    $response = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('invalid-rows.xlsx', $workbook),
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.valid_rows', 0)
        ->assertJsonPath('data.failed_rows', 1)
        ->assertJsonPath('data.issues.0.row', 2);
    expect($response->json('data.issues.0.messages'))
        ->toContain('NIS wajib diisi.')
        ->toContain('Nama lengkap siswa wajib diisi.')
        ->toContain('Format email tidak valid.')
        ->toContain('Setoran awal minimal Rp10.000.');
});

test('xlsx import rejects numeric NIS cells that may have lost leading zeroes', function () {
    [, $token] = reportingUser('administrator', 'numeric_nis_import_admin');
    $major = Major::factory()->create(['name' => 'RPL', 'code' => 'RPL']);
    $schoolClass = SchoolClass::factory()->create([
        'name' => 'X RPL 1',
        'major_id' => $major->id,
    ]);
    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['0012345678', 'Siswa NIS Numerik', 'X', $major->name, '1', 'numeric-nis@example.test', '10000'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'numeric-nis-');
    file_put_contents($path, $workbook);
    $archive = new ZipArchive;
    try {
        expect($archive->open($path))->toBeTrue();
        $worksheet = $archive->getFromName('xl/worksheets/sheet1.xml');
        expect($worksheet)->toBeString();
        $worksheet = str_replace(
            '<c r="A2" s="1" t="inlineStr"><is><t xml:space="preserve">0012345678</t></is></c>',
            '<c r="A2"><v>12345678</v></c>',
            $worksheet
        );
        expect($archive->addFromString('xl/worksheets/sheet1.xml', $worksheet))->toBeTrue();
    } finally {
        if ($archive->status === ZipArchive::ER_OK) {
            $archive->close();
        }
    }

    try {
        $response = $this->post('/api/admin/customers/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('numeric-nis.xlsx', file_get_contents($path)),
        ], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    } finally {
        @unlink($path);
    }

    $response->assertOk()
        ->assertJsonPath('data.valid_rows', 0)
        ->assertJsonPath('data.failed_rows', 1)
        ->assertJsonPath('data.issues.0.row', 2)
        ->assertJsonPath(
            'data.issues.0.messages.0',
            'NIS harus disimpan sebagai teks agar angka nol di awal tidak hilang.'
        );
});

test('xlsx import preview rejects missing invalid and duplicate email addresses', function () {
    [, $token] = reportingUser('administrator', 'import_email_admin');
    $major = Major::factory()->create(['name' => 'Rekayasa Perangkat Lunak', 'code' => 'RPL']);
    $schoolClass = SchoolClass::factory()->create([
        'name' => 'X RPL 1',
        'major_id' => $major->id,
    ]);
    $existing = User::create([
        'name' => 'Existing',
        'username' => 'existing_email_user',
        'full_name' => 'Existing',
        'email' => 'used@example.test',
        'password' => 'password123',
        'role' => 'nasabah',
        'status' => 'active',
    ]);

    $workbook = app(CustomerImportService::class)->template([
        CustomerImportService::HEADERS,
        ['EMAIL-1', 'Email Kosong', 'X', $major->name, '1', '', '10000'],
        ['EMAIL-2', 'Email Invalid', 'X', $major->name, '1', 'invalid-email', '10000'],
        ['EMAIL-3', 'Email Existing', 'X', $major->name, '1', $existing->email, '10000'],
        ['EMAIL-4', 'Email Baru', 'X', $major->name, '1', 'same@example.test', '10000'],
        ['EMAIL-5', 'Email Duplikat', 'X', $major->name, '1', 'SAME@example.test', '10000'],
    ]);
    $response = $this->post('/api/admin/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('emails.xlsx', $workbook),
    ], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.checked_rows', 5)
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.failed_rows', 4)
        ->assertJsonPath('data.duplicate_rows', 2);
});

test('deposit minimum and withdrawal reserve are enforced by the API', function () {
    [, $token] = reportingUser('teller', 'minimum_amount_teller');
    $customer = Customer::factory()->create(['balance' => 20000]);
    $this->postJson('/api/teller/cash-reconciliation/opening', [
        'date' => now('Asia/Jakarta')->toDateString(),
        'opening_balance' => 500000,
        'opening_physical_balance' => 500000,
    ], ['Authorization' => 'Bearer '.$token])->assertCreated();

    $depositToken = bin2hex(random_bytes(32));
    CashDepositScanToken::create([
        'token_hash' => hash('sha256', $depositToken),
        'customer_id' => $customer->id,
        'user_id' => User::where('username', 'minimum_amount_teller')->value('id'),
        'purpose' => 'cash_deposit',
        'expires_at' => now()->addMinutes(5),
    ]);
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 9999,
        'scan_token' => $depositToken,
    ], $headers)->assertUnprocessable();
    expect($customer->fresh()->balance)->toBe(20000);
    expect($customer->transactions()->count())->toBe(0);

    $this->postJson('/api/teller/deposit', [
        'customer_id' => $customer->id,
        'amount' => 10000,
        'scan_token' => $depositToken,
    ], $headers)->assertCreated();
    expect($customer->transactions()->where('status', 'pending')->count())->toBe(1);
    expect($customer->fresh()->balance)->toBe(20000);

    $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 10001,
    ], $headers)->assertUnprocessable();

    $this->postJson('/api/teller/withdrawal', [
        'customer_id' => $customer->id,
        'amount' => 10000,
    ], $headers)->assertOk();
    expect($customer->fresh()->balance)->toBe(20000);
});

test('withdrawal approval rechecks the latest locked customer balance', function () {
    $customer = Customer::factory()->create(['balance' => 30000]);
    [$teller] = reportingUser('teller', 'latest_balance_teller');
    $transaction = transactionForReport(
        $customer->id,
        $teller->id,
        'withdrawal',
        20000,
        'pending',
        now()->toDateTimeString()
    );
    $customer->update(['balance' => 25000]);

    expect(fn () => app(TransactionService::class)->approve($transaction->id, $customer->id))
        ->toThrow(ValidationException::class);
    expect($customer->fresh()->balance)->toBe(25000);
    expect($transaction->fresh()->status)->toBe('pending');
    expect($transaction->journals()->count())->toBe(0);
});

test('withdrawal approval allows the exact minimum remaining balance', function () {
    $customer = Customer::factory()->create(['balance' => 30000]);
    [$teller] = reportingUser('teller', 'exact_minimum_teller');
    $transaction = transactionForReport(
        $customer->id,
        $teller->id,
        'withdrawal',
        20000,
        'pending',
        now()->toDateTimeString()
    );

    $approved = app(TransactionService::class)->approve($transaction->id, $customer->id);

    expect($approved->status)->toBe('approved');
    expect($customer->fresh()->balance)->toBe(10000);
    expect($approved->journals()->count())->toBe(2);
});
