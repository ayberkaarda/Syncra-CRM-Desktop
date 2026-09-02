<?php

namespace App\Services\Auth;

use App\Listeners\LogFailedLogin;
use App\Listeners\LogLockout;
use App\Models\SessionLog;
use App\Models\User;
use App\Support\UserAgentParser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues desktop device tokens (SYNCDESKTOP §4.3, protocol §3.5).
 *
 * ------------------------------------------------------------------------
 * WHY THE THROTTLE IS DONE HERE AND NOT WITH `throttle:login`
 * ------------------------------------------------------------------------
 * Protocol §3.5 requires this endpoint to share the SAME keyed lockout as
 * POST /api/login, and SYNCDESKTOP §4.3 requires it to answer
 * `423 LOCKED_OUT` with `retry_after`. Those two cannot both come from the
 * middleware: a named limiter's response callback is fixed at registration
 * (429 TOO_MANY_ATTEMPTS), and registering a SECOND named limiter would give
 * this endpoint a SEPARATE attempt counter, because
 * ThrottleRequests::handleRequestUsingNamedLimiter() derives the cache key as
 * `md5($limiterName . $limit->key)`.
 *
 * So the check is performed inline against the EXACT key the `login` limiter
 * writes to - `AuthService::limiterKey(AuthService::throttleKey($email, $ip))` -
 * which makes the counter genuinely shared: five failures split any way across
 * the browser and the desktop app lock both. The escalating window
 * (1→2→4→…→60 min) is shared too, since `AuthService::lockoutCounterKey()` is a
 * plain cache key that carries no limiter name.
 *
 * ------------------------------------------------------------------------
 * WHY 401 HERE BUT 422 ON /api/login
 * ------------------------------------------------------------------------
 * The SPA renders a form and wants a field-level validation error. The desktop
 * client renders a login screen driven by HTTP status: 401 means "credentials
 * rejected", 403 "account disabled", 423 "locked". SYNCDESKTOP §4.3 fixes
 * these; the messages stay identical to the web flow so neither surface can be
 * used to enumerate accounts.
 */
class DeviceTokenService
{
    /**
     * The single ability a device token ever carries (SYNCDESKTOP K4).
     */
    public const ABILITY = 'desktop';

    public function __construct(
        private readonly LogFailedLogin $logFailedLogin,
        private readonly LogLockout $logLockout,
        private readonly UserAgentParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, token: NewAccessToken}
     */
    public function issue(array $input, Request $request): array
    {
        $email = (string) $input['email'];
        $throttleKey = AuthService::throttleKey($email, $request->ip());
        $limiterKey = AuthService::limiterKey($throttleKey);

        $this->assertNotLockedOut($limiterKey, $email, $request);

        $guard = Auth::guard('web');

        // validate() checks credentials WITHOUT establishing a session - there
        // is no session to establish here anyway, and a deactivated account
        // must never be signed in even for one request.
        if (! $guard->validate(['email' => $email, 'password' => (string) $input['password']])) {
            $this->registerFailure($throttleKey, $limiterKey, $email, $request, $guard->getLastAttempted());

            throw new HttpResponseException(response()->json([
                'errors' => [
                    'message' => __('errors.sync.invalid_credentials'),
                    'code' => 'INVALID_CREDENTIALS',
                ],
            ], 401));
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if (! $user->is_active) {
            $this->logFailedLogin->log($user, $email, $request, 'desktop');

            throw new HttpResponseException(response()->json([
                'errors' => [
                    'message' => __('errors.sync.user_inactive'),
                    'code' => 'USER_INACTIVE',
                ],
            ], 403));
        }

        $token = DB::transaction(function () use ($user, $input): NewAccessToken {
            /*
             * ONE TOKEN PER DEVICE (SYNCDESKTOP §4.3). Re-authenticating from
             * the same machine - a reinstall, a wipe, a forgotten session -
             * must not leave an orphaned credential behind that nobody can see
             * or revoke. Scoped to this user's tokens: a fingerprint is a
             * device identifier, not an authorisation, so it may never reach
             * across accounts.
             */
            $user->tokens()
                ->where('device_fingerprint', $input['device_fingerprint'])
                ->delete();

            $created = $user->createToken((string) $input['device_name'], [self::ABILITY]);

            /*
             * Written after creation rather than through createToken(), which
             * has no seam for extra columns. Same transaction, so a token can
             * never exist without its fingerprint - which would make it
             * un-replaceable by the delete above.
             */
            $created->accessToken->forceFill([
                'device_fingerprint' => $input['device_fingerprint'],
                'device_platform' => $input['platform'],
            ])->save();

            return $created;
        });

        RateLimiter::clear($limiterKey);
        Cache::forget(AuthService::lockoutCounterKey($throttleKey));

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->logDeviceLogin($user, $input, $request);

        return ['user' => $user->refresh()->load('roles'), 'token' => $token];
    }

    /**
     * @throws HttpResponseException 423 LOCKED_OUT
     */
    private function assertNotLockedOut(string $limiterKey, string $email, Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($limiterKey, AuthService::MAX_LOGIN_ATTEMPTS)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'errors' => [
                'message' => __('errors.too_many_attempts'),
                'code' => 'LOCKED_OUT',
                'retry_after' => RateLimiter::availableIn($limiterKey),
            ],
        ], 423, ['Retry-After' => (string) RateLimiter::availableIn($limiterKey)]));
    }

    /**
     * Count the failure against the SHARED login counter and escalate the
     * window when this attempt exhausted it - the same two steps
     * AuthService::registerFailedAttempt() performs for the web flow.
     */
    private function registerFailure(
        string $throttleKey,
        string $limiterKey,
        string $email,
        Request $request,
        ?User $matchedUser,
    ): void {
        RateLimiter::hit($limiterKey, AuthService::lockoutMinutes($throttleKey) * 60);

        Log::warning('Başarısız cihaz girişi denemesi.', [
            'reason' => 'invalid_credentials',
            'channel' => 'desktop',
            'email' => $email,
            'ip' => $request->ip(),
        ]);

        $this->logFailedLogin->log($matchedUser, $email, $request, 'desktop');

        if (RateLimiter::attempts($limiterKey) < AuthService::MAX_LOGIN_ATTEMPTS) {
            return;
        }

        $counterKey = AuthService::lockoutCounterKey($throttleKey);
        $lockouts = (int) Cache::get($counterKey, 0) + 1;

        Cache::put($counterKey, $lockouts, now()->addMinutes(AuthService::LOCKOUT_MEMORY_MINUTES));

        $this->logLockout->log($email, $request, 'desktop');
    }

    /**
     * The successful-login audit row.
     *
     * LogSuccessfulLogin is NOT reused: it reads `$request->session()->getId()`
     * as the key the matching `logout` row joins on, and a bearer request has
     * no session at all. Calling it here would throw, be swallowed by its own
     * try/catch, and leave NO row - the opposite of what §4.3 asks for. The
     * device's identity is the token, so `session_id` legitimately stays null
     * and `channel` carries the distinction instead.
     */
    private function logDeviceLogin(User $user, array $input, Request $request): void
    {
        try {
            $userAgent = (string) $request->userAgent();
            $parsed = $this->parser->parse($userAgent);

            SessionLog::create([
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'event' => 'login',
                'channel' => 'desktop',
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent,
                'device' => (string) $input['device_name'],
                'browser' => 'Syncra Desktop '.$input['app_version'],
                'platform' => (string) $input['platform'],
                'session_id' => null,
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Same trade-off the web listeners make: an audit-row failure must
            // never cost the user their login.
            Log::error('Cihaz giriş logu (session_logs) yazılamadı.', [
                'user_id' => $user->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
