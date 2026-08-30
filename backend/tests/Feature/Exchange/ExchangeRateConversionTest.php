<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use App\Services\Exchange\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Faz 14 / İz E — `ExchangeRateService`'in dönüşüm ucu
 * (`toBase` / `fromBase` / `convert` / `resolveForFreeze`).
 *
 * Bu dosyanın asıl konusu ARİTMETİK DİSİPLİNİDİR (docs/QUOTE-FINANCIALS.md):
 * hesap bcmath ile decimal STRING üzerinde yapılır, float'a hiç dönülmez ve
 * yuvarlama half-up'tır. `Unit=100` bölme doğruluğu TcmbRateFetcherTest'te
 * ölçülür, burada TEKRARLANMAZ.
 */
class ExchangeRateConversionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    private function rate(string $currency, string $rate, ?string $date = null): ExchangeRate
    {
        return ExchangeRate::factory()->create([
            'currency' => $currency,
            'rate' => $rate,
            'rate_date' => $date ?? today()->toDateString(),
        ]);
    }

    public function test_base_currency_conversion_is_an_identity(): void
    {
        $this->assertSame('1234.56', $this->service()->toBase('1234.56', 'TRY'));
        $this->assertSame('1234.56', $this->service()->convert('1234.56', 'TRY', 'TRY'));
    }

    public function test_to_base_multiplies_by_the_stored_rate(): void
    {
        $this->rate('USD', '41.250000');

        $this->assertSame('41250.00', $this->service()->toBase('1000.00', 'USD'));
        $this->assertSame('412.50', $this->service()->toBase('10', 'USD'));
    }

    public function test_from_base_divides_by_the_stored_rate(): void
    {
        $this->rate('USD', '40.000000');

        $this->assertSame('25.00', $this->service()->fromBase('1000.00', 'USD'));
    }

    public function test_cross_currency_conversion_goes_through_the_base_currency(): void
    {
        $this->rate('USD', '40.000000');
        $this->rate('EUR', '50.000000');

        // 100 USD = 4.000 TRY = 80 EUR
        $this->assertSame('80.00', $this->service()->convert('100.00', 'USD', 'EUR'));
    }

    public function test_conversion_returns_null_when_a_required_rate_is_missing(): void
    {
        $this->rate('USD', '40.000000');

        $this->assertNull($this->service()->toBase('100.00', 'GBP'));
        $this->assertNull($this->service()->convert('100.00', 'USD', 'GBP'));
        $this->assertNull($this->service()->fromBase('100.00', 'GBP'));
    }

    public function test_conversion_uses_the_rate_valid_on_a_given_date(): void
    {
        $this->rate('USD', '30.000000', '2026-01-10');
        $this->rate('USD', '45.000000', '2026-06-10');

        $service = $this->service();

        $this->assertSame('3000.00', $service->toBase('100', 'USD', CarbonImmutable::parse('2026-02-01')));
        $this->assertSame('4500.00', $service->toBase('100', 'USD', CarbonImmutable::parse('2026-07-01')));
        // Tarihten ÖNCE hiç satır yoksa dönüşüm yapılamaz (uydurma kur yok).
        $this->assertNull($service->toBase('100', 'USD', CarbonImmutable::parse('2025-12-31')));
    }

    // -------------------------------------------------------------------
    // Yuvarlama ve float yasağı
    // -------------------------------------------------------------------

    public function test_rounding_is_half_up_at_the_half_cent(): void
    {
        // 1 × 0.005 = 0.005 → half-up → 0.01 (banker's rounding 0.00 verirdi)
        $this->rate('USD', '0.005000');

        $this->assertSame('0.01', $this->service()->toBase('1', 'USD'));
    }

    public function test_negative_amounts_round_half_up_away_from_zero(): void
    {
        $this->rate('USD', '0.005000');

        $this->assertSame('-0.01', $this->service()->toBase('-1', 'USD'));
    }

    /**
     * FLOAT YASAĞI: aşağıdaki tutar IEEE-754 double hassasiyetinin ötesinde
     * anlamlı basamak taşır. Hesap bir noktada `(float)`'a dönseydi son
     * kuruşlar sessizce kayar ve bu iddia düşerdi.
     */
    public function test_large_amounts_survive_without_float_precision_loss(): void
    {
        $this->rate('USD', '1.000000');

        $this->assertSame('12345678901234.57', $this->service()->toBase('12345678901234.57', 'USD'));
        $this->assertSame('12345678901234.57', $this->service()->convert('12345678901234.57', 'TRY', 'TRY'));
    }

    public function test_six_decimal_rates_are_applied_without_truncation(): void
    {
        $this->rate('USD', '33.333333');

        // 3 × 33.333333 = 99.999999 → 2 hane half-up = 100.00
        $this->assertSame('100.00', $this->service()->toBase('3', 'USD'));
        // 0.01 × 33.333333 = 0.33333333 → 0.33
        $this->assertSame('0.33', $this->service()->toBase('0.01', 'USD'));
    }

    public function test_a_non_numeric_amount_is_rejected_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->toBase('bir milyon', 'USD');
    }

    // -------------------------------------------------------------------
    // Donma (freeze) kur çözümü
    // -------------------------------------------------------------------

    public function test_resolve_for_freeze_prefers_the_rate_valid_on_the_day(): void
    {
        $this->rate('USD', '30.000000', '2026-01-10');
        $this->rate('USD', '45.000000', '2026-06-10');

        $resolved = $this->service()->resolveForFreeze('USD', CarbonImmutable::parse('2026-02-01'));

        $this->assertNotNull($resolved);
        $this->assertSame('30.000000', (string) $resolved->rate);
    }

    public function test_resolve_for_freeze_falls_back_to_the_latest_known_rate(): void
    {
        $this->rate('USD', '45.000000', '2026-06-10');

        $resolved = $this->service()->resolveForFreeze('USD', CarbonImmutable::parse('2026-01-01'));

        $this->assertNotNull($resolved);
        $this->assertSame('45.000000', (string) $resolved->rate);
        $this->assertSame('2026-06-10', $resolved->rate_date->toDateString());
    }

    public function test_resolve_for_freeze_returns_null_for_base_currency_and_for_unknown_rates(): void
    {
        $service = $this->service();

        // TRY'nin satırı YOKTUR (örtük 1.000000) — çağıran isBaseCurrency() sorar.
        $this->assertNull($service->resolveForFreeze('TRY', CarbonImmutable::now()));
        $this->assertNull($service->resolveForFreeze('GBP', CarbonImmutable::now()));
    }
}
