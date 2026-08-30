<?php

namespace Tests\Feature;

use App\Events\DashboardInvalidated;
use App\Events\DealMoved;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faz 11 — `/api/dashboard/*` (kpis, funnel, revenue-trend,
 * recent-activities, task-summary) + canlılık (DashboardInvalidated
 * debounce).
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Bkz. ReportApiTest::setUp() — Faz 10'un bildirim gönderim yolu bu
        // dosyanın kapsamı dışında, gerçek kuyruklamayı devre dışı bırakır.
        Notification::fake();
    }

    private function actorWithDashboardView(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['dashboard.view']);

        return $user;
    }

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected_on_every_dashboard_endpoint(): void
    {
        $this->getJson('/api/dashboard/kpis')->assertStatus(401);
        $this->getJson('/api/dashboard/funnel')->assertStatus(401);
        $this->getJson('/api/dashboard/revenue-trend')->assertStatus(401);
        $this->getJson('/api/dashboard/recent-activities')->assertStatus(401);
        $this->getJson('/api/dashboard/task-summary')->assertStatus(401);
    }

    public function test_user_without_dashboard_view_permission_is_forbidden(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/dashboard/kpis')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/dashboard/funnel')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/dashboard/revenue-trend')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/dashboard/recent-activities')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/dashboard/task-summary')->assertStatus(403);
    }

    public function test_invalid_date_range_returns_422_not_500(): void
    {
        $actor = $this->actorWithDashboardView();

        $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2026-08-31&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    // -------------------------------------------------------------------
    // kpis
    // -------------------------------------------------------------------

    public function test_kpis_computes_revenue_and_delta_against_the_previous_period(): void
    {
        $actor = $this->actorWithDashboardView();
        $stage = $this->openStage();

        // Mevcut dönem: 2026-06-01..2026-06-10 (10 gün).
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 1000, 'closed_at' => '2026-06-05 10:00:00',
        ]);
        Deal::factory()->lost()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 300, 'closed_at' => '2026-06-06 10:00:00',
        ]);
        Deal::factory()->create([
            'pipeline_stage_id' => $stage->id, 'status' => 'open', 'amount' => 2500,
            'created_at' => '2026-06-07 10:00:00',
        ]);
        Activity::factory()->create(['occurred_at' => '2026-06-08 12:00:00']);

        // Önceki dönem (aynı uzunluk, hemen önce): 2026-05-22..2026-05-31.
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 500, 'closed_at' => '2026-05-25 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2026-06-01&to=2026-06-10')
            ->assertOk();

        $response->assertJsonPath('data.revenue.value', '1000.00');
        $response->assertJsonPath('data.revenue.previous', '500.00');
        $response->assertJsonPath('data.revenue.delta_pct', 100.0);
        $response->assertJsonPath('data.won_count.value', 1);
        $response->assertJsonPath('data.lost_count.value', 1);
        $response->assertJsonPath('data.conversion_rate.value', 50.0);
        $response->assertJsonPath('data.open_deals_count.value', 1);
        $response->assertJsonPath('data.open_deals_value.value', '2500.00');
        $response->assertJsonPath('data.activities_count.value', 1);
        $response->assertJsonPath('data.avg_deal_size.value', '1000.00');

        // Önceki dönemde açık deal / aktivite yok → previous 0, delta_pct null.
        $response->assertJsonPath('data.open_deals_count.previous', 0);
        $response->assertJsonPath('data.open_deals_count.delta_pct', null);
    }

    public function test_kpis_empty_period_returns_200_with_zeroed_metrics(): void
    {
        $actor = $this->actorWithDashboardView();

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2020-01-01&to=2020-01-31')
            ->assertOk();

        $response->assertJsonPath('data.revenue.value', '0.00');
        $response->assertJsonPath('data.revenue.delta_pct', null);
        $response->assertJsonPath('data.won_count.value', 0);
        $response->assertJsonPath('data.conversion_rate.value', 0.0);
    }

    // -------------------------------------------------------------------
    // funnel
    // -------------------------------------------------------------------

    public function test_funnel_returns_only_active_stages_in_position_order_with_counts(): void
    {
        $actor = $this->actorWithDashboardView();

        $first = PipelineStage::factory()->create(['position' => 1, 'is_active' => true]);
        $second = PipelineStage::factory()->create(['position' => 2, 'is_active' => true]);
        $inactive = PipelineStage::factory()->inactive()->create(['position' => 3]);

        Deal::factory()->create([
            'pipeline_stage_id' => $first->id, 'status' => 'open', 'amount' => 1000,
            'created_at' => '2026-06-05 10:00:00',
        ]);
        Deal::factory()->create([
            'pipeline_stage_id' => $first->id, 'status' => 'open', 'amount' => 2000,
            'created_at' => '2026-06-06 10:00:00',
        ]);
        Deal::factory()->create([
            'pipeline_stage_id' => $inactive->id, 'status' => 'open', 'amount' => 9999,
            'created_at' => '2026-06-06 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/funnel?from=2026-06-01&to=2026-06-30')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($first->id, $data[0]['stage_id']);
        $this->assertSame(2, $data[0]['count']);
        $this->assertSame('3000.00', $data[0]['value']);
        $this->assertSame($second->id, $data[1]['stage_id']);
        $this->assertSame(0, $data[1]['count']);
    }

    /**
     * `stage_name_key` — Sales Funnel'ın Türkçe kalma hatasının kök nedeni burasıydı
     * (`dataKey="stage_name"`). Seed edilmiş bir aşama dolu `name_key` taşımalı, admin'in
     * yeniden adlandırdığı/oluşturduğu bir aşama ise NULL taşımalı — frontend ikisini
     * ayırabilsin diye.
     */
    public function test_funnel_rows_carry_the_stage_name_key(): void
    {
        $actor = $this->actorWithDashboardView();

        $seeded = PipelineStage::factory()->create([
            'position' => 1, 'is_active' => true, 'slug' => 'yeni-firsat', 'name_key' => 'yeni-firsat',
        ]);
        $custom = PipelineStage::factory()->create([
            'position' => 2, 'is_active' => true, 'name_key' => null,
        ]);

        $data = $this->actingAs($actor)
            ->getJson('/api/dashboard/funnel?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->json('data');

        $this->assertSame('yeni-firsat', $data[0]['stage_name_key']);
        $this->assertSame($seeded->id, $data[0]['stage_id']);
        $this->assertNull($data[1]['stage_name_key']);
        $this->assertSame($custom->id, $data[1]['stage_id']);
    }

    // -------------------------------------------------------------------
    // revenue-trend
    // -------------------------------------------------------------------

    public function test_revenue_trend_groups_by_day(): void
    {
        $actor = $this->actorWithDashboardView();
        $stage = $this->openStage();

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 100, 'closed_at' => '2026-06-05 09:00:00',
        ]);
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 150, 'closed_at' => '2026-06-05 18:00:00',
        ]);
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 200, 'closed_at' => '2026-06-06 09:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/revenue-trend?from=2026-06-01&to=2026-06-30&group_by=day')
            ->assertOk();

        $response->assertJsonPath('data.0.period', '2026-06-05');
        $response->assertJsonPath('data.0.revenue', '250.00');
        $response->assertJsonPath('data.0.won_count', 2);
        $response->assertJsonPath('data.1.period', '2026-06-06');
        $response->assertJsonPath('data.1.revenue', '200.00');
    }

    public function test_revenue_trend_invalid_group_by_returns_422(): void
    {
        $actor = $this->actorWithDashboardView();

        $this->actingAs($actor)
            ->getJson('/api/dashboard/revenue-trend?group_by=quarter')
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // recent-activities
    // -------------------------------------------------------------------

    public function test_recent_activities_respects_limit_and_orders_by_most_recent(): void
    {
        $actor = $this->actorWithDashboardView();

        Activity::factory()->create(['subject' => 'Eski', 'occurred_at' => '2026-06-01 08:00:00']);
        Activity::factory()->create(['subject' => 'Orta', 'occurred_at' => '2026-06-02 08:00:00']);
        Activity::factory()->create(['subject' => 'Yeni', 'occurred_at' => '2026-06-03 08:00:00']);

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/recent-activities?limit=2')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('Yeni', $data[0]['subject']);
        $this->assertSame('Orta', $data[1]['subject']);
    }

    // -------------------------------------------------------------------
    // task-summary
    // -------------------------------------------------------------------

    public function test_task_summary_counts_overdue_due_today_and_completed_today(): void
    {
        $actor = $this->actorWithDashboardView();

        Task::factory()->overdue()->create(['priority' => 'high']);
        // Bugün, ama HENÜZ gecikmemiş olacak şekilde günün sonuna yakın —
        // `overdue` ile `due_today`'in birbirini ELEMEDİĞİ (bir görev aynı
        // anda ikisi de olabilir) durumu testte YANLIŞLIKLA karıştırmamak
        // için `now()`'dan kesinlikle SONRAKİ bir an seçilir.
        Task::factory()->create([
            'status' => 'pending', 'priority' => 'normal',
            'due_at' => now()->endOfDay(),
        ]);
        Task::factory()->completed()->create([
            'status' => 'completed', 'completed_at' => now(),
        ]);
        Task::factory()->cancelled()->create();

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/task-summary')
            ->assertOk();

        $response->assertJsonPath('data.overdue_count', 1);
        $response->assertJsonPath('data.due_today_count', 1);
        $response->assertJsonPath('data.completed_today_count', 1);
        $response->assertJsonPath('data.by_priority.high', 1);
        $response->assertJsonPath('data.by_priority.normal', 1);
    }

    // -------------------------------------------------------------------
    // Canlılık: DealMoved → DashboardInvalidated (debounced)
    // -------------------------------------------------------------------

    public function test_deal_moved_triggers_a_single_debounced_dashboard_invalidation(): void
    {
        Event::fake([DashboardInvalidated::class]);

        $stage = $this->openStage();
        $actor = User::factory()->create();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $payload = DealMoved::payload($deal, $stage->id, $actor);

        // Aynı "burst" içinde art arda 3 taşıma — yalnızca 1 invalidate beklenir.
        event(new DealMoved($payload));
        event(new DealMoved($payload));
        event(new DealMoved($payload));

        Event::assertDispatched(DashboardInvalidated::class, 1);
        Event::assertDispatched(function (DashboardInvalidated $event) {
            return $event->keys === ['kpis', 'funnel', 'revenue-trend'];
        });
    }
}
