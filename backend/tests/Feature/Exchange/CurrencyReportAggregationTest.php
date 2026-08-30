<?php

namespace Tests\Feature\Exchange;

use App\Models\Deal;
use App\Models\ExchangeRate;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faz 14 / İz E — çoklu para birimi altında rapor/dashboard toplama
 * (docs/PHASE-INTL.md §2.4, "en ince nokta").
 *
 * Üç iddia sınanır:
 *   1. KAPANMIŞ fırsat donmuş `base_amount`'tan toplanır → kur sonradan
 *      değişse bile DÜNKÜ RAPOR BUGÜN AYNI RAKAMI verir (kararlılık).
 *   2. AÇIK fırsat GÜNCEL kurla, para birimi kovaları hâlinde çevrilir;
 *      karışık (TRY+USD+EUR) bir kümede toplam doğrudur.
 *   3. Yanıt, kullandığı kuru ve tarihini `rate_info` ile TAŞIR; kur
 *      bulunamayan kayıtlar sessizce kaybolmaz, sayıları görünür.
 *
 * Tarihler DAİMA sabit `from`/`to` ile verilir (ReportApiTest ile aynı
 * gerekçe: varsayılan pencere "bugün"e bağlı olurdu).
 */
class CurrencyReportAggregationTest extends TestCase
{
    use RefreshDatabase;

    private PipelineStage $openStage;

    private PipelineStage $wonStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->openStage = PipelineStage::factory()->create(['slug' => 'yeni-firsat', 'position' => 1]);
        $this->wonStage = PipelineStage::factory()->won()->create(['slug' => 'kazanildi', 'position' => 2]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actor(array $permissions = ['reports.view', 'dashboard.view']): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * Donmuş değerleri AÇIKÇA yazan kapanmış fırsat — kapanış anının
     * kuru testin girdisidir, fabrikanın rastgele seçimi değil.
     */
    private function closedDeal(string $amount, string $currency, string $baseAmount, string $rate, string $closedAt): Deal
    {
        return Deal::factory()->create([
            'pipeline_stage_id' => $this->wonStage->getKey(),
            'status' => 'won',
            'closed_at' => $closedAt,
            'won_reason' => 'Fiyat uygundu',
            'amount' => $amount,
            'currency' => $currency,
            'base_amount' => $baseAmount,
            'base_rate' => $rate,
            'base_rate_date' => substr($closedAt, 0, 10),
        ]);
    }

    private function openDeal(string $amount, string $currency, string $createdAt): Deal
    {
        return Deal::factory()->open()->create([
            'pipeline_stage_id' => $this->openStage->getKey(),
            'amount' => $amount,
            'currency' => $currency,
            'created_at' => $createdAt,
        ]);
    }

    // -------------------------------------------------------------------
    // 1. Kararlılık — donmuş tutar, güncel kurdan BAĞIMSIZ
    // -------------------------------------------------------------------

    public function test_closed_deal_revenue_is_unchanged_when_the_rate_moves_afterwards(): void
    {
        $actor = $this->actor();

        // Ocak'ta 1000 USD, o günkü kur 30.00 → 30.000,00 TRY donmuş.
        $this->closedDeal('1000.00', 'USD', '30000.00', '30.000000', '2026-01-15 10:00:00');
        // Aynı dönemde 20.000,00 TRY'lik bir fırsat daha.
        $this->closedDeal('20000.00', 'TRY', '20000.00', '1.000000', '2026-01-20 10:00:00');

        $url = '/api/reports/sales-performance?from=2026-01-01&to=2026-01-31&group_by=month';

        $before = $this->actingAs($actor)->getJson($url)->assertOk()->json('data.totals.revenue');
        $this->assertSame('50000.00', $before);

        // Kur bugün üçe katlanıyor — tarihsel gelir DEĞİŞMEMELİ.
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '90.000000', 'rate_date' => today()->toDateString(),
        ]);

        $after = $this->actingAs($actor)->getJson($url)->assertOk()->json('data.totals.revenue');

        $this->assertSame($before, $after);
        $this->assertSame('50000.00', $after);
    }

    public function test_revenue_trend_and_dashboard_kpis_use_the_same_frozen_figure(): void
    {
        $actor = $this->actor();

        $this->closedDeal('1000.00', 'EUR', '45000.00', '45.000000', '2026-03-10 09:00:00');

        ExchangeRate::factory()->create([
            'currency' => 'EUR', 'rate' => '10.000000', 'rate_date' => today()->toDateString(),
        ]);

        $trend = $this->actingAs($actor)
            ->getJson('/api/dashboard/revenue-trend?from=2026-03-01&to=2026-03-31&group_by=month')
            ->assertOk()->json('data');

        $this->assertSame('45000.00', $trend[0]['revenue']);

        $kpis = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2026-03-01&to=2026-03-31')
            ->assertOk()->json('data');

        $this->assertSame('45000.00', $kpis['revenue']['value']);
    }

    // -------------------------------------------------------------------
    // 2. Açık fırsat — GÜNCEL kur, karışık para birimi
    // -------------------------------------------------------------------

    public function test_open_deals_in_mixed_currencies_are_converted_with_the_current_rate(): void
    {
        $actor = $this->actor();

        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '40.000000', 'rate_date' => today()->toDateString(),
        ]);
        ExchangeRate::factory()->create([
            'currency' => 'EUR', 'rate' => '45.000000', 'rate_date' => today()->toDateString(),
        ]);

        $this->openDeal('10000.00', 'TRY', '2026-05-02 10:00:00');
        $this->openDeal('1000.00', 'USD', '2026-05-03 10:00:00');   // 40.000,00
        $this->openDeal('200.00', 'EUR', '2026-05-04 10:00:00');    // 9.000,00

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2026-05-01&to=2026-05-31')
            ->assertOk();

        // 10.000 + 40.000 + 9.000 = 59.000,00
        $this->assertSame('59000.00', $response->json('data.open_deals_value.value'));
        $this->assertSame(3, $response->json('data.open_deals_count.value'));

        $rateInfo = $response->json('rate_info');
        $this->assertSame('TRY', $rateInfo['display_currency']);
        $this->assertSame('current_rate', $rateInfo['open_basis']);
        $this->assertSame(today()->toDateString(), $rateInfo['as_of']);
        $this->assertFalse($rateInfo['is_stale']);

        $buckets = collect($rateInfo['converted_buckets'])->keyBy('currency');
        $this->assertSame('1000.00', $buckets['USD']['amount']);
        $this->assertSame('40.000000', $buckets['USD']['rate']);
        $this->assertSame('40000.00', $buckets['USD']['converted']);
        $this->assertSame('9000.00', $buckets['EUR']['converted']);
        // TRY zaten görüntü para birimi — kova AÇILMAZ (dönüşüm yapılmadı).
        $this->assertFalse($buckets->has('TRY'));
    }

    public function test_open_deal_bucket_without_a_rate_is_reported_as_unconverted_not_silently_zero(): void
    {
        $actor = $this->actor();

        $this->openDeal('5000.00', 'TRY', '2026-05-02 10:00:00');
        $this->openDeal('750.00', 'GBP', '2026-05-03 10:00:00'); // GBP kuru YOK

        $response = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2026-05-01&to=2026-05-31')
            ->assertOk();

        $this->assertSame('5000.00', $response->json('data.open_deals_value.value'));

        $unconverted = $response->json('rate_info.unconverted_open');
        $this->assertSame([['currency' => 'GBP', 'amount' => '750.00']], $unconverted);
    }

    public function test_closed_deal_without_a_frozen_amount_is_counted_in_rate_info(): void
    {
        $actor = $this->actor();

        $this->closedDeal('1000.00', 'TRY', '1000.00', '1.000000', '2026-02-10 09:00:00');

        // Kapanış anında kur yoktu → base_amount null.
        Deal::factory()->create([
            'pipeline_stage_id' => $this->wonStage->getKey(),
            'status' => 'won',
            'closed_at' => '2026-02-11 09:00:00',
            'won_reason' => 'Referans',
            'amount' => '900.00',
            'currency' => 'GBP',
            'base_amount' => null,
            'base_rate' => null,
            'base_rate_date' => null,
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/reports/sales-performance?from=2026-02-01&to=2026-02-28&group_by=month')
            ->assertOk();

        $this->assertSame('1000.00', $response->json('data.totals.revenue'));
        $this->assertSame(2, $response->json('data.totals.won_count'));
        $this->assertSame(1, $response->json('rate_info.unconverted_closed_count'));
    }

    // -------------------------------------------------------------------
    // 3. Görüntü para birimi tercihi
    // -------------------------------------------------------------------

    public function test_display_currency_follows_the_requesting_users_preference(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '40.000000', 'rate_date' => today()->toDateString(),
        ]);

        $tryUser = $this->actor();
        $usdUser = $this->actor();
        $usdUser->forceFill(['preferred_currency' => 'USD'])->save();

        // 80.000,00 TRY donmuş gelir.
        $this->closedDeal('80000.00', 'TRY', '80000.00', '1.000000', '2026-04-10 09:00:00');

        $url = '/api/reports/sales-performance?from=2026-04-01&to=2026-04-30&group_by=month';

        $tryResponse = $this->actingAs($tryUser)->getJson($url)->assertOk();
        $this->assertSame('80000.00', $tryResponse->json('data.totals.revenue'));
        $this->assertSame('TRY', $tryResponse->json('rate_info.display_currency'));
        // Hedef = temel para birimi → hiçbir dönüşüm yok, rakam KARARLI.
        $this->assertSame('frozen_base', $tryResponse->json('rate_info.closed_basis'));

        $usdResponse = $this->actingAs($usdUser)->getJson($url)->assertOk();
        // 80.000 TRY / 40 = 2.000,00 USD
        $this->assertSame('2000.00', $usdResponse->json('data.totals.revenue'));
        $this->assertSame('USD', $usdResponse->json('rate_info.display_currency'));
        // Donmuş TRY tutarı GÖSTERİM için çevrildi — bu ayrım açıkça bildirilir.
        $this->assertSame('frozen_base_converted', $usdResponse->json('rate_info.closed_basis'));
    }

    public function test_an_unsupported_preferred_currency_falls_back_to_the_base_currency(): void
    {
        $user = $this->actor();
        // Doğrudan DB'ye yazılmış geçersiz bir tercih raporu KIRMAMALI.
        $user->forceFill(['preferred_currency' => 'JPY'])->save();

        $this->closedDeal('1000.00', 'TRY', '1000.00', '1.000000', '2026-04-10 09:00:00');

        $response = $this->actingAs($user)
            ->getJson('/api/reports/sales-performance?from=2026-04-01&to=2026-04-30&group_by=month')
            ->assertOk();

        $this->assertSame('TRY', $response->json('rate_info.display_currency'));
        $this->assertSame('1000.00', $response->json('data.totals.revenue'));
    }

    // -------------------------------------------------------------------
    // Bayatlık görünürlüğü (§2.6)
    // -------------------------------------------------------------------

    public function test_a_stale_rate_is_flagged_in_rate_info(): void
    {
        $actor = $this->actor();

        ExchangeRate::factory()->create([
            'currency' => 'USD',
            'rate' => '40.000000',
            'rate_date' => today()->subDays(9)->toDateString(),
        ]);

        $this->openDeal('100.00', 'USD', '2026-05-03 10:00:00');

        $rateInfo = $this->actingAs($actor)
            ->getJson('/api/dashboard/kpis?from=2026-05-01&to=2026-05-31')
            ->assertOk()->json('rate_info');

        $this->assertTrue($rateInfo['is_stale']);
        $this->assertSame(9, $rateInfo['days_stale']);
        $this->assertSame(today()->subDays(9)->toDateString(), $rateInfo['as_of']);
    }

    // -------------------------------------------------------------------
    // Diğer uçlar aynı sözleşmeyi taşır
    // -------------------------------------------------------------------

    public function test_user_and_source_reports_expose_rate_info(): void
    {
        $actor = $this->actor();

        $this->actingAs($actor)->getJson('/api/reports/user-performance?from=2026-04-01&to=2026-04-30')
            ->assertOk()->assertJsonPath('rate_info.display_currency', 'TRY');

        $this->actingAs($actor)->getJson('/api/reports/source-analysis?from=2026-04-01&to=2026-04-30')
            ->assertOk()->assertJsonPath('rate_info.base_currency', 'TRY');

        $this->actingAs($actor)->getJson('/api/dashboard/funnel?from=2026-04-01&to=2026-04-30')
            ->assertOk()->assertJsonPath('rate_info.open_basis', 'current_rate');
    }

    public function test_user_performance_converts_open_deals_per_owner_currency_bucket(): void
    {
        $actor = $this->actor();
        $owner = User::factory()->create();

        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '40.000000', 'rate_date' => today()->toDateString(),
        ]);

        $this->openDeal('1000.00', 'TRY', '2026-06-02 10:00:00')->update(['owner_id' => $owner->id]);
        $this->openDeal('100.00', 'USD', '2026-06-03 10:00:00')->update(['owner_id' => $owner->id]);

        $rows = $this->actingAs($actor)
            ->getJson('/api/reports/user-performance?from=2026-06-01&to=2026-06-30')
            ->assertOk()->json('data.data');

        $row = collect($rows)->firstWhere('user_id', $owner->id);

        $this->assertSame(2, $row['open_deals_count']);
        // 1.000 + (100 × 40) = 5.000,00
        $this->assertSame('5000.00', $row['open_deals_value']);
    }

    public function test_csv_export_carries_the_rate_footnote(): void
    {
        $actor = $this->actor(['reports.view', 'reports.export']);

        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '40.000000', 'rate_date' => today()->toDateString(),
        ]);

        $this->closedDeal('1000.00', 'USD', '30000.00', '30.000000', '2026-07-10 09:00:00');

        $response = $this->actingAs($actor)
            ->get('/api/reports/export?report=sales-performance&format=csv&from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Görüntü para birimi', $csv);
        $this->assertStringContainsString('TRY', $csv);
        // Ekrandaki rakamla AYNI donmuş tutar.
        $this->assertStringContainsString('30000.00', $csv);
    }
}
