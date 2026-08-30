<?php

namespace App\Listeners;

use App\Models\SessionLog;
use App\Support\UserAgentParser;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writes the durable `session_logs` row for a successful login.
 *
 * NOT wired through Event::listen(Login::class, ...) - see
 * App\Services\Auth\AuthService::login() for why this is called directly,
 * after $request->session()->regenerate(). In short: SessionGuard::login()
 * already regenerates the session internally (SessionGuard::updateSession())
 * and fires the real Illuminate\Auth\Events\Login event BEFORE AuthService's
 * own, second, explicit regenerate() call. An automatic listener on that
 * event would therefore capture a session id that AuthService immediately
 * invalidates by regenerating again, so the value it wrote to session_logs
 * would already be wrong for the row that never gets a matching `logout`.
 *
 * Still built as a listener with `log(Login $event, ...)` for shape/reuse -
 * it is just invoked as a plain method call instead of through the event
 * dispatcher.
 *
 * IMPORTANT: the method is deliberately named `log()`, not `handle()`.
 * Laravel's automatic event discovery (config('app.discover_events'), on by
 * default) scans every class under app/Listeners and auto-subscribes any
 * public `handle*`/`__invoke` method to the event type-hinted as its first
 * parameter - regardless of whether Event::listen() was ever called. A
 * `handle(Login $event, Request $request)` method here would have been
 * auto-wired to the REAL Login event Illuminate fires internally (with the
 * wrong-timing session id described above) and then crashed outright
 * (ArgumentCountError: the auto dispatcher only ever passes the event, never
 * the extra $request argument). Naming it `log()` opts this class out of
 * discovery entirely, which is exactly what direct invocation requires.
 */
class LogSuccessfulLogin
{
    public function __construct(private readonly UserAgentParser $parser) {}

    public function log(Login $event, Request $request): void
    {
        try {
            $userAgent = (string) $request->userAgent();
            $parsed = $this->parser->parse($userAgent);

            $this->persist([
                'user_id' => $event->user->getAuthIdentifier(),
                'email' => $event->user->email ?? null,
                'event' => 'login',
                'ip_address' => $request->ip(),
                'user_agent' => $userAgent,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
                // MUST be read after regenerate() by the caller - see class
                // docblock. This is the id the matching `logout` row keys on.
                'session_id' => $request->session()->getId(),
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A failed session_logs write must never fail the login itself -
            // the DB row is an audit convenience, not part of the auth
            // contract. The file-based warning AuthService already logs
            // elsewhere is the security-relevant record; this is a secondary
            // net, so we degrade to Log::error and let the request proceed.
            Log::error('Oturum açma logu (session_logs) yazılamadı.', [
                'user_id' => $event->user->getAuthIdentifier(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Isolated seam so tests can force a write failure (e.g. by binding a
     * subclass that overrides this method) without touching the real DB.
     */
    protected function persist(array $attributes): void
    {
        SessionLog::create($attributes);
    }
}
