<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\PullRequest;
use App\Models\User;
use App\Services\Sync\SyncPullService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/sync/pull` — the delta reader (SYNCDESKTOP §4.4).
 *
 * POST rather than GET, even though this reads nothing but state: the request
 * carries one cursor PER TABLE (twenty-plus of them), and a query string long
 * enough to hold them is neither cacheable in practice nor reliably within
 * proxy URL limits. The body is the only honest place for it.
 *
 * Thin controller: everything is in SyncPullService.
 */
class PullController extends Controller
{
    public function __construct(private readonly SyncPullService $pull) {}

    public function __invoke(PullRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        return response()->json($this->pull->pull(
            $user,
            array_map('intval', $validated['cursors'] ?? []),
            (int) ($validated['limit'] ?? SyncPullService::DEFAULT_LIMIT),
            isset($validated['window_days']) ? (int) $validated['window_days'] : null,
        ));
    }
}
