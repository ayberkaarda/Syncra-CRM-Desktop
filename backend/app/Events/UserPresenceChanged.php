<?php

namespace App\Events;

use App\Broadcasting\ChannelRegistry;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Extra presence detail that the Pusher protocol does not carry on its own.
 *
 * `presence-online` already publishes member_added / member_removed frames, so
 * this event is NOT how the SPA learns that someone connected - that would be
 * duplicated (and racy) bookkeeping. What it carries is the part the protocol
 * has no slot for: WHERE the user is and WHAT state they are in.
 *
 *   status   online | away | offline   - idle detection lives in the SPA
 *   location a route name such as "deals.show" plus its record id, so the
 *            roster can show "Ayse - Deal #42" and Phase 7 can warn about two
 *            people dragging the same Kanban card
 *
 * ShouldBroadcast (queued, not ShouldBroadcastNow): presence detail is a
 * cosmetic signal fired on navigation, i.e. frequently. Pushing it onto the
 * Redis queue keeps a slow or down Reverb from ever adding latency to a
 * page-view request. Anything that must arrive even if the queue worker is
 * dead - UserDeactivated is the example - is a different class of event.
 */
class UserPresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'online'|'away'|'offline'  $status
     * @param  array<string, mixed>  $location  e.g. ['route' => 'deals.show', 'record_id' => 42]
     */
    public function __construct(
        public readonly User $user,
        public readonly string $status = 'online',
        public readonly array $location = [],
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('online'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.presence';
    }

    /**
     * Reuses the presence roster payload so a member's shape is identical
     * whether the SPA got it from the subscription snapshot or from this
     * event - one merge path in the client, no field drift.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ChannelRegistry::payload($this->user) + [
            'status' => $this->status,
            'location' => $this->location,
            'at' => now()->toIso8601String(),
        ];
    }
}
