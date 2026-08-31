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
            // The bootstrap window. Capped so a client cannot ask the server to
            // stream its entire history in one request (K8/K12: the retention
            // ceiling is a disk budget, and "Download archive" is the deliberate
            // way to widen it).
            'window_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
