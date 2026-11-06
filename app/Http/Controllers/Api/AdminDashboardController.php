<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // Role yang termasuk pengguna internal Bank Mini.
        $internalRoles = [
            'administrator',
            'teller',
            'supervisor',
        ];

        // Hitung pengguna berdasarkan role.
        $usersByRole = User::query()
            ->whereIn('role', $internalRoles)
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        // Hitung pengguna internal berdasarkan status akun.
        $usersByStatus = User::query()
            ->whereIn('role', $internalRoles)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Hitung rekening berdasarkan status.
        $customersByStatus = Customer::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Ambil total saldo seluruh rekening.
        $totalBalance = Customer::query()->sum('balance');

        return response()->json([
            'message' => 'Ringkasan dashboard berhasil diambil.',
            'data' => [
                // Statistik pengguna internal.
                'total_users' => (int) $usersByRole->sum(),

                'total_administrators' => (int) (
                    $usersByRole->get('administrator', 0)
                ),

                'total_tellers' => (int) (
                    $usersByRole->get('teller', 0)
                ),

                'total_supervisors' => (int) (
                    $usersByRole->get('supervisor', 0)
                ),

                'active_users' => (int) (
                    $usersByStatus->get('active', 0)
                ),

                'blocked_users' => (int) (
                    $usersByStatus->get('blocked', 0)
                ),

                // Statistik rekening nasabah.
                'total_customers' => (int) Customer::query()->count(),

                'active_customers' => (int) (
                    $customersByStatus->get('active', 0)
                ),

                'blocked_customers' => (int) (
                    $customersByStatus->get('blocked', 0)
                ),

                'total_balance' => (int) $totalBalance,
            ],
        ]);
    }
}