<?php

use App\Models\Customer;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function adminWithTokenForCustomerTest(): array
{
    static $counter = 0;
    $counter++;

    $admin = User::create([
        'name' => 'Admin '.$counter,
        'username' => 'admin_cust_'.$counter,
        'full_name' => 'Admin Cust '.$counter,
        'email' => 'admin_cust_'.$counter.'@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    return [$admin, $token];
}

function setupCustomerPrerequisites()
{
    $majorRPL = Major::firstOrCreate(['name' => 'Rekayasa Perangkat Lunak', 'code' => 'RPL']);
    $majorTKJ = Major::firstOrCreate(['name' => 'Teknik Komputer dan Jaringan', 'code' => 'TKJ']);

    $classRPL = SchoolClass::firstOrCreate(['name' => 'XII RPL 1', 'major_id' => $majorRPL->id]);
    $classTKJ = SchoolClass::firstOrCreate(['name' => 'XII TKJ 1', 'major_id' => $majorTKJ->id]);
    $classRPL2 = SchoolClass::firstOrCreate(['name' => 'XI RPL 2', 'major_id' => $majorRPL->id]);

    return [$classRPL, $classTKJ, $classRPL2, $majorRPL, $majorTKJ];
}

// 1. Authorization
test('unauthenticated mendapat 401', function () {
    $response = $this->getJson('/api/admin/customers');
    $response->assertStatus(401);
});

test('teller tidak boleh mengakses endpoint administrator', function () {
    $teller = User::create([
        'name' => 'Teller',
        'username' => 'teller_cust_1',
        'full_name' => 'Teller Cust',
        'email' => 'teller_cust_1@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    $token = $teller->createToken('test')->plainTextToken;

    $response = $this->getJson('/api/admin/customers', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(403);
});

test('supervisor tidak boleh mengakses endpoint administrator', function () {
    $supervisor = User::create([
        'name' => 'Supervisor',
        'username' => 'super_cust_1',
        'full_name' => 'Supervisor Cust',
        'email' => 'super_cust_1@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'supervisor',
        'status' => 'active',
    ]);

    $token = $supervisor->createToken('test')->plainTextToken;

    $response = $this->getJson('/api/admin/customers', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(403);
});

// 2 & 3. Pembuatan Rekening & Generator
test('pembuatan rekening sukses dengan validasi benar', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL, $classTKJ] = setupCustomerPrerequisites();

    $nis = '100100'.rand(1000, 9999);

    $response = $this->postJson('/api/admin/customers', [
        'student_number' => $nis,
        'student_name' => 'Siswa Baru RPL',
        'class_id' => $classRPL->id,
        'email' => strtolower($nis).'@student.test',
        'opening_balance' => 10000,
        'security_pin' => '123456',
        'account_number' => 'HACK123', // Client cannot manipulate this
        'balance' => 99999, // Should be ignored and set to 0
        'status' => 'blocked', // Should be ignored and set to active
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.customer.student_number', $nis)
        ->assertJsonPath('data.customer.status', 'active')
        ->assertJsonPath('data.customer.balance', 10000)
        ->assertJsonPath('data.opening_transaction.status', 'approved')
        ->assertJsonPath('data.opening_transaction.amount', 10000);

    // PIN should not be in response (hidden)
    $response->assertJsonMissing(['security_pin' => '123456']);

    $accountNumber = $response->json('data.customer.account_number');
    expect($accountNumber)->not->toBe('HACK123');
    expect(substr($accountNumber, 0, 3))->toBe('RPL');
    expect(strlen($accountNumber))->toBe(6);
    $customer = Customer::where('student_number', $nis)->firstOrFail();
    expect($customer->balance)->toBe(10000);
    expect($customer->security_pin)->toBeNull();
    expect($customer->transactions)->toHaveCount(1);
    expect($customer->transactions->first()->journals)->toHaveCount(2);
    expect($customer->transactions->first()->journals->sum('amount'))->toBe(20000);
});

test('pembuatan rekening menolak input tidak valid (unik student_number, pin 6 digit)', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL] = setupCustomerPrerequisites();

    $nis = 'DUPLICATE123';

    // Create first
    $this->postJson('/api/admin/customers', [
        'student_number' => $nis,
        'student_name' => 'First',
        'class_id' => $classRPL->id,
        'email' => 'first_'.$nis.'@student.test',
        'opening_balance' => 10000,
        'security_pin' => '123456',
    ], ['Authorization' => "Bearer $token"])->assertStatus(201);

    // Create duplicate NIS and invalid PIN length
    $response = $this->postJson('/api/admin/customers', [
        'student_number' => $nis, // duplicate
        'student_name' => 'Second',
        'class_id' => $classRPL->id,
        'email' => 'second_'.$nis.'@student.test',
        'opening_balance' => 10000,
        'security_pin' => '12345', // 5 digits
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['student_number']);
});

test('urutan sequence per jurusan dan menolak jika kapasitas melebihi 999', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL, $classTKJ, $classRPL2, $majorRPL] = setupCustomerPrerequisites();

    // Bersihkan dulu customer RPL untuk tes sequence
    Customer::where('account_number', 'like', 'RPL%')->delete();

    // Buat customer dengan sequence mendekati akhir secara manual
    Customer::create([
        'account_number' => 'RPL998',
        'student_number' => 'TEST998',
        'student_name' => 'Test 998',
        'class_id' => $classRPL->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 0,
    ]);

    // Request pembuatan 999 sukses
    $res999 = $this->postJson('/api/admin/customers', [
        'student_number' => 'TEST999',
        'student_name' => 'Test 999',
        'class_id' => $classRPL->id,
        'email' => 'test999@student.test',
        'opening_balance' => 10000,
        'security_pin' => '123456',
    ], ['Authorization' => "Bearer $token"]);

    $res999->assertStatus(201);
    expect($res999->json('data.customer.account_number'))->toBe('RPL999');

    $customerCountBefore = Customer::count();

    // Request pembuatan 1000 gagal
    $res1000 = $this->postJson('/api/admin/customers', [
        'student_number' => 'TEST1000',
        'student_name' => 'Test 1000',
        'class_id' => $classRPL->id,
        'email' => 'test1000@student.test',
        'opening_balance' => 10000,
        'security_pin' => '123456',
    ], ['Authorization' => "Bearer $token"]);

    $res1000->assertStatus(422)
        ->assertJsonPath('message', 'Kapasitas nomor rekening untuk jurusan '.$majorRPL->name.' sudah penuh (maksimal 999).');

    expect(Customer::count())->toBe($customerCountBefore);
});

// 4. Update Rekening
test('update rekening sukses dalam satu jurusan, properti immutable tidak berubah', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL, $classTKJ, $classRPL2] = setupCustomerPrerequisites();

    $customer = Customer::create([
        'account_number' => 'RPL111',
        'student_number' => 'OLD_NIS',
        'student_name' => 'Lama',
        'class_id' => $classRPL->id,
        'status' => 'active',
        'security_pin' => Hash::make('111111'),
        'balance' => 50000,
    ]);

    $response = $this->putJson('/api/admin/customers/'.$customer->id, [
        'student_name' => 'Baru',
        'class_id' => $classRPL2->id, // valid, beda kelas tapi sama jurusan (RPL)
        'status' => 'blocked',
        // Trying to manipulate immutable fields
        'student_number' => 'NEW_NIS',
        'account_number' => 'HACK111',
        'balance' => 999999,
        'security_pin' => '999999',
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.student_name', 'Baru')
        ->assertJsonPath('data.status', 'blocked')
        ->assertJsonPath('data.class_id', $classRPL2->id)
        ->assertJsonPath('data.student_number', 'OLD_NIS') // immutable
        ->assertJsonPath('data.account_number', 'RPL111') // immutable
        ->assertJsonPath('data.balance', 50000); // immutable

    // check hash hasn't changed to 999999
    expect(Hash::check('111111', $customer->fresh()->security_pin))->toBeTrue();
});

test('update sebagian hanya mengubah field yang dikirim', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();

    [$classRPL] = setupCustomerPrerequisites();

    $customer = Customer::create([
        'account_number' => 'RPL555',
        'student_number' => 'STU555',
        'student_name' => 'Nama Lama',
        'class_id' => $classRPL->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 50000,
    ]);

    $response = $this->putJson(
        '/api/admin/customers/'.$customer->id,
        [
            'student_name' => 'Nama Baru',
        ],
        [
            'Authorization' => "Bearer $token",
        ]
    );

    $response->assertStatus(200)
        ->assertJsonPath('data.student_name', 'Nama Baru')
        ->assertJsonPath('data.class_id', $classRPL->id)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.account_number', 'RPL555')
        ->assertJsonPath('data.student_number', 'STU555')
        ->assertJsonPath('data.balance', 50000);

    expect(Hash::check(
        '123456',
        $customer->fresh()->security_pin
    ))->toBeTrue();
});

test('update class_id lintas jurusan ditolak dengan HTTP 422', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL, $classTKJ] = setupCustomerPrerequisites();

    $customer = Customer::create([
        'account_number' => 'RPL222',
        'student_number' => 'STU222',
        'student_name' => 'Test Lintas',
        'class_id' => $classRPL->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 0,
    ]);

    $response = $this->putJson('/api/admin/customers/'.$customer->id, [
        'student_name' => 'Test Lintas Baru',
        'class_id' => $classTKJ->id, // Beda jurusan! (TKJ vs RPL)
        'status' => 'active',
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Tidak dapat mengubah kelas ke jurusan yang berbeda.');
});

test('update ke status tidak valid ditolak', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL] = setupCustomerPrerequisites();

    $customer = Customer::create([
        'account_number' => 'RPL333',
        'student_number' => 'STU333',
        'student_name' => 'Test',
        'class_id' => $classRPL->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 0,
    ]);

    $response = $this->putJson('/api/admin/customers/'.$customer->id, [
        'student_name' => 'Test',
        'class_id' => $classRPL->id,
        'status' => 'invalid_status',
    ], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('update / get rekening tidak ditemukan mengembalikan 404', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL] = setupCustomerPrerequisites();

    $responseGet = $this->getJson('/api/admin/customers/9999', ['Authorization' => "Bearer $token"]);
    $responseGet->assertStatus(404);

    $responsePut = $this->putJson('/api/admin/customers/9999', [
        'student_name' => 'Test',
        'class_id' => $classRPL->id,
        'status' => 'active',
    ], ['Authorization' => "Bearer $token"]);
    $responsePut->assertStatus(404);
});

// 5. Read (List dan Detail)
test('read list dan detail menampilkan school_class.major dan hidden pin', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();
    [$classRPL] = setupCustomerPrerequisites();
    $customerUser = User::create([
        'name' => 'Nasabah Email',
        'username' => 'read_email_customer',
        'full_name' => 'Nasabah Email',
        'email' => 'nasabah@example.test',
        'password' => Hash::make('password123'),
        'role' => 'nasabah',
        'status' => 'active',
    ]);

    $customer = Customer::create([
        'account_number' => 'RPL444',
        'student_number' => 'STU444',
        'student_name' => 'Read Test',
        'class_id' => $classRPL->id,
        'user_id' => $customerUser->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 0,
    ]);

    // List
    $responseList = $this->getJson('/api/admin/customers', ['Authorization' => "Bearer $token"]);
    $responseList->assertStatus(200);
    $dataList = $responseList->json('data');

    $found = false;
    foreach ($dataList as $c) {
        if ($c['id'] === $customer->id) {
            expect($c)->toHaveKey('school_class.major');
            expect($c)->not->toHaveKey('security_pin');
            expect($c['user']['email'])->toBe('nasabah@example.test');
            $found = true;
        }
    }
    expect($found)->toBeTrue();

    // Detail
    $responseDetail = $this->getJson('/api/admin/customers/'.$customer->id, ['Authorization' => "Bearer $token"]);
    $responseDetail->assertStatus(200)
        ->assertJsonStructure(['data' => ['school_class' => ['major']]])
        ->assertJsonMissing(['data' => ['security_pin' => '123456']]);
});

// 6. Endpoint DELETE ditiadakan
test('request DELETE tidak diizinkan untuk rekening', function () {
    [$admin, $token] = adminWithTokenForCustomerTest();

    $response = $this->deleteJson('/api/admin/customers/1', [], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertStatus(405); // Method Not Allowed (Route tidak ada)
});

test('transaksi rollback ketika terjadi exception setelah data dibuat', function () {
    [$classRPL] = setupCustomerPrerequisites();

    expect(function () use ($classRPL) {
        DB::transaction(function () use ($classRPL) {
            Customer::create([
                'account_number' => 'RPL997',
                'student_number' => 'ROLLBACK_TEST',
                'student_name' => 'Siswa Rollback',
                'class_id' => $classRPL->id,
                'status' => 'active',
                'security_pin' => Hash::make('123456'),
                'balance' => 0,
            ]);

            throw new RuntimeException('Simulasi kegagalan transaksi');
        });
    })->toThrow(RuntimeException::class);

    expect(
        Customer::where('student_number', 'ROLLBACK_TEST')->exists()
    )->toBeFalse();
});
