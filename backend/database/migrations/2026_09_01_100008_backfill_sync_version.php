<?php

use App\Sync\SyncableRegistry;
use App\Sync\SyncVersionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desktop sync — one-shot backfill (SYNCDESKTOP §4.2, protocol §2.6).
 *
 * Every row that existed before `sync_version` did carries the column default
 * 0. A cursor of 0 is the bootstrap cursor, so those rows would be pulled -
 * but `WHERE sync_version > 0` excludes them, i.e. the entire pre-existing
 * database would be invisible to every desktop client. This migration hands
 * each one a real version and leaves `sync_counter` at the maximum.
 *
 * ORDER MATTERS: this runs BEFORE 2026_09_01_100009 creates the
 * `conversation_user` triggers. Backfilling a triggered table would make its
 * BEFORE UPDATE trigger the authority over the value being written and would
 * then have `sync_counter` rewound by the final statement here. Sequencing the
 * two migrations is a cheaper guarantee than making either side aware of the
 * other.
 */
return new class extends Migration
{
    public function up(): void
    {
        SyncVersionBackfill::run();
    }

    /**
     * Reverse: put the scope back in its pre-backfill state (every row
     * unversioned, counter at zero). Genuinely reversible - the version space
     * carries no information that is not reproducible by re-running up().
     */
    public function down(): void
    {
        foreach (SyncableRegistry::syncVersionTables() as $table) {
            DB::table($table)->update(['sync_version' => 0]);
        }

        DB::table('sync_counter')->where('id', 1)->update(['value' => 0]);
    }
};
