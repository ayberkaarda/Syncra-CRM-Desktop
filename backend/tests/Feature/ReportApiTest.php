<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faz 11 — `/api/reports/*` (sales-performance, user-performance,
 * source-analysis, conversion, export).
 *
 * Tarihler DAİMA sabit, açık `from`/`to` ile verilir — `now()`'a bağlı
 * varsayılan aralık testi "bugün" çalıştırıldığı güne göre kırılgan
 * yapardı.
 */
class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Faz 10 (bildirimler, paralel çalışan başka bir şeridin sahipliğinde)
        // deals.owner_id doldurulduğunda/deal kazanıldığında/kaybedildiğinde
        // DealNotificationObserver üzerinden gerçek bir bildirim kuyruklar.
        // Bu testler bildirim davranışını DEĞİL rapor agregasyonunu
        // doğruluyor — gerçek gönderim kanalını (queue serialization dahil)
        // devre dışı bırakmak kapsam dışı bir modülün iç detaylarına
        // bağımlılığı önler.
        Notification::fake();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected_on_every_report_endpoint(): void
    {
        $this->getJson('/api/reports/sales-performance')->assertStatus(401);
        $this->getJson('/api/reports/user-performance')->assertStatus(401);
        $this->getJson('/api/reports/source-analysis')->assertStatus(401);
        $this->getJson('/api/reports/conversion')->assertStatus(401);
        $this->getJson('/api/reports/export?report=conversion&format=csv')->assertStatus(401);
    }

    public function test_user_without_reports_view_permission_is_forbidden(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/reports/sales-performance')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/reports/user-performance')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/reports/source-analysis')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/reports/conversion')->assertStatus(403);
    }

    public function test_reports_view_permission_does_not_grant_export(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        $this->actingAs($actor)
            ->getJson('/api/reports/export?report=conversion&format=csv')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Tarih / parametre doğrulama
    // -------------------------------------------------------------------

    public function test_from_after_to_is_rejected_with_422(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2026-06-30&to=2026-06-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    public function test_invalid_date_format_is_rejected_with_422(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        $this->actingAs($actor)
            ->getJson('/api/reports/conversion?from=30-06-2026&to=2026-06-30')
            ->assertStatus(422);
    }

    public function test_invalid_group_by_is_rejected_with_422(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2026-06-01&to=2026-06-30&group_by=year')
            ->assertStatus(422);
    }

    public function test_invalid_export_report_slug_is_rejected_with_422(): void
    {
        $actor = $this->actorWithPermissions(['reports.export']);

        $this->actingAs($actor)
            ->getJson('/api/reports/export?report=nonsense&format=csv')
            ->assertStatus(422);
    }

    public function test_invalid_export_format_is_rejected_with_422(): void
    {
        $actor = $this->actorWithPermissions(['reports.export']);

        $this->actingAs($actor)
            ->getJson('/api/reports/export?report=conversion&format=pdf')
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Boş sonuç → 200 (404 DEĞİL)
    // -------------------------------------------------------------------

    public function test_empty_result_returns_200_with_empty_data(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2020-01-01&to=2020-01-31')
            ->assertOk()
            ->assertJsonPath('data.data', [])
            ->assertJsonPath('data.totals.revenue', '0.00')
            ->assertJsonPath('data.totals.won_count', 0);
    }

    // -------------------------------------------------------------------
    // sales-performance
    // -------------------------------------------------------------------

    public function test_sales_performance_aggregates_won_and_lost_deals_by_month(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);
        $stage = $this->openStage();

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id,
            'amount' => 10000,
            'closed_at' => '2026-06-05 10:00:00',
        ]);
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id,
            'amount' => 5000.50,
            'closed_at' => '2026-06-20 10:00:00',
        ]);
        Deal::factory()->lost()->create([
            'pipeline_stage_id' => $stage->id,
            'amount' => 2000,
            'closed_at' => '2026-06-10 10:00:00',
        ]);
        // Aralık dışı — sayılmamalı.
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id,
            'amount' => 999999,
            'closed_at' => '2026-07-01 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2026-06-01&to=2026-06-30&group_by=month')
            ->assertOk();

        $response->assertJsonPath('data.group_by', 'month');
        $response->assertJsonPath('data.data.0.period', '2026-06');
        $response->assertJsonPath('data.data.0.revenue', '15000.50');
        $response->assertJsonPath('data.data.0.won_count', 2);
        $response->assertJsonPath('data.data.0.lost_count', 1);
        $response->assertJsonPath('data.data.0.deals_count', 3);
        $response->assertJsonPath('data.totals.revenue', '15000.50');
        $response->assertJsonPath('data.totals.won_count', 2);
        $response->assertJsonPath('data.totals.lost_count', 1);
    }

    public function test_sales_performance_user_id_filter_isolates_a_single_owner(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);
        $stage = $this->openStage();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'owner_id' => $ownerA->id,
            'amount' => 1000, 'closed_at' => '2026-06-05 10:00:00',
        ]);
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'owner_id' => $ownerB->id,
            'amount' => 4000, 'closed_at' => '2026-06-06 10:00:00',
        ]);

        $this->actingAs($actor)
            ->getJson("/api/reports/sales-performance?from=2026-06-01&to=2026-06-30&user_id={$ownerA->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.revenue', '1000.00')
            ->assertJsonPath('data.totals.won_count', 1);
    }

    // -------------------------------------------------------------------
    // user-performance
    // -------------------------------------------------------------------

    public function test_user_performance_ranks_owners_by_revenue_descending(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);
        $stage = $this->openStage();
        $top = User::factory()->create(['name' => 'Zeynep Top']);
        $second = User::factory()->create(['name' => 'Ahmet İkinci']);

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'owner_id' => $top->id,
            'amount' => 50000, 'closed_at' => '2026-06-05 10:00:00',
        ]);
        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'owner_id' => $second->id,
            'amount' => 10000, 'closed_at' => '2026-06-05 10:00:00',
        ]);
        Deal::factory()->lost()->create([
            'pipeline_stage_id' => $stage->id, 'owner_id' => $second->id,
            'amount' => 3000, 'closed_at' => '2026-06-06 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/reports/user-performance?from=2026-06-01&to=2026-06-30')
            ->assertOk();

        $response->assertJsonPath('data.data.0.user_id', $top->id);
        $response->assertJsonPath('data.data.0.revenue', '50000.00');
        $response->assertJsonPath('data.data.1.user_id', $second->id);
        $response->assertJsonPath('data.data.1.revenue', '10000.00');
        $response->assertJsonPath('data.data.1.lost_count', 1);
        $response->assertJsonPath('data.data.1.conversion_rate', 50.0);
    }

    // -------------------------------------------------------------------
    // source-analysis
    // -------------------------------------------------------------------

    public function test_source_analysis_attributes_won_revenue_to_the_originating_lead_source(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);
        $stage = $this->openStage();

        $wonDeal = Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id,
            'amount' => 7000,
            'closed_at' => '2026-06-10 10:00:00',
        ]);

        Lead::factory()->converted()->create([
            'source' => 'web',
            'created_at' => '2026-06-01 09:00:00',
            'converted_at' => '2026-06-03 09:00:00',
            'converted_deal_id' => $wonDeal->id,
        ]);
        Lead::factory()->newLead()->create([
            'source' => 'web',
            'created_at' => '2026-06-02 09:00:00',
        ]);
        Lead::factory()->newLead()->create([
            'source' => 'referral',
            'created_at' => '2026-06-04 09:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/reports/source-analysis?from=2026-06-01&to=2026-06-30')
            ->assertOk();

        $data = $response->json('data.data');
        $bySource = collect($data)->keyBy('source');

        $this->assertSame(2, $bySource['web']['leads_count']);
        $this->assertSame(1, $bySource['web']['converted_count']);
        $this->assertSame(50.0, $bySource['web']['conversion_rate']);
        $this->assertSame('7000.00', $bySource['web']['revenue']);
        $this->assertSame(1, $bySource['referral']['leads_count']);
        $this->assertSame('0.00', $bySource['referral']['revenue']);
    }

    // -------------------------------------------------------------------
    // conversion
    // -------------------------------------------------------------------

    public function test_conversion_report_computes_status_breakdown_and_average_days(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        Lead::factory()->converted()->create([
            'created_at' => '2026-06-01 08:00:00',
            'converted_at' => '2026-06-05 08:00:00',
        ]);
        Lead::factory()->converted()->create([
            'created_at' => '2026-06-02 08:00:00',
            'converted_at' => '2026-06-04 08:00:00',
        ]);
        Lead::factory()->newLead()->create(['created_at' => '2026-06-03 08:00:00']);
        Lead::factory()->create(['status' => 'qualified', 'created_at' => '2026-06-06 08:00:00']);

        $response = $this->actingAs($actor)
            ->getJson('/api/reports/conversion?from=2026-06-01&to=2026-06-30')
            ->assertOk();

        $response->assertJsonPath('data.total_leads', 4);
        $response->assertJsonPath('data.converted_count', 2);
        $response->assertJsonPath('data.conversion_rate', 50.0);
        $response->assertJsonPath('data.avg_days_to_convert', 3.0);
        $response->assertJsonPath('data.by_status.new', 1);
        $response->assertJsonPath('data.by_status.qualified', 1);
        $response->assertJsonPath('data.by_status.converted', 2);
    }

    // -------------------------------------------------------------------
    // export
    // -------------------------------------------------------------------

    public function test_export_csv_streams_utf8_bom_and_headings(): void
    {
        $actor = $this->actorWithPermissions(['reports.export']);
        $stage = $this->openStage();

        Deal::factory()->won()->create([
            'pipeline_stage_id' => $stage->id, 'amount' => 1234.56, 'closed_at' => '2026-06-05 10:00:00',
        ]);

        $response = $this->actingAs($actor)
            ->get('/api/reports/export?report=sales-performance&format=csv&from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Dönem', mb_convert_encoding($content, 'UTF-8', 'UTF-8'));
    }

    public function test_export_xlsx_returns_a_binary_spreadsheet(): void
    {
        $actor = $this->actorWithPermissions(['reports.export']);

        $response = $this->actingAs($actor)
            ->get('/api/reports/export?report=conversion&format=xlsx&from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
