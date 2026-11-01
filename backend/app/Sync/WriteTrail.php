<?php

namespace App\Sync;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What ConflictDetector reads to learn which writes moved a row, and when
 * (protocol §5). Two records, because writes come in two kinds:
 *
 *   - an audited save: its audit row carries `subject_sync_version`, stamped by
 *     App\Observers\ActivityLogObserver from the subject's own in-memory version;
 *   - an embedded or bulk write through SyncVersionBumper: it deliberately has
 *     no audit row, so `sync_untrailed_writes` keeps the last version such a
 *     write gave each row. One row per subject, removed when the subject is
 *     physically deleted (`forgetSubject()`, from SyncVersionObserver). A
 *     delete no observer sees - a foreign key cascade, a query builder delete -
 *     leaves its row behind, and `prune()` sweeps those. A leftover row is
 *     harmless meanwhile: versions are never reused, so it explains nothing.
 *
 * The audit rows live wherever `activitylog.database_connection` puts them,
 * which can be another database; `auditConnection()` is the one place that
 * says which, for the stamp, the migration and ConflictDetector alike.
 *
 * WHY THE APPLICATION AND NOT A TRIGGER: the subject is right there when the
 * audit row is built, a trigger would have to be handed the version through
 * connection state, which `ACTIVITY_LOGGER_DB_CONNECTION` breaks, and creating
 * one needs a privilege shared hosting does not give (protocol §5).
 *
 * The code can run ahead of the schema: during a deploy, and after a rollback.
 * `ready()` keeps writes working then, and the detector treats the whole record
 * as moved, because it cannot tell otherwise.
 */
final class WriteTrail
{
    public const TABLE = 'sync_untrailed_writes';

    public const COLUMN = 'subject_sync_version';

    /**
     * The row holding the counter's value when the table was created. Embedded
     * writes below it were never recorded, so a base below it proves nothing.
     */
    public const BASELINE = '*';

    /**
     * Container key of the cached answer to `ready()`.
     */
    private const READY = 'sync.write-trail.ready';

    /**
     * Whether the schema is there, asked once per request or queued job.
     *
     * The answer is a scoped container instance, not a static: the queue worker
     * (and Octane) drop scoped instances before every job (and request), and a
     * plain PHP request starts with none. So a long-lived process follows a
     * migration either way - it arriving, or being rolled back underneath it -
     * from its next unit of work, with no restart and two schema queries per
     * unit at most. A static remembered "ready" until the process died, and
     * every audited write after a rollback failed on the dropped column.
     */
    public static function ready(): bool
    {
        $container = Container::getInstance();
        $container->scopedIf(self::READY, fn (): bool => self::auditSchema()
            ->hasColumn(config('activitylog.table_name', 'activity_log'), self::COLUMN)
            && Schema::hasTable(self::TABLE));

        return (bool) $container->make(self::READY);
    }

    /**
     * Ask `ready()` again on its next call, inside the same unit of work.
     */
    public static function forget(): void
    {
        Container::getInstance()->forgetInstance(self::READY);
    }

    /**
     * The connection the audit rows are written to and must be read from.
     */
    public static function auditConnection(): Connection
    {
        return DB::connection(config('activitylog.database_connection'));
    }

    private static function auditSchema(): Builder
    {
        return Schema::connection(config('activitylog.database_connection'));
    }

    /**
     * Copy the version of the write being audited onto its audit row.
     *
     * The subject's `sync_version` attribute is that version: SyncVersionObserver
     * and SyncVersionBumper both set it on the instance the audit row is built
     * from. A subject outside the version space leaves the column NULL.
     */
    public static function stamp(Model $activity): void
    {
        if ($activity->exists || ! $activity->relationLoaded('subject') || ! self::ready()) {
            return;
        }

        $version = $activity->getRelation('subject')?->getAttribute('sync_version');

        if ($version !== null) {
            $activity->setAttribute(self::COLUMN, (int) $version);
        }
    }

    public static function recordUntrailed(string $table, int|string $id, int $version): void
    {
        if (! self::ready()) {
            return;
        }

        DB::table(self::TABLE)->upsert(
            ['subject_table' => $table, 'subject_id' => $id, 'sync_version' => $version],
            ['subject_table', 'subject_id'],
            ['sync_version'],
        );
    }

    /**
     * @return array{0: int, 1: int} this row's last untrailed version, and the baseline
     */
    public static function untrailed(Model $model): array
    {
        $rows = DB::table(self::TABLE)
            ->where(function ($query) use ($model): void {
                $query->where('subject_table', $model->getTable())->where('subject_id', $model->getKey());
            })
            ->orWhere('subject_table', self::BASELINE)
            ->pluck('sync_version', 'subject_table');

        return [(int) ($rows[$model->getTable()] ?? 0), (int) ($rows[self::BASELINE] ?? 0)];
    }

    /**
     * Drop the record of a row that no longer exists. Called once its delete has
     * run, so a delete that fails leaves the record in place.
     */
    public static function forgetSubject(Model $model): void
    {
        if (! self::ready()) {
            return;
        }

        DB::table(self::TABLE)
            ->where('subject_table', $model->getTable())
            ->where('subject_id', $model->getKey())
            ->delete();
    }

    /**
     * Remove the records of rows deleted where no observer saw it. Every table
     * SyncVersionBumper writes to is keyed by `id`.
     *
     * @return int records removed
     */
    public static function prune(): int
    {
        if (! self::ready()) {
            return 0;
        }

        $removed = 0;
        $tables = DB::table(self::TABLE)->where('subject_table', '!=', self::BASELINE)
            ->distinct()->pluck('subject_table');

        foreach ($tables as $table) {
            $records = DB::table(self::TABLE)->where('subject_table', $table);

            if (Schema::hasTable($table)) {
                $records->whereNotExists(function ($query) use ($table): void {
                    $query->from($table)->whereColumn($table.'.id', self::TABLE.'.subject_id');
                });
            }

            $removed += $records->delete();
        }

        return $removed;
    }
}
