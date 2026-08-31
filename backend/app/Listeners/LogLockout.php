<?php

namespace App\Listeners;

use App\Models\SessionLog;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writes ONE `session_logs` row the moment a login identity gets locked out.
 *
 * NOT wired through Event::listen(Lockout::class, ...):
 * Illuminate\Auth\Events\Lockout is only ever dispatched by the
 * Illuminate\Foundation\Auth\ThrottlesLogins trait, which this application
 * does not use - login throttling here is the NAMED rate limiter registered
 * in AppServiceProvider::registerLoginRateLimiter() (`RateLimiter::for('login',
 * ...)`, applied via the `throttle:login` middleware). That code path never
 * dispatches Lockout, so there is no event to listen for; it has to be
 * called directly from AuthService.
 *
 * WHERE "the lockout starts" is detected: AuthService::registerFailedAttempt()
 * already re-reads RateLimiter::attempts() after the throttle:login
 * middleware has recorded this request's hit. The FIRST failed attempt whose
 * count reaches MAX_LOGIN_ATTEMPTS is, by construction, the exact request
 * that trips the limiter - the very next request for the same key gets
 * rejected by the `throttle:login` middleware before it ever reaches the
 * controller/AuthService again. So calling this from inside that
 * "attempts() just reached the max" branch fires exactly once per lockout,
 * not once per subsequent 429.
 */
class LogLockout
{
    public function __construct(private readonly UserAgentParser $parser) {}

    /**
     * `$channel` (F1): 'web' for the SPA, 'desktop' for
     * POST /api/auth/device. Defaulted rather than required so every
     * existing caller keeps its exact meaning, and so the column's own
     * DEFAULT 'web' and this signature cannot drift apart.
     */
    public function log(string $email, Request $request, string $channel = 'web'): void
    {
        try {
            $userAgent = (string) $request->userAgent();
            $parsed = $this->parser->parse($userAgent);

            $this->persist([
                'email' => $email,
                'event' => 'locked_out',
                'channel' => $channel,
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Kilitlenme logu (session_logs) yazılamadı.', [
                'email' => $email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Isolated seam - see LogSuccessfulLogin::persist(). */
    protected function persist(array $attributes): void
    {
        SessionLog::create($attributes);
    }
}
