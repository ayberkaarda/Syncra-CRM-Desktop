<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a REAL device token, not merely a caller that passes
 * `ability:desktop` (protocol §3.3 / K-A).
 *
 * ------------------------------------------------------------------------
 * WHY `ability:desktop` IS NOT ENOUGH - the chain, verified in vendor
 * ------------------------------------------------------------------------
 *   1. Guard.php:31-38  when Sanctum authenticates from the session cookie it
 *      calls `$user->withAccessToken(new TransientToken)` - but only if
 *      `supportsTokens($user)` is true.
 *   2. Guard.php:71-76  `supportsTokens()` checks for the HasApiTokens trait.
 *      Before F1 that was false; adding the trait to User made it true.
 *   3. TransientToken.php:15-18  its `can()` returns an unconditional `true`.
 *   4. CheckForAnyAbility.php:22-30  only asks "is currentAccessToken() set"
 *      and "does tokenCan() pass".
 *
 * So from the moment User gained HasApiTokens, EVERY SPA cookie session
 * satisfies `ability:desktop`. SYNCDESKTOP §9's first security criterion
 * ("a token without the `desktop` ability gets 403 on /api/sync/*") cannot be
 * met by the ability middleware alone, and - worse - a test written with
 * `actingAs()` would pass while proving nothing.
 *
 * This middleware closes the hole by demanding the concrete token class.
 * `instanceof PersonalAccessToken` is exactly the distinction that matters: a
 * TransientToken is not one, so cookie sessions are refused here regardless of
 * what their abilities claim.
 *
 * 403, not 401: the caller IS authenticated and the route DOES exist - what is
 * missing is the credential type. Same shape as USER_DEACTIVATED and
 * PASSWORD_CHANGE_REQUIRED: a 403 with a distinguishing `code` so the client
 * can tell "log in again" from "this endpoint is desktop-only".
 */
class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'errors' => [
                    'message' => __('errors.sync.device_token_required'),
                    'code' => 'ABILITY_REQUIRED',
                ],
            ], 403);
        }

        return $next($request);
    }
}
