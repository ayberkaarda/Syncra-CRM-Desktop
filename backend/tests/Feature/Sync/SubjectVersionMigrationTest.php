<?php

namespace Tests\Feature\Sync;

use App\Models\Company;
use App\Services\Sync\ConflictDetector;
use App\Sync\SyncVersionBumper;
use App\Sync\WriteTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration behind the conflict window, run for real: it adds a column
 * and commits as it goes, so none of this can sit inside a test transaction.
 * Whatever a test creates it removes, and the schema is put back afterwards.
 */
class SubjectVersionMigrationTest extends TestCase
{
    private const FILE = '2026_09_01_100012_add_subject_sync_version_to_activity_log_table.php';

    /** @var array<int, int> */
    private array $companies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        $migration = $this->migration();

        if (! Schema::hasColumn('activity_log', 'subject_sync_version')) {
            $migration->up();
        }

        config(['activitylog.database_connection' => null]);
        WriteTrail::forget();

        DB::table('activity_log')->whereIn('subject_id', $this->companies)
            ->where('subject_type', (new Company)->getMorphClass())->delete();
        DB::table('companies')->whereIn('id', $this->companies)->delete();

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::FILE);
    }

    private function company(): Company
    {
        $company = Company::factory()->create();
        $this->companies[] = $company->id;

        return $company;
    }

    /** @return array<int, int|null> */
    private function versions(Company $company): array
    {
        return DB::table('activity_log')
            ->where('subject_type', $company->getMorphClass())
            ->where('subject_id', $company->id)
            ->orderBy('id')
            ->pluck('subject_sync_version')
            ->map(fn ($version) => $version === null ? null : (int) $version)
            ->all();
    }

    public function test_the_backfill_ties_existing_audit_rows_to_their_subject(): void
    {
        $company = $this->company();
        $company->update(['city' => 'Trabzon']);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $version = (int) $company->fresh()->sync_version;
        $this->assertSame([$version, $version], $this->versions($company));
    }

    /** Column present, backfill or anything after it interrupted. */
    public function test_running_it_again_after_a_partial_failure_finishes_the_job(): void
    {
        $company = $this->company();
        $company->update(['city' => 'Trabzon']);

        DB::table('activity_log')->where('subject_id', $company->id)
            ->where('subject_type', $company->getMorphClass())
            ->update(['subject_sync_version' => null]);

        $this->migration()->up();

        $version = (int) $company->fresh()->sync_version;
        $this->assertSame([$version, $version], $this->versions($company));
    }

    public function test_a_live_write_records_its_own_version_without_any_trigger(): void
    {
        $this->assertSame([], DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
            ['activity_log']
        ));

        $company = $this->company();
        $created = (int) $company->sync_version;
        $company->update(['city' => 'Trabzon']);
        $updated = (int) $company->fresh()->sync_version;
        $company->update(['city' => 'Rize']);

        $this->assertGreaterThan($created, $updated);
        $this->assertSame([$created, $updated, (int) $company->fresh()->sync_version], $this->versions($company));
    }

    /** `ACTIVITY_LOGGER_DB_CONNECTION` is a supported setting of this project. */
    public function test_an_audit_log_on_its_own_connection_still_records_the_version(): void
    {
        config([
            'database.connections.audit' => config('database.connections.'.config('database.default')),
            'activitylog.database_connection' => 'audit',
        ]);

        $company = $this->company();
        $company->update(['city' => 'Trabzon']);

        $this->assertSame((int) $company->fresh()->sync_version, $this->versions($company)[1] ?? null);
    }

    /**
     * The audit log in a database of its own, which the default connection
     * cannot see: the detector has to read it where it was written.
     */
    public function test_the_detector_reads_an_audit_log_kept_in_another_database(): void
    {
        $main = DB::connection()->getDatabaseName();
        $audit = $main.'_audit';

        DB::statement("DROP DATABASE IF EXISTS `{$audit}`");
        DB::statement("CREATE DATABASE `{$audit}`");

        try {
            DB::statement("CREATE TABLE `{$audit}`.`activity_log` LIKE `{$main}`.`activity_log`");
            config([
                'database.connections.audit' => array_merge(config('database.connections.'.config('database.default')), ['database' => $audit]),
                'activitylog.database_connection' => 'audit',
            ]);

            $company = $this->company();
            $base = (int) $company->sync_version;
            $company->update(['city' => 'Trabzon']);

            $this->assertSame(0, DB::table('activity_log')->where('subject_id', $company->id)
                ->where('subject_type', $company->getMorphClass())->count(), 'The audit rows went to the other database.');
            $this->assertSame(
                [],
                app(ConflictDetector::class)->detect($company->fresh(), 'company', ['notes'], $base, null),
                'Only the city moved, and the trail that says so is in the audit database.'
            );
            $this->assertSame(
                ['city'],
                app(ConflictDetector::class)->detect($company->fresh(), 'company', ['city'], $base, null),
            );
        } finally {
            config(['activitylog.database_connection' => null]);
            DB::purge('audit');
            DB::statement("DROP DATABASE IF EXISTS `{$audit}`");
        }
    }

    /**
     * A queue worker that has already written with the schema in place, and is
     * still running when the migration is rolled back. Nothing tells it; its
     * next job has to work out for itself that the schema is gone.
     */
    public function test_a_running_worker_keeps_writing_after_a_rollback(): void
    {
        $company = $this->company();
        $company->update(['city' => 'Trabzon']);
        $this->assertTrue(WriteTrail::ready());
        config(['queue.default' => 'database']);

        try {
            $this->migration()->down();

            $id = $company->id;
            dispatch(function () use ($id): void {
                $company = Company::query()->findOrFail($id);
                $company->update(['city' => 'Rize']);
                SyncVersionBumper::bump($company);
            });
            $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1]);

            $this->assertSame([], DB::table('failed_jobs')->pluck('exception')->map(
                fn ($exception) => strtok((string) $exception, "\n")
            )->all());
            $this->assertSame('Rize', $company->fresh()->city);
        } finally {
            DB::table('jobs')->delete();
            DB::table('failed_jobs')->delete();
        }
    }

    /** Code ahead of the schema: during a deploy, or after a rollback. */
    public function test_writes_keep_working_while_the_schema_is_rolled_back(): void
    {
        $company = $this->company();
        $base = (int) $company->sync_version;
        $this->migration()->down();
        // The next request or job, which is when a running process notices.
        $this->app->forgetScopedInstances();

        $this->assertFalse(Schema::hasColumn('activity_log', 'subject_sync_version'));

        $company->fresh()->update(['city' => 'Trabzon']);
        SyncVersionBumper::bump($company->fresh());

        $this->assertSame('Trabzon', $company->fresh()->city);
        $this->assertSame(
            ['notes'],
            app(ConflictDetector::class)->detect($company->fresh(), 'company', ['notes'], $base, null),
            'Without the schema the detector cannot tell what moved.'
        );
    }

    /** A base below the baseline predates the record of embedded writes. */
    public function test_a_base_older_than_the_baseline_counts_embedded_fields_as_moved(): void
    {
        $company = $this->company();
        $base = (int) $company->sync_version;
        $company->update(['city' => 'Trabzon']);
        $detector = app(ConflictDetector::class);
        $baseline = fn (int $version) => DB::table(WriteTrail::TABLE)
            ->where('subject_table', WriteTrail::BASELINE)->update(['sync_version' => $version]);

        $original = (int) DB::table(WriteTrail::TABLE)
            ->where('subject_table', WriteTrail::BASELINE)->value('sync_version');

        try {
            $baseline($base + 1);
            $this->assertSame(['tag_ids'], $detector->detect($company->fresh(), 'company', ['tag_ids'], $base, null));

            $baseline($base);
            $this->assertSame([], $detector->detect($company->fresh(), 'company', ['tag_ids'], $base, null));
        } finally {
            $baseline($original);
        }
    }
}
