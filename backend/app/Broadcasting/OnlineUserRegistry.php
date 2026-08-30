<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pusher\ApiErrorException;
use Throwable;

/**
 * Answers "who is online right now?".
 *
 * ----------------------------------------------------------------------------
 * WHY NOT THE DATABASE
 * ----------------------------------------------------------------------------
 * The obvious implementation - a `users.is_online` column or a `last_seen_at`
 * timestamp - is wrong here for two reasons. It writes to the users table on
 * every heartbeat of every user, and it is a lie the moment a browser tab dies
 * without a clean logout: the flag stays true until something sweeps it. There
 * is no such thing as a durable "online" fact; presence is connection state and
 * it belongs where the connections are.
 *
 * ----------------------------------------------------------------------------
 * SOURCE OF TRUTH: REVERB, CACHED IN REDIS
 * ----------------------------------------------------------------------------
 * Reverb holds the presence-online membership in the process that owns the
 * sockets, and exposes it over the Pusher HTTP API:
 *
 *     GET /apps/{app_id}/channels/presence-online/users  ->  {"users":[{"id":"7"}]}
 *
 * That is authoritative - a member disappears the instant its socket closes,
 * with no sweeper and no stale rows. Two things it is not: cheap (an HTTP
 * round-trip per call) and always available (it dies with the Reverb process).
 * Both are handled by Redis, which is also this app's cache and session store:
 *
 *   - `syncra:online:ids` (5s TTL) absorbs bursts. A dashboard polling every
 *     second, or ten users loading the roster at once, costs Reverb one call.
 *   - `syncra:online:snapshot` (5m TTL) is refreshed on every successful fetch
 *     and read only when Reverb is unreachable. A Reverb restart then degrades
 *     the roster to "slightly stale" instead of "empty", and `stale` in the
 *     response tells the SPA to say so.
 *
 * The users endpoint returns ids only, so names/roles still come from the
 * users table - but that is profile data, not presence. Whether someone is
 * online is never read from, or written to, the database.
 */
final class OnlineUserRegistry
{
    /** Short-lived de-duplication of the Reverb HTTP call. */
    public const IDS_KEY = 'syncra:online:ids';

    /** Last known good roster, read only when Reverb cannot be reached. */
    public const SNAPSHOT_KEY = 'syncra:online:snapshot';

    private const IDS_TTL = 5;

    private const SNAPSHOT_TTL = 300;

    private const PROFILE_TTL = 30;

    public function __construct(private readonly BroadcastFactory $broadcast) {}

    /**
     * @return array{users: array<int, array<string, mixed>>, source: string, stale: bool}
     */
    public function roster(): array
    {
        [$ids, $source, $stale] = $this->memberIds();

        return [
            'users' => $this->profiles($ids),
            'source' => $source,
            'stale' => $stale,
        ];
    }

    /**
     * @return array{0: array<int, int>, 1: string, 2: bool}
     */
    private function memberIds(): array
    {
        try {
            $ids = Cache::remember(self::IDS_KEY, self::IDS_TTL, fn (): array => $this->fetchFromReverb());

            // Only a fetch that actually succeeded may refresh the fallback.
            // (Re-put on a cache hit too: it keeps the snapshot alive for as
            // long as anyone is asking, which is exactly when it is needed.)
            Cache::put(self::SNAPSHOT_KEY, $ids, self::SNAPSHOT_TTL);

            return [$ids, 'reverb', false];
        } catch (Throwable $e) {
            // Reverb being down must never 500 the dashboard.
            Log::warning('Reverb presence API is unreachable; serving the cached roster.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            /** @var array<int, int> $snapshot */
            $snapshot = Cache::get(self::SNAPSHOT_KEY, []);

            return [$snapshot, 'cache', true];
        }
    }

    /**
     * @return array<int, int>
     */
    private function fetchFromReverb(): array
    {
        $broadcaster = $this->broadcast->connection();

        // BROADCAST_CONNECTION=null (tests, or a deployment with realtime
        // switched off) has no HTTP API to ask. Treated as "unreachable" so
        // the snapshot path handles it, rather than pretending nobody is on.
        if (! $broadcaster instanceof PusherBroadcaster) {
            throw new \RuntimeException('The active broadcast connection exposes no presence API.');
        }

        $pusher = $broadcaster->getPusher();

        try {
            /** @var object{users?: array<int, object{id?: string}>} $response */
            $response = $pusher->get('/channels/presence-online/users');
        } catch (ApiErrorException $e) {
            // Reverb answers the members endpoint with an error - not an empty
            // list - when the channel has no subscribers at all, because an
            // unoccupied channel does not exist as far as the API is
            // concerned. Swallowing that here would be wrong (a genuine API
            // failure looks identical), so ask the channel endpoint, which
            // does answer for an unoccupied channel, and only then conclude
            // "nobody is online".
            //
            // Without this, an empty office would be reported as `stale`
            // forever and the roster would keep serving whoever happened to be
            // in the last snapshot.
            /** @var object{occupied?: bool} $channel */
            $channel = $pusher->get('/channels/presence-online');

            if (($channel->occupied ?? false) === false) {
                return [];
            }

            throw $e;
        }

        return collect($response->users ?? [])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Hydrate ids into roster entries.
     *
     * `->active()` is applied on purpose: an account deactivated while its tab
     * was open may still hold a socket for a few seconds, and it must not be
     * listed as an online colleague in the meantime.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function profiles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        sort($ids);

        return Cache::remember(
            'syncra:online:profiles:'.md5(implode(',', $ids)),
            self::PROFILE_TTL,
            static fn (): array => User::query()
                ->active()
                ->whereKey($ids)
                ->with('roles:id,name')
                ->orderBy('name')
                ->get()
                ->map(static fn (User $user): array => ChannelRegistry::payload($user))
                ->all(),
        );
    }
}
