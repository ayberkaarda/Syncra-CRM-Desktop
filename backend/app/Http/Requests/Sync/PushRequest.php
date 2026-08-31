<?php

namespace App\Http\Requests\Sync;

use App\Services\Sync\SyncPushService;
use App\Sync\SyncableRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * `POST /api/sync/push` request contract (SYNCDESKTOP §4.4).
 *
 * Shape only. Whether a mutation is ALLOWED - policies, status machines,
 * ownership, the action whitelist - is decided per mutation by
 * MutationApplier, because those answers differ per row and the batch must not
 * fail as a whole for one bad entry.
 *
 * The two batch-level limits are enforced BEFORE any of that, because they are
 * about the request itself: 200 mutations and 2 MB. They fail the whole call
 * with PUSH_BATCH_TOO_LARGE, which is the honest answer - a client that
 * over-fills a batch has a bug in its batching, and answering per mutation
 * would hide it.
 */
class PushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Byte ceiling first: rejecting an oversized body before the validator
     * walks a 200-entry array keeps the cheapest check the earliest one.
     */
    protected function prepareForValidation(): void
    {
        if (strlen((string) $this->getContent()) > SyncPushService::MAX_BYTES) {
            $this->failBatchTooLarge();
        }

        if (is_array($this->input('mutations')) && count($this->input('mutations')) > SyncPushService::MAX_BATCH) {
            $this->failBatchTooLarge();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'string', 'uuid'],
            'mutations' => ['required', 'array', 'min:1', 'max:'.SyncPushService::MAX_BATCH],

            'mutations.*.seq' => ['required', 'integer', 'min:0'],
            // The replay key. `uuid` rather than a free string because the
            // ledger's primary key is CHAR(36) - a longer value would be
            // silently truncated and could then collide with another mutation.
            'mutations.*.idempotency_key' => ['required', 'string', 'uuid'],
            'mutations.*.op' => ['required', Rule::in(['create', 'update', 'delete', 'action'])],
            'mutations.*.entity' => ['required', Rule::in(SyncableRegistry::pushEntities())],
            /*
             * `client_id` and `server_id` are BOTH optional here, and neither
             * is redundant.
             *
             * A mutation may address its target by either one: `server_id`
             * when the row is already known to the server, `client_id` when it
             * is not - a task created and completed in the same offline
             * session arrives as create + action in ONE batch, and the second
             * mutation cannot name an id that does not exist yet
             * (SYNCDESKTOP §5.4 orders actions after their entity's create).
             * MutationApplier requires one of the two per op and answers
             * UNRESOLVED_REFERENCE when a client_id cannot be resolved.
             *
             * Enforcing "exactly one of" here was rejected: the requirement
             * differs per op (`notification.read_all` carries NEITHER - it is
             * user-scoped, protocol §4.3/P10), and a batch-level 422 would
             * reject 199 good mutations because of one malformed entry.
             */
            'mutations.*.client_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'mutations.*.server_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'mutations.*.base_sync_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'mutations.*.action' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Present on exactly one action - `notification.read_all`, the only
            // user-scoped mutation in the format (protocol §4.3/P10).
            'mutations.*.scope' => ['sometimes', 'nullable', Rule::in(['user'])],
            /*
             * `occurred_at` and `payload` are optional because `op=delete`
             * carries NEITHER (SYNCDESKTOP §4.4's own example:
             * seq/idempotency_key/op/entity/server_id/base_sync_version and
             * nothing else). `occurred_at` exists solely so ConflictDetector
             * can compare it against `activity_log.created_at`, which is an
             * `op=update` question; a delete's conflict decision is the
             * `base_sync_version` comparison alone. Requiring them would 422
             * every delete the client sends.
             */
            'mutations.*.occurred_at' => ['sometimes', 'nullable', 'date'],
            'mutations.*.changed_fields' => ['sometimes', 'array'],
            'mutations.*.changed_fields.*' => ['string', 'max:64'],
            'mutations.*.payload' => ['sometimes', 'array'],
        ];
    }

    private function failBatchTooLarge(): never
    {
        throw new HttpResponseException(response()->json([
            'errors' => [
                'message' => __('errors.sync.push_batch_too_large'),
                'code' => 'PUSH_BATCH_TOO_LARGE',
            ],
        ], 422));
    }
}
