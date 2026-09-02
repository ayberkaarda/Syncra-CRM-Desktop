<?php

namespace App\Observers;

use App\Sync\SyncableRegistry;
use App\Sync\SyncCounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Writes hard-delete tombstones into `sync_deletions` (SYNCDESKTOP §4.2,
 * protocol §2.7).
 *
 * Registered on exactly THREE models: `Tag`, `PriceListItem` (KARAR P19) and
 * Laravel's DatabaseNotification. `conversation_user`, the fourth tombstone
 * table, is covered by an AFTER DELETE trigger because `detach()` never
 * reaches PHP.
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
            'owner_key' => $this->ownerKeyFor($model),
            'sync_version' => SyncCounter::next(),
            'deleted_at' => now(),
        ]);
    }

    /**
     * The owner this tombstone belongs to, or null when the table has no
     * per-user owner (RISK-2 O3 / TM-F2).
     *
     * Written HERE, at delete time, because it cannot be recovered later: the
     * row is gone the moment the DELETE commits, which is exactly why the leak
     * existed. `SyncableRegistry::ownerScopedTombstoneTables()` carries the
     * table-by-table reasoning for why `notifications` is the only entry.
     *
     * The value mirrors the pair `SyncScope::applyRowScope()` filters the
     * `notifications` ROWS on - `notifiable_type` AND `notifiable_id` - so a
     * tombstone is visible to precisely the callers who could have seen the
     * row it replaces. Matching on the id alone would be one polymorphic
     * collision (a non-User notifiable sharing a numeric id) away from
     * handing a user somebody else's uuid again.
     */
    private function ownerKeyFor(Model $model): ?string
    {
        if (! in_array($model->getTable(), SyncableRegistry::ownerScopedTombstoneTables(), true)) {
            return null;
        }

        $type = $model->getAttribute('notifiable_type');
        $id = $model->getAttribute('notifiable_id');

        // Defensive: a notification with no notifiable is not something this
        // schema can produce (both columns are NOT NULL), but a null owner_key
        // is delivered to NOBODY rather than to everybody, so the fallback
        // fails closed.
        return $type === null || $id === null ? null : $type.':'.$id;
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
