<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ============================================================================
 * SESSION REVOKE - full mechanism
 * ============================================================================
 *
 * When an administrator deactivates a user, three layers cooperate:
 *
 * 1. App\Http\Middleware\EnsureUserIsActive  (SYNCHRONOUS, the real security
 *    boundary). Every authenticated request re-reads `is_active` from the
 *    already-hydrated user model; a deactivated account is logged out, its
 *    session invalidated, and the request answered 403 USER_DEACTIVATED.
 *    Nothing can slip past this - it does not depend on a queue, a websocket
 *    server, or the browser being online.
 *
 * 2. This broadcast (ASYNCHRONOUS, user experience only). It pushes the news to
 *    `private-user.{id}` so an already-open tab is dropped to the login screen
 *    immediately instead of at its next request. If Reverb is down the user is
 *    still fully locked out by layer 1 - they simply find out a moment later.
 *
 * 3. Remember-token reset. The "remember me" cookie authenticates without a
 *    session, so `setRememberToken(Str::random(60))` must run when an account
 *    is deactivated, otherwise the cookie could mint a fresh session later.
 *    That reset belongs to UserController@toggleActive (parallel lane C),
 *    which is also the dispatcher of this event.
 *
 * Why not just delete the user's sessions? SESSION_DRIVER=redis stores each
 * session under a key derived from the session id with no user_id index, so
 * there is no way to enumerate "all sessions of user X" without scanning the
 * whole keyspace. Hence layer 1 is the mechanism rather than a safety net.
 *
 * ---------------------------------------------------------------------------
 * PHASE NOTE: Reverb is not installed yet (Phase 4). ShouldBroadcast (queued)
 * is used on purpose so dispatching only pushes a job onto the Redis queue and
 * can never fail the deactivation request itself. Channel authorization
 * (routes/channels.php) and the real fan-out are completed in Phase 4.
 * ---------------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------------
 * PHASE 14 / TRACK D: WHY `$message` DEFAULTS TO NULL, RESOLVED IN broadcastWith()
 * ---------------------------------------------------------------------------
 * The queued broadcast job (`Illuminate\Broadcasting\BroadcastEvent`) calls
 * `broadcastWith()` on the QUEUE WORKER, long after the HTTP request (and its
 * `SetLocale`-resolved app locale) is gone — resolving the sentence at
 * construction time (like `DealVersionConflictException`, which is consumed
 * synchronously inside the same request) would freeze it in whatever locale
 * happens to be active on the worker, not the deactivated user's own
 * preference. A PHP constructor default also cannot call `__()` (not a
 * constant expression), so the caller (`UserService::deactivate()`, which
 * only ever passes `$userId`) keeps working unchanged. The recipient's own
 * `locale` is looked up at broadcast time instead — the same "resolve for the
 * actual reader, not the sender" rule `NotificationText` applies to persisted
 * notifications, applied here to an ephemeral (never stored) broadcast frame.
 */
class UserDeactivated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    /**
     * Stable event name for the SPA listener.
     */
    public function broadcastAs(): string
    {
        return 'user.deactivated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'message' => $this->message ?? $this->resolveMessage(),
        ];
    }

    /**
     * Deactivated user's own `locale` column, not `app()->getLocale()` — the queue
     * worker has no request-scoped locale (see class docblock).
     */
    private function resolveMessage(): string
    {
        $locale = User::query()->whereKey($this->userId)->value('locale');

        return __('errors.user_deactivated.message', [], is_string($locale) && $locale !== '' ? $locale : null);
    }
}
