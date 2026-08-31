<?php

namespace App\Http\Requests\Sync;

use App\Services\Sync\SyncPullService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/sync/pull` request contract (SYNCDESKTOP §4.4).
 *
 * Authorisation is the route's middleware chain (auth:sanctum, active,
 * password.changed, ability:desktop, device.token) plus SyncScope's per-table
 * permission check, so `authorize()` has nothing left to decide.
 *
 * `cursors.*` is `integer|min:0` rather than free-form: the value goes into a
 * `WHERE sync_version > ?` comparison, and a negative or non-numeric cursor
 * would either scan the whole table or fail at the driver.
 */
class PullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursors' => ['sometimes', 'array'],
            'cursors.*' => ['integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.SyncPullService::MAX_LIMIT],
            /*
             * The bootstrap window, and a BOOTSTRAP HINT rather than a
             * command (RISK-2 #3).
             *
             * `min:0`, not `min:1`. SYNCDESKTOP §4.4 says the window applies
             * only at `cursor = 0` ("delta'da filtre yok"), but this rule used
             * to reject `0` on EVERY request - so a client that kept the field
             * in its delta payload got a 422 for a value the server was going
             * to ignore anyway. `0` and `null` now mean the same thing, "no
             * window", and `SyncPullService::pull()` collapses the former into
             * the latter so there is exactly one code path.
             *
             * The 365 ceiling stays a hard 422: it is a disk budget (K8/K12,
             * "Download archive" is the deliberate way to widen it), and
             * silently clamping an over-wide ask would hide the client bug
             * that produced it.
             */
            'window_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
