<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Herkese açık güncel kur ucu (`GET /api/exchange-rates/current`,
 * docs/PHASE-INTL.md §2 Karar B) — yalnız `auth:sanctum`, EK İZİN YOK.
 *
 * Asıl mesele bu testte: `ExchangeRateSettingsTest`in aksine, `settings.manage`
 * İZNİ OLMAYAN sıradan bir kullanıcı da 200 almalı — bu uç bir yönetici
 * yüzeyi DEĞİL, kişisel görüntüleme yüzeyidir. `/api/settings/exchange-rates`
 * yönetim ucunun kendi yetki testleri `ExchangeRateSettingsTest`te değişmeden
 * kalır (bu dosya onu tekrarlamaz).
 */
class ExchangeRateCurrentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function ordinaryUser(): User
    {
        // Bilerek `settings.manage` YOK — bu ucun tüm amacı bu kullanıcının da
        // 200 alabildiğini kanıtlamak.
        $user = User::factory()->create();
        $user->givePermissionTo(['deals.view']);

        return $user;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/exchange-rates/current')->assertStatus(401);
    }

    public function test_a_user_without_settings_manage_permission_gets_200(): void
    {
        $user = $this->ordinaryUser();

        ExchangeRate::factory()->forCurrency('USD', '34.123400')
            ->onDate(CarbonImmutable::today())->create();

        $this->actingAs($user)->getJson('/api/exchange-rates/current')->assertStatus(200);
    }

    public function test_response_contract_matches_the_fixed_shape(): void
    {
        $today = CarbonImmutable::today();

        ExchangeRate::factory()->forCurrency('USD', '34.123400')->onDate($today)->create();
        ExchangeRate::factory()->forCurrency('GBP', '43.500000')->onDate($today)->create();

        $response = $this->actingAs($this->ordinaryUser())->getJson('/api/exchange-rates/current');

        $response->assertStatus(200)
            ->assertJsonPath('base_currency', 'TRY')
            ->assertJsonPath('as_of', $today->toDateString())
            ->assertJsonPath('is_stale', false)
            ->assertJsonPath('days_stale', 0)
            ->assertJsonCount(3, 'rates');

        $byCurrency = collect($response->json('rates'))->keyBy('currency');

        $this->assertSame('34.123400', $byCurrency['USD']['rate']);
        $this->assertSame($today->toDateString(), $byCurrency['USD']['rate_date']);
        $this->assertFalse($byCurrency['USD']['is_stale']);
        $this->assertSame(0, $byCurrency['USD']['days_stale']);

        // TRY temel para birimi olarak listede YOK.
        $this->assertFalse($byCurrency->has('TRY'));
    }

    public function test_a_currency_without_any_stored_rate_returns_null_rate_not_omitted(): void
    {
        ExchangeRate::factory()->forCurrency('USD', '34.123400')
            ->onDate(CarbonImmutable::today())->create();
        // EUR/GBP hiç girilmedi.

        $response = $this->actingAs($this->ordinaryUser())->getJson('/api/exchange-rates/current');

        $response->assertStatus(200)->assertJsonCount(3, 'rates');

        $byCurrency = collect($response->json('rates'))->keyBy('currency');

        $this->assertNull($byCurrency['EUR']['rate']);
        $this->assertNull($byCurrency['EUR']['rate_date']);
        $this->assertFalse($byCurrency['EUR']['is_stale']);
        $this->assertSame(0, $byCurrency['EUR']['days_stale']);

        $this->assertNull($byCurrency['GBP']['rate']);
    }

    public function test_as_of_is_null_when_no_rate_exists_at_all(): void
    {
        $response = $this->actingAs($this->ordinaryUser())->getJson('/api/exchange-rates/current');

        $response->assertStatus(200)
            ->assertJsonPath('as_of', null)
            ->assertJsonPath('is_stale', false)
            ->assertJsonPath('days_stale', 0);

        $byCurrency = collect($response->json('rates'))->keyBy('currency');
        $this->assertNull($byCurrency['USD']['rate']);
        $this->assertNull($byCurrency['EUR']['rate']);
        $this->assertNull($byCurrency['GBP']['rate']);
    }

    public function test_as_of_uses_the_oldest_rate_date_and_top_level_staleness_reflects_it(): void
    {
        $today = CarbonImmutable::today();

        // USD taze (bugün), EUR bayat (5 gün önce) — en eski tarih EUR'unki olmalı.
        ExchangeRate::factory()->forCurrency('USD', '34.000000')->onDate($today)->create();
        ExchangeRate::factory()->forCurrency('EUR', '37.000000')->onDate($today->subDays(5))->create();

        $response = $this->actingAs($this->ordinaryUser())->getJson('/api/exchange-rates/current');

        $response->assertStatus(200)
            ->assertJsonPath('as_of', $today->subDays(5)->toDateString())
            ->assertJsonPath('is_stale', true)
            ->assertJsonPath('days_stale', 5);

        $byCurrency = collect($response->json('rates'))->keyBy('currency');
        $this->assertFalse($byCurrency['USD']['is_stale']);
        $this->assertTrue($byCurrency['EUR']['is_stale']);
    }
}
