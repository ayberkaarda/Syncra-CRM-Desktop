<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ayarlar > Kur ekranı (`/api/settings/exchange-rates*`, docs/PHASE-INTL.md
 * §2.1, §2.6) — `settings.manage`. Diğer Ayarlar controller'larıyla
 * (PipelineStageSettingsTest, CustomFieldSettingsTest) aynı yetki deseni.
 *
 * `ExchangeRateService`'in kendi mantığı (bayatlık hesabı, `storeManualRate`
 * doğrulaması) `ExchangeRateServiceTest`'te zaten test edildi — burada
 * yalnız HTTP katmanı (yetki, doğrulama zinciri, upsert) doğrulanır.
 */
class ExchangeRateSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['settings.manage']);

        return $user;
    }

    // -------------------------------------------------------------------
    // Yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/settings/exchange-rates')->assertStatus(401);
        $this->postJson('/api/settings/exchange-rates', [])->assertStatus(401);
    }

    public function test_a_user_without_settings_manage_is_forbidden(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(['deals.view']);

        $this->actingAs($actor)->getJson('/api/settings/exchange-rates')->assertStatus(403);
        $this->actingAs($actor)->postJson('/api/settings/exchange-rates', [
            'currency' => 'USD', 'rate' => '34.1234', 'rate_date' => CarbonImmutable::today()->toDateString(),
        ])->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Listeleme
    // -------------------------------------------------------------------

    public function test_index_lists_every_supported_currency_even_without_a_stored_rate(): void
    {
        ExchangeRate::factory()->forCurrency('USD', '34.123400')->onDate(CarbonImmutable::today())->create();

        $response = $this->actingAs($this->manager())->getJson('/api/settings/exchange-rates');

        $response->assertStatus(200)->assertJsonCount(3, 'data');

        $byCurrency = collect($response->json('data'))->keyBy('currency');

        $this->assertSame('34.123400', $byCurrency['USD']['rate']['rate']);
        $this->assertFalse($byCurrency['USD']['rate']['is_stale']);
        $this->assertNull($byCurrency['EUR']['rate']);
        $this->assertNull($byCurrency['GBP']['rate']);

        $response->assertJsonPath('meta.base_currency', 'TRY')
            ->assertJsonPath('meta.stale_threshold_days', 4);
    }

    public function test_a_stale_rate_is_flagged(): void
    {
        ExchangeRate::factory()->forCurrency('USD', '34.000000')
            ->onDate(CarbonImmutable::today()->subDays(5))->create();

        $response = $this->actingAs($this->manager())->getJson('/api/settings/exchange-rates');

        $byCurrency = collect($response->json('data'))->keyBy('currency');
        $this->assertTrue($byCurrency['USD']['rate']['is_stale']);
        $this->assertSame(5, $byCurrency['USD']['rate']['days_stale']);
    }

    // -------------------------------------------------------------------
    // Manuel giriş
    // -------------------------------------------------------------------

    public function test_a_manager_can_enter_a_manual_rate(): void
    {
        $manager = $this->manager();

        $response = $this->actingAs($manager)->postJson('/api/settings/exchange-rates', [
            'currency' => 'USD',
            'rate' => '34.1234',
            'rate_date' => CarbonImmutable::today()->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.entered_by', $manager->id);

        $this->assertDatabaseHas('exchange_rates', [
            'currency' => 'USD', 'source' => 'manual', 'entered_by' => $manager->id,
        ]);
    }

    public function test_negative_zero_and_oversized_rates_are_rejected(): void
    {
        $manager = $this->manager();
        $today = CarbonImmutable::today()->toDateString();

        foreach (['-1', '0', '999999999'] as $badRate) {
            $this->actingAs($manager)->postJson('/api/settings/exchange-rates', [
                'currency' => 'USD', 'rate' => $badRate, 'rate_date' => $today,
            ])->assertStatus(422);
        }
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        $this->actingAs($this->manager())->postJson('/api/settings/exchange-rates', [
            'currency' => 'JPY', 'rate' => '0.23', 'rate_date' => CarbonImmutable::today()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_future_rate_date_is_rejected(): void
    {
        $this->actingAs($this->manager())->postJson('/api/settings/exchange-rates', [
            'currency' => 'USD', 'rate' => '34.10', 'rate_date' => CarbonImmutable::tomorrow()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_second_entry_on_the_same_day_upserts_instead_of_duplicating(): void
    {
        $manager = $this->manager();
        $today = CarbonImmutable::today()->toDateString();

        $this->actingAs($manager)->postJson('/api/settings/exchange-rates', [
            'currency' => 'USD', 'rate' => '34.0000', 'rate_date' => $today,
        ])->assertStatus(201);

        $this->actingAs($manager)->postJson('/api/settings/exchange-rates', [
            'currency' => 'USD', 'rate' => '34.5000', 'rate_date' => $today,
        ])->assertStatus(201)->assertJsonPath('data.rate', '34.500000');

        $this->assertSame(1, ExchangeRate::query()->where('currency', 'USD')->where('rate_date', $today)->count());
    }
}
