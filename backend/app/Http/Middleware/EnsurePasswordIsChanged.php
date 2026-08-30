<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of the `must_change_password` flag.
 *
 * A password minted by an administrator is a one-time credential: until the
 * user replaces it, the administrator knows their password (non-repudiation is
 * gone) and any leak of the hand-off channel is permanent account access.
 *
 * Applied through a WHITELIST route group (see routes/api.php): everything
 * inside the authenticated group is subject to the flag except logout, me and
 * password/change. A blacklist would be fail-open - every endpoint added in a
 * later phase would silently become a bypass.
 *
 * Deliberately asymmetric with EnsureUserIsActive: this middleware does NOT
 * terminate the session. The identity is valid and the user must stay signed in
 * long enough to actually change their password; only their access is narrowed.
 *
 * Order is auth:sanctum -> active -> password.changed, so a deactivated account
 * is rejected with USER_DEACTIVATED before the password rule is ever consulted.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No extra query: read straight off the model the guard already
        // hydrated for this request (same pattern as EnsureUserIsActive).
        if ($user !== null && $user->must_change_password) {
            return AuthService::passwordChangeRequiredResponse();
        }

        return $next($request);
    }
}
