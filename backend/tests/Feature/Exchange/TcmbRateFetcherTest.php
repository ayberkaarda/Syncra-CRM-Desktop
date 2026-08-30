<?php

namespace Tests\Feature\Exchange;

use App\Services\Exchange\TcmbRateFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Exchange\Concerns\BuildsTcmbXml;
use Tests\TestCase;

/**
 * `TcmbRateFetcher` XML ayrıştırma + `ForexBuying/Unit` bölme doğruluğu.
 * Gerçek TCMB'ye AĞ ÇAĞRISI YAPILMAZ — tüm istekler `Http::fake()` ile
 * kapalı devre çalışır.
 */
class TcmbRateFetcherTest extends TestCase
{
    use BuildsTcmbXml;
    use RefreshDatabase;

    private const TCMB_URL_PATTERN = 'https://www.tcmb.gov.tr/*';

    public function test_rate_is_forex_buying_divided_by_unit_when_unit_is_one(): void
    {
        $xml = $this->buildTcmbXml('25.08.2026', '08/25/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.123456'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertTrue($result->success);
        $this->assertSame('32.123456', $result->rates['USD']['rate']);
        $this->assertSame(1, $result->rates['USD']['unit']);
    }

    /**
     * `Unit=100` senaryosu (ör. JPY benzeri düşük değerli bir para birimi):
     * saklanan `rate` HER ZAMAN "1 birim yabancı para = kaç TRY" olmalı,
     * yani `ForexBuying / Unit`. Test bilerek `supported_currencies`'e
     * GİRMEYEN bir kod (JPY) kullanmaz — bunun yerine bölme mantığının
     * `Unit`'i sabit varsaymadığını, GEÇERLİ bir desteklenen para birimi
     * (USD) üzerinde Unit=100 simüle ederek kanıtlar (fetcher'ın bölme
     * ADIMI para birimi koduna göre DEĞİL yalnızca Unit değerine göre
     * çalıştığını doğrular — kod bağımsız genel davranış).
     */
    public function test_division_is_generic_and_handles_unit_100_correctly(): void
    {
        $xml = $this->buildTcmbXml('25.08.2026', '08/25/2026', [
            'USD' => ['unit' => 100, 'forexBuying' => '3212.345600'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertTrue($result->success);
        // 3212.3456 / 100 = 32.123456
        $this->assertSame('32.123456', $result->rates['USD']['rate']);
        $this->assertSame(100, $result->rates['USD']['unit']);
    }

    public function test_multiple_supported_currencies_are_parsed_independently(): void
    {
        $xml = $this->buildTcmbXml('25.08.2026', '08/25/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.500000'],
            'EUR' => ['unit' => 1, 'forexBuying' => '37.800000'],
            'GBP' => ['unit' => 1, 'forexBuying' => '44.100000'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->rates);
        $this->assertSame('32.500000', $result->rates['USD']['rate']);
        $this->assertSame('37.800000', $result->rates['EUR']['rate']);
        $this->assertSame('44.100000', $result->rates['GBP']['rate']);
    }

    public function test_unsupported_currencies_in_xml_are_ignored(): void
    {
        $xml = $this->buildTcmbXml('25.08.2026', '08/25/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.500000'],
            'JPY' => ['unit' => 100, 'forexBuying' => '21.500000'],
            'AUD' => ['unit' => 1, 'forexBuying' => '21.000000'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('USD', $result->rates);
        $this->assertArrayNotHasKey('JPY', $result->rates);
        $this->assertArrayNotHasKey('AUD', $result->rates);
    }

    public function test_rate_date_is_parsed_from_date_attribute(): void
    {
        $xml = $this->buildTcmbXml('03.01.2026', '01/03/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.500000'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertTrue($result->success);
        $this->assertSame('2026-01-03', $result->rateDate->toDateString());
    }

    public function test_network_failure_returns_failed_result_without_throwing(): void
    {
        Http::fake([self::TCMB_URL_PATTERN => Http::response('', 500)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertFalse($result->success);
        $this->assertNull($result->rateDate);
        $this->assertSame([], $result->rates);
        $this->assertNotNull($result->errorMessage);
    }

    public function test_malformed_xml_returns_failed_result(): void
    {
        Http::fake([self::TCMB_URL_PATTERN => Http::response('<not><valid', 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertFalse($result->success);
    }

    public function test_rate_values_are_decimal_strings_not_floats(): void
    {
        $xml = $this->buildTcmbXml('25.08.2026', '08/25/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.123456'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        // decimal string olarak saklanır — float ASLA (QUOTE-FINANCIALS disiplini).
        $this->assertIsString($result->rates['USD']['rate']);
        $this->assertFalse(is_float($result->rates['USD']['rate']));
    }
}
