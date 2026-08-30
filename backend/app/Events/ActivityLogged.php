<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One new row in `activity_log`, pushed to the Logs page as it happens.
 *
 * Dispatched from App\Observers\ActivityLogObserver::created(), which is the
 * only place an audit row can come into existence - model events, hand-written
 * `activity()->log()` calls and anything a future module adds all funnel
 * through the same Eloquent `created` event.
 *
 * ---------------------------------------------------------------------------
 * WHY ShouldBroadcast AND NOT ShouldBroadcastNow
 * ---------------------------------------------------------------------------
 * Audit writing sits inside the request that made the change: saving a deal
 * already costs an UPDATE plus an INSERT into `activity_log`. Broadcasting
 * synchronously would add an HTTP round-trip to Reverb on top of that, on the
 * user's critical path, for a feature nobody in that request is waiting on -
 * and would make a slow or unreachable Reverb slow down (or fail) ordinary
 * CRUD. Queued (QUEUE_CONNECTION=redis) the dispatch is an LPUSH and the fan
 * out happens in the worker.
 *
 * The trade-off is the right way round: UserDeactivated must arrive even if
 * the queue is behind, because it is a security-adjacent UX guarantee. A log
 * row arriving 200 ms late costs nothing - and if the worker is down entirely,
 * the row is still in the database and the page shows it on the next fetch.
 *
 * ---------------------------------------------------------------------------
 * WHY A PLAIN ARRAY AND NOT THE Activity MODEL
 * ---------------------------------------------------------------------------
 * SerializesModels would store a class + id and re-query the row in the
 * worker. Two problems: the observer fires inside whatever transaction the
 * caller opened (seeders and multi-step services wrap their work), so the
 * worker can pick the job up before the row is committed and find nothing;
 * and re-hydrating means re-resolving subject and causer in a context with no
 * authenticated user. The payload is therefore computed once, at the moment
 * the row is written, and travels as scalars.
 */
class ActivityLogged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  see ActivityFormatter::broadcastPayload()
     */
    public function __construct(public readonly array $payload) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // `private-logs` - authorized in routes/channels.php against the
        // `logs.view` permission. An audit trail names who did what; it is not
        // a channel every logged-in user may sit on.
        return [
            new PrivateChannel('logs'),
        ];
    }

    /**
     * Short, stable event name for the SPA listener.
     *
     * Broadcasting the FQCN ("App\Events\ActivityLogged") would make the
     * frontend depend on a backend namespace: moving or renaming the class
     * silently stops every listener. Same contract as
     * UserDeactivated::broadcastAs() = 'user.deactivated'.
     */
    public function broadcastAs(): string
    {
        return 'activity.logged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
