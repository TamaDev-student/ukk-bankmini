<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user?->role === 'nasabah' &&
            $user->must_change_password &&
            ! $request->routeIs('customer.password.first-change')
        ) {
            return response()->json([
                'message' => 'Ganti password sementara Anda sebelum menggunakan fitur lainnya.',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
