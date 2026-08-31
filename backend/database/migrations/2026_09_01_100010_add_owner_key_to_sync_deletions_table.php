<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — owner scope for hard-delete tombstones (RISK-2 O3 / TM-F2).
 *
 * THE HOLE THIS CLOSES
 * --------------------
 * `sync_deletions` carried no owner column, and by the time a tombstone is
 * read the row it describes is GONE - so the owner could not be resolved
 * retroactively either. `SyncPullService::deletionsFor()` therefore had a
 * scope for exactly one table (`conversation_user`, whose `row_key` happens
 * to embed the pair `conversation_id:user_id`) and none for `notifications`:
 * every device received every user's deleted notification uuids. No content
 * and no attribution travelled with them, only EXISTENCE - but existence of
 * another user's notification is still that user's data, and now that the
 * desktop client genuinely syncs, it is demonstrable on a live system rather
 * than theoretical. F6 is blocked on it.
 *
 * WHY A COLUMN AND NOT A JOIN: a tombstone outlives its row by design (that
 * is the entire point), so `sync_deletions` is the only place the owner can
 * still be recorded. It has to be written at DELETE time, inside the same
 * transaction, by `SyncDeletionObserver`.
 *
 * NULLABLE, AND WHY THE NULLS ARE NOT A LOOPHOLE
 * ---------------------------------------------
 * Three of the four tombstone tables have no per-user owner at all and keep
 * `owner_key = NULL` forever (see `SyncableRegistry::ownerScopedTombstoneTables()`
 * for the full reasoning): `tags` is org-wide vocabulary, `price_list_items`
 * is gated wholesale by `products.view`, and `conversation_user` is scoped by
 * its own `row_key`. Only `notifications` writes an owner, and
 * `deletionsFor()` requires a MATCH there rather than treating NULL as
 * "visible to all" - so tombstones written before this migration are
 * delivered to nobody instead of to everybody. The cost is a client that
 * keeps one stale notification in its mirror until the next re-bootstrap; the
 * alternative is keeping the leak open for exactly the rows the fix exists
 * for.
 *
 * VARCHAR(64), not a bigint FK: the value is `notifiable_type:notifiable_id`,
 * mirroring the two columns `SyncScope::applyRowScope()` filters the
 * `notifications` ROWS on, so the tombstone is visible to precisely the
 * callers who could have seen the row. A raw id would be one polymorphic
 * collision away from being wrong, and an FK would forbid the NULLs above.
 *
 * Reversible: `down()` drops the index and the column, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_deletions', function (Blueprint $table) {
            $table->string('owner_key', 64)->nullable()->after('row_key');

            // The scoped pull query is
            // `WHERE table_name = ? AND owner_key = ? AND sync_version > ?
            //  ORDER BY sync_version` - exactly this composite. The existing
            // `(table_name, sync_version)` index stays for the three unscoped
            // tables.
            $table->index(
                ['table_name', 'owner_key', 'sync_version'],
                'sync_deletions_table_owner_version_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sync_deletions', function (Blueprint $table) {
            $table->dropIndex('sync_deletions_table_owner_version_index');
            $table->dropColumn('owner_key');
        });
    }
};
