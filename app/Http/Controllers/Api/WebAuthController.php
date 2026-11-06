<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles session-cookie authentication for the SPA web frontend.
 *
 * These endpoints rely on Sanctum's stateful (cookie/session) authentication.
 * They do NOT issue bearer tokens — the browser receives an HttpOnly session
 * cookie and an XSRF-TOKEN cookie instead.
 *
 * The existing token-based AuthController (POST /api/login, POST /api/logout)
 * remains untouched for mobile clients.
 */
class WebAuthController extends Controller
{
    /**
     * Authenticate a user via session cookie (SPA login).
     *
     * Rate-limited to 5 attempts per minute per username+IP.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Rate limiting: 5 attempts per minute per username+IP
        $throttleKey = 'web-login:'.mb_strtolower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }

        if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'message' => 'Username atau password salah.',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        // Check if account is blocked
        if ($user->status !== 'active') {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Akun Anda telah diblokir. Silakan hubungi administrator.',
            ], 403);
        }

        // Web portal is strictly for operational staff (administrator, teller, supervisor)
        $allowedRoles = ['administrator', 'teller', 'supervisor'];
        if (! in_array($user->role, $allowedRoles, true)) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Akses ditolak. Portal Web hanya dapat diakses oleh staf operasional.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => 'Login berhasil.',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'full_name' => $user->full_name,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Logout the current session-authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Return the currently authenticated user's info.
     */
    public function user(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'full_name' => $user->full_name,
                'role' => $user->role,
            ],
        ]);
    }
}
