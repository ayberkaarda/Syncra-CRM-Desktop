<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Exchange\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ExchangeRateService` — bayatlık, temel para birimi kısayolu ve manuel
 * kur girişi (PHASE-INTL §2.3, §2.6). Sunucu tarafı doğrulama ve depolama
 * bu testte doğrulanır; UI göstergesi/uç noktası başka şeridindir.
 */
class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    public function test_try_is_base_currency_and_has_no_stored_row(): void
    {
        $this->assertTrue($this->service()->isBaseCurrency('TRY'));
        $this->assertTrue($this->service()->isBaseCurrency('try'));
        $this->assertNull($this->service()->latest('TRY'));
    }

    public function test_latest_returns_most_recent_rate_for_currency(): void
    {
        ExchangeRate::factory()->forCurrency('USD', '32.000000')->onDate(CarbonImmutable::parse('2026-08-20'))->create();
        ExchangeRate::factory()->forCurrency('USD', '32.500000')->onDate(CarbonImmutable::parse('2026-08-24'))->create();
        ExchangeRate::factory()->forCurrency('USD', '32.250000')->onDate(CarbonImmutable::parse('2026-08-22'))->create();

        $latest = $this->service()->latest('USD');

        $this->assertNotNull($latest);
        $this->assertSame('2026-08-24', $latest->rate_date->toDateString());
        $this->assertSame('32.500000', (string) $latest->rate);
    }

    public function test_as_of_returns_rate_valid_on_or_before_given_date(): void
    {
        ExchangeRate::factory()->forCurrency('USD', '32.000000')->onDate(CarbonImmutable::parse('2026-08-21'))->create();
        ExchangeRate::factory()->forCurrency('USD', '33.000000')->onDate(CarbonImmutable::parse('2026-08-24'))->create();

        // 23'ünde (hafta sonu) sorulursa hâlâ 21'in (Cuma) kuru geçerlidir.
        $rate = $this->service()->asOf('USD', CarbonImmutable::parse('2026-08-23'));

        $this->assertNotNull($rate);
        $this->assertSame('2026-08-21', $rate->rate_date->toDateString());
    }

    public function test_rate_exactly_four_days_old_is_not_stale(): void
    {
        $rate = ExchangeRate::factory()->forCurrency('USD', '32.000000')->onDate(CarbonImmutable::parse('2026-08-20'))->create();

        $this->assertFalse($this->service()->isStale($rate, CarbonImmutable::parse('2026-08-24')));
        $this->assertSame(4, $this->service()->daysStale($rate, CarbonImmutable::parse('2026-08-24')));
    }

    public function test_rate_older_than_four_days_is_stale(): void
    {
        $rate = ExchangeRate::factory()->forCurrency('USD', '32.000000')->onDate(CarbonImmutable::parse('2026-08-19'))->create();

        $this->assertTrue($this->service()->isStale($rate, CarbonImmutable::parse('2026-08-24')));
        $this->assertSame(5, $this->service()->daysStale($rate, CarbonImmutable::parse('2026-08-24')));
    }

    public function test_manual_entry_stores_positive_decimal_with_entered_by(): void
    {
        $user = User::factory()->create();

        $rate = $this->service()->storeManualRate('usd', '33.750000', CarbonImmutable::parse('2026-08-24'), $user->id);

        $this->assertSame('USD', $rate->currency);
        $this->assertSame('33.750000', (string) $rate->rate);
        $this->assertSame('manual', $rate->source);
        $this->assertSame($user->id, $rate->entered_by);
    }

    public function test_manual_entry_rejects_non_positive_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->storeManualRate('USD', '0', CarbonImmutable::today(), null);
    }

    public function test_manual_entry_rejects_negative_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->storeManualRate('USD', '-5.00', CarbonImmutable::today(), null);
    }

    public function test_manual_entry_rejects_unreasonably_large_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->storeManualRate('USD', '999999999.000000', CarbonImmutable::today(), null);
    }

    public function test_manual_entry_rejects_unsupported_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->storeManualRate('JPY', '1.500000', CarbonImmutable::today(), null);
    }

    public function test_manual_entry_rules_are_exposed_for_validation(): void
    {
        $rules = $this->service()->manualEntryRules();

        $this->assertArrayHasKey('currency', $rules);
        $this->assertArrayHasKey('rate', $rules);
        $this->assertArrayHasKey('rate_date', $rules);
    }
}
