<?php

namespace Tests\Feature;

use App\Models\PageVisitLog;
use App\Models\SessionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PruneLogsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOldPageVisit(int $daysOld): PageVisitLog
    {
        $user = User::factory()->create();

        return PageVisitLog::factory()->create([
            'user_id' => $user->id,
            'entered_at' => now()->subDays($daysOld),
        ]);
    }

    private function makeOldSessionLog(int $daysOld): SessionLog
    {
        return SessionLog::factory()->create([
            'created_at' => now()->subDays($daysOld),
            'updated_at' => now()->subDays($daysOld),
        ]);
    }

    private function makeOldActivity(int $daysOld): Activity
    {
        return Activity::create([
            'log_name' => 'test',
            'description' => 'test kaydı',
            'created_at' => now()->subDays($daysOld),
            'updated_at' => now()->subDays($daysOld),
        ]);
    }

    public function test_old_records_are_deleted_and_recent_ones_are_kept(): void
    {
        $old = $this->makeOldPageVisit(100); // 90 gün varsayılanını aşıyor
        $recent = $this->makeOldPageVisit(10); // saklama süresi içinde

        $this->artisan('logs:prune', ['--table' => 'page_visits', '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('page_visit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('page_visit_logs', ['id' => $recent->id]);
    }

    public function test_dry_run_deletes_nothing_but_reports_correct_count(): void
    {
        $this->makeOldPageVisit(100);
        $this->makeOldPageVisit(120);
        $this->makeOldPageVisit(10); // silinmeyecek

        $countBefore = PageVisitLog::count();

        $this->artisan('logs:prune', ['--table' => 'page_visits', '--dry-run' => true])
            ->expectsOutputToContain('2')
            ->assertExitCode(0);

        $this->assertSame($countBefore, PageVisitLog::count());
    }

    public function test_table_option_only_affects_the_targeted_table(): void
    {
        $oldVisit = $this->makeOldPageVisit(100);
        $oldSession = $this->makeOldSessionLog(400); // 365 gün varsayılanını aşıyor
        $oldActivity = $this->makeOldActivity(400);

        $this->artisan('logs:prune', ['--table' => 'page_visits', '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('page_visit_logs', ['id' => $oldVisit->id]);
        // Diğer tablolara dokunulmadı.
        $this->assertDatabaseHas('session_logs', ['id' => $oldSession->id]);
        $this->assertDatabaseHas(config('activitylog.table_name', 'activity_log'), ['id' => $oldActivity->id]);
    }

    public function test_days_option_overrides_the_default_retention(): void
    {
        // 10 gün eski, varsayılan (90 gün) saklama süresinin İÇİNDE — normalde silinmez.
        $visit = $this->makeOldPageVisit(10);

        $this->artisan('logs:prune', ['--table' => 'page_visits', '--days' => 5, '--force' => true])
            ->assertExitCode(0);

        // --days=5 ile 10 günlük kayıt artık saklama süresini aşmış sayılır.
        $this->assertDatabaseMissing('page_visit_logs', ['id' => $visit->id]);
    }

    public function test_force_is_required_in_non_interactive_mode_without_dry_run(): void
    {
        $old = $this->makeOldPageVisit(100);

        $this->artisan('logs:prune', ['--table' => 'page_visits', '--no-interaction' => true])
            ->assertExitCode(1);

        // --force verilmediği ve etkileşimsiz olduğu için hiçbir şey silinmedi.
        $this->assertDatabaseHas('page_visit_logs', ['id' => $old->id]);
    }
}
