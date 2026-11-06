<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\DailyReport;
use App\Models\DailyReportCorrection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
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
        ])->orderBy('id')->get();

        return response()->json([
            'message' => 'Daftar user berhasil diambil.',
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

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        if (isset($validated['status']) && $user->customer) {
            $user->customer->update(['status' => $validated['status']]);
            Cache::forget("customer_pin_failed_attempts_{$user->customer->id}");
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

    public function destroy(int $id): JsonResponse
    {
        try {
            return DB::transaction(function () use ($id) {
                $user = User::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                $hasFinancialHistory = Transaction::query()
                    ->where('user_id', $user->id)
                    ->exists()
                    || DailyReport::query()
                        ->where(function (Builder $query) use ($user) {
                            $query->where('teller_id', $user->id)
                                ->orWhere('approved_by', $user->id)
                                ->orWhere('opening_balance_recorded_by', $user->id);
                        })
                        ->exists()
                    || DailyReportCorrection::query()
                        ->where('user_id', $user->id)
                        ->exists();

                if ($hasFinancialHistory) {
                    $user->forceFill(['status' => 'blocked'])->save();
                    $user->tokens()->delete();

                    return response()->json([
                        'message' => 'Pengguna memiliki riwayat transaksi atau laporan keuangan, sehingga tidak dihapus. Akun telah diblokir dan seluruh token akses dicabut agar riwayat audit tetap utuh.',
                        'data' => [
                            'deleted' => false,
                            'deactivated' => true,
                        ],
                    ], 200);
                }

                $user->delete();

                return response()->json([
                    'message' => 'User berhasil dihapus.',
                    'data' => [
                        'deleted' => true,
                        'deactivated' => false,
                    ],
                ], 200);
            });
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? $e->getCode();
            $driverCode = $e->errorInfo[1] ?? null;

            if ($sqlState === '23000' || $driverCode === 19 || $driverCode === 1451) {
                return response()->json([
                    'message' => 'Pengguna masih terhubung dengan data yang harus dipertahankan. Akun tidak dihapus untuk menjaga riwayat audit; periksa keterkaitan data atau blokir akun.',
                ], 409);
            }

            throw $e;
        }
    }
}
