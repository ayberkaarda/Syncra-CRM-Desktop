<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KARAR A29 backfill (defter O90): `SyncPullService::attachMessageAttachments()`
 * now flattens an attachment's metadata onto its `messages` pull row, but a
 * message attached BEFORE that code existed already carries a real, non-zero
 * `sync_version` from whenever it was first synced. Messages are edited
 * rarely, so such a row has no reason to change again and will never re-enter
 * a client's delta - meaning it never picks up the four new fields.
 *
 * `App\Sync\SyncVersionBackfill::run()` is NOT reusable here: its `WHERE
 * sync_version = 0` clause (see its class docblock) targets rows that never
 * got a version at all, which these rows are not - they already have one.
 * The counter-bump pattern is copied instead of the method being called on a
 * row set it would silently skip (see `2026_09_01_100008_backfill_sync_version.php`
 * for the original).
 *
 * Scope: `attachment_id IS NOT NULL AND deleted_at IS NULL` - the exact set
 * `attachMessageAttachments()` fills in fields for. A soft-deleted message
 * never gets those fields (parity with `MessageResource`'s mask), so
 * re-versioning it here would just spend a counter value for a pull row that
 * comes back unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET @sync_backfill_n := (SELECT value FROM sync_counter WHERE id = 1)');

        DB::update(
            'UPDATE `messages` SET sync_version = (@sync_backfill_n := @sync_backfill_n + 1) '
            .'WHERE attachment_id IS NOT NULL AND deleted_at IS NULL ORDER BY id'
        );

        DB::statement('UPDATE sync_counter SET value = @sync_backfill_n WHERE id = 1');
    }

    /**
     * Not reversible. Unlike `SyncVersionBackfill::run()::down()` (which
     * restores `sync_version` to 0 - a sentinel with no other meaning), the
     * rows this migration touches had a REAL prior `sync_version` that is
     * overwritten and not reconstructable from anything left in the database.
     * `down()` is deliberately a no-op; the safe way back is a fresh
     * `migrate:fresh`, not an attempt to restore values this migration never
     * recorded.
     */
    public function down(): void
    {
        // Intentionally empty - see docblock above.
    }
};
