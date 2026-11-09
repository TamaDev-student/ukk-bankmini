<?php

use App\Models\Customer;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StudentImportService;
use Database\Seeders\ClassRosterSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

function studentTestAdmin(): array
{
    $admin = User::create([
        'name' => 'Admin Siswa',
        'username' => 'admin-siswa-'.uniqid(),
        'full_name' => 'Admin Siswa Test',
        'email' => 'admin-siswa-'.uniqid().'@example.test',
        'password' => Hash::make('Password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);

    return [$admin, $admin->createToken('test')->plainTextToken];
}

beforeEach(function () {
    app(ClassRosterSeeder::class)->run();
});

// =========================================================================
// 1. Import ulang tanpa duplikasi NIS
// =========================================================================
test('1. import ulang tanpa duplikasi NIS menandai duplikasi dan tidak menambah baris ganda di database', function () {
    [$admin, $token] = studentTestAdmin();
    $schoolClass = SchoolClass::first();

    // Buat data siswa awal di DB
    Student::create([
        'student_number' => 'NIS-DUP-01',
        'student_name' => 'Siswa Existing',
        'class_id' => $schoolClass->id,
        'grade_level' => 'X',
        'cohort_year' => 2026,
        'graduation_year' => 2029,
    ]);

    $initialCount = Student::count();

    // Buat file CSV import yang berisi NIS yang sama
    $csvContent = implode("\n", [
        'KELAS : X RPL 1',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA',
        '1,NIS-DUP-01,Siswa Existing Diimpor Lagi',
        '2,NIS-BARU-02,Siswa Baru Valid',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'csv_dup_');
    file_put_contents($tempCsv, $csvContent);

    $file = new UploadedFile($tempCsv, 'test_dup.csv', 'text/csv', null, true);

    $previewRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/preview', ['file' => $file])
        ->assertOk();

    $summary = $previewRes->json('data.summary');
    expect($summary['total_found'])->toBe(2)
        ->and($summary['duplicate_count'])->toBe(1)
        ->and($summary['valid_count'])->toBe(1);

    $previewToken = $previewRes->json('data.preview_token');

    $confirmRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/confirm', ['preview_token' => $previewToken])
        ->assertOk();

    expect($confirmRes->json('data.imported_count'))->toBe(1)
        ->and(Student::count())->toBe($initialCount + 1);

    // NIS-DUP-01 tetap tepat satu baris
    expect(Student::where('student_number', 'NIS-DUP-01')->count())->toBe(1);

    @unlink($tempCsv);
});

// =========================================================================
// 2. NIS dengan angka nol di depan
// =========================================================================
test('2. NIS dengan angka nol di depan tetap mempertahankan angka nol dan tidak dikonversi ke integer', function () {
    [$admin, $token] = studentTestAdmin();

    $csvContent = implode("\n", [
        'KELAS : X RPL 1',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA',
        '1,00123,Siswa Angka Nol Depan',
        '2,000456,Siswa Tiga Nol Depan',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'csv_zero_');
    file_put_contents($tempCsv, $csvContent);

    $file = new UploadedFile($tempCsv, 'test_zero.csv', 'text/csv', null, true);

    $previewRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/preview', ['file' => $file])
        ->assertOk();

    $previewToken = $previewRes->json('data.preview_token');

    $this->withToken($token)
        ->postJson('/api/admin/students/import/confirm', ['preview_token' => $previewToken])
        ->assertOk();

    // Verifikasi di database: student_number adalah string dengan leading zeros
    $st1 = Student::where('student_number', '00123')->first();
    expect($st1)->not->toBeNull()
        ->and($st1->student_number)->toBe('00123');

    $st2 = Student::where('student_number', '000456')->first();
    expect($st2)->not->toBeNull()
        ->and($st2->student_number)->toBe('000456');

    // Verifikasi via pencarian API
    $apiRes = $this->withToken($token)
        ->getJson('/api/admin/students?search=00123')
        ->assertOk();

    $apiData = $apiRes->json('data.data');
    expect(count($apiData))->toBe(1)
        ->and($apiData[0]['student_number'])->toBe('00123');

    @unlink($tempCsv);
});

// =========================================================================
// 3. Kelas yang belum dikonfigurasi tetap dapat masuk master data
// =========================================================================
test('3. kelas yang belum dikonfigurasi (XII AK 4) tetap masuk master data dengan class_id null dan tidak dapat didaftarkan nasabah', function () {
    [$admin, $token] = studentTestAdmin();

    // Rombel XII AK 4 sengaja tidak dibuat di tabel classes
    expect(SchoolClass::where('name', 'XII AK 4')->first())->toBeNull();

    $csvContent = implode("\n", [
        'KELAS : XII AK 4',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA',
        '1,99911,Siswa Akuntansi Empat',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'csv_unconf_');
    file_put_contents($tempCsv, $csvContent);

    $file = new UploadedFile($tempCsv, 'test_unconf.csv', 'text/csv', null, true);

    $previewRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/preview', [
            'file' => $file,
            'academic_year' => 2026,
        ])
        ->assertOk();

    $rows = $previewRes->json('data.rows');
    expect(count($rows))->toBe(1);

    $row = $rows[0];
    expect($row['student_number'])->toBe('99911')
        ->and($row['student_name'])->toBe('Siswa Akuntansi Empat')
        ->and($row['grade_level'])->toBe('XII')
        ->and($row['cohort_year'])->toBe(2024)
        ->and($row['graduation_year'])->toBe(2027)
        ->and($row['class_id'])->toBeNull()
        ->and($row['original_class'])->toBe('XII AK 4')
        ->and($row['is_class_configured'])->toBeFalse()
        ->and($row['is_valid'])->toBeTrue()
        ->and($row['status'])->toBe('unconfigured_class')
        ->and($row['warning_message'])->not->toBeEmpty();

    $previewToken = $previewRes->json('data.preview_token');

    // Konfirmasi import tetap berhasil menyimpan siswa ke master data
    $this->withToken($token)
        ->postJson('/api/admin/students/import/confirm', ['preview_token' => $previewToken])
        ->assertOk();

    $student = Student::where('student_number', '99911')->first();
    expect($student)->not->toBeNull()
        ->and($student->class_id)->toBeNull()
        ->and($student->original_class)->toBe('XII AK 4')
        ->and($student->grade_level)->toBe('XII')
        ->and($student->cohort_year)->toBe(2024)
        ->and($student->graduation_year)->toBe(2027);

    // Verifikasi API index: can_register bernilai false
    $indexRes = $this->withToken($token)
        ->getJson('/api/admin/students?search=99911')
        ->assertOk();

    $indexData = $indexRes->json('data.data.0');
    expect($indexData['is_class_configured'])->toBeFalse()
        ->and($indexData['can_register'])->toBeFalse();

    // Verifikasi: Pendaftaran nasabah satu siswa harus DITOLAK (422)
    $this->withToken($token)
        ->postJson("/api/admin/students/{$student->id}/create-account", [
            'email' => 'siswa.ak4@example.test',
            'opening_balance' => 20000,
        ])
        ->assertUnprocessable();

    // Verifikasi: Pendaftaran massal juga harus GAGAL secara terisolasi
    $batchRes = $this->withToken($token)
        ->postJson('/api/admin/students/batch-create-accounts', [
            'students' => [
                [
                    'student_id' => $student->id,
                    'email' => 'siswa.ak4.batch@example.test',
                    'opening_balance' => 20000,
                ],
            ],
        ])
        ->assertOk();

    expect($batchRes->json('data.success_count'))->toBe(0)
        ->and($batchRes->json('data.failure_count'))->toBe(1)
        ->and($batchRes->json('data.failures.0.reason'))->toContain('belum terkonfigurasi');

    @unlink($tempCsv);
});

// =========================================================================
// 4. Parser dengan beberapa kelas dalam satu worksheet
// =========================================================================
test('4. parser mampu membaca beberapa blok kelas yang tersusun secara vertikal dalam satu worksheet', function () {
    $csvContent = implode("\n", [
        'KELAS : X RPL 1',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA,JK,1,2,3',
        '1,88001,Siswa Satu RPL Satu,L,H,H,H',
        '2,88002,Siswa Dua RPL Satu,P,H,H,H',
        '',
        'KELAS : X RPL 2',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA,JK,1,2,3',
        '1,88003,Siswa Tiga RPL Dua,L,H,H,H',
        '2,88004,Siswa Empat RPL Dua,P,H,H,H',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'csv_multi_class_');
    file_put_contents($tempCsv, $csvContent);

    $service = new StudentImportService();
    $result = $service->parseCsv($tempCsv);

    expect(count($result['students']))->toBe(4);

    expect($result['students'][0]['raw_nis'])->toBe('88001')
        ->and($result['students'][0]['class_string'])->toBe('X RPL 1');

    expect($result['students'][1]['raw_nis'])->toBe('88002')
        ->and($result['students'][1]['class_string'])->toBe('X RPL 1');

    expect($result['students'][2]['raw_nis'])->toBe('88003')
        ->and($result['students'][2]['class_string'])->toBe('X RPL 2');

    expect($result['students'][3]['raw_nis'])->toBe('88004')
        ->and($result['students'][3]['class_string'])->toBe('X RPL 2');

    @unlink($tempCsv);
});

// =========================================================================
// 5. Parser tetap membaca blok kelas berikutnya setelah bagian tanda tangan
// =========================================================================
test('5. parser tidak berhenti saat menemukan bagian tanda tangan guru / NIP dan tetap membaca kelas berikutnya', function () {
    $csvContent = implode("\n", [
        'KELAS : X RPL 1',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA',
        '1,77001,Siswa Sebelum Tanda Tangan',
        '',
        'Mengetahui,',
        'Guru Mata Pelajaran,',
        'NIP. 198001012005011003',
        '',
        'DAFTAR HADIR SISWA',
        'KELAS : X RPL 2',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA',
        '1,77002,Siswa Setelah Tanda Tangan',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'csv_signature_');
    file_put_contents($tempCsv, $csvContent);

    $service = new StudentImportService();
    $result = $service->parseCsv($tempCsv);

    expect(count($result['students']))->toBe(2)
        ->and($result['students'][0]['raw_nis'])->toBe('77001')
        ->and($result['students'][0]['class_string'])->toBe('X RPL 1')
        ->and($result['students'][1]['raw_nis'])->toBe('77002')
        ->and($result['students'][1]['class_string'])->toBe('X RPL 2');

    @unlink($tempCsv);
});

// =========================================================================
// 6. Import master tidak menambah User, Customer, dan transaksi
// =========================================================================
test('6. import master siswa hanya menambah data tabel students tanpa menambah User, Customer, dan transaksi', function () {
    [$admin, $token] = studentTestAdmin();

    $initialUserCount = User::count();
    $initialCustomerCount = Customer::count();
    $initialTransactionCount = Transaction::count();
    $initialStudentCount = Student::count();

    $csvContent = implode("\n", [
        'KELAS : X RPL 1',
        'TAHUN PELAJARAN 2026/2027',
        'NO,NIS,NAMA SISWA',
        '1,66001,Siswa Murni Master 1',
        '2,66002,Siswa Murni Master 2',
        '3,66003,Siswa Murni Master 3',
    ]);

    $tempCsv = tempnam(sys_get_temp_dir(), 'csv_pure_master_');
    file_put_contents($tempCsv, $csvContent);

    $file = new UploadedFile($tempCsv, 'test_pure.csv', 'text/csv', null, true);

    $previewRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/preview', ['file' => $file])
        ->assertOk();

    // Verifikasi saat preview: tidak ada perubahan pada database
    expect(User::count())->toBe($initialUserCount)
        ->and(Customer::count())->toBe($initialCustomerCount)
        ->and(Transaction::count())->toBe($initialTransactionCount)
        ->and(Student::count())->toBe($initialStudentCount);

    $previewToken = $previewRes->json('data.preview_token');

    // Verifikasi saat konfirmasi import
    $this->withToken($token)
        ->postJson('/api/admin/students/import/confirm', ['preview_token' => $previewToken])
        ->assertOk();

    expect(Student::count())->toBe($initialStudentCount + 3)
        ->and(User::count())->toBe($initialUserCount)
        ->and(Customer::count())->toBe($initialCustomerCount)
        ->and(Transaction::count())->toBe($initialTransactionCount);

    @unlink($tempCsv);
});

// =========================================================================
// 7. Registrasi siswa yang sama dua kali ditolak
// =========================================================================
test('7. registrasi siswa yang sama dua kali ditolak dengan kode status 422 dan tidak menghasilkan rekening ganda', function () {
    [$admin, $token] = studentTestAdmin();
    $schoolClass = SchoolClass::first();

    $student = Student::create([
        'student_number' => 'NIS-DOUBLE-REG',
        'student_name' => 'Siswa Double Reg',
        'class_id' => $schoolClass->id,
        'grade_level' => 'X',
        'cohort_year' => 2026,
        'graduation_year' => 2029,
    ]);

    // Registrasi pertama: Berhasil
    $res1 = $this->withToken($token)
        ->postJson("/api/admin/students/{$student->id}/create-account", [
            'email' => 'siswa.double@example.test',
            'opening_balance' => 15000,
        ])
        ->assertOk();

    expect($res1->json('data.customer.student_number'))->toBe('NIS-DOUBLE-REG');

    $initialCustomerCount = Customer::where('student_number', 'NIS-DOUBLE-REG')->count();
    expect($initialCustomerCount)->toBe(1);

    // Registrasi kedua untuk siswa yang sama: Harus Ditolak (422)
    $this->withToken($token)
        ->postJson("/api/admin/students/{$student->id}/create-account", [
            'email' => 'siswa.double.baru@example.test',
            'opening_balance' => 25000,
        ])
        ->assertUnprocessable();

    // Pastikan rekening tetap tepat 1 dan saldo tidak tertimpa/berganda
    expect(Customer::where('student_number', 'NIS-DOUBLE-REG')->count())->toBe(1);
    $customer = Customer::where('student_number', 'NIS-DOUBLE-REG')->first();
    expect($customer->balance)->toBe(15000);
});

// =========================================================================
// 8. Batch registration lima siswa menghasilkan tepat lima Customer
// =========================================================================
test('8. batch registration lima siswa menghasilkan tepat lima Customer dengan saldo dan akun terisolasi', function () {
    [$admin, $token] = studentTestAdmin();
    $schoolClass = SchoolClass::first();

    $students = [];
    for ($i = 1; $i <= 5; $i++) {
        $students[] = Student::create([
            'student_number' => 'NIS-BATCH5-0'.$i,
            'student_name' => "Siswa Batch Lima Ke-{$i}",
            'class_id' => $schoolClass->id,
            'grade_level' => 'X',
            'cohort_year' => 2026,
            'graduation_year' => 2029,
        ]);
    }

    $batchPayload = [];
    foreach ($students as $idx => $st) {
        $batchPayload[] = [
            'student_id' => $st->id,
            'email' => "siswa.batch5.{$idx}@example.test",
            'opening_balance' => 12000,
        ];
    }

    $res = $this->withToken($token)
        ->postJson('/api/admin/students/batch-create-accounts', [
            'students' => $batchPayload,
        ])
        ->assertOk();

    expect($res->json('data.success_count'))->toBe(5)
        ->and($res->json('data.failure_count'))->toBe(0)
        ->and(count($res->json('data.created_accounts')))->toBe(5);

    foreach ($students as $st) {
        $fresh = $st->fresh();
        expect($fresh->customer)->not->toBeNull()
            ->and($fresh->customer->balance)->toBe(12000)
            ->and($fresh->user)->not->toBeNull()
            ->and($fresh->has_account)->toBeTrue();
    }
});

// =========================================================================
// 9. Integrasi penuh file Excel Absen 2026-2027 (1.635 siswa, 35 XII AK 4)
// =========================================================================
test('9. file riil Absen 2026-2027.xlsx berhasil mengimpor seluruh 1.635 siswa termasuk 35 dari XII AK 4', function () {
    [$admin, $token] = studentTestAdmin();
    $fixturePath = base_path('tests/Fixtures/Absen_2026-2027.xlsx');

    if (! file_exists($fixturePath)) {
        $this->markTestSkipped('Fixture Absen_2026-2027.xlsx tidak ditemukan.');
    }

    $uploadedFile = new UploadedFile(
        $fixturePath,
        'Absen_2026-2027.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );

    $initialUserCount = User::count();
    $initialCustomerCount = Customer::count();
    $initialTransactionCount = Transaction::count();

    // 1. Preview
    $previewRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/preview', [
            'file' => $uploadedFile,
            'academic_year' => 2026,
        ])
        ->assertOk();

    $summary = $previewRes->json('data.summary');
    expect($summary['total_found'])->toBe(1636)
        ->and($summary['valid_count'])->toBe(1636)
        ->and($summary['unconfigured_class_count'])->toBe(35)
        ->and($summary['duplicate_count'])->toBe(0)
        ->and(User::count())->toBe($initialUserCount)
        ->and(Customer::count())->toBe($initialCustomerCount)
        ->and(Transaction::count())->toBe($initialTransactionCount);

    $previewToken = $previewRes->json('data.preview_token');

    // 2. Confirm Import
    $confirmRes = $this->withToken($token)
        ->postJson('/api/admin/students/import/confirm', [
            'preview_token' => $previewToken,
        ])
        ->assertOk();

    expect($confirmRes->json('data.imported_count'))->toBe(1636)
        ->and(User::count())->toBe($initialUserCount)
        ->and(Customer::count())->toBe($initialCustomerCount)
        ->and(Transaction::count())->toBe($initialTransactionCount);

    // 3. Verifikasi siswa dari XII AK 4 (kelas belum terkonfigurasi di sistem)
    $adinda = Student::where('student_number', '18080')->first();
    expect($adinda)->not->toBeNull()
        ->and($adinda->student_name)->toBe('ADINDA EKA SILVIA DEVANI')
        ->and($adinda->grade_level)->toBe('XII')
        ->and($adinda->cohort_year)->toBe(2024)
        ->and($adinda->graduation_year)->toBe(2027)
        ->and($adinda->class_id)->toBeNull()
        ->and($adinda->original_class)->toBe('XII AK 4')
        ->and($adinda->customer)->toBeNull()
        ->and($adinda->has_account)->toBeFalse();

    // Verifikasi siswa dari kelas yang sah/terkonfigurasi (contoh: XII TKJ 2)
    $adrian = Student::where('student_number', '17864')->first();
    expect($adrian)->not->toBeNull()
        ->and($adrian->student_name)->toBe('ADRIAN CELLOLINO YOLANDA')
        ->and($adrian->grade_level)->toBe('XII')
        ->and($adrian->cohort_year)->toBe(2024)
        ->and($adrian->graduation_year)->toBe(2027)
        ->and($adrian->class_id)->not->toBeNull()
        ->and($adrian->original_class)->toBe('XII TKJ 2');

    // 4. Verifikasi jumlah siswa dengan class_id null tepat 35
    expect(Student::whereNull('class_id')->count())->toBe(35)
        ->and(Student::whereNotNull('class_id')->count())->toBe(1601);
});
