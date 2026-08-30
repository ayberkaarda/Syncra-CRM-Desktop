<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5/F4 — `DateRangeResolver` azami aralık (MAX_WINDOW_DAYS=366) regresyon
 * kilidi.
 *
 * `?from=2000-01-01&to=2026-12-31` gibi sınırsız bir aralık sınırsız
 * agregasyon sorgusu tetikliyordu (DB/CPU maliyet yüzeyi). Bu sınıf hem
 * `/api/reports/*` hem `/api/dashboard/*` tarafından paylaşıldığı için
 * (bkz. App\Services\Reports\Support\DateRangeResolver) her iki uç ailesi
 * de burada doğrulanır.
 *
 * Off-by-one KASITLI test edilir: tam sınır (366 gün, artık yıl dahil bir
 * takvim yılı) GEÇMELİ, bir gün fazlası (367) 422 dönmeli.
 */
class ReportDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_report_endpoint_accepts_a_range_of_exactly_366_days(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        // 2024 artık yıl: 2024-01-01 -> 2024-12-31 tam 366 gün (uçlar dahil).
        $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2024-01-01&to=2024-12-31')
            ->assertStatus(200);
    }

    public function test_report_endpoint_rejects_a_range_of_367_days_with_422(): void
    {
        $actor = $this->actorWithPermissions(['reports.view']);

        // Bir gün fazlası: 2024-01-01 -> 2025-01-01 = 367 gün.
        $response = $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2024-01-01&to=2025-01-01');

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    public function test_dashboard_endpoint_accepts_a_range_of_exactly_366_days(): void
    {
        $actor = $this->actorWithPermissions(['dashboard.view']);

        $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2024-01-01&to=2024-12-31')
            ->assertStatus(200);
    }

    public function test_dashboard_endpoint_rejects_a_range_of_367_days_with_422(): void
    {
        $actor = $this->actorWithPermissions(['dashboard.view']);

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2024-01-01&to=2025-01-01');

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }
}
