<?php

use App\Sync\SyncableRegistry;
use App\Sync\WriteTrail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync - ties every write to the version space (protocol §5).
 *
 * ConflictDetector has to answer "which fields moved after the client's
 * `base_sync_version`?", and the audit trail only knew WHEN a field moved. The
 * old window compared that to the client's `occurred_at`, so an offline edit
 * made after somebody else's save fell outside it and overwrote them silently.
 *
 * `activity_log.subject_sync_version` carries the version of an audited write;
 * `sync_untrailed_writes` the last version of an embedded or bulk write, which
 * has no audit row. The application writes both (App\Sync\WriteTrail). There is
 * no trigger: it would need the TRIGGER privilege, and SUPER or
 * `log_bin_trust_function_creators` wherever the binary log is on.
 *
 * EVERY STEP CAN BE REPEATED, and each one commits on its own, so a run stopped
 * anywhere is finished by running it again. The backfill only touches rows that
 * are still NULL. Adding a nullable column at the end is instant on MariaDB
 * 10.3+ and MySQL 8.
 *
 * EXISTING AUDIT ROWS: the version an old row belonged to was never recorded,
 * but it cannot exceed the subject's version today, so that is what gets stored.
 * A client based at or above it is correctly clear of the row; one based below
 * may see a conflict it did not need to, until it pulls that row. That errs in
 * the only safe direction. `created` rows carry the same value and the detector
 * ignores them for a real base: nobody can hold a base from before a row existed.
 *
 * NULLABLE: a row the backfill cannot reach (a subject outside the sync scope,
 * or one hard-deleted since) stays NULL, and the detector counts NULL against
 * the client. No index: the detector already narrows by subject.
 */
return new class extends Migration
{
    private const BATCH = 5000;

    public function up(): void
    {
        $table = $this->table();
        $schema = Schema::connection($this->connection());

        if (! $schema->hasColumn($table, WriteTrail::COLUMN)) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger(WriteTrail::COLUMN)->nullable();
            });
        }

        if (! Schema::hasTable(WriteTrail::TABLE)) {
            Schema::create(WriteTrail::TABLE, function (Blueprint $blueprint) {
                $blueprint->string('subject_table', 64);
                $blueprint->unsignedBigInteger('subject_id');
                $blueprint->unsignedBigInteger('sync_version');
                $blueprint->primary(['subject_table', 'subject_id']);
            });
        }

        // After the table exists, so every embedded write the counter has
        // handed out above this value is on record.
        DB::table(WriteTrail::TABLE)->insertOrIgnore([
            'subject_table' => WriteTrail::BASELINE,
            'subject_id' => 0,
            'sync_version' => (int) DB::table('sync_counter')->where('id', 1)->value('value'),
        ]);

        foreach (SyncableRegistry::tables() as $subjectTable => $definition) {
            $model = $definition['model'];

            // An audit log kept in another database cannot be joined to its
            // subjects. Its old rows stay NULL, which only costs conflicts.
            if ($model !== null && method_exists($model, 'getActivitylogOptions') && $schema->hasTable($subjectTable)) {
                $this->backfill($subjectTable, (new $model)->getMorphClass());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(WriteTrail::TABLE);

        $schema = Schema::connection($this->connection());

        if ($schema->hasColumn($this->table(), WriteTrail::COLUMN)) {
            $schema->table($this->table(), function (Blueprint $blueprint) {
                $blueprint->dropColumn(WriteTrail::COLUMN);
            });
        }
    }

    /**
     * In id ranges rather than one statement: `activity_log` is the largest
     * table of a long-running install, and a single UPDATE would hold every row
     * it touched until the end.
     */
    private function backfill(string $subjectTable, string $morphClass): void
    {
        $connection = DB::connection($this->connection());
        $table = $this->table();
        $last = (int) $connection->table($table)->where('subject_type', $morphClass)->max('id');
        $column = WriteTrail::COLUMN;

        for ($from = 0; $from < $last; $from += self::BATCH) {
            $connection->update(
                "UPDATE `{$table}` a
                 JOIN `{$subjectTable}` s ON s.id = a.subject_id
                 SET a.{$column} = s.sync_version
                 WHERE a.subject_type = ? AND a.{$column} IS NULL AND a.id > ? AND a.id <= ?",
                [$morphClass, $from, $from + self::BATCH]
            );
        }
    }

    private function table(): string
    {
        return config('activitylog.table_name', 'activity_log');
    }

    private function connection(): ?string
    {
        return config('activitylog.database_connection');
    }
};
