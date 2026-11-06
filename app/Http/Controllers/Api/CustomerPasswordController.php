<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class CustomerPasswordController extends Controller
{
    public function firstChange(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->must_change_password) {
            return response()->json([
                'message' => 'Akun ini tidak memerlukan perubahan password awal.',
            ], 409);
        }

        if (
            ! $user->temporary_password_expires_at ||
            $user->temporary_password_expires_at->isPast()
        ) {
            return response()->json([
                'message' => 'Password sementara telah kedaluwarsa. Hubungi administrator untuk mendapatkan kredensial baru.',
                'temporary_password_expired' => true,
            ], 403);
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(10)->letters()->mixedCase()->numbers(),
            ],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Password berhasil diperbarui.',
            'must_change_password' => false,
        ]);
    }
}
