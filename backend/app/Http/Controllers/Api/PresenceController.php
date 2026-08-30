<?php

namespace App\Http\Controllers\Api;

use App\Broadcasting\OnlineUserRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Read side of the presence system.
 *
 * The live roster reaches the SPA over the `presence-online` websocket channel
 * (member_added / member_removed). This endpoint exists for the cases where a
 * socket is not the right tool: the first paint before Echo has connected, a
 * client whose connection dropped, and any server-rendered or polled view.
 *
 * It is intentionally NOT a second source of truth - see OnlineUserRegistry.
 */
class PresenceController extends Controller
{
    public function __construct(private readonly OnlineUserRegistry $registry) {}

    /**
     * GET /api/presence/online
     *
     * No permission check beyond the route middleware: knowing which
     * colleagues are connected is the same information every authenticated
     * user already receives by subscribing to `presence-online`, so gating it
     * here would only make the two disagree. The payload is the presence
     * payload, nothing more.
     */
    public function online(): JsonResponse
    {
        $roster = $this->registry->roster();

        return response()->json([
            'data' => $roster['users'],
            'meta' => [
                'count' => count($roster['users']),
                // `reverb` = live membership; `cache` = last known roster
                // because the websocket server could not be reached. `stale`
                // lets the SPA badge the list instead of showing it as fact.
                'source' => $roster['source'],
                'stale' => $roster['stale'],
            ],
        ]);
    }
}
