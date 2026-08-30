<?php

namespace App\Services\Reports\Support;

use App\Models\ExchangeRate;
use App\Services\Exchange\ExchangeRateService;

/**
 * =============================================================================
 * RAPOR/DASHBOARD PARA BİRİMİ BAĞLAMI — istek başına TEK örnek
 * =============================================================================
 * docs/PHASE-INTL.md §2.4'ün ("rapor toplama — en ince nokta") çalışan
 * karşılığı. Üç işi birden yapar ve üçünü de TEK yerde tutar:
 *
 *   1. HEDEF PARA BİRİMİNİ ÇÖZER — isteği yapan kullanıcının
 *      `preferred_currency`'si (yoksa temel para birimi TRY). İstek başına
 *      BİR kez çözülür, her rapor satırında yeniden sorulmaz.
 *   2. DÖNÜŞÜMÜ YAPAR — iki AYRI semantikle:
 *        · `fromFrozenBase()` — KAPANMIŞ fırsat. Değer zaten TRY'de DONMUŞ
 *          (`deals.base_amount`); hedef TRY ise hiçbir dönüşüm YOKTUR,
 *          rakam kararlıdır (dünkü rapor bugün aynı sayıyı verir).
 *        · `convertOpen()` — AÇIK fırsat. Kaydın kendi para birimindeki
 *          tutar, GÜNCEL kurla hedefe çevrilir (ileriye dönük borunun
 *          "bugünkü değeri").
 *   3. NE KULLANDIĞINI KAYDEDER — `rateInfo()`, kullanılan her kuru,
 *      tarihini, bayatlığını ve ÇEVRİLEMEYENLERİ makine-okunur biçimde
 *      döner. Frontend bunu "Açık fırsatlar dd.mm.yyyy kuruyla çevrildi"
 *      dipnotu + amber bayatlık uyarısı olarak basar. Aksi hâlde rakamlar
 *      açıklanamaz olurdu (§2.4 son madde).
 *
 * -----------------------------------------------------------------------------
 * N+1 YOK, HAM SQL YOK (Faz 11 kuralı korunur)
 * -----------------------------------------------------------------------------
 * Bu sınıf SATIR BAŞINA değil, KOVA (bucket) başına çağrılır: rapor servisi
 * tek bir `GROUP BY ... currency` sorgusu çalıştırır (en fazla 4 para birimi
 * → en fazla 4 kova) ve her kovayı bir kez buraya verir. Kur satırları da
 * para birimi başına BİR kez okunup önbelleğe alınır (`$rateCache`), yani
 * dönüşüm maliyeti veri hacminden BAĞIMSIZ ve sabittir.
 *
 * -----------------------------------------------------------------------------
 * KARARLILIK GARANTİSİNİN SINIRI (açıkça yazılmıştır)
 * -----------------------------------------------------------------------------
 * Kapanmış fırsatın değeri TEMEL para biriminde (TRY) donar. Hedef para
 * birimi de TRY ise (varsayılan) tarihsel rakam MUTLAK kararlıdır — kurlar
 * ne yaparsa yapsın değişmez. Kullanıcı görüntü para birimini USD/EUR/GBP
 * seçerse, donmuş TRY tutarının o para birimindeki karşılığı GÜNCEL kurla
 * hesaplanır ve doğal olarak gün gün değişir; bu bir hata değil, "TRY
 * cinsinden sabit bir tutarı başka bir para biriminde göstermek" isteğinin
 * kaçınılmaz sonucudur. `rate_info.closed_basis` bu iki durumu ayırt eder
 * (`frozen_base` / `frozen_base_converted`) ki arayüz dipnotu doğru cümleyi
 * kursun.
 */
final class ReportCurrencyContext
{
    /**
     * Kullanılan kur satırları — para birimi başına bir kez okunur.
     *
     * @var array<string, ExchangeRate|null>
     */
    private array $rateCache = [];

    /**
     * Dönüşüm kovaları: kaynak para birimi => toplam kaynak tutar, kullanılan
     * kur/tarih ve hedef karşılığı.
     *
     * @var array<string, array{currency: string, amount: string, rate: string|null, rate_date: string|null, converted: string}>
     */
    private array $buckets = [];

    /**
     * Kuru bulunamadığı için çevrilemeyen kovalar (para birimi => ham tutar).
     *
     * @var array<string, string>
     */
    private array $unconverted = [];

    private int $unconvertedClosedCount = 0;

    private bool $closedConverted = false;

    private function __construct(
        private readonly ExchangeRateService $rates,
        private readonly string $displayCurrency,
    ) {}

    /**
     * `$preferred` geçersiz/boş/desteklenmeyen ise sessizce TEMEL para
     * birimine düşülür — bir rapor, kullanıcının profilindeki bir tercih
     * yüzünden 500 vermemelidir.
     */
    public static function make(ExchangeRateService $rates, ?string $preferred = null): self
    {
        $base = $rates->baseCurrency();
        $display = strtoupper((string) ($preferred ?? ''));

        $allowed = array_merge([$base], $rates->supportedCurrencies());

        if (! in_array($display, $allowed, true)) {
            $display = $base;
        }

        return new self($rates, $display);
    }

    public function displayCurrency(): string
    {
        return $this->displayCurrency;
    }

    public function baseCurrency(): string
    {
        return $this->rates->baseCurrency();
    }

    public function isBaseDisplay(): bool
    {
        return $this->displayCurrency === $this->baseCurrency();
    }

    /**
     * KAPANMIŞ fırsat toplamı: `SUM(deals.base_amount)` (TRY'de DONMUŞ).
     *
     * Hedef temel para birimiyse hiçbir kur okunmaz, hiçbir çarpma yapılmaz —
     * SQL'in verdiği toplam yalnızca 2 haneye normalize edilir. Kararlılık
     * garantisi tam olarak buradan gelir.
     *
     * @param  mixed  $baseSum  PDO'dan gelen SUM() sonucu (numeric string|null)
     * @return string hedef para biriminde decimal string (2 hane)
     */
    public function fromFrozenBase(mixed $baseSum): string
    {
        $amount = MoneyFormatter::normalize($baseSum);

        if ($this->isBaseDisplay()) {
            return $amount;
        }

        $this->closedConverted = true;

        return $this->record($this->baseCurrency(), $amount);
    }

    /**
     * AÇIK fırsat kovası: `$currency` cinsinden toplam → hedef para birimi,
     * GÜNCEL kurla.
     *
     * @param  mixed  $sum  PDO'dan gelen SUM() sonucu (numeric string|null)
     */
    public function convertOpen(mixed $sum, ?string $currency): string
    {
        $amount = MoneyFormatter::normalize($sum);
        $currency = strtoupper((string) ($currency ?: $this->baseCurrency()));

        if ($currency === $this->displayCurrency) {
            return $amount;
        }

        return $this->record($currency, $amount);
    }

    /**
     * Birden çok kovanın hedef para birimindeki toplamı — bcmath ile,
     * float'a hiç dönmeden.
     *
     * @param  iterable<array{0: mixed, 1: ?string}>  $buckets  [sum, currency] çiftleri
     */
    public function sumOpenBuckets(iterable $buckets): string
    {
        $total = '0.00';

        foreach ($buckets as [$sum, $currency]) {
            $total = bcadd($total, $this->convertOpen($sum, $currency), 2);
        }

        return $total;
    }

    /**
     * "Kapanmış ama donmuş temel tutarı OLMAYAN" fırsat sayısı — kur hiç
     * bulunamadığı için `base_amount` null kalan kayıtlar (bkz.
     * App\Services\Deals\DealMoveService::freezeBaseAmount 3. madde).
     *
     * Bu sayı GÖRÜNÜR olmak zorundadır: aksi hâlde o fırsatların geliri
     * toplamdan sessizce düşer ve kimse fark etmez — tam olarak §2.4'ün
     * yasakladığı sessiz hata sınıfı.
     */
    public function noteUnconvertedClosed(int $count): void
    {
        $this->unconvertedClosedCount += max(0, $count);
    }

    /**
     * Bir kovayı çevirir, kullanılan kuru kaydeder ve sonucu döner.
     * Kur yoksa "0.00" döner AMA kova `unconverted` olarak işaretlenir —
     * rakam eksik kalır, eksikliği rate_info'da GÖRÜNÜR olur.
     */
    private function record(string $currency, string $amount): string
    {
        $converted = $this->rates->convert($amount, $currency, $this->displayCurrency);

        if ($converted === null) {
            $this->unconverted[$currency] = bcadd($this->unconverted[$currency] ?? '0.00', $amount, 2);

            return '0.00';
        }

        $rate = $this->resolveRate($currency) ?? $this->resolveRate($this->displayCurrency);

        $existing = $this->buckets[$currency] ?? null;

        $this->buckets[$currency] = [
            'currency' => $currency,
            'amount' => bcadd($existing['amount'] ?? '0.00', $amount, 2),
            'rate' => $currency === $this->baseCurrency() && $this->isBaseDisplay()
                ? '1.000000'
                : ($rate !== null ? (string) $rate->rate : '1.000000'),
            'rate_date' => $rate?->rate_date?->toDateString(),
            'converted' => bcadd($existing['converted'] ?? '0.00', $converted, 2),
        ];

        return $converted;
    }

    private function resolveRate(string $currency): ?ExchangeRate
    {
        if (! array_key_exists($currency, $this->rateCache)) {
            $this->rateCache[$currency] = $this->rates->latest($currency);
        }

        return $this->rateCache[$currency];
    }

    /**
     * =========================================================================
     * `rate_info` SÖZLEŞMESİ — frontend bu alan adlarına bağlanır
     * =========================================================================
     * Yanıt zarfında `data`'nın KARDEŞİ olarak döner:
     *   `{ "data": ..., "rate_info": { ... } }`
     *
     *   display_currency         string        Rakamların gösterildiği para birimi (ISO 4217).
     *   base_currency            string        Temel para birimi ("TRY"). Donmuş değerler bunda saklanır.
     *   closed_basis             string        "frozen_base"           → kapanmış fırsatlar donmuş TRY tutarıyla,
     *                                                                    hiçbir dönüşüm yok, rakam KARARLI.
     *                                          "frozen_base_converted" → donmuş TRY tutarı görüntü para birimine
     *                                                                    GÜNCEL kurla çevrildi (gün gün değişir).
     *   open_basis               string        "current_rate" — açık fırsatlar daima güncel kurla çevrilir.
     *   as_of                    string|null   Dönüşümde kullanılan kurların EN ESKİ yayın tarihi (Y-m-d).
     *                                          Hiç dönüşüm yapılmadıysa (her şey zaten görüntü para biriminde)
     *                                          null — arayüz o zaman kur dipnotunu hiç basmaz.
     *   is_stale                 bool          `as_of` bayat mı (> 4 takvim günü, PHASE-INTL §2.6). Amber uyarı.
     *   days_stale               int           `as_of` kaç gün eski (dönüşüm yoksa 0).
     *   converted_buckets        array         Çevrilen kovalar; her biri:
     *                                            currency   string       kaynak para birimi
     *                                            amount     string       kaynak tutar toplamı ("12500.00")
     *                                            rate       string       kullanılan kur ("41.250000")
     *                                            rate_date  string|null  o kurun yayın tarihi (Y-m-d)
     *                                            converted  string       görüntü para birimindeki karşılık
     *                                          Kova YOKSA boş dizi. Sıra: para birimi koduna göre alfabetik.
     *   unconverted_open         array         Kuru bulunamadığı için ÇEVRİLEMEYEN açık-fırsat kovaları;
     *                                          her biri { currency: string, amount: string }. Bu tutarlar
     *                                          toplamlara 0 olarak girmiştir — arayüz uyarı göstermelidir.
     *   unconverted_closed_count int           Kapanmış olduğu hâlde donmuş temel tutarı OLMAYAN fırsat sayısı
     *                                          (kapanış anında hiç kur yoktu). Gelir toplamına DAHİL DEĞİL.
     *
     * GARANTİ: alanların hepsi HER yanıtta vardır (boş/0/null olabilir ama
     * eksik olmaz) — frontend koşullu alan kontrolü yazmak zorunda kalmaz.
     *
     * @return array<string, mixed>
     */
    public function rateInfo(): array
    {
        $buckets = $this->buckets;
        ksort($buckets);

        $dates = array_values(array_filter(array_column($buckets, 'rate_date')));
        sort($dates);
        $asOf = $dates[0] ?? null;

        // Bayatlık, EN ESKİ kur satırına göre ölçülür (en kötü durum):
        // iki para birimi çevrildiyse ve biri 6 gün eskiyse, dipnot
        // "kur güncel" dememelidir.
        $oldestRate = null;

        if ($asOf !== null) {
            foreach ($this->rateCache as $cached) {
                if ($cached !== null && $cached->rate_date->toDateString() === $asOf) {
                    $oldestRate = $cached;

                    break;
                }
            }
        }

        $daysStale = $oldestRate !== null ? $this->rates->daysStale($oldestRate) : 0;

        $unconverted = [];

        foreach ($this->unconverted as $currency => $amount) {
            $unconverted[] = ['currency' => $currency, 'amount' => $amount];
        }

        usort($unconverted, fn (array $a, array $b) => strcmp($a['currency'], $b['currency']));

        return [
            'display_currency' => $this->displayCurrency,
            'base_currency' => $this->baseCurrency(),
            'closed_basis' => $this->closedConverted ? 'frozen_base_converted' : 'frozen_base',
            'open_basis' => 'current_rate',
            'as_of' => $asOf,
            'is_stale' => $oldestRate !== null && $this->rates->isStale($oldestRate),
            'days_stale' => $daysStale,
            'converted_buckets' => array_values($buckets),
            'unconverted_open' => $unconverted,
            'unconverted_closed_count' => $this->unconvertedClosedCount,
        ];
    }
}
