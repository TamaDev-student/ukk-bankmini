<?php

use App\Models\Customer;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassRosterService;
use Database\Seeders\ClassRosterSeeder;
use Illuminate\Support\Facades\Hash;

function classRosterAdmin(): array
{
    $user = User::create([
        'name' => 'Roster Administrator',
        'username' => 'roster-admin',
        'full_name' => 'Roster Administrator',
        'email' => 'roster-admin@example.test',
        'password' => Hash::make('Password123'),
        'role' => 'administrator',
        'status' => 'active',
    ]);

    return [$user, $user->createToken('test')->plainTextToken];
}

test('class roster seeder creates all configured classes idempotently', function () {
    $seeder = app(ClassRosterSeeder::class);
    $seeder->run();

    expect(Major::query()->whereIn('code', array_keys(config('class_roster.majors')))->count())
        ->toBe(8)
        ->and(app(ClassRosterService::class)->configuredClasses())->toHaveCount(48);

    $seeder->run();

    expect(app(ClassRosterService::class)->configuredClasses())->toHaveCount(48);
});

test('admin classes endpoint exposes only configured class combinations', function () {
    app(ClassRosterSeeder::class)->run();
    [, $token] = classRosterAdmin();

    $response = $this->withToken($token)
        ->getJson('/api/admin/classes')
        ->assertOk();

    $classes = collect($response->json('data'));
    expect($classes)->toHaveCount(48);

    foreach (config('class_roster.majors') as $code => $maximumRombel) {
        expect($classes->where('major_code', $code))->toHaveCount(3 * $maximumRombel);
        expect($classes->where('major_code', $code)->max('rombel_limit'))->toBe($maximumRombel);
    }

    expect($classes->pluck('grade_level')->unique()->sort()->values()->all())
        ->toBe(['X', 'XI', 'XII']);
});

test('admin cannot assign a class outside the configured roster', function () {
    [$admin, $token] = classRosterAdmin();
    $major = Major::factory()->create(['name' => 'Jurusan Di Luar Konfigurasi', 'code' => 'OUT']);
    $schoolClass = SchoolClass::factory()->create([
        'name' => 'X OUT 1',
        'major_id' => $major->id,
    ]);

    $this->withToken($token)
        ->postJson('/api/admin/customers', [
            'student_number' => 'OUTSIDE-ROSTER-1',
            'student_name' => 'Siswa Uji',
            'email' => 'outside-roster@example.test',
            'class_id' => $schoolClass->id,
            'opening_balance' => 10000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_id']);

    expect($admin->customer)->toBeNull();
});

test('admin can preserve a legacy class but cannot move an account to another unconfigured class', function () {
    [, $token] = classRosterAdmin();
    $major = Major::factory()->create(['name' => 'Jurusan Lama', 'code' => 'OLD']);
    $currentClass = SchoolClass::factory()->create([
        'name' => 'X OLD 1',
        'major_id' => $major->id,
    ]);
    $otherClass = SchoolClass::factory()->create([
        'name' => 'X OLD 2',
        'major_id' => $major->id,
    ]);
    $customer = Customer::factory()->create(['class_id' => $currentClass->id]);

    $this->withToken($token)
        ->putJson('/api/admin/customers/'.$customer->id, [
            'student_name' => 'Nama diperbarui',
            'class_id' => $currentClass->id,
        ])
        ->assertOk();

    $this->withToken($token)
        ->putJson('/api/admin/customers/'.$customer->id, [
            'class_id' => $otherClass->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['class_id']);

    expect($customer->fresh()->class_id)->toBe($currentClass->id);
});
