<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Exchange\Concerns\BuildsTcmbXml;
use Tests\TestCase;

/**
 * `exchange:fetch-tcmb` komutu — PHASE-INTL §2.1/§2.7. Gerçek TCMB'ye AĞ
 * ÇAĞRISI YAPILMAZ, tamamı `Http::fake()` ile kapalı devre.
 */
class FetchTcmbRatesTest extends TestCase
{
    use BuildsTcmbXml;
    use RefreshDatabase;

    private const TCMB_URL_PATTERN = 'https://www.tcmb.gov.tr/*';

    private function fakeTodayXml(): string
    {
        return $this->buildTcmbXml('25.08.2026', '08/25/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.500000'],
            'EUR' => ['unit' => 1, 'forexBuying' => '37.800000'],
            'GBP' => ['unit' => 1, 'forexBuying' => '44.100000'],
        ]);
    }

    public function test_successful_fetch_writes_three_rows(): void
    {
        Http::fake([self::TCMB_URL_PATTERN => Http::response($this->fakeTodayXml(), 200)]);

        $this->artisan('exchange:fetch-tcmb')->assertExitCode(0);

        $this->assertSame(3, ExchangeRate::query()->count());
        $usd = ExchangeRate::query()->forCurrency('USD')->first();
        $this->assertSame('2026-08-25', $usd->rate_date->toDateString());
        $this->assertSame('tcmb', $usd->source);
        $this->assertNull($usd->entered_by);
    }

    public function test_dry_run_does_not_persist_anything(): void
    {
        Http::fake([self::TCMB_URL_PATTERN => Http::response($this->fakeTodayXml(), 200)]);

        $this->artisan('exchange:fetch-tcmb', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, ExchangeRate::query()->count());
    }

    /**
     * Aynı gün için komut iki kez çalıştırılır — `unique(currency, rate_date)`
     * sayesinde tekilleşir: ikinci çalıştırma satır sayısını ARTIRMAZ ve
     * mevcut değerleri BOZMAZ.
     */
    public function test_running_twice_on_the_same_day_is_idempotent(): void
    {
        Http::fake([self::TCMB_URL_PATTERN => Http::response($this->fakeTodayXml(), 200)]);

        $this->artisan('exchange:fetch-tcmb')->assertExitCode(0);
        $this->assertSame(3, ExchangeRate::query()->count());

        $firstRunUpdatedAt = ExchangeRate::query()->forCurrency('USD')->first()->updated_at;

        $this->artisan('exchange:fetch-tcmb')->assertExitCode(0);

        $this->assertSame(3, ExchangeRate::query()->count(), 'İkinci çalıştırma yeni satır eklememeli.');
        $usd = ExchangeRate::query()->forCurrency('USD')->first();
        $this->assertSame('32.500000', (string) $usd->rate);
        $this->assertEquals($firstRunUpdatedAt->toDateTimeString(), $usd->updated_at->toDateTimeString());
    }

    /**
     * HAFTA SONU / TATİL SENARYOSU (PHASE-INTL §2.1 — kritik davranış):
     * TCMB'nin `today.xml`'i son yayınlanan günü (bugün DEĞİL) tekrar
     * servis ettiğini simüle eder. O tarih için satırlar ZATEN varsa
     * (önceki gün çekilmiş), komut YENİ YAZMAZ ama yine de BAŞARI (exit 0)
     * döner ve son kur DEĞİŞMEDEN kalır — bu bir HATA DEĞİLDİR.
     */
    public function test_no_new_publication_is_treated_as_success_not_failure(): void
    {
        Log::spy();

        // Cuma günü zaten çekilmiş.
        ExchangeRate::factory()->forCurrency('USD', '32.500000')->onDate(CarbonImmutable::parse('2026-08-21'))->create();
        ExchangeRate::factory()->forCurrency('EUR', '37.800000')->onDate(CarbonImmutable::parse('2026-08-21'))->create();
        ExchangeRate::factory()->forCurrency('GBP', '44.100000')->onDate(CarbonImmutable::parse('2026-08-21'))->create();

        // Cumartesi/Pazar today.xml hâlâ Cuma'nın (21.08.2026) verisini döndürür.
        $weekendXml = $this->buildTcmbXml('21.08.2026', '08/21/2026', [
            'USD' => ['unit' => 1, 'forexBuying' => '32.500000'],
            'EUR' => ['unit' => 1, 'forexBuying' => '37.800000'],
            'GBP' => ['unit' => 1, 'forexBuying' => '44.100000'],
        ]);

        Http::fake([self::TCMB_URL_PATTERN => Http::response($weekendXml, 200)]);

        $this->artisan('exchange:fetch-tcmb')
            ->assertExitCode(0)
            ->assertSuccessful();

        // Hâlâ yalnızca 3 satır (Cuma'nınkiler) — duplicate yok, yeni gün yok.
        $this->assertSame(3, ExchangeRate::query()->count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'yeni TCMB verisi yok'))
            ->once();
    }

    /**
     * TOPLAM BAŞARISIZLIK: TCMB'ye hiç ulaşılamıyor → komut yine de
     * BAŞARI (exit 0) döner, son bilinen kur korunur, kırılma yok.
     */
    public function test_total_failure_keeps_last_known_rate_and_does_not_break(): void
    {
        ExchangeRate::factory()->forCurrency('USD', '32.500000')->onDate(CarbonImmutable::parse('2026-08-24'))->create();

        Http::fake([self::TCMB_URL_PATTERN => fn () => throw new ConnectionException('bağlantı zaman aşımı')]);

        $this->artisan('exchange:fetch-tcmb')->assertExitCode(0);

        $this->assertSame(1, ExchangeRate::query()->count());
        $usd = ExchangeRate::query()->forCurrency('USD')->first();
        $this->assertSame('32.500000', (string) $usd->rate);
    }

    public function test_stored_rate_is_decimal_string_not_float_in_database(): void
    {
        Http::fake([self::TCMB_URL_PATTERN => Http::response($this->fakeTodayXml(), 200)]);

        $this->artisan('exchange:fetch-tcmb')->assertExitCode(0);

        $raw = DB::table('exchange_rates')->where('currency', 'USD')->value('rate');
        $this->assertIsString($raw);
        $this->assertSame('32.500000', $raw);
    }
}
