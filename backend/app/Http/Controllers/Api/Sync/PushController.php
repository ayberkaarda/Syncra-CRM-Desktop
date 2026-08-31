<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\PushRequest;
use App\Models\User;
use App\Services\Sync\SyncPushService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/sync/push` — replays the client's offline outbox
 * (SYNCDESKTOP §4.4).
 *
 * ALWAYS 200, even when every mutation was rejected. The HTTP status describes
 * the TRANSPORT; the outcome of each business decision lives in its own
 * `results[]` entry. A batch-level error status would force the client to
 * discard results that were already committed and would make the partial
 * response of protocol §4.3/P10b - where a truncated batch is a normal,
 * expected outcome - impossible to express.
 *
 * The two exceptions are shape failures raised before any work starts
 * (PushRequest): a malformed batch and PUSH_BATCH_TOO_LARGE, both 422.
 */
class PushController extends Controller
{
    public function __construct(private readonly SyncPushService $push) {}

    public function __invoke(PushRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        return response()->json($this->push->push(
            $user,
            (string) $validated['batch_id'],
            $validated['mutations'],
        ));
    }
}
