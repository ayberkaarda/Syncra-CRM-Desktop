<?php

namespace App\Observers;

use App\Sync\SyncCounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Writes hard-delete tombstones into `sync_deletions` (SYNCDESKTOP §4.2,
 * protocol §2.7).
 *
 * Registered on exactly TWO models: `Tag` and Laravel's DatabaseNotification.
 * `conversation_user`, the third tombstone table, is covered by an AFTER
 * DELETE trigger because `detach()` never reaches PHP.
 *
 * Everything else in the sync scope either soft-deletes - the row itself comes
 * back through the delta carrying `deleted_at` plus a fresh version, which is
 * a strictly richer tombstone - or is embedded in an owner payload
 * (`taggables`, `quote_items`, `custom_field_values`), where a shrinking
 * `tags`/`items`/`custom_fields` array IS the deletion signal.
 *
 * ------------------------------------------------------------------------
 * `deleting`, NOT `deleted`
 * ------------------------------------------------------------------------
 * `deleting` runs inside the caller's transaction. If the DELETE is rolled
 * back, the tombstone rolls back with it. On `deleted` the tombstone could
 * survive a rolled-back delete and every client would drop a row that still
 * exists on the server - unrecoverable without a full re-bootstrap.
 *
 * `Tag` does not use SoftDeletes today, so the guard below is defensive: if a
 * soft delete is ever added, `deleting` starts firing for it too, and writing
 * a tombstone THEN would be wrong twice over (the row still exists, and the
 * delta already reports it through `deleted_at`).
 */
class SyncDeletionObserver
{
    public function deleting(Model $model): void
    {
        if ($this->isSoftDelete($model)) {
            return;
        }

        DB::table('sync_deletions')->insert([
            'table_name' => $model->getTable(),
            // String, not int: notifications are UUID-keyed.
            'row_key' => (string) $model->getKey(),
            'sync_version' => SyncCounter::next(),
            'deleted_at' => now(),
        ]);
    }

    private function isSoftDelete(Model $model): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model::class), true)) {
            return false;
        }

        /** @phpstan-ignore-next-line SoftDeletes is confirmed above. */
        return ! $model->isForceDeleting();
    }
}
