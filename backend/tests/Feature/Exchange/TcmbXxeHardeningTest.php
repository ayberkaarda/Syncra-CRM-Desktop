<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use App\Services\Exchange\ExchangeRateService;
use App\Services\Exchange\TcmbRateFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Exchange\Concerns\BuildsTcmbXml;
use Tests\TestCase;

/**
 * =============================================================================
 * A5.8 — XXE (XML External Entity) sertleştirme testi (PHASE-INTL §2.5, H7)
 * =============================================================================
 *
 * TCMB'nin döndürdüğü XML'i ayrıştıran `TcmbRateFetcher`in gerçek bir
 * sunucu dosyasının içeriğini SIZDIRMADIĞINI kanıtlar: taklit XML'e harici
 * bir DTD varlığı (`<!ENTITY xxe SYSTEM "file:///...">`) gömülür ve bu
 * varlığa metin içinde referans verilir (`&xxe;`). Beklenen: ayrıştırıcı bu
 * referansı ÇÖZMEZ (dosya içeriğine asla dönüşmez) — `LIBXML_NOENT`
 * kullanılmadığı için (bkz. TcmbRateFetcher dokümanı) libxml bunu, boş bir
 * "çözülmemiş varlık" düğümü olarak bırakır.
 *
 * Empirik doğrulama (bu ortamda, PHP 8.2 / libxml): `LIBXML_NONET` TEK
 * BAŞINA (NOENT olmadan) verildiğinde `(string) $node` çağrısı BOŞ string
 * döner — asla dosya içeriği değil. Aynı senaryo `LIBXML_NOENT` eklenerek
 * çalıştırılırsa (bkz. bu dosyanın "karşıt kanıt" testi DEĞİL, sadece
 * yorum) içerik gerçekten sızar; bu da `LIBXML_NOENT`in neden KULLANILMADIĞINI
 * somut olarak doğrular.
 */
class TcmbXxeHardeningTest extends TestCase
{
    use BuildsTcmbXml;
    use RefreshDatabase;

    private const TCMB_URL_PATTERN = 'https://www.tcmb.gov.tr/*';

    private string $secretPath;

    private string $secretContent = 'GIZLI-SUNUCU-ICERIGI-'; // + benzersiz sonek runtime'da eklenir

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretContent .= uniqid('', true);
        $this->secretPath = str_replace('\\', '/', tempnam(sys_get_temp_dir(), 'sigma_xxe_test_'));
        file_put_contents($this->secretPath, $this->secretContent);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->secretPath)) {
            unlink($this->secretPath);
        }

        parent::tearDown();
    }

    private function xxeDoctype(): string
    {
        return "  <!ENTITY xxe SYSTEM \"file:///{$this->secretPath}\">";
    }

    /**
     * Tek para birimi (USD) ve bu para biriminin `ForexBuying` alanı
     * tamamen `&xxe;` referansından ibaret: entity çözülmediği için
     * ForexBuying sayısal olmayan/boş kalır, o para birimi ATLANIR;
     * geriye desteklenen hiçbir para birimi kalmadığından fetch BAŞARISIZ
     * sayılır (bkz. TcmbRateFetcher::parse() "sessiz 0 satır" reddi).
     */
    public function test_xxe_entity_is_not_resolved_and_fetch_fails_when_only_currency_is_malicious(): void
    {
        $xml = $this->buildTcmbXml(
            '25.08.2026',
            '08/25/2026',
            ['USD' => ['forexBuying' => '&xxe;']],
            $this->xxeDoctype(),
        );

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertFalse($result->success, 'Yalnızca zehirli veri içeren bir yanıt başarı sayılmamalı.');
        $this->assertSame([], $result->rates);
        $this->assertStringNotContainsString($this->secretContent, (string) $result->errorMessage);

        // Hiçbir şey DB'ye yazılmadı — sızıntı yok, kirli veri yok.
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    /**
     * Karışık senaryo: EUR geçerli veri taşır, USD'nin `ForexBuying`'i XXE
     * denemesi. Beklenen: fetch GENEL olarak başarılı (EUR verisi geçerli),
     * ama USD tamamen dışlanır VE hiçbir yerde (rates dizisi, DB) sunucu
     * dosyasının gerçek içeriği görünmez.
     */
    public function test_xxe_payload_does_not_leak_file_contents_into_stored_rates(): void
    {
        $xml = $this->buildTcmbXml(
            '25.08.2026',
            '08/25/2026',
            [
                'EUR' => ['forexBuying' => '35.123456'],
                'USD' => ['forexBuying' => '&xxe;'],
            ],
            $this->xxeDoctype(),
        );

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertTrue($result->success, 'EUR verisi geçerli olduğu için fetch genel olarak başarılı olmalı.');
        $this->assertArrayHasKey('EUR', $result->rates);
        $this->assertArrayNotHasKey('USD', $result->rates, 'XXE payload taşıyan USD tamamen dışlanmalı.');

        $serialized = json_encode($result->rates);
        $this->assertStringNotContainsString($this->secretContent, (string) $serialized);

        app(ExchangeRateService::class)->applyFetch($result);

        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertNull(ExchangeRate::query()->forCurrency('USD')->first());

        $allStoredValues = ExchangeRate::query()->get()->map(fn (ExchangeRate $r) => (string) $r->rate)->implode('|');
        $this->assertStringNotContainsString($this->secretContent, $allStoredValues);
    }

    /**
     * Ayrıştırmadan ÖNCE uygulanan gövde boyut sınırı: sınırı aşan bir
     * yanıt hiç `simplexml_load_string`'e girmeden reddedilir. Testte
     * gerçekçi büyüklükte bir dosya oluşturmamak için sınır geçici olarak
     * küçültülür (davranış aynı kod yolu, maliyeti düşük).
     */
    public function test_oversized_response_is_rejected_before_parsing(): void
    {
        Config::set('exchange.fetch.max_response_bytes', 50);

        $xml = $this->buildTcmbXml('25.08.2026', '08/25/2026', ['USD' => []]);
        $this->assertGreaterThan(50, strlen($xml));

        Http::fake([self::TCMB_URL_PATTERN => Http::response($xml, 200)]);

        $result = app(TcmbRateFetcher::class)->fetch();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('boyut', (string) $result->errorMessage);
        $this->assertSame(0, ExchangeRate::query()->count());
    }
}
