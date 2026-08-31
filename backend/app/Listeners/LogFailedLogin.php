<?php

namespace App\Listeners;

use App\Models\SessionLog;
use App\Models\User;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writes a `session_logs` row for a rejected login attempt (wrong password,
 * unknown e-mail, or a deactivated account).
 *
 * NOT wired through Event::listen(Failed::class, ...): AuthService uses
 * Auth::guard('web')->validate($credentials) rather than attempt()/
 * attemptWhen(), and SessionGuard::validate() never calls
 * fireFailedEvent() - only attempt()/attemptWhen()/once() do (see
 * vendor/laravel/framework .../Auth/SessionGuard.php). The Failed event
 * simply never fires on this code path, so there is nothing to listen for;
 * this has to be called directly from AuthService at the point the
 * credential check fails.
 */
class LogFailedLogin
{
    public function __construct(private readonly UserAgentParser $parser) {}

    /**
     * @param  User|null  $matchedUser  the user matched by e-mail, if any -
     *                                  Auth::guard('web')->getLastAttempted()
     *                                  is populated even on a failed
     *                                  validate() call, so this is available
     *                                  whether the attempt failed on a bad
     *                                  password OR an unknown e-mail.
     *
     * SECURITY: $matchedUser is used ONLY to fill the internal `user_id`
     * column. It never reaches the HTTP response - the 422 message AuthService
     * throws is identical for "unknown e-mail" and "wrong password" (see
     * AuthService::login()), which is what prevents user enumeration. Do not
     * add anything here that leaks $matchedUser back to the caller.
     *
     * `$channel` (F1): 'web' for the SPA, 'desktop' for
     * POST /api/auth/device. Defaulted rather than required so every
     * existing caller keeps its exact meaning, and so the column's own
     * DEFAULT 'web' and this signature cannot drift apart.
     */
    public function log(?User $matchedUser, string $attemptedEmail, Request $request, string $channel = 'web'): void
    {
        try {
            $userAgent = (string) $request->userAgent();
            $parsed = $this->parser->parse($userAgent);

            $this->persist([
                'user_id' => $matchedUser?->getAuthIdentifier(),
                'email' => $attemptedEmail,
                'event' => 'failed_login',
                'channel' => $channel,
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Başarısız giriş logu (session_logs) yazılamadı.', [
                'email' => $attemptedEmail,
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
