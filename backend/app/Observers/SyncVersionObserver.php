<?php

namespace App\Observers;

use App\Sync\SyncCounter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Stamps `sync_version` on every Eloquent write in the sync scope
 * (SYNCDESKTOP §4.2, protocol §2.2).
 *
 * Registered on the ~20 tables whose write surface is genuinely Eloquent.
 * `conversation_user` is NOT one of them - its writes are raw SQL and pivot
 * calls, so it uses database triggers instead, and the two mechanisms are
 * never combined on one table (see the trigger migration).
 *
 * Assigning in PHP rather than in the database is what lets the push response
 * return `sync_version` without a follow-up SELECT.
 */
class SyncVersionObserver
{
    /**
     * ------------------------------------------------------------------
     * WHY THE DIRTY CHECK IS NOT OPTIONAL
     * ------------------------------------------------------------------
     * Eloquent skips the UPDATE entirely when a saved model has no dirty
     * attributes. An observer that assigns unconditionally would MAKE every
     * model dirty and turn each of those no-op saves into a real write:
     * a burned counter value and a phantom delta on every client. Probe T7
     * measured the same failure on the trigger side, where protocol §2.4/P4b
     * answers it with a `<=>` guard; this is the observer-side equivalent.
     *
     * The check is safe at `saving` time: Eloquent touches `updated_at` inside
     * performUpdate(), AFTER this event, so `getDirty()` here reflects real
     * caller changes only.
     */
    public function saving(Model $model): void
    {
        if ($model->exists && $model->getDirty() === []) {
            return;
        }

        $model->setAttribute('sync_version', SyncCounter::next());
    }

    /**
     * ------------------------------------------------------------------
     * SOFT DELETE DOES NOT GO THROUGH `saving`
     * ------------------------------------------------------------------
     * SoftDeletes::runSoftDelete() builds its own column list
     * (`deleted_at` + `updated_at`) and pushes it through the query builder -
     * no `saving` event, and anything assigned to the model here would not be
     * part of that statement. So the version is written directly, BEFORE the
     * soft delete runs; the subsequent statement only touches its own two
     * columns and leaves ours in place.
     *
     * Without this the tombstone would be invisible: the row would carry
     * `deleted_at` but keep its old version and never cross a client's cursor.
     *
     * A force delete (or a hard-delete model) needs nothing here - the row is
     * about to be gone. Its tombstone, where one is owed, is
     * SyncDeletionObserver's job.
     */
    public function deleting(Model $model): void
    {
        if (! $this->usesSoftDeletes($model)) {
            return;
        }

        /** @phpstan-ignore-next-line SoftDeletes is confirmed above. */
        if ($model->isForceDeleting()) {
            return;
        }

        $version = SyncCounter::next();

        DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->update(['sync_version' => $version]);

        $model->setAttribute('sync_version', $version);
        $model->syncOriginalAttribute('sync_version');
    }

    private function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model::class), true);
    }
}
