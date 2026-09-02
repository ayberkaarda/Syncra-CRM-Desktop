<?php

namespace App\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Advances `sync_version` on rows whose change Eloquent never reports
 * (protocol §1.4, §1.5, §2.3).
 *
 * TWO shapes of problem, one tool:
 *
 *  1. EMBEDDED CHILDREN. `taggables`, `quote_items` and `custom_field_values`
 *     are not sync tables (protocol §1.4/§1.5) - they ride inside their owner's
 *     payload. So when ONLY the embedded data changes and the owner's own
 *     columns stay clean, the owner is not dirty, no observer fires, and the
 *     delta silently misses the change. The owner is bumped explicitly.
 *
 *  2. BULK STATEMENTS. `Model::query()->where(...)->update([...])` and its
 *     delete twin never instantiate models, so no `saving` event exists to
 *     hook. Protocol §2.3 lists eight such call sites.
 *
 * ------------------------------------------------------------------------
 * WHY A RAW UPDATE AND NOT `$model->save()`
 * ------------------------------------------------------------------------
 * Three properties are required at once, and only a direct statement has all
 * three:
 *   - `updated_at` MUST NOT move. SYNCDESKTOP §4.2 is explicit, and the column
 *     is the bootstrap `window_days` filter - shifting it would drag old rows
 *     back into every new client's initial window.
 *   - NO audit row. LeadConversionService's own docblock (:330) argues that
 *     replacing its bulk update with a 50-model loop would bury the single
 *     meaningful "converted" entry under 50 mechanical ones. Bumping through
 *     Eloquent would do exactly what that decision rejects.
 *   - ONE version PER ROW. Protocol §2.5/K-C: the pull cursor is a single
 *     scalar, so if `LIMIT` ever falls between two rows sharing a version, the
 *     second one is never returned again. `bumpRows()` therefore issues one
 *     statement per row rather than one for the set.
 *
 * The in-memory model is kept in step (attribute set AND original synced) so a
 * later `save()` does not try to write a stale version back.
 */
final class SyncVersionBumper
{
    /**
     * Bump one model's row and return the version written.
     */
    public static function bump(Model $model): int
    {
        $version = SyncCounter::next();

        DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->update(['sync_version' => $version]);

        $model->setAttribute('sync_version', $version);
        $model->syncOriginalAttribute('sync_version');

        return $version;
    }

    /**
     * Bump a set of rows addressed by primary key, one distinct version each.
     *
     * @param  array<int, int|string>  $keys
     * @return array<int|string, int> key => version
     */
    public static function bumpRows(string $table, array $keys, string $keyName = 'id'): array
    {
        $versions = [];

        foreach (array_unique($keys) as $key) {
            $version = SyncCounter::next();

            DB::table($table)->where($keyName, $key)->update(['sync_version' => $version]);

            $versions[$key] = $version;
        }

        return $versions;
    }
}
