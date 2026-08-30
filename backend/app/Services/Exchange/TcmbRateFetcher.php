<?php

namespace App\Services\Exchange;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * =============================================================================
 * TCMB günlük kur XML'i — çekme + GÜVENLİ ayrıştırma (Faz 14 / İz E, H7)
 * =============================================================================
 *
 * docs/PHASE-INTL.md §2.1/§2.5'in TEK somut uygulaması. Gerçek TCMB XML'i
 * (24.08.2026, Bülten 2026/157) WebFetch ile incelenerek yazıldı:
 *
 *   <Tarih_Date Tarih="dd.mm.yyyy" Date="mm/dd/yyyy" Bulten_No="...">
 *     <Currency CrossOrder=".." Kod="USD" CurrencyCode="USD">
 *       <Unit>1</Unit>
 *       <Isim>...</Isim>
 *       <CurrencyName>...</CurrencyName>
 *       <ForexBuying>...</ForexBuying>
 *       <ForexSelling>...</ForexSelling>
 *       <BanknoteBuying>...</BanknoteBuying>
 *       <BanknoteSelling>...</BanknoteSelling>
 *       <CrossRateUSD>...</CrossRateUSD>
 *       <CrossRateOther>...</CrossRateOther>
 *     </Currency>
 *     ...
 *   </Tarih_Date>
 *
 * -----------------------------------------------------------------------------
 * NEDEN `ForexBuying` (Döviz Alış)
 * -----------------------------------------------------------------------------
 * Türkiye'de yabancı para cinsinden düzenlenen faturaların TL karşılığı ve
 * VUK md. 280 değerlemesi TCMB DÖVİZ ALIŞ kuruyla yapılır (muhasebe
 * standardı) — `ForexSelling`/`Banknote*` burada kanonik dönüşüm DEĞİLDİR
 * (efektif satış yalnızca özel sözleşme senaryolarında kullanılır).
 *
 * -----------------------------------------------------------------------------
 * NEDEN `ForexBuying / Unit` (bölme GENEL yazılır)
 * -----------------------------------------------------------------------------
 * `Unit` para birimine göre 1 veya 100'dür (doğrulandı: USD/EUR/GBP=1,
 * JPY=100 — TCMB düşük değerli para birimlerini 100 birim üzerinden
 * yayınlar). Saklanan `rate` HER ZAMAN "1 birim yabancı para = kaç TRY"
 * olmalı; bu yüzden bölme `Unit` sabit 1 varsayılmadan GENEL yazılır
 * (`bcdiv($forexBuying, $unit, 6)`) — böylece ileride `supported_currencies`
 * listesine `Unit=100` bir para birimi (ör. JPY) eklenirse kod DEĞİŞMEDEN
 * doğru çalışır (bkz. testte JPY-benzeri taklit kayıt).
 *
 * -----------------------------------------------------------------------------
 * XXE SERTLEŞTİRME (H7) — TAM BAYRAK KÜMESİ VE GEREKÇESİ
 * -----------------------------------------------------------------------------
 * `simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET)`:
 *
 *   - `LIBXML_NONET`: harici DTD/entity için AĞ erişimini kapatır (savunma
 *     derinliği; today.xml'de harici ağ erişimi gerektiren bir yapı yoktur).
 *   - `LIBXML_NOENT` KULLANILMAZ: bu bayrak, adından farklı olarak, harici
 *     VARLIK (entity) ÇÖZÜMLEMEYİ AÇAR — yani XXE'yi MÜMKÜN KILAR. Tam
 *     istenenin tersi. libxml ≥2.9 (2013+) varsayılan davranışı zaten
 *     harici entity substitution'ı KAPALI tutar; `LIBXML_NOENT` verilmediği
 *     sürece `<!ENTITY xxe SYSTEM "file:///...">` tanımlı bir DOCTYPE'taki
 *     `&xxe;` referansı ÇÖZÜLMEZ (ya boş/literal kalır ya da ayrıştırıcı
 *     "Entity 'xxe' not defined" hatasıyla `false` döner) — dosya içeriği
 *     asla metne karışmaz. Bkz. tests/Feature/Exchange/TcmbXxeHardeningTest.
 *   - `LIBXML_DTDLOAD` KULLANILMAZ (bayrak hiç verilmez → kapalı kalır):
 *     harici DTD dosyasının AYRICA YÜKLENMESİNİ engeller.
 *   - `libxml_disable_entity_loader()` ÇAĞRILMAZ: PHP 8.0'da kullanımdan
 *     kaldırıldı (deprecated/no-op) — ona güvenmek yanlış güvenlik hissi
 *     verir. Gerçek güvenlik yukarıdaki bayrakların YOKLUĞUNDAN gelir.
 *
 * Ayrıştırmadan ÖNCE `config('exchange.fetch.max_response_bytes')` gövde
 * boyut sınırı uygulanır — aşırı büyük (veya "billion laughs" tarzı entity
 * genişletmeye çalışan) bir yanıt ayrıştırıcıya hiç girmeden reddedilir.
 *
 * -----------------------------------------------------------------------------
 * GİDEN HTTP SERTLEŞTİRMESİ
 * -----------------------------------------------------------------------------
 * - Hedef URL SABİT bir class sabiti (`SOURCE_URL`) — env/config'ten
 *   OKUNMAZ, hiçbir kullanıcı girdisiyle değiştirilemez (SSRF yüzeyi yok).
 * - `timeout(10)` + `connectTimeout(5)`, TLS doğrulaması AÇIK (Laravel Http
 *   istemcisinin varsayılanı; burada KAPATAN hiçbir kod yok).
 * - `retry(2, 500)`: 2 deneme, aralarında artan bekleme (Laravel'in
 *   dahili exponential backoff'u).
 * - Yönlendirme takibi kapalı (`allow_redirects=false`): sabit URL zaten
 *   TCMB'nin kendi adresi; bir yönlendirme normal senaryoda beklenmez ve
 *   izin verilmesi ek bir (küçük) yüzey açar.
 * - Toplam başarısızlık (ağ hatası, HTTP hata kodu, boyut aşımı, ayrıştırma
 *   reddi) `TcmbFetchResult::failed()` ile taşınır — bu sınıf ASLA exception
 *   fırlatmaz; çağıran taraf (komut) "son bilinen kuru kullan, kırılma"
 *   kararını (PHASE-INTL §2.1) `success` bayrağına bakarak verir.
 */
class TcmbRateFetcher
{
    /**
     * Sabit kaynak URL — env()/config() ÜZERİNDEN OKUNMAZ. Bilinçli karar:
     * bu sabitin bir ortam değişkeniyle değiştirilebilir olması, çekme
     * hattını rastgele bir hedefe yönlendirilebilir hale getirir (SSRF).
     */
    private const SOURCE_URL = 'https://www.tcmb.gov.tr/kurlar/today.xml';

    public function fetch(): TcmbFetchResult
    {
        try {
            $response = Http::timeout((int) config('exchange.fetch.timeout_seconds', 10))
                ->connectTimeout((int) config('exchange.fetch.connect_timeout_seconds', 5))
                ->retry(
                    (int) config('exchange.fetch.retry_times', 2),
                    (int) config('exchange.fetch.retry_delay_ms', 500)
                )
                ->withOptions(['allow_redirects' => false])
                ->get(self::SOURCE_URL);
        } catch (Throwable $e) {
            return TcmbFetchResult::failed('TCMB isteği başarısız: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return TcmbFetchResult::failed('TCMB HTTP hatası: durum kodu '.$response->status());
        }

        $body = $response->body();
        $maxBytes = (int) config('exchange.fetch.max_response_bytes', 5 * 1024 * 1024);

        if (strlen($body) > $maxBytes) {
            return TcmbFetchResult::failed(
                'TCMB yanıtı boyut sınırını aştı ('.strlen($body)." bayt > {$maxBytes} bayt) — ayrıştırılmadan reddedildi."
            );
        }

        if (trim($body) === '') {
            return TcmbFetchResult::failed('TCMB boş yanıt döndürdü.');
        }

        return $this->parse($body);
    }

    private function parse(string $xml): TcmbFetchResult
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // Bkz. sınıf dokümanındaki "XXE SERTLEŞTİRME" bölümü — bayrak
        // kümesi ve NEDEN LIBXML_NOENT KULLANILMADIĞI orada gerekçelidir.
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        if ($element === false) {
            $firstError = $errors[0]->message ?? 'bilinmeyen ayrıştırma hatası';

            return TcmbFetchResult::failed('TCMB XML ayrıştırılamadı: '.trim($firstError));
        }

        $rateDateRaw = (string) ($element['Date'] ?? '');
        $rateDate = $this->parseRateDate($rateDateRaw);

        if ($rateDate === null) {
            return TcmbFetchResult::failed("TCMB XML tarih özniteliği okunamadı/geçersiz: '{$rateDateRaw}'");
        }

        $supported = array_map('strtoupper', (array) config('exchange.supported_currencies', []));
        $rates = [];

        foreach ($element->Currency ?? [] as $currencyNode) {
            $code = strtoupper(trim((string) ($currencyNode['Kod'] ?? $currencyNode['CurrencyCode'] ?? '')));

            if ($code === '' || ! in_array($code, $supported, true)) {
                continue;
            }

            $unitRaw = trim((string) ($currencyNode->Unit ?? ''));
            $forexBuyingRaw = trim((string) ($currencyNode->ForexBuying ?? ''));

            if (! is_numeric($unitRaw) || (int) $unitRaw <= 0 || ! is_numeric($forexBuyingRaw) || (float) $forexBuyingRaw <= 0) {
                // Bir para birimi için bozuk/eksik veri gelirse o para birimi
                // ATLANIR (sessizce yutulmaz — bkz. FetchTcmbRates loglaması);
                // diğer para birimleri etkilenmez.
                continue;
            }

            $unit = (int) $unitRaw;

            // GENEL bölme: `Unit` sabit 1 varsayılmaz (bkz. sınıf dokümanı).
            $rate = bcdiv($forexBuyingRaw, (string) $unit, 6);

            $rates[$code] = ['rate' => $rate, 'unit' => $unit];
        }

        if ($rates === []) {
            // XML ayrıştırılabildi ama desteklenen HİÇBİR para birimi
            // bulunamadı — bu, TCMB'nin XML yapısını sessizce değiştirdiği
            // anlamına gelebilir. Bunu "başarı, 0 satır" gibi yutmak Faz 9
            // KDV sınıfı bir sessiz hata olurdu; bilinçli olarak FAILURE
            // sayılır ki komut bunu loglayıp son kuru korusun.
            return TcmbFetchResult::failed('TCMB XML ayrıştırıldı ancak desteklenen para birimlerinden hiçbiri bulunamadı (XML yapısı değişmiş olabilir).');
        }

        return TcmbFetchResult::success($rateDate, $rates);
    }

    /**
     * TCMB `Date` özniteliği mm/dd/yyyy biçimindedir (`Tarih` özniteliğinin
     * dd.mm.yyyy'sine göre ayrıştırma belirsizliği daha düşük). Katı format
     * eşleşmesi ister — beklenmeyen bir biçim sessizce yanlış bir tarihe
     * yuvarlanmaz, `null` döner ve çağıran taraf bunu hata sayar.
     */
    private function parseRateDate(string $raw): ?CarbonImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('m/d/Y', $raw);
        } catch (Throwable) {
            return null;
        }

        if ($date === false) {
            return null;
        }

        return $date->startOfDay();
    }
}
