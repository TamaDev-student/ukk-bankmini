<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::query()
            ->orderByDesc('created_at');

        // Filter berdasarkan username
        if ($request->filled('username')) {
            $query->where(
                'username',
                'like',
                '%' . $request->username . '%'
            );
        }

        // Filter berdasarkan role
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Filter berdasarkan jenis aktivitas
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Filter berdasarkan tanggal
        if ($request->filled('date')) {
            $request->validate([
                'date' => ['date_format:Y-m-d'],
            ]);

            $query->whereDate('created_at', $request->date);
        }

        $logs = $query
            ->paginate(15)
            ->withQueryString();

        return response()->json([
            'message' => 'Data activity log berhasil diambil.',
            'data' => $logs,
        ]);
    }

    public function show(int $id)
    {
        $log = ActivityLog::find($id);

        if (! $log) {
            return response()->json([
                'message' => 'Data activity log tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'message' => 'Detail activity log berhasil diambil.',
            'data' => $log,
        ]);
    }
}