<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| Web Auth (Session-Cookie) & Security Tests
|--------------------------------------------------------------------------
|
| Tests for the SPA web authentication flow and endpoint isolation:
| - Role restriction on web login (nasabah blocked from web portal)
| - Operational roles permitted (administrator, teller, supervisor)
| - Endpoint isolation: Bearer tokens blocked from /api/web/*
| - Mobile Bearer token auth preserved for mobile endpoints
| - CSRF cookie endpoint & browser SPA flow
| - Account status middleware for session & bearer tokens
| - Mobile login username column correctness
|
*/

beforeEach(function () {
    $this->withHeaders([
        'Referer' => 'http://localhost',
        'Origin' => 'http://localhost',
    ]);
});

// ──────────────────────────────────────────────
// 1. Web Login & Role Restrictions
// ──────────────────────────────────────────────

test('web login succeeds with valid credentials for teller', function () {
    User::factory()->create([
        'username' => 'teller01',
        'password' => Hash::make('secret123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/web/login', [
        'username' => 'teller01',
        'password' => 'secret123',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Login berhasil.')
        ->assertJsonPath('user.username', 'teller01')
        ->assertJsonPath('user.role', 'teller')
        ->assertJsonMissing(['token']);
});

test('web login rejects nasabah role with 403 and creates no session', function () {
    User::factory()->create([
        'username' => 'nasabah01',
        'password' => Hash::make('secret123'),
        'role' => 'nasabah',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/web/login', [
        'username' => 'nasabah01',
        'password' => 'secret123',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('message', 'Akses ditolak. Portal Web hanya dapat diakses oleh staf operasional.');

    // Assert user is not authenticated on web guard
    $this->assertGuest('web');

    // Accessing protected web endpoint should fail
    $this->getJson('/api/web/user')
        ->assertUnauthorized();
});

test('web login succeeds for all three operational roles', function () {
    $roles = ['administrator', 'teller', 'supervisor'];

    foreach ($roles as $role) {
        User::factory()->create([
            'username' => "user_{$role}",
            'password' => Hash::make('secret123'),
            'role' => $role,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/web/login', [
            'username' => "user_{$role}",
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login berhasil.')
            ->assertJsonPath('user.role', $role);

        // Logout before next iteration
        $this->postJson('/api/web/logout');
    }
});

test('web login fails with wrong password', function () {
    User::factory()->create([
        'username' => 'teller01',
        'password' => Hash::make('secret123'),
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/web/login', [
        'username' => 'teller01',
        'password' => 'wrongpassword',
    ]);

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Username atau password salah.');
});

test('web login fails with nonexistent username', function () {
    $response = $this->postJson('/api/web/login', [
        'username' => 'nobody',
        'password' => 'password',
    ]);

    $response->assertUnauthorized();
});

test('web login rejects blocked account with 403', function () {
    User::factory()->create([
        'username' => 'blocked_user',
        'password' => Hash::make('secret123'),
        'status' => 'blocked',
        'role' => 'teller',
    ]);

    $response = $this->postJson('/api/web/login', [
        'username' => 'blocked_user',
        'password' => 'secret123',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('message', 'Akun Anda telah diblokir. Silakan hubungi administrator.');

    $this->assertGuest('web');
});

test('web login validates required fields', function () {
    $response = $this->postJson('/api/web/login', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'password']);
});

test('web login rate limits after 5 failed attempts', function () {
    User::factory()->create([
        'username' => 'limited_user',
        'password' => Hash::make('correct'),
        'status' => 'active',
        'role' => 'teller',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/web/login', [
            'username' => 'limited_user',
            'password' => 'wrong',
        ])->assertUnauthorized();
    }

    $response = $this->postJson('/api/web/login', [
        'username' => 'limited_user',
        'password' => 'correct',
    ]);

    $response->assertStatus(429);
});

// ──────────────────────────────────────────────
// 2. Web Endpoint Isolation & Logout
// ──────────────────────────────────────────────

test('web logout invalidates session', function () {
    $user = User::factory()->create([
        'role' => 'teller',
        'status' => 'active',
    ]);

    $this->actingAs($user);

    $response = $this->postJson('/api/web/logout');

    $response->assertOk()
        ->assertJsonPath('message', 'Logout berhasil.');

    $this->getJson('/api/web/user')
        ->assertUnauthorized();
});

test('web logout requires authentication', function () {
    $response = $this->postJson('/api/web/logout');

    $response->assertUnauthorized();
});

test('web user endpoint returns authenticated user info', function () {
    $user = User::factory()->create([
        'username' => 'admin01',
        'name' => 'Admin',
        'full_name' => 'Administrator User',
        'role' => 'administrator',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/web/user');

    $response->assertOk()
        ->assertJsonPath('user.username', 'admin01')
        ->assertJsonPath('user.name', 'Admin')
        ->assertJsonPath('user.full_name', 'Administrator User')
        ->assertJsonPath('user.role', 'administrator');
});

test('web user endpoint requires authentication', function () {
    $response = $this->getJson('/api/web/user');

    $response->assertUnauthorized();
});

test('mobile bearer token cannot access web-specific endpoints', function () {
    $user = User::factory()->create([
        'role' => 'teller',
        'status' => 'active',
    ]);

    $token = $user->createToken('mobile_token')->plainTextToken;

    // GET /api/web/user with bearer token must be rejected (401)
    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/web/user')
        ->assertUnauthorized();

    // POST /api/web/logout with bearer token must be rejected (401)
    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/web/logout')
        ->assertUnauthorized();
});

test('mobile bearer token can access mobile and operational endpoints', function () {
    $user = User::factory()->create([
        'role' => 'teller',
        'status' => 'active',
    ]);

    $token = $user->createToken('mobile_token')->plainTextToken;

    // GET /api/user works with bearer token
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/user');

    $response->assertOk()
        ->assertJsonPath('id', $user->id);

    // POST /api/logout works with bearer token
    $logoutResponse = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/api/logout');

    $logoutResponse->assertOk()
        ->assertJsonPath('message', 'Logout berhasil');

    // Token has been deleted
    expect(PersonalAccessToken::findToken($token))->toBeNull();

    // Clear in-memory auth state to simulate a new subsequent HTTP request
    $this->app['auth']->forgetGuards();

    // Token cannot be reused after logout (401 Unauthorized)
    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/user')
        ->assertUnauthorized();
});

test('web session request to mobile logout endpoint POST /api/logout is rejected with 403 and session remains active', function () {
    $user = User::factory()->create([
        'username' => 'session_teller',
        'role' => 'teller',
        'status' => 'active',
    ]);

    // Authenticate user via Web session
    $this->actingAs($user);

    // Calling mobile logout endpoint POST /api/logout with web session should be rejected with 403
    $response = $this->postJson('/api/logout');

    $response->assertForbidden()
        ->assertJsonPath('message', 'Endpoint ini khusus untuk autentikasi token mobile.');

    // Crucial: Web session must remain valid and usable after the rejected request
    $userResponse = $this->getJson('/api/web/user');
    $userResponse->assertOk()
        ->assertJsonPath('user.username', 'session_teller');

    $opResponse = $this->getJson('/api/user');
    $opResponse->assertOk()
        ->assertJsonPath('id', $user->id);
});

// ──────────────────────────────────────────────
// 3. CSRF & SPA Flow Verification
// ──────────────────────────────────────────────

test('sanctum csrf-cookie endpoint returns 204 and sets xsrf and session cookies', function () {
    $response = $this->get('/sanctum/csrf-cookie');

    $response->assertNoContent(); // 204
    $response->assertCookie('XSRF-TOKEN');
});

test('full browser spa flow: csrf-cookie -> login -> user -> logout', function () {
    $user = User::factory()->create([
        'username' => 'spa_teller',
        'password' => Hash::make('secret123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    // Step 1: GET /sanctum/csrf-cookie
    $csrfResponse = $this->get('/sanctum/csrf-cookie');
    $csrfResponse->assertNoContent();
    $csrfResponse->assertCookie('XSRF-TOKEN');

    // Step 2: POST /api/web/login
    $loginResponse = $this->postJson('/api/web/login', [
        'username' => 'spa_teller',
        'password' => 'secret123',
    ]);

    $loginResponse->assertOk()
        ->assertJsonPath('message', 'Login berhasil.')
        ->assertJsonPath('user.username', 'spa_teller')
        ->assertJsonMissing(['token']);

    // Step 3: GET /api/web/user
    $userResponse = $this->getJson('/api/web/user');
    $userResponse->assertOk()
        ->assertJsonPath('user.username', 'spa_teller');

    // Step 4: POST /api/web/logout
    $logoutResponse = $this->postJson('/api/web/logout');
    $logoutResponse->assertOk()
        ->assertJsonPath('message', 'Logout berhasil.');

    // Step 5: GET /api/web/user after logout
    $this->getJson('/api/web/user')
        ->assertUnauthorized();
});

// ──────────────────────────────────────────────
// 4. Mobile Login Username Field Match
// ──────────────────────────────────────────────

test('mobile login returns actual username column not name column', function () {
    $user = User::factory()->create([
        'name' => 'Budi Operator Display Name',
        'username' => 'budi_teller_01',
        'full_name' => 'Budi Operator Lengkap',
        'password' => Hash::make('mobile123'),
        'role' => 'teller',
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/login', [
        'username' => 'budi_teller_01',
        'password' => 'mobile123',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Login berhasil')
        ->assertJsonPath('user.username', 'budi_teller_01')
        ->assertJsonPath('user.full_name', 'Budi Operator Lengkap')
        ->assertJsonPath('user.role', 'teller')
        ->assertJsonStructure(['token', 'user']);

    // Explicitly assert that username is NOT the name column
    expect($response->json('user.username'))->not->toBe('Budi Operator Display Name');
    expect($response->json('user.username'))->toBe('budi_teller_01');
});

test('mobile login rejects blocked account', function () {
    User::factory()->create([
        'username' => 'blocked_mobile',
        'password' => Hash::make('password123'),
        'role' => 'teller',
        'status' => 'blocked',
    ]);

    $response = $this->postJson('/api/login', [
        'username' => 'blocked_mobile',
        'password' => 'password123',
    ]);

    $response->assertForbidden()
        ->assertJsonPath('message', 'Akun Anda telah diblokir. Silakan hubungi administrator.');
});

// ──────────────────────────────────────────────
// 5. Account Status Middleware (EnsureAccountIsActive)
// ──────────────────────────────────────────────

test('blocked user is rejected from web endpoints during active session and session is invalidated', function () {
    $user = User::factory()->create([
        'status' => 'active',
        'role' => 'teller',
    ]);

    $this->actingAs($user);

    // Admin blocks the user
    $user->update(['status' => 'blocked']);

    // Next request is rejected by EnsureAccountIsActive
    $response = $this->getJson('/api/web/user');

    $response->assertForbidden()
        ->assertJsonPath('message', 'Akun Anda telah diblokir. Silakan hubungi administrator.');

    // Subsequent request should fail because session was invalidated
    $this->getJson('/api/web/user')
        ->assertUnauthorized();
});

test('blocked user with bearer token is rejected with 403 and token is deleted without session error', function () {
    $user = User::factory()->create([
        'status' => 'active',
        'role' => 'teller',
    ]);

    $token = $user->createToken('mobile_token')->plainTextToken;
    $otherToken = $user->createToken('other_mobile_token')->plainTextToken;

    // Admin blocks the user
    $user->update(['status' => 'blocked']);

    // Next request with Bearer token is rejected by EnsureAccountIsActive
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/user');

    $response->assertForbidden()
        ->assertJsonPath('message', 'Akun Anda telah diblokir. Silakan hubungi administrator.');

    // Verify token was deleted from database
    expect(PersonalAccessToken::findToken($token))->toBeNull();
    expect(PersonalAccessToken::findToken($otherToken))->not->toBeNull();

    // Subsequent request with revoked token fails with 401 Unauthenticated
    $this->app['auth']->forgetGuards();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/user')
        ->assertUnauthorized();
});

test('active user passes account status check', function () {
    $user = User::factory()->create([
        'status' => 'active',
        'role' => 'administrator',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/web/user');

    $response->assertOk();
});

// ──────────────────────────────────────────────
// 6. Security & Response Sanitation
// ──────────────────────────────────────────────

test('web login response does not contain password or token fields', function () {
    User::factory()->create([
        'username' => 'secure_user',
        'password' => Hash::make('secure123'),
        'status' => 'active',
        'role' => 'teller',
    ]);

    $response = $this->postJson('/api/web/login', [
        'username' => 'secure_user',
        'password' => 'secure123',
    ]);

    $response->assertOk()
        ->assertJsonMissing(['token'])
        ->assertJsonMissing(['password'])
        ->assertJsonMissing(['two_factor_secret'])
        ->assertJsonMissing(['remember_token']);
});

test('web user response does not expose sensitive fields', function () {
    $user = User::factory()->create([
        'status' => 'active',
        'role' => 'teller',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/web/user');

    $response->assertOk()
        ->assertJsonMissing(['password'])
        ->assertJsonMissing(['two_factor_secret'])
        ->assertJsonMissing(['remember_token']);
});

// ──────────────────────────────────────────────
// 7. Role-Based Access via Session Auth
// ──────────────────────────────────────────────

test('teller can access protected routes via session auth', function () {
    $user = User::factory()->create([
        'role' => 'teller',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/user');

    $response->assertOk();
});

test('non-teller cannot access teller routes', function () {
    $user = User::factory()->create([
        'role' => 'supervisor',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/teller/customers/TEST001');

    $response->assertForbidden();
});
