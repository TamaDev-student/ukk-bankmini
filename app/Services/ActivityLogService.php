<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ActivityLogService
{
    public function log(
        string $action,
        ?string $description = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?User $user = null
    ): ?ActivityLog {
        try {
            $user = $user ?? Auth::user();

            return ActivityLog::create([
                'user_id' => $user?->id,
                'username' => $user?->username,
                'role' => $user?->role,
                'action' => $action,
                'description' => $description,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => app()->runningInConsole()
                    ? null
                    : request()->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}