<?php

namespace App\Services\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Listeners\LogFailedLogin;
use App\Listeners\LogLockout;
use App\Listeners\LogSuccessfulLogin;
use App\Listeners\LogSuccessfulLogout;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * All authentication business logic lives here - controllers stay thin
 * (Controller -> Service -> Repository is the project-wide rule).
 */
class AuthService
{
    /**
     * Faz 5 / B - session_logs (login/logout/failed_login/locked_out) audit
     * trail writers. Injected (not resolved ad-hoc) so the container wires
     * their own UserAgentParser dependency once. See each listener's
     * docblock for why it is called directly here instead of through
     * Illuminate's event dispatcher.
     */
    public function __construct(
        private readonly LogSuccessfulLogin $logSuccessfulLogin,
        private readonly LogSuccessfulLogout $logSuccessfulLogout,
        private readonly LogFailedLogin $logFailedLogin,
        private readonly LogLockout $logLockout,
    ) {}

    /**
     * Name of the named rate limiter registered in AppServiceProvider and
     * referenced by the `throttle:login` middleware on POST /api/login.
     */
    public const LIMITER_NAME = 'login';

    /**
     * Failed attempts tolerated inside one throttle window.
     */
    public const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Base lockout in minutes; doubles on every consecutive lockout.
     */
    public const BASE_LOCKOUT_MINUTES = 1;

    /**
     * Upper bound for the exponential backoff (minutes).
     */
    public const MAX_LOCKOUT_MINUTES = 60;

    /**
     * How long the escalation counter itself is remembered (minutes).
     * After a quiet period the offender starts from the base lockout again.
     */
    public const LOCKOUT_MEMORY_MINUTES = 180;

    /**
     * Throttle key for a login attempt.
     *
     * Deliberately combines the submitted e-mail AND the client IP:
     *  - IP only would let one bad actor behind a NAT / office gateway lock out
     *    every colleague sharing that address;
     *  - e-mail only would let a distributed attacker cheaply lock a known
     *    account out, and would be trivially resettable from any other IP.
     */
    public static function throttleKey(string $email, ?string $ip): string
    {
        return 'login:'.sha1(mb_strtolower(trim($email)).'|'.((string) $ip));
    }

    /**
     * The cache key the throttle middleware actually counts against.
     *
     * A named limiter does not store attempts under the raw `Limit::by()` key:
     * ThrottleRequests::handleRequestUsingNamedLimiter() rewrites it to
     * `md5($limiterName.$limit->key)`. Reading or clearing the counter from the
     * outside therefore has to go through the exact same derivation - using the
     * raw key would silently operate on a counter nobody ever writes to.
     *
     * (Key hashing is on by default; this application never calls
     * ThrottleRequests::shouldHashKeys(false).)
     */
    public static function limiterKey(string $throttleKey): string
    {
        return md5(static::LIMITER_NAME.$throttleKey);
    }

    /**
     * Cache key holding how many consecutive lockouts this identity produced.
     */
    public static function lockoutCounterKey(string $throttleKey): string
    {
        return $throttleKey.':lockouts';
    }

    /**
     * Current window length in minutes for the given throttle key.
     *
     * 1st window 1 min, then 2, 4, 8, 16, 32, capped at 60.
     */
    public static function lockoutMinutes(string $throttleKey): int
    {
        $lockouts = (int) Cache::get(static::lockoutCounterKey($throttleKey), 0);

        $minutes = static::BASE_LOCKOUT_MINUTES * (2 ** min($lockouts, 16));

        return (int) min($minutes, static::MAX_LOCKOUT_MINUTES);
    }

    /**
     * Authenticate the request and return the signed-in user.
     *
     * @throws ValidationException invalid credentials (422)
     * @throws HttpResponseException deactivated account (403)
     */
    public function login(LoginRequest $request): User
    {
        $credentials = $request->credentials();
        $throttleKey = static::throttleKey($credentials['email'], $request->ip());

        $guard = Auth::guard('web');

        // validate() checks the credentials WITHOUT establishing a session, so a
        // deactivated account is never signed in - not even for a single request.
        if (! $guard->validate($credentials)) {
            // getLastAttempted() is populated by validate() even on failure -
            // it is the user matched by e-mail (null if the e-mail is
            // unknown). Used ONLY to fill session_logs.user_id; see
            // LogFailedLogin's docblock for why that never leaks into the
            // response and cannot be used to enumerate accounts.
            $this->registerFailedAttempt($throttleKey, $credentials['email'], $request, $guard->getLastAttempted());

            throw ValidationException::withMessages([
                // Never reveal whether the e-mail exists: an unknown account and
                // a wrong password produce the exact same message and status,
                // which is what blocks user enumeration.
                'email' => 'E-posta veya şifre hatalı.',
            ]);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if (! $user->is_active) {
            $this->logAuthFailure('deactivated_account', $credentials['email'], $request);
            $this->logFailedLogin->log($user, $credentials['email'], $request);

            throw new HttpResponseException(static::deactivatedResponse());
        }

        $guard->login($user, $request->remember());

        // Session fixation protection: the session id the visitor arrived with
        // must never survive the privilege change. MANDATORY.
        $request->session()->regenerate();

        $this->clearThrottle($throttleKey);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Read AFTER regenerate() on purpose - see LogSuccessfulLogin's
        // docblock: SessionGuard::login() already regenerates the session
        // once internally and fires its own (real) Login event before this
        // line runs, so that automatic event would carry a session id this
        // second, explicit regenerate() immediately invalidates. Building our
        // own Login event object here and handing it straight to the
        // listener guarantees the id we persist is the one that survives.
        $this->logSuccessfulLogin->log(new Login('web', $user, $request->remember()), $request);

        return $user->refresh()->load('roles');
    }

    /**
     * Terminate the current session completely.
     */
    public function logout(Request $request): void
    {
        // Captured BEFORE guard->logout()/session()->invalidate(): the guard
        // nulls its cached user on logout(), and invalidate() rotates the
        // session id, so both would be gone by the time we could otherwise
        // read them. See LogSuccessfulLogout's docblock for why this is a
        // direct call rather than an Event::listen(Logout::class, ...).
        /** @var User|null $user */
        $user = Auth::guard('web')->user();
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->logSuccessfulLogout->log($user, $sessionId, $request);
    }

    /**
     * Closed-loop password reset.
     *
     * Users cannot reset their own password - an administrator does it through
     * POST /api/users/{user}/reset-password. We only record the request and
     * always answer 202, whether or not the address exists, so the endpoint
     * cannot be used to enumerate accounts.
     *
     * NOTE: no table is created here on purpose. The persistent administrator
     * approval queue is Phase 10 work; until then the application log is the
     * record of record.
     */
    public function recordPasswordResetRequest(string $email, Request $request): void
    {
        Log::info('Şifre sıfırlama talebi alındı.', [
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'requested_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Replace the user's password and clear the forced-change flag.
     *
     * The order below is binding (docs/AUTH-FLOWS.md §3.2).
     *
     * $user MUST be the instance the guard hydrated for this request
     * ($request->user()). Sanctum's AuthenticateSession middleware re-stores
     * `password_hash_web` from the guard's user in its after-response phase; if
     * we mutated a different instance the session would be stamped with the
     * OLD hash and the very session that just changed the password would be
     * logged out on its next request.
     */
    public function changePassword(User $user, string $password, Request $request): User
    {
        $wasForced = (bool) $user->must_change_password;

        // Plain text in - the model cast ('password' => 'hashed') hashes it.
        $user->password = $password;
        $user->must_change_password = false;

        // Rotate on EVERY password change: a "remember me" cookie minted while
        // the temporary password was valid must not survive on any device.
        $user->setRememberToken(Str::random(60));

        $user->save();

        /*
         * Faz F1 — DEVICE TOKENS (SYNCDESKTOP §4.3, protokol §3.6).
         *
         * Step 4.5 in docs/AUTH-FLOWS.md §3.2's binding order: after the hash
         * is stored, before the session is regenerated.
         *
         * THE TRAP: the obvious "keep my own token, drop the rest" filter
         * `where('id', '!=', $user->currentAccessToken()?->id)` deletes NOTHING
         * when the change arrives from the SPA. A cookie session's
         * currentAccessToken() is a TransientToken, which has no `id`, so the
         * expression becomes `where('id', '!=', null)` - and in SQL that
         * matches no row at all. The gate would silently do nothing, forever,
         * with no error to notice.
         *
         * So the token TYPE is tested explicitly:
         *   - change made from the SPA  -> every device token is dropped. The
         *     browser is not one of them, and the user is choosing to
         *     re-authenticate their machines.
         *   - change made from a device -> that device keeps working, all the
         *     others are dropped.
         */
        $current = $user->currentAccessToken();

        $tokens = $user->tokens();

        if ($current instanceof PersonalAccessToken) {
            $tokens->whereKeyNot($current->getKey());
        }

        $tokens->delete();

        /*
         * A password change is a privilege boundary - same rule as login.
         *
         * GUARDED IN F1: a desktop client changing its password arrives with a
         * bearer token and NO session at all (it never matches
         * SANCTUM_STATEFUL_DOMAINS - protocol §3.8 requires exactly that), so
         * `session()` would throw "Session store not set on request" and turn a
         * successful password change into a 500 AFTER the hash was already
         * written. Same `hasSession()` guard logout() has carried since Faz 5.
         *
         * Skipping it costs nothing there: session fixation is a cookie
         * problem, and a bearer credential has no session id to fixate. The
         * device's OTHER tokens are already gone a few lines above.
         */
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // NOTE: the user's OTHER SPA sessions need no code here. Sanctum's
        // AuthenticateSession (config/sanctum.php -> middleware.authenticate_session)
        // compares the session's `password_hash_web` against the current hash on
        // every stateful request and throws 401 on a mismatch, so they drop on
        // their next request. Redis sessions are not indexed by user id, so this
        // lazy model is the deliberate design (see App\Events\UserDeactivated).
        //
        // F1 CORRECTION: that argument covers STATEFUL requests only. Bearer
        // tokens never reach AuthenticateSession, so nothing described above
        // would ever invalidate them - which is why the explicit token delete
        // a few lines up is not redundant with this paragraph but the missing
        // half of it.
        Log::info('Şifre değiştirildi.', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'was_forced' => $wasForced,
        ]);

        return $user->refresh()->load('roles');
    }

    /**
     * The single canonical 403 payload for an unchanged temporary password.
     * Used by the EnsurePasswordIsChanged middleware.
     *
     * 403 and not 401/404/422: the caller IS authenticated, the resource does
     * exist and the request is well formed - access is conditionally refused.
     * Same shape as USER_DEACTIVATED: 403 plus a distinguishing `code`.
     */
    public static function passwordChangeRequiredResponse(): JsonResponse
    {
        return response()->json([
            'errors' => [
                'message' => 'Devam etmeden önce geçici şifrenizi değiştirmeniz gerekiyor.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ],
        ], 403);
    }

    /**
     * The single canonical 403 payload for a deactivated account.
     * Used by the login flow and by the EnsureUserIsActive middleware.
     */
    public static function deactivatedResponse(): JsonResponse
    {
        return response()->json([
            'errors' => [
                'message' => 'Hesabınız devre dışı bırakılmış. Lütfen sistem yöneticisi ile iletişime geçin.',
                'code' => 'USER_DEACTIVATED',
            ],
        ], 403);
    }

    /**
     * Record a failed attempt and escalate the lockout window when the
     * allowance for the current window has just been exhausted.
     *
     * The throttle:login middleware already performed the hit() for this
     * request, so attempts() is authoritative at this point.
     */
    protected function registerFailedAttempt(string $throttleKey, string $email, Request $request, ?User $matchedUser = null): void
    {
        $this->logAuthFailure('invalid_credentials', $email, $request);
        $this->logFailedLogin->log($matchedUser, $email, $request);

        if (RateLimiter::attempts(static::limiterKey($throttleKey)) < static::MAX_LOGIN_ATTEMPTS) {
            return;
        }

        $counterKey = static::lockoutCounterKey($throttleKey);
        $lockouts = (int) Cache::get($counterKey, 0) + 1;

        Cache::put($counterKey, $lockouts, now()->addMinutes(static::LOCKOUT_MEMORY_MINUTES));

        Log::warning('Giriş kilitlendi, artan bekleme uygulanıyor.', [
            'email' => $email,
            'ip' => $request->ip(),
            'consecutive_lockouts' => $lockouts,
            'next_window_minutes' => static::lockoutMinutes($throttleKey),
        ]);

        // This branch runs exactly once per lockout - see LogLockout's
        // docblock for why the named rate limiter never dispatches the real
        // Illuminate\Auth\Events\Lockout event and why "attempts() just
        // reached MAX_LOGIN_ATTEMPTS" is the correct, single-shot detection
        // point (every subsequent request for this key is rejected by the
        // throttle:login middleware before it reaches AuthService again).
        $this->logLockout->log($email, $request);
    }

    /**
     * Drop both the attempt counter and the escalation counter.
     */
    protected function clearThrottle(string $throttleKey): void
    {
        RateLimiter::clear(static::limiterKey($throttleKey));
        Cache::forget(static::lockoutCounterKey($throttleKey));
    }

    /**
     * File-based warning log for a failed sign-in attempt.
     *
     * Kept alongside (not replaced by) the session_logs DB row written via
     * logFailedLogin/logLockout below: this is the security team's grep-able
     * safety net that survives even if the DB write itself fails.
     */
    protected function logAuthFailure(string $reason, string $email, Request $request): void
    {
        Log::warning('Başarısız giriş denemesi.', [
            'reason' => $reason,
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'attempted_at' => now()->toIso8601String(),
        ]);
    }
}
