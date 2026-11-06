<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthController extends Controller
{
    public function login(
        Request $request,
        ActivityLogService $activityLogService
    ) {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where(
            'username',
            $credentials['username']
        )->first();

        if (
            ! $user ||
            ! Hash::check($credentials['password'], $user->password)
        ) {
            return response()->json([
                'message' => 'Username atau password salah',
            ], 401);
        }

        // Reject blocked accounts
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Akun Anda telah diblokir. Silakan hubungi administrator.',
            ], 403);
        }

        if (
            $user->role === 'nasabah' &&
            $user->must_change_password &&
            (
                ! $user->temporary_password_expires_at ||
                $user->temporary_password_expires_at->isPast()
            )
        ) {
            return response()->json([
                'message' => 'Password sementara telah kedaluwarsa. Hubungi administrator untuk mendapatkan kredensial baru.',
                'temporary_password_expired' => true,
            ], 403);
        }

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Record successful login
        $activityLogService->log(
            action: 'auth.login',
            description: 'Pengguna berhasil login.',
            entityType: 'User',
            entityId: $user->id,
            user: $user
        );

        return response()->json([
            'message' => 'Login berhasil',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'must_change_password' => $user->role === 'nasabah' && $user->must_change_password,
            ],
            'token' => $token,
        ]);
    }

    public function logout(
        Request $request,
        ActivityLogService $activityLogService
    ) {
        $token = $request->user()->currentAccessToken();

        if (
            $token instanceof TransientToken ||
            ! ($token instanceof PersonalAccessToken)
        ) {
            return response()->json([
                'message' => 'Endpoint ini khusus untuk autentikasi token mobile.',
            ], 403);
        }

        // Record logout before deleting the token
        $activityLogService->log(
            action: 'auth.logout',
            description: 'Pengguna berhasil logout.',
            entityType: 'User',
            entityId: $request->user()->id,
        );

        $token->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }
}
