<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::select([
            'id',
            'name',
            'username',
            'full_name',
            'email',
            'role',
            'status',
            'created_at',
        ])->where(function ($query) {
            $query->where('status', 'active')
                ->orWhereNotIn('role', ['teller', 'nasabah']);
        })->orderBy('id')->get();

        return response()->json([
            'message' => 'Daftar user berhasil diambil.',
            'data' => $users,
        ], 200);
    }

    public function archived(): JsonResponse
    {
        $users = User::query()
            ->where('status', 'blocked')
            ->whereIn('role', ['teller', 'nasabah'])
            ->select([
                'id',
                'name',
                'username',
                'full_name',
                'email',
                'role',
                'status',
                'blocked_at',
            ])
            ->orderByDesc('blocked_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Daftar pengguna arsip berhasil diambil.',
            'data' => $users,
        ], 200);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['status'] = 'active';

        $user = User::create($validated);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'created_at' => $user->created_at,
            ],
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::select([
            'id',
            'name',
            'username',
            'full_name',
            'email',
            'role',
            'status',
            'blocked_at',
            'created_at',
            'updated_at',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Detail user berhasil diambil.',
            'data' => $user,
        ], 200);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $validated = $request->validated();
        $previousStatus = $user->status;

        if (
            ($validated['status'] ?? null) === 'blocked' &&
            $request->user()->is($user)
        ) {
            return response()->json([
                'message' => 'Anda tidak dapat memblokir akun sendiri.',
            ], 409);
        }

        if (
            $previousStatus === 'blocked' &&
            ($validated['status'] ?? null) === 'active' &&
            $user->role === 'nasabah' &&
            ! $user->customer
        ) {
            return response()->json([
                'message' => 'Akun nasabah tidak memiliki rekening yang terhubung dan tidak dapat dipulihkan.',
            ], 409);
        }

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        if (
            isset($validated['status']) &&
            $validated['status'] !== $previousStatus
        ) {
            $user->forceFill([
                'blocked_at' => $validated['status'] === 'blocked' ? now() : null,
            ])->save();

            if ($validated['status'] === 'blocked') {
                $user->tokens()->delete();
            }

            if ($user->customer) {
                $user->customer->update([
                    'status' => $validated['status'],
                    'pin_locked_at' => null,
                ]);
                Cache::forget("customer_pin_failed_attempts_{$user->customer->id}");
            }
        }

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'updated_at' => $user->updated_at,
            ],
        ], 200);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $user = User::query()->lockForUpdate()->findOrFail($id);

            if ($request->user()->is($user)) {
                return response()->json([
                    'message' => 'Anda tidak dapat mengarsipkan akun sendiri.',
                ], 409);
            }

            if (! in_array($user->role, ['teller', 'nasabah'], true)) {
                return response()->json([
                    'message' => 'Hanya akun teller dan nasabah yang dapat diarsipkan.',
                ], 422);
            }

            if ($user->status === 'blocked') {
                return response()->json([
                    'message' => 'Akun ini sudah berada di arsip.',
                ], 409);
            }

            $user->forceFill([
                'status' => 'blocked',
                'blocked_at' => now(),
            ])->save();
            $user->tokens()->delete();

            if ($user->customer) {
                $user->customer->update([
                    'status' => 'blocked',
                    'pin_locked_at' => null,
                ]);
                Cache::forget("customer_pin_failed_attempts_{$user->customer->id}");
            }

            return response()->json([
                'message' => 'Akun berhasil diblokir dan dipindahkan ke arsip.',
                'data' => [
                    'id' => $user->id,
                    'archived' => true,
                    'blocked_at' => $user->blocked_at,
                ],
            ], 200);
        });
    }

    public function restore(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $user = User::query()
                ->with('customer')
                ->lockForUpdate()
                ->findOrFail($id);

            if (! in_array($user->role, ['teller', 'nasabah'], true)) {
                return response()->json([
                    'message' => 'Hanya akun teller dan nasabah yang dapat dipulihkan dari arsip.',
                ], 422);
            }

            if ($user->status !== 'blocked') {
                return response()->json([
                    'message' => 'Akun ini tidak sedang berada di arsip.',
                ], 409);
            }

            if ($user->role === 'nasabah' && ! $user->customer) {
                return response()->json([
                    'message' => 'Akun nasabah tidak memiliki rekening yang terhubung dan tidak dapat dipulihkan.',
                ], 409);
            }

            $user->forceFill([
                'status' => 'active',
                'blocked_at' => null,
            ])->save();

            if ($user->customer) {
                $user->customer->update([
                    'status' => 'active',
                    'pin_locked_at' => null,
                ]);
                Cache::forget("customer_pin_failed_attempts_{$user->customer->id}");
            }

            return response()->json([
                'message' => 'Akun berhasil dipulihkan dan kembali aktif.',
                'data' => [
                    'id' => $user->id,
                    'status' => 'active',
                ],
            ], 200);
        });
    }
}
