<?php

namespace Tests\Feature\Sync;

use App\Models\Company;
use App\Sync\WriteTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WriteTrail housekeeping: how long `ready()` trusts its answer in a process
 * that never starts a new unit of work, and the scheduled sweep of records
 * whose row is gone.
 */
class WriteTrailMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        WriteTrail::forget();

        parent::tearDown();
    }

    /**
     * A daemon loop or a job that runs for hours never drops scoped instances.
     * The schema changing underneath it (here: the audit table it would stamp
     * going away) has to be noticed without a restart, and without asking the
     * schema on every write.
     */
    public function test_a_process_that_never_resets_scoped_state_sees_a_schema_change(): void
    {
        WriteTrail::forget();
        $this->assertTrue(WriteTrail::ready());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        config(['activitylog.table_name' => 'activity_log_gone']);

        $this->assertTrue(WriteTrail::ready(), 'one answer is reused inside its window');
        $this->assertSame(0, $queries, 'a write inside the window asks nothing');

        $this->travel(WriteTrail::READY_TTL + 1)->seconds();

        $this->assertFalse(WriteTrail::ready(), 'the answer from before the change went stale');

        config(['activitylog.table_name' => 'activity_log']);
        $this->travel(WriteTrail::READY_TTL + 1)->seconds();

        $this->assertTrue(WriteTrail::ready(), 'and the schema coming back is seen too');
    }

    public function test_the_daily_log_prune_sweeps_records_of_rows_that_are_gone(): void
    {
        $kept = Company::factory()->create();
        DB::table(WriteTrail::TABLE)->insert([
            ['subject_table' => 'companies', 'subject_id' => $kept->id, 'sync_version' => 5],
            ['subject_table' => 'companies', 'subject_id' => $kept->id + 1000, 'sync_version' => 6],
        ]);

        $this->artisan('logs:prune', ['--force' => true])->assertSuccessful();

        $this->assertSame(
            [(string) $kept->id],
            DB::table(WriteTrail::TABLE)->where('subject_table', 'companies')->pluck('subject_id')->map(fn ($id) => (string) $id)->all(),
        );
        $this->assertTrue(DB::table(WriteTrail::TABLE)->where('subject_table', WriteTrail::BASELINE)->exists());
    }

    private function orphan(): void
    {
        DB::table(WriteTrail::TABLE)->insert(
            ['subject_table' => 'companies', 'subject_id' => 999999, 'sync_version' => 6],
        );
    }

    private function orphanSurvived(): bool
    {
        return DB::table(WriteTrail::TABLE)->where('subject_id', 999999)->exists();
    }

    public function test_a_run_without_force_and_without_interaction_sweeps_nothing(): void
    {
        $this->orphan();

        $this->artisan('logs:prune', ['--no-interaction' => true])->assertFailed();

        $this->assertTrue($this->orphanSurvived());
    }

    public function test_declining_the_confirmation_sweeps_nothing(): void
    {
        $this->orphan();

        $this->artisan('logs:prune')
            ->expectsConfirmation('Toplam 1 satır kalıcı olarak silinecek. Devam edilsin mi?', 'no')
            ->assertSuccessful();

        $this->assertTrue($this->orphanSurvived());
    }

    public function test_pruning_another_table_leaves_the_write_trail_alone(): void
    {
        $this->orphan();

        $this->artisan('logs:prune', ['--table' => 'sessions', '--force' => true])->assertSuccessful();

        $this->assertTrue($this->orphanSurvived());
    }

    public function test_the_write_trail_can_be_pruned_on_its_own(): void
    {
        $this->orphan();

        $this->artisan('logs:prune', ['--table' => 'write_trail', '--force' => true])->assertSuccessful();

        $this->assertFalse($this->orphanSurvived());
    }

    public function test_a_dry_run_sweeps_nothing(): void
    {
        DB::table(WriteTrail::TABLE)->insert(
            ['subject_table' => 'companies', 'subject_id' => 999999, 'sync_version' => 6],
        );

        $this->artisan('logs:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue(DB::table(WriteTrail::TABLE)->where('subject_id', 999999)->exists());
    }
}
