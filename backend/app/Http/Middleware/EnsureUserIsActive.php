<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard stop for accounts that were deactivated while a session was open.
 *
 * This is the PRIMARY, synchronous half of the session-revoke mechanism
 * (see App\Events\UserDeactivated for the full description). Because the
 * session store is Redis and Laravel's Redis session driver keys sessions by
 * session id only - there is no user_id index to sweep - revoking every open
 * session of a given user is not practical. Checking the flag on each
 * authenticated request is therefore the mechanism, not a fallback.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No extra database query: $request->user() is the model the guard has
        // already resolved and cached for this request, so `is_active` is read
        // straight off the hydrated attributes.
        if ($user !== null && ! $user->is_active) {
            Log::warning('Devre dışı hesap ile istek engellendi.', [
                'user_id' => $user->id,
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return AuthService::deactivatedResponse();
        }

        return $next($request);
    }
}
