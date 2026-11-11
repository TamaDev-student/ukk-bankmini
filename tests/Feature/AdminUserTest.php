<?php

use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Membuat User dengan semua field UC-02 yang wajib diisi.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeUser(array $overrides = []): User
{
    static $counter = 0;
    $counter++;

    return User::factory()->create(array_merge([
        'name' => 'User Test '.$counter,
        'username' => 'usertest'.$counter,
        'full_name' => 'User Test Lengkap '.$counter,
        'email' => 'usertest'.$counter.'@bank.com',
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'active',
    ], $overrides));
}

/**
 * Membuat administrator dan mengembalikan token-nya.
 *
 * @return array{0: User, 1: string}
 */
function adminWithToken(): array
{
    static $adminCounter = 0;
    $adminCounter++;

    $admin = makeUser([
        'role' => 'administrator',
        'username' => 'admintest_'.$adminCounter,
        'email' => 'admintest_'.$adminCounter.'@bank.com',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    return [$admin, $token];
}

test('daftar manajemen tidak menampilkan akun nasabah dan teller yang sudah diarsipkan', function () {
    [, $token] = adminWithToken();

    makeUser(['role' => 'teller']);
    makeUser(['role' => 'supervisor']);
    $archived = makeUser(['role' => 'teller', 'status' => 'blocked']);
    $blockedAdministrator = makeUser(['role' => 'administrator', 'status' => 'blocked']);

    $response = $this->withToken($token)->getJson('/api/admin/users');

    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => [
                '*' => ['id', 'name', 'username', 'full_name', 'email', 'role', 'status'],
            ],
        ])
        ->assertJsonMissing(['id' => $archived->id])
        ->assertJsonFragment(['id' => $blockedAdministrator->id]);
});

test('administrator tidak dapat mengarsipkan akunnya sendiri', function () {
    [$admin, $token] = adminWithToken();

    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$admin->id)
        ->assertStatus(409);

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
        'status' => 'active',
    ]);
});

test('teller tidak dapat mengakses daftar user (403)', function () {
    $teller = makeUser(['role' => 'teller']);
    $token = $teller->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/users')
        ->assertForbidden();
});

test('supervisor tidak dapat mengakses daftar user (403)', function () {
    $supervisor = makeUser(['role' => 'supervisor']);
    $token = $supervisor->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/users')
        ->assertForbidden();
});

test('unauthenticated request ke daftar user ditolak (401)', function () {
    $this->getJson('/api/admin/users')->assertUnauthorized();
});

test('administrator dapat membuat user baru', function () {
    [, $token] = adminWithToken();

    $payload = [
        'name' => 'Budi Santoso',
        'username' => 'budi01',
        'full_name' => 'Budi Santoso SE',
        'email' => 'budi@bank.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'teller',
    ];

    $response = $this->withToken($token)->postJson('/api/admin/users', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.username', 'budi01')
        ->assertJsonPath('data.role', 'teller')
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('users', [
        'username' => 'budi01',
        'email' => 'budi@bank.com',
        'role' => 'teller',
        'status' => 'active',
    ]);
});

test('create user gagal jika username sudah digunakan (422)', function () {
    [, $token] = adminWithToken();

    makeUser([
        'username' => 'existing_user',
        'email' => 'existing@bank.com',
    ]);

    $payload = [
        'name' => 'Nama Lain',
        'username' => 'existing_user',
        'full_name' => 'Nama Lain Lengkap',
        'email' => 'namalain@bank.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'teller',
    ];

    $this->withToken($token)
        ->postJson('/api/admin/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username']);
});

test('create user gagal jika email sudah digunakan (422)', function () {
    [, $token] = adminWithToken();

    makeUser(['email' => 'duplicate@bank.com']);

    $payload = [
        'name' => 'Nama Baru',
        'username' => 'username_baru_unik',
        'full_name' => 'Nama Baru Lengkap',
        'email' => 'duplicate@bank.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'teller',
    ];

    $this->withToken($token)
        ->postJson('/api/admin/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('create user gagal jika password kurang dari 8 karakter (422)', function () {
    [, $token] = adminWithToken();

    $payload = [
        'name' => 'Nama Test',
        'username' => 'usertest_pw',
        'full_name' => 'Nama Test Lengkap',
        'email' => 'testpw@bank.com',
        'password' => 'short',
        'password_confirmation' => 'short',
        'role' => 'teller',
    ];

    $this->withToken($token)
        ->postJson('/api/admin/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('create user gagal jika password confirmation tidak cocok (422)', function () {
    [, $token] = adminWithToken();

    $payload = [
        'name' => 'Nama Test',
        'username' => 'usertest_confirm',
        'full_name' => 'Nama Test Lengkap',
        'email' => 'testconfirm@bank.com',
        'password' => 'password123',
        'password_confirmation' => 'salah_password',
        'role' => 'teller',
    ];

    $this->withToken($token)
        ->postJson('/api/admin/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('create user gagal jika role di luar daftar resmi (422)', function () {
    [, $token] = adminWithToken();

    $payload = [
        'name' => 'Nama Test',
        'username' => 'usertest_invalid_role',
        'full_name' => 'Nama Test Lengkap',
        'email' => 'testinvalidrole@bank.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'owner',
    ];

    $this->withToken($token)
        ->postJson('/api/admin/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);

    $this->assertDatabaseMissing('users', [
        'username' => 'usertest_invalid_role',
    ]);
});

test('create user gagal jika semua field wajib kosong (422)', function () {
    [, $token] = adminWithToken();

    $this->withToken($token)
        ->postJson('/api/admin/users', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name',
            'username',
            'full_name',
            'email',
            'password',
            'role',
        ]);
});

test('teller tidak dapat membuat user (403)', function () {
    $teller = makeUser(['role' => 'teller']);
    $token = $teller->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/admin/users', [])
        ->assertForbidden();
});

test('administrator dapat melihat detail user', function () {
    [, $token] = adminWithToken();
    $target = makeUser(['role' => 'teller']);

    $response = $this->withToken($token)
        ->getJson('/api/admin/users/'.$target->id);

    $response->assertOk()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.username', $target->username)
        ->assertJsonPath('data.role', 'teller');
});

test('show user mengembalikan 404 jika id tidak ada', function () {
    [, $token] = adminWithToken();

    $this->withToken($token)
        ->getJson('/api/admin/users/99999')
        ->assertNotFound();
});

test('teller tidak dapat melihat detail user (403)', function () {
    $teller = makeUser(['role' => 'teller']);
    $token = $teller->createToken('test')->plainTextToken;
    $target = makeUser();

    $this->withToken($token)
        ->getJson('/api/admin/users/'.$target->id)
        ->assertForbidden();
});

test('administrator dapat update status user menjadi blocked', function () {
    [, $token] = adminWithToken();
    $target = makeUser(['role' => 'teller', 'status' => 'active']);

    $response = $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'status' => 'blocked',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'blocked');

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'status' => 'blocked',
    ]);
    expect($target->fresh()->blocked_at)->not->toBeNull();
});

test('administrator dapat update role user', function () {
    [, $token] = adminWithToken();
    $target = makeUser(['role' => 'teller']);

    $response = $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'role' => 'supervisor',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.role', 'supervisor');
});

test('administrator dapat update password user', function () {
    [, $token] = adminWithToken();
    $target = makeUser();

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
        ->assertOk();

    $this->assertTrue(
        Hash::check('newpassword123', $target->fresh()->password)
    );
});

test('update tanpa password tidak mengubah password lama', function () {
    [, $token] = adminWithToken();

    $target = makeUser([
        'password' => Hash::make('password_lama'),
    ]);

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'name' => 'Nama Diubah',
        ])
        ->assertOk();

    $this->assertTrue(
        Hash::check('password_lama', $target->fresh()->password)
    );
});

test('update username gagal jika username dipakai user lain (422)', function () {
    [, $token] = adminWithToken();

    makeUser([
        'username' => 'milik_user_lain',
        'email' => 'userlain@bank.com',
    ]);

    $target = makeUser();

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'username' => 'milik_user_lain',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username']);
});

test('update username sendiri tidak menghasilkan error unique (200)', function () {
    [, $token] = adminWithToken();
    $target = makeUser(['username' => 'username_sama']);

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'username' => 'username_sama',
        ])
        ->assertOk();
});

test('update status ke nilai tidak valid gagal (422)', function () {
    [, $token] = adminWithToken();
    $target = makeUser();

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'status' => 'inactive',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('update user mengembalikan 404 jika id tidak ada', function () {
    [, $token] = adminWithToken();

    $this->withToken($token)
        ->putJson('/api/admin/users/99999', [
            'name' => 'Tidak Ada',
        ])
        ->assertNotFound();
});

test('teller tidak dapat update user (403)', function () {
    $teller = makeUser(['role' => 'teller']);
    $token = $teller->createToken('test')->plainTextToken;
    $target = makeUser();

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id, [
            'status' => 'blocked',
        ])
        ->assertForbidden();
});

test('administrator dapat mengarsipkan user tanpa menghapus akun', function () {
    [, $token] = adminWithToken();
    $target = makeUser(['role' => 'teller']);
    $target->createToken('active-session');

    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$target->id)
        ->assertOk()
        ->assertJsonPath('message', 'Akun berhasil diblokir dan dipindahkan ke arsip.')
        ->assertJsonPath('data.archived', true);

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'status' => 'blocked',
    ]);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $target->id,
        'name' => 'active-session',
    ]);
    $this->postJson('/api/login', [
        'username' => $target->username,
        'password' => 'password123',
    ])->assertForbidden();

    $this->withToken($token)
        ->getJson('/api/admin/users')
        ->assertJsonMissing(['id' => $target->id]);
    $this->withToken($token)
        ->getJson('/api/admin/users/archived')
        ->assertJsonFragment(['id' => $target->id]);
});

test('administrator dapat memulihkan akun teller dari arsip', function () {
    [, $token] = adminWithToken();
    $target = makeUser([
        'role' => 'teller',
        'status' => 'blocked',
        'blocked_at' => now(),
    ]);

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id.'/restore')
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('users', [
        'id' => $target->id,
        'status' => 'active',
        'blocked_at' => null,
    ]);

    $this->withToken($token)
        ->getJson('/api/admin/users')
        ->assertJsonFragment(['id' => $target->id]);
    $this->withToken($token)
        ->getJson('/api/admin/users/archived')
        ->assertJsonMissing(['id' => $target->id]);
});

test('nasabah yang diarsipkan mempertahankan rekening dan transaksi serta dapat dipulihkan', function () {
    [, $token] = adminWithToken();
    $customerUser = makeUser(['role' => 'nasabah']);

    $majorId = DB::table('majors')->insertGetId([
        'name' => 'Rekayasa Perangkat Lunak',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $classId = DB::table('classes')->insertGetId([
        'name' => 'XII RPL',
        'major_id' => $majorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $customerId = DB::table('customers')->insertGetId([
        'account_number' => 'NASABAH001',
        'student_number' => 'NISNASABAH001',
        'student_name' => 'Nasabah Test',
        'class_id' => $classId,
        'user_id' => $customerUser->id,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 100000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transactions')->insert([
        'customer_id' => $customerId,
        'user_id' => $customerUser->id,
        'transaction_date' => now(),
        'transaction_type' => 'deposit',
        'amount' => 50000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$customerUser->id)
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'user_id' => $customerUser->id,
        'status' => 'blocked',
    ]);
    $this->assertDatabaseHas('transactions', [
        'customer_id' => $customerId,
        'user_id' => $customerUser->id,
    ]);
    $this->withToken($token)
        ->getJson('/api/admin/users/archived')
        ->assertJsonFragment(['id' => $customerUser->id]);
    $this->withToken($token)
        ->putJson('/api/admin/customers/'.$customerId, ['status' => 'active'])
        ->assertStatus(409);

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$customerUser->id.'/restore')
        ->assertOk();

    $this->assertDatabaseHas('users', [
        'id' => $customerUser->id,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('transactions', [
        'customer_id' => $customerId,
        'user_id' => $customerUser->id,
    ]);
});

test('arsip user mengembalikan 404 jika id tidak ada', function () {
    [, $token] = adminWithToken();

    $this->withToken($token)
        ->deleteJson('/api/admin/users/99999')
        ->assertNotFound();
});

test('teller tidak dapat mengarsipkan user (403)', function () {
    $teller = makeUser(['role' => 'teller']);
    $token = $teller->createToken('test')->plainTextToken;
    $target = makeUser();

    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$target->id)
        ->assertForbidden();
});

test('teller tidak dapat memulihkan user dari arsip (403)', function () {
    $teller = makeUser(['role' => 'teller']);
    $token = $teller->createToken('test')->plainTextToken;
    $target = makeUser(['role' => 'teller', 'status' => 'blocked']);

    $this->withToken($token)
        ->putJson('/api/admin/users/'.$target->id.'/restore')
        ->assertForbidden();
});

test('supervisor tidak dapat mengarsipkan user (403)', function () {
    $supervisor = makeUser(['role' => 'supervisor']);
    $token = $supervisor->createToken('test')->plainTextToken;
    $target = makeUser();

    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$target->id)
        ->assertForbidden();
});

test('arsip teller dengan transaksi mencabut akses tanpa menghapus riwayat', function () {
    [, $token] = adminWithToken();
    $teller = makeUser(['role' => 'teller']);

    $majorId = DB::table('majors')->insertGetId([
        'name' => 'Rekayasa Perangkat Lunak',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $classId = DB::table('classes')->insertGetId([
        'name' => 'XII RPL 1',
        'major_id' => $majorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerId = DB::table('customers')->insertGetId([
        'account_number' => 'RPL001',
        'student_number' => 'NIS20260001',
        'student_name' => 'Siswa Test FK',
        'class_id' => $classId,
        'status' => 'active',
        'security_pin' => Hash::make('123456'),
        'balance' => 100000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transactions')->insert([
        'customer_id' => $customerId,
        'user_id' => $teller->id,
        'transaction_date' => now(),
        'transaction_type' => 'deposit',
        'amount' => 50000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $teller->createToken('active-session');
    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$teller->id)
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    $this->assertDatabaseHas('users', [
        'id' => $teller->id,
        'status' => 'blocked',
    ]);
    $this->assertDatabaseHas('transactions', [
        'user_id' => $teller->id,
    ]);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $teller->id,
        'name' => 'active-session',
    ]);
});

test('arsip teller dengan laporan harian mempertahankan laporan', function () {
    [, $token] = adminWithToken();
    $teller = makeUser(['role' => 'teller']);
    $report = DailyReport::create([
        'teller_id' => $teller->id,
        'report_date' => now('Asia/Jakarta')->toDateString(),
        'status' => 'draft',
        'opening_balance' => 250000,
        'opening_balance_source' => 'first_day_teller',
        'opening_balance_recorded_by' => $teller->id,
        'opening_balance_recorded_at' => now(),
        'opening_physical_balance' => 250000,
        'opening_difference' => 0,
    ]);

    $this->withToken($token)
        ->deleteJson('/api/admin/users/'.$teller->id)
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    $this->assertDatabaseHas('users', [
        'id' => $teller->id,
        'status' => 'blocked',
    ]);
    $this->assertDatabaseHas('daily_reports', [
        'id' => $report->id,
        'teller_id' => $teller->id,
        'opening_balance' => 250000,
    ]);
});
