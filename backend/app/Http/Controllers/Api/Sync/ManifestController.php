<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Sync\SyncPullService;
use App\Services\Sync\SyncPushService;
use App\Services\Sync\SyncScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/sync/manifest` — what this client may sync, and under what limits
 * (SYNCDESKTOP §4.4, protocol §4.1).
 *
 * The client calls this before every sync round (cached ~10 minutes) and it
 * answers three questions:
 *
 *  1. `protocol_version` - do we still speak the same language? A mismatch
 *     stops synchronisation entirely rather than degrading it. A client one
 *     version behind does not know which of its assumptions broke, so
 *     "continue carefully" is not an option; it asks the user to update.
 *
 *  2. `tables` - the permission surface, resolved at THIS moment. Permissions
 *     change while a laptop is in a bag; a table that disappears from this map
 *     is one the client must stop pulling and stop showing.
 *
 *  3. `policy` - the server's batch/page ceilings, so the client sizes its
 *     requests from the server's answer instead of a hard-coded constant that
 *     drifts from the server's.
 *
 * An unpermitted table has NO KEY here (SyncScope), not an empty entry.
 */
class ManifestController extends Controller
{
    public function __construct(private readonly SyncScope $scope) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tables = [];

        foreach ($this->scope->tablesFor($user) as $table => $definition) {
            $tables[$table] = ['mode' => $definition['mode']];
        }

        return response()->json([
            'protocol_version' => SyncPullService::PROTOCOL_VERSION,
            'server_time' => now()->toIso8601String(),
            'tables' => $tables,
            // The SAME list the SPA's can() helper is built on - one source of
            // truth for "what may this user do", so an offline UI cannot be
            // more permissive than the online one.
            'permissions' => UserResource::effectivePermissions($user),
            'user' => UserResource::make($user->loadMissing('roles'))->resolve($request),
            'policy' => [
                'retention_days_max' => 365,
                'push_batch_max' => SyncPushService::MAX_BATCH,
                'push_bytes_max' => SyncPushService::MAX_BYTES,
                'pull_limit_max' => SyncPullService::MAX_LIMIT,
            ],
        ]);
    }
}
