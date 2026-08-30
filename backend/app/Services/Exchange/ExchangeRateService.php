<?php

namespace App\Services\Exchange;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Kur veri katmanının OKUMA + YAZMA cephesi + PARA DÖNÜŞÜM UCU
 * (Faz 14 / İz E, docs/PHASE-INTL.md §2.3–§2.4).
 *
 * İki sorumluluk, tek sınıf — bilinçli: "hangi kur geçerli?" sorusunun
 * cevabı (`latest`/`asOf`/`resolveForFreeze`) ile "bu tutar o kurla kaç
 * eder?" sorusunun cevabı (`convert`/`toBase`) ayrı sınıflara bölünürse
 * ikisi arasında sessizce farklı kur seçen iki yol doğar. Kur SEÇİMİ ve
 * kur UYGULAMASI aynı yerde durur.
 *
 * ARİTMETİK DİSİPLİNİ (docs/QUOTE-FINANCIALS.md): tüm dönüşümler bcmath
 * ile `string` üzerinde yapılır. `(float)` cast'i para yolunda ASLA
 * kullanılmaz — ara hesaplar SCALE (12) hane taşır, sonuç TEK SEFERDE
 * 2 haneye half-up yuvarlanır (`roundMoney`).
 */
class ExchangeRateService
{
    /**
     * Bayatlık uyarı eşiği (PHASE-INTL §2.6): normal hafta sonu (Cuma kuru
     * Pazartesi sabahına dek geçerli — 3 gün) + 1 resmi tatil toleransı.
     */
    public const STALE_THRESHOLD_DAYS = 4;

    /**
     * Dönüşüm ara hesaplarında kullanılan bcmath ölçeği. Kur 6 hane
     * (decimal(18,6)), tutar 2 hane — 12 hane, zincirleme çarpma/bölmede
     * (ör. USD → TRY → EUR) hassasiyet kaybını imkânsız kılan geniş bir
     * tampondur. Sonuç yalnızca EN SONDA 2 haneye indirilir.
     */
    private const SCALE = 12;

    public function baseCurrency(): string
    {
        return strtoupper((string) config('exchange.base_currency', 'TRY'));
    }

    public function isBaseCurrency(string $currency): bool
    {
        return strtoupper($currency) === strtoupper((string) config('exchange.base_currency', 'TRY'));
    }

    /**
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return array_map('strtoupper', (array) config('exchange.supported_currencies', []));
    }

    /**
     * Bir para biriminin en güncel (en yeni tarihli) kur satırı.
     *
     * TRY için DAİMA `null` döner — TRY'nin exchange_rates'te satırı yoktur
     * (bkz. migration dokümanı); çağıran taraf `isBaseCurrency()` ile
     * kontrol edip rate=1 kabul etmelidir.
     */
    public function latest(string $currency): ?ExchangeRate
    {
        if ($this->isBaseCurrency($currency)) {
            return null;
        }

        return ExchangeRate::query()
            ->forCurrency($currency)
            ->latestFirst()
            ->first();
    }

    /**
     * Verilen tarihte (veya ondan önceki en yakın günde) geçerli kur —
     * "o gün için ne kullanılırdı" sorgusu (ör. teklif `sent` anındaki
     * kur donması başka şerit tarafından bu metotla kurulabilir).
     */
    public function asOf(string $currency, CarbonInterface $date): ?ExchangeRate
    {
        if ($this->isBaseCurrency($currency)) {
            return null;
        }

        return ExchangeRate::query()
            ->forCurrency($currency)
            ->where('rate_date', '<=', $date->toDateString())
            ->latestFirst()
            ->first();
    }

    /**
     * Bir dönüşümde KULLANILACAK kur satırı — tek karar noktası.
     *
     * `$date` verilmezse `latest()` (bugünün "güncel kur"u), verilirse
     * `asOf()` (o gün geçerli olan kur). TRY için `null` döner; çağıran
     * `isBaseCurrency()` ile rate=1 kısayolunu kullanır.
     */
    public function rateFor(string $currency, ?CarbonInterface $date = null): ?ExchangeRate
    {
        return $date === null
            ? $this->latest($currency)
            : $this->asOf($currency, $date);
    }

    /**
     * DONMA (freeze) anı için kur çözümü — fırsat kapanışı ve teklif
     * gönderimi AYNI kuralı kullansın diye tek yerde.
     *
     * Sıra: (1) o gün (veya öncesi) geçerli kur — doğru olan budur;
     * (2) yoksa EN SON BİLİNEN kur (`latest`) — ör. kur altyapısı bu
     * tarihten sonra kurulmuşsa geçmişe dönük satır yoktur, elde olan
     * tek gerçek veri en yenisidir; (3) o da yoksa `null`.
     *
     * ÜÇÜNCÜ ADIMDA 0/1 UYDURULMAZ: "kur bilinmiyor" ile "kur 1'dir"
     * bambaşka iki iddiadır; ikincisi bir USD fırsatını 1 TRY'ye çevirip
     * raporu sessizce bozar. Çağıran taraf null'ı GÖRÜNÜR bir eksiklik
     * olarak işler (warning log + rapor dipnotu).
     */
    public function resolveForFreeze(string $currency, CarbonInterface $date): ?ExchangeRate
    {
        if ($this->isBaseCurrency($currency)) {
            return null;
        }

        return $this->asOf($currency, $date) ?? $this->latest($currency);
    }

    /**
     * Genel dönüşüm: `$from` → `$to`, DAİMA temel para birimi (TRY)
     * üzerinden. `$date` verilmezse güncel kur, verilirse o gün geçerli
     * kur kullanılır.
     *
     * Neden TRY üzerinden: `exchange_rates` yalnız "1 birim yabancı para =
     * X TRY" satırları tutar (TCMB'nin yayınladığı şey budur). USD→EUR
     * için ayrı bir çapraz kur saklamak, aynı bilginin ikinci ve
     * çelişebilir bir kopyasını üretirdi.
     *
     * @param  string  $amount  decimal string (ör. "1500.00")
     * @return string|null decimal string (2 hane, half-up); gereken kur
     *                     satırlarından biri yoksa `null`
     */
    public function convert(string $amount, string $from, string $to, ?CarbonInterface $date = null): ?string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Dönüştürülecek tutar sayısal bir decimal string olmalıdır.');
        }

        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $this->roundMoney($amount);
        }

        // 1) Kaynak → TRY
        if ($this->isBaseCurrency($from)) {
            $inBase = $amount;
        } else {
            $fromRate = $this->rateFor($from, $date);

            if ($fromRate === null) {
                return null;
            }

            $inBase = bcmul($amount, (string) $fromRate->rate, self::SCALE);
        }

        // 2) TRY → Hedef
        if ($this->isBaseCurrency($to)) {
            return $this->roundMoney($inBase);
        }

        $toRate = $this->rateFor($to, $date);

        if ($toRate === null || bccomp((string) $toRate->rate, '0', 6) <= 0) {
            return null;
        }

        return $this->roundMoney(bcdiv($inBase, (string) $toRate->rate, self::SCALE));
    }

    /**
     * `$amount` (`$currency` cinsinden) → temel para birimi (TRY).
     * Kur yoksa `null`.
     *
     * @param  string  $amount  decimal string
     * @return string|null decimal string (TRY, 2 hane)
     */
    public function toBase(string $amount, string $currency, ?CarbonInterface $date = null): ?string
    {
        return $this->convert($amount, $currency, $this->baseCurrency(), $date);
    }

    /**
     * Temel para birimindeki (TRY) bir tutarı `$currency`'ye çevirir —
     * `toBase()`'in tersi. Donmuş `deals.base_amount` (TRY) değerini
     * kullanıcının `preferred_currency`'sinde göstermek için.
     */
    public function fromBase(string $amount, string $currency, ?CarbonInterface $date = null): ?string
    {
        return $this->convert($amount, $this->baseCurrency(), $currency, $date);
    }

    /**
     * bcmath TRUNCATE eder, yuvarlamaz. Half-up (docs/QUOTE-FINANCIALS.md
     * §4: 0.005 → 0.01, banker's rounding DEĞİL) için işaretine göre
     * ±0.005 eklenip 2 haneye kesilir — float'a hiç dönmeden.
     */
    private function roundMoney(string $value): string
    {
        $increment = bccomp($value, '0', self::SCALE) < 0 ? '-0.005' : '0.005';

        return bcadd($value, $increment, 2);
    }

    /**
     * Bir kur satırı bayat mı? (`> 4 takvim günü`, bkz. STALE_THRESHOLD_DAYS
     * dokümanı). `$asOf` verilmezse bugüne göre değerlendirilir.
     */
    public function isStale(ExchangeRate $rate, ?CarbonInterface $asOf = null): bool
    {
        return $this->daysStale($rate, $asOf) > self::STALE_THRESHOLD_DAYS;
    }

    public function daysStale(ExchangeRate $rate, ?CarbonInterface $asOf = null): int
    {
        $asOf ??= CarbonImmutable::today();

        return (int) $rate->rate_date->diffInDays($asOf);
    }

    /**
     * `TcmbRateFetcher::fetch()`'in BAŞARILI sonucunu DB'ye idempotent
     * şekilde yazar (`unique(currency, rate_date)` üzerine upsert).
     *
     * Aynı gün ikinci kez çağrılırsa (hafta sonu/tatil sonrası TCMB'nin
     * aynı tarihi tekrar servis etmesi DAHİL) hiçbir satır DUPLICATE
     * olmaz; değer değişmemişse `unchanged`, değişmişse/yeni satır
     * açılmışsa `written` sayılır — `FetchTcmbRates` bu ayrımla "bugün
     * yeni yayın yok" bilgisini `info` seviyesinde loglar (hata DEĞİL).
     */
    public function applyFetch(TcmbFetchResult $result): ExchangeRateUpsertSummary
    {
        if (! $result->success || $result->rateDate === null) {
            throw new InvalidArgumentException('Yalnızca başarılı bir TcmbFetchResult uygulanabilir.');
        }

        $rateDate = $result->rateDate->toDateString();
        $currencies = array_keys($result->rates);

        $existing = ExchangeRate::query()
            ->whereIn('currency', $currencies)
            ->where('rate_date', $rateDate)
            ->get()
            ->keyBy('currency');

        $written = 0;
        $unchanged = 0;

        DB::transaction(function () use ($result, $rateDate, $existing, &$written, &$unchanged) {
            foreach ($result->rates as $currency => $data) {
                /** @var ExchangeRate|null $current */
                $current = $existing->get($currency);

                if ($current !== null && $current->source === 'tcmb'
                    && bccomp((string) $current->rate, $data['rate'], 6) === 0
                    && (int) $current->unit === $data['unit']) {
                    // Değer birebir aynı — yazma gereksiz, sayaç için işaretle.
                    $unchanged++;

                    continue;
                }

                ExchangeRate::query()->updateOrCreate(
                    ['currency' => $currency, 'rate_date' => $rateDate],
                    ['rate' => $data['rate'], 'unit' => $data['unit'], 'source' => 'tcmb', 'entered_by' => null],
                );
                $written++;
            }
        });

        return new ExchangeRateUpsertSummary($result->rateDate, $written, $unchanged, $currencies);
    }

    /**
     * Ayarlar'dan elle kur girişi (BE ucu/controller başka şeridin —
     * bu metot yalnız veri katmanı sözleşmesidir). Çağırmadan önce
     * `manualEntryRules()` ile doğrulanmış veri BEKLENİR; burada da
     * savunma amaçlı (defense-in-depth) ikinci bir aralık kontrolü yapılır
     * — PHASE-INTL §2.5 "manuel giriş: pozitif ve makul aralıkta decimal".
     */
    public function storeManualRate(string $currency, string $rate, CarbonInterface $date, ?int $enteredBy): ExchangeRate
    {
        $currency = strtoupper($currency);

        if (! in_array($currency, $this->supportedCurrencies(), true)) {
            throw new InvalidArgumentException("Desteklenmeyen para birimi: {$currency}");
        }

        $this->assertReasonableRate($rate);

        return ExchangeRate::query()->updateOrCreate(
            ['currency' => $currency, 'rate_date' => $date->toDateString()],
            ['rate' => $rate, 'unit' => 1, 'source' => 'manual', 'entered_by' => $enteredBy],
        );
    }

    /**
     * Laravel FormRequest/Validator kurallarında doğrudan kullanılabilecek
     * kural seti — manuel kur girişi ucunu yazacak şerit bunu tekrar
     * tanımlamak yerine buradan alır (drift riski olmasın diye tek yer).
     *
     * @return array<string, array<int, mixed>>
     */
    public function manualEntryRules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in($this->supportedCurrencies())],
            'rate' => ['required', 'numeric', 'gt:0', 'max:'.(int) config('exchange.manual_rate_max', 100000)],
            'rate_date' => ['required', 'date'],
        ];
    }

    private function assertReasonableRate(string $rate): void
    {
        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new InvalidArgumentException('Kur pozitif bir decimal olmalıdır.');
        }

        $max = (int) config('exchange.manual_rate_max', 100000);

        if ((float) $rate > $max) {
            throw new InvalidArgumentException("Kur makul aralığın dışında (> {$max}).");
        }
    }
}
