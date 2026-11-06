<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the authenticated user's account status on every request.
 *
 * Blocks requests from users whose status is not 'active',
 * even if they still hold a valid session or token.
 * For session-based (web) requests the session is invalidated;
 * for token-based (API) requests the current token is revoked.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            // Invalidate session for web guard only when session is present
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Akun Anda telah diblokir. Silakan hubungi administrator.',
                'is_blocked' => true,
            ], 403);
        }

        return $next($request);
    }
}
