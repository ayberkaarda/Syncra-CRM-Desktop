<?php

namespace App\Listeners;

use App\Models\SessionLog;
use App\Models\User;
use App\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Closes the `session_logs` row opened at login, or - if that row can no
 * longer be found - records a standalone `logout` row.
 *
 * NOT wired through Event::listen(Logout::class, ...). Illuminate\Auth\
 * Events\Logout actually fires at a *safe* point (before session()->
 * invalidate()), so an automatic listener would technically read a valid
 * session id. It is still invoked directly from AuthService::logout() for
 * two reasons: (1) symmetry with the other three session-log writers, which
 * all have to be explicit for their own reasons (see LogSuccessfulLogin,
 * LogFailedLogin, LogLockout), so there is one consistent wiring pattern
 * instead of a partially-automatic, partially-manual mix; (2) it lets
 * AuthService capture the session id itself, at the exact line the task
 * requires (immediately before session()->invalidate()), rather than
 * trusting that a future refactor of the event dispatch order in
 * SessionGuard keeps working by accident.
 */
class LogSuccessfulLogout
{
    public function __construct(private readonly UserAgentParser $parser) {}

    /**
     * @param  User|null  $user  the user that WAS authenticated, captured by
     *                           AuthService before Auth::guard('web')->logout()
     * @param  string|null  $sessionId  read before session()->invalidate()
     */
    public function log(?User $user, ?string $sessionId, Request $request): void
    {
        try {
            $loggedOutAt = now();

            if ($sessionId !== null) {
                $openLogin = SessionLog::query()
                    ->where('session_id', $sessionId)
                    ->where('event', 'login')
                    ->whereNull('logged_out_at')
                    ->latest('id')
                    ->first();

                if ($openLogin !== null) {
                    $duration = $openLogin->logged_in_at !== null
                        ? max(0, $openLogin->logged_in_at->diffInSeconds($loggedOutAt))
                        : null;

                    $this->update($openLogin, [
                        'logged_out_at' => $loggedOutAt,
                        'duration_seconds' => $duration,
                    ]);

                    return;
                }
            }

            // No matching `login` row (session id missing/expired/lost) -
            // do not swallow the event, open a standalone `logout` row
            // instead. duration_seconds is left null on purpose: we have no
            // reliable logged_in_at to compute it from.
            $userAgent = (string) $request->userAgent();
            $parsed = $this->parser->parse($userAgent);

            $this->persist([
                'user_id' => $user?->getAuthIdentifier(),
                'email' => $user?->email,
                'event' => 'logout',
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
                'session_id' => $sessionId,
                'logged_out_at' => $loggedOutAt,
                'duration_seconds' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Çıkış logu (session_logs) yazılamadı/güncellenemedi.', [
                'user_id' => $user?->getAuthIdentifier(),
                'session_id' => $sessionId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Isolated seam - see LogSuccessfulLogin::persist(). */
    protected function persist(array $attributes): void
    {
        SessionLog::create($attributes);
    }

    /** Isolated seam - see LogSuccessfulLogin::persist(). */
    protected function update(SessionLog $log, array $attributes): void
    {
        $log->forceFill($attributes)->save();
    }
}
