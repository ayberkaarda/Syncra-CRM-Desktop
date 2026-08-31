<?php

namespace App\Sync;

use Illuminate\Support\Facades\DB;

/**
 * Assigns a `sync_version` to rows that never got one (protocol §2.6).
 *
 * TWO callers, ONE implementation on purpose:
 *   1. the backfill migration (2026_09_01_100008), for every row that existed
 *      before the column did;
 *   2. DemoDataSeeder::run(), because that seeder writes with `bulkInsert()`
 *      (DemoDataSeeder.php:31 documents the "bulk insert instead of factories,
 *      for speed" decision) and bulk inserts fire NO Eloquent model events, so
 *      SyncVersionObserver never sees them. Rewriting the seeder was rejected
 *      in protocol §2.2: the seeder's own design decision stands, and a
 *      one-shot backfill at the end of run() is both cheaper and impossible to
 *      forget for a table added later.
 *
 * The statement is the one from protocol §2.6, with one addition:
 *
 *     WHERE sync_version = 0
 *
 * Without it the helper is not idempotent - a second run would renumber rows
 * that already carry a valid version, burning counter values and pushing every
 * already-synced row back into every client's delta. `0` is exactly the
 * "never versioned" sentinel the column default establishes, so the filter is
 * the precise inverse of the problem being fixed.
 *
 * `ORDER BY id` makes the assignment deterministic: version order matches
 * insertion order, which is what a client bootstrapping in cursor order sees.
 */
final class SyncVersionBackfill
{
    /**
     * @param  array<int, string>|null  $tables  Defaults to the full sync scope.
     * @return array<string, int> table => rows versioned
     */
    public static function run(?array $tables = null): array
    {
        $tables ??= SyncableRegistry::syncVersionTables();

        $affected = [];

        foreach ($tables as $table) {
            // Read the counter into a session variable, hand out consecutive
            // values inside the UPDATE, then push the counter forward once.
            // Three statements instead of a correlated subquery per row: the
            // whole point is to be cheap on a table that may hold millions of
            // rows.
            DB::statement('SET @sync_backfill_n := (SELECT value FROM sync_counter WHERE id = 1)');

            $rows = DB::update(
                'UPDATE `'.$table.'` SET sync_version = (@sync_backfill_n := @sync_backfill_n + 1) '
                .'WHERE sync_version = 0 ORDER BY id'
            );

            DB::statement('UPDATE sync_counter SET value = @sync_backfill_n WHERE id = 1');

            $affected[$table] = $rows;
        }

        return $affected;
    }
}
