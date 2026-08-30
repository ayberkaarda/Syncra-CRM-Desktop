<?php

namespace App\Services\Quotes;

/**
 * =============================================================================
 * Teklif hesap motoru — docs/QUOTE-FINANCIALS.md'nin TEK uygulaması
 * =============================================================================
 *
 * SAF sınıftır: veritabanı yok, konteyner yok, `now()` yok, Eloquent yok. Tek
 * girdisi bir dizi, tek çıktısı bir dizi. Bu, hesabın binlerce senaryoyla
 * birim testinden geçirilebilmesi içindir (QuoteCalculatorTest).
 *
 * -----------------------------------------------------------------------------
 * FORMÜL (sözleşme §2) — KDV, İNDİRİM SONRASI MATRAHTAN
 * -----------------------------------------------------------------------------
 *   line_total_i = round2(quantity_i × unit_price_i × (1 - discount_percent_i/100))
 *   subtotal     = Σ line_total_i                              (tam toplam)
 *   discount     = type==='percent' ? round2(subtotal × value/100) : value
 *   group_net_g  = Σ line_total_i   (tax_rate_i = g olanlar)
 *   group_disc_g = indirimin g grubuna düşen ciro payı          (§3, tam kapanır)
 *   group_base_g = group_net_g - group_disc_g
 *   group_tax_g  = round2(group_base_g × g / 100)
 *   tax_amount   = Σ group_tax_g                                (tam toplam)
 *   total        = subtotal - discount + tax_amount             (tam aritmetik)
 *
 * KDV Kanunu md. 25/a: fatura üzerinde gösterilen ticari iskontolar KDV
 * matrahına dahil edilmez. Teklif bir fatura değildir ama faturaya DÖNÜŞEN
 * belgedir; teklifteki toplamla faturadaki toplamın farklı çıkması ticari
 * güven sorunudur. Eski formül (KDV'yi indirim ÖNCESİ tutardan hesaplayan)
 * müşteriye FAZLA KDV yansıtıyordu.
 *
 * -----------------------------------------------------------------------------
 * ARİTMETİK: TAM SAYI KURUŞ + BCMATH — FLOAT ZİNCİRİ YOK
 * -----------------------------------------------------------------------------
 * Sözleşme §2 float aritmetiğini yasaklar. Burada iki araç birlikte kullanılır
 * ve iş bölümü kasıtlıdır:
 *
 *   - TOPLAMA / ÇIKARMA / KARŞILAŞTIRMA → PHP `int` (kuruş). Tam sayı toplamı
 *     tanım gereği kayıpsızdır; `subtotal`, `tax_amount` ve `total` bu yüzden
 *     "tam toplam"dır ve ek yuvarlama görmez.
 *   - ÇARPMA / BÖLME → **bcmath** (`bcmul`, `bcdiv`, `bcmod`). Gerekçe TAŞMA:
 *     `quantity` decimal(10,2) ve `unit_price` decimal(15,2) olduğu için
 *     `q_c × p_c × (10000 - d_bp)` çarpımı en kötü durumda 10^27
 *     mertebesine çıkar ve 64-bit `int`'i (≈9.2×10^18) SESSİZCE float'a
 *     dönüştürür — tam da kaçınmak istediğimiz şeye. bcmath keyfi
 *     hassasiyette çalıştığı için girdi büyüklüğünden bağımsız olarak TAM
 *     sonuç verir. Aynı sorun §3'teki `D × net_g` çarpımında da vardır.
 *
 * `float` yalnızca SINIR NOKTALARINDA görünür: dışarıdan gelen ham değeri
 * okurken ve sonucu API'ye verirken. Aradaki hiçbir adımda float aritmetiği
 * yapılmaz.
 *
 * -----------------------------------------------------------------------------
 * YUVARLAMA (sözleşme §4): HALF-UP, 2 HANE, YALNIZCA 4 NOKTADA
 * -----------------------------------------------------------------------------
 *   1. `line_total_i`                              — half-up
 *   2. yüzdeden hesaplanan `discount_amount`       — half-up
 *   3. grup indirim payları                        — floor + en büyük kalan
 *   4. `group_tax_g`                               — half-up
 *
 * Half-up ("0.005 → 0.01") bilinçli tercihtir; banker's rounding
 * KULLANILMAZ. Türkiye ticari pratiği ve müşterinin tutarı elle
 * doğrulayabilmesi esastır: 0.045'in 0.04 çıktığı bir belge, hesap makinesiyle
 * kontrol eden muhasebeciye hata gibi görünür.
 *
 * 3. nokta neden half-up DEĞİL: paylar half-up yuvarlansaydı toplamları
 * `discount_amount`'ı aşabilirdi. floor + en büyük kalan,
 * `Σ pay_g = discount_amount` eşitliğini HER DURUMDA tam sağlayan tek
 * yöntemdir (§3).
 *
 * TOLERANS YOKTUR: `Σ line_total = subtotal`, `Σ group_tax = tax_amount`,
 * `Σ pay_g = discount_amount` ve `total = subtotal - discount + tax`
 * eşitlikleri yaklaşık değil TAM'dır; testler tam eşitlik arar.
 */
class QuoteCalculator
{
    public const DISCOUNT_AMOUNT = 'amount';

    public const DISCOUNT_PERCENT = 'percent';

    /**
     * @var array<int, string>
     */
    public const DISCOUNT_TYPES = [self::DISCOUNT_AMOUNT, self::DISCOUNT_PERCENT];

    /**
     * decimal(15,2) kolonunun taşıyabileceği en büyük mutlak tutar (kuruş).
     * Aşan bir değer veritabanı hatası yerine anlaşılır bir 422 üretsin diye
     * burada yakalanır.
     */
    private const MAX_KURUS = 999_999_999_999_999;

    /**
     * Ondalıklı karşılaştırmalarda kullanılan bcmath ölçeği. bcmath'in
     * varsayılan ölçeği 0'dır ve ondalık kısmı YOK SAYAR; her `bccomp`
     * çağrısında açıkça verilmesi gerekir.
     */
    private const COMPARE_SCALE = 10;

    /**
     * Teklifin tüm finansal çıktısını üretir.
     *
     * @param  array<int, array<string, mixed>>  $items  Her biri quantity, unit_price,
     *                                                   discount_percent, tax_rate taşır.
     *                                                   Diğer anahtarlar (name, product_id, ...)
     *                                                   dokunulmadan geri döner.
     * @param  int|float|string  $discountValue  Kullanıcının girdiği ham değer.
     * @param  string  $discountType  'amount' (TL) veya 'percent' (0-100).
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal: float,
     *     discount_type: string,
     *     discount_value: float,
     *     discount_amount: float,
     *     tax_amount: float,
     *     total: float,
     *     tax_breakdown: array<int, array<string, float>>
     * }
     *
     * @throws QuoteCalculationException 422'ye çevrilecek doğrulama hatası
     */
    public static function calculate(
        array $items,
        int|float|string $discountValue = 0,
        string $discountType = self::DISCOUNT_AMOUNT,
    ): array {
        $items = array_values($items);

        if (! in_array($discountType, self::DISCOUNT_TYPES, true)) {
            throw new QuoteCalculationException(
                'İndirim tipi yalnızca "amount" veya "percent" olabilir.',
                'QUOTE_CALCULATION_INVALID',
                ['discount_type' => ['İndirim tipi yalnızca "amount" veya "percent" olabilir.']],
            );
        }

        // --- Adım 1: kalem net tutarları (1. yuvarlama noktası) ------------
        $lineKurus = [];
        $rateKeys = [];

        foreach ($items as $index => $item) {
            [$quantity, $unitPrice, $discountPercent, $rateKey] = self::readItem($item, $index);

            // Tek bcmath ifadesi: q_c × p_c × (10000 - d_bp) / 1.000.000.
            // Ara adımda YUVARLAMA YOK — örneğin önce indirimli birim fiyat
            // yuvarlansaydı 1000 adetlik bir kalemde hata 5 TL'ye çıkardı.
            $numerator = bcmul(bcmul($quantity, $unitPrice), bcsub('10000', $discountPercent));
            $kurus = (int) self::divideHalfUp($numerator, '1000000');

            if ($kurus > self::MAX_KURUS) {
                self::deny($index, 'unit_price', 'Kalem tutarı desteklenen en büyük değeri aşıyor.');
            }

            $lineKurus[$index] = $kurus;
            $rateKeys[$index] = $rateKey;
        }

        // --- Adım 2: ara toplam (tam toplam, ek yuvarlama YOK) ------------
        $subtotalKurus = array_sum($lineKurus);

        if ($subtotalKurus > self::MAX_KURUS) {
            throw new QuoteCalculationException(
                'Teklif ara toplamı desteklenen en büyük değeri aşıyor.',
                'QUOTE_CALCULATION_INVALID',
                ['items' => ['Teklif ara toplamı desteklenen en büyük değeri aşıyor.']],
            );
        }

        // --- Adım 3: teklif geneli indirim tutarı (2. yuvarlama noktası) --
        $discountKurus = self::resolveDiscount($discountValue, $discountType, $subtotalKurus);

        // --- Adım 4-5: oran gruplarına dağıt, matrah ve KDV --------------
        $groups = self::groupByRate($lineKurus, $rateKeys);
        $shares = self::allocateToGroups($groups, $subtotalKurus, $discountKurus);

        $breakdown = [];
        $taxKurusTotal = 0;

        foreach ($groups as $rateKey => $netKurus) {
            $share = $shares[$rateKey];
            $baseKurus = $netKurus - $share;

            // 4. yuvarlama noktası: grup KDV'si. Oran decimal(5,2) olduğu için
            // yüzde binde (basis) tutulur; bölen 10000'dir.
            $taxKurus = (int) self::divideHalfUp(
                bcmul((string) $baseKurus, (string) $rateKey),
                '10000'
            );

            $taxKurusTotal += $taxKurus;

            $breakdown[] = [
                'rate' => self::rateFromKey($rateKey),
                'net' => self::fromKurus($netKurus),
                'discount' => self::fromKurus($share),
                'base' => self::fromKurus($baseKurus),
                'tax' => self::fromKurus($taxKurus),
            ];
        }

        // Oran özeti YÜKSEKTEN DÜŞÜĞE sıralanır: hem fatura KDV özeti
        // tablolarının alışıldık düzeni budur, hem de çıktı sırası
        // deterministik olur (§8.11).
        usort($breakdown, fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

        // --- Adım 6: teklif toplamları (tam aritmetik) --------------------
        $totalKurus = $subtotalKurus - $discountKurus + $taxKurusTotal;

        foreach ($lineKurus as $index => $kurus) {
            $items[$index]['line_total'] = self::fromKurus($kurus);
        }

        return [
            'items' => $items,
            'subtotal' => self::fromKurus($subtotalKurus),
            'discount_type' => $discountType,
            'discount_value' => (float) self::normalize($discountValue),
            'discount_amount' => self::fromKurus($discountKurus),
            'tax_amount' => self::fromKurus($taxKurusTotal),
            'total' => self::fromKurus($totalKurus),
            'tax_breakdown' => $breakdown,
        ];
    }

    /**
     * Oran bazlı KDV matrah özeti — sözleşme §3'ün son notu.
     *
     * PDF'teki "KDV Matrah Özeti" tablosu ve testler bunu kullanır. Ayrı bir
     * hesap DEĞİLDİR, `calculate()`'in aynı çalışmasından süzülür: ikinci bir
     * hesap yolu, özet tablo ile başlık toplamlarının birbirinden sapması
     * anlamına gelirdi.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, float>> [['rate','net','discount','base','tax'], ...]
     *
     * @throws QuoteCalculationException
     */
    public static function taxBreakdown(
        array $items,
        int|float|string $discountValue = 0,
        string $discountType = self::DISCOUNT_AMOUNT,
    ): array {
        return self::calculate($items, $discountValue, $discountType)['tax_breakdown'];
    }

    // -----------------------------------------------------------------
    // Adım 3
    // -----------------------------------------------------------------

    /**
     * @throws QuoteCalculationException
     */
    private static function resolveDiscount(
        int|float|string $discountValue,
        string $discountType,
        int $subtotalKurus,
    ): int {
        $value = self::normalize($discountValue, 'discount_value');

        // bccomp'a ÖLÇEK VERMEK ŞART: varsayılan ölçek 0'dır ve yalnızca
        // tam sayı kısmını karşılaştırır — `bccomp('100.01', '100')` sıfır
        // döner, `bccomp('-0.5', '0')` da sıfır döner. Ölçeksiz bırakıldığında
        // "%100.01 indirim" ve "-0.50 TL indirim" doğrulamadan SESSİZCE
        // geçerdi.
        if (bccomp($value, '0', self::COMPARE_SCALE) < 0) {
            self::denyField('discount_value', 'İndirim değeri negatif olamaz.');
        }

        if ($discountType === self::DISCOUNT_PERCENT) {
            if (bccomp($value, '100', self::COMPARE_SCALE) > 0) {
                self::denyField('discount_value', 'Yüzde indirim 0 ile 100 arasında olmalıdır.');
            }

            // 2. yuvarlama noktası: yüzdeden TL'ye çeviri, half-up.
            //
            // Yüzde ÖNCE yüzde-binde tam sayıya çevrilir (5.00 → 500) ve
            // bölen 10000 olur. Doğrudan `bcmul($subtotal, '5.005')`
            // yazılamaz: bcmul'ün varsayılan ölçeği 0'dır ve sonucun ondalık
            // kısmını SESSİZCE keser — kuruş kaybı buradan girerdi.
            $valueBasis = self::scale($value, 2);

            $discountKurus = (int) self::divideHalfUp(
                bcmul((string) $subtotalKurus, $valueBasis),
                '10000'
            );
        } else {
            $discountKurus = (int) self::scale($value, 2);
        }

        if ($discountKurus > $subtotalKurus) {
            // `subtotal = 0` iken bu kontrol "indirim de 0 olmalı" kuralını
            // (§2 kenar durumlar) kendiliğinden uygular.
            throw new QuoteCalculationException(
                'İndirim tutarı ara toplamdan büyük olamaz.',
                'QUOTE_DISCOUNT_EXCEEDS_SUBTOTAL',
                ['discount_amount' => [sprintf(
                    'İndirim tutarı ara toplamdan (%s) büyük olamaz.',
                    number_format($subtotalKurus / 100, 2, ',', '.')
                )]],
            );
        }

        return $discountKurus;
    }

    // -----------------------------------------------------------------
    // Adım 4 — §3 dağıtım algoritması
    // -----------------------------------------------------------------

    /**
     * Kalem net tutarlarını KDV oranına göre gruplar.
     *
     * Gruplama anahtarı oranın 2 haneli TAM SAYI karşılığıdır (yüzde binde):
     * 20.00 ve 20.0 aynı anahtara (2000) düşer — §2'nin son kenar durumu.
     * Float anahtar kullanılsaydı 20.0 ile 19.999999999 ayrı grup olurdu.
     *
     * @param  array<int, int>  $lineKurus
     * @param  array<int, int>  $rateKeys
     * @return array<int, int> rateKey => net kuruş
     */
    private static function groupByRate(array $lineKurus, array $rateKeys): array
    {
        $groups = [];

        foreach ($lineKurus as $index => $kurus) {
            $rateKey = $rateKeys[$index];
            $groups[$rateKey] = ($groups[$rateKey] ?? 0) + $kurus;
        }

        return $groups;
    }

    /**
     * §3: teklif geneli indirimi KDV oranı gruplarına CİRO PAYIYLA dağıtır.
     *
     * Neden ciro payı: nötr ve savunulabilir tek ölçüttür. "Yüksek KDV'li
     * gruptan düş" gibi stratejiler müşteri lehine daha çok vergi düşürür ama
     * matrahı taraflı kaydırır ve vergi idaresi nezdinde izahı zordur.
     * "İlk kalemden düş" ise kalem SIRASINI vergiyi belirleyen bir değişkene
     * çevirirdi.
     *
     * Kuruş artığı floor + EN BÜYÜK KALAN ile kapatılır. Sıralama tam
     * belirlidir ve üç kademelidir:
     *   1) büyük kesir  2) büyük net  3) YÜKSEK oran
     * Üçüncü kademe müşteri lehinedir: artık kuruş, yüksek oranlı grubun
     * matrahını düşürdüğünde toplam KDV en çok azalır. Aynı zamanda
     * determinizmi garanti eder (§8.11) — üç ölçüt de eşit olan iki grup
     * olamaz, çünkü oran grup anahtarıdır ve benzersizdir.
     *
     * @param  array<int, int>  $groups  rateKey => net kuruş
     * @return array<int, int> rateKey => pay (kuruş); Σ = $discountKurus
     */
    private static function allocateToGroups(array $groups, int $subtotalKurus, int $discountKurus): array
    {
        $shares = array_fill_keys(array_keys($groups), 0);

        if ($discountKurus === 0 || $subtotalKurus === 0) {
            return $shares;
        }

        $remainders = [];
        $distributed = 0;

        foreach ($groups as $rateKey => $netKurus) {
            if ($netKurus <= 0) {
                // §3: net_g = 0 olan grup dağıtıma katılmaz, payı 0'dır.
                $remainders[$rateKey] = '0';

                continue;
            }

            // raw_g = D × net_g / S. Çarpım bcmath ile TAM yapılır (int'te
            // taşardı); kesir, aynı bölene (S) sahip olduğu için doğrudan
            // KALAN karşılaştırılarak sıralanabilir — ondalığa hiç
            // dönüştürülmez.
            $product = bcmul((string) $discountKurus, (string) $netKurus);

            $floor = (int) bcdiv($product, (string) $subtotalKurus, 0);
            $remainders[$rateKey] = bcmod($product, (string) $subtotalKurus);

            $shares[$rateKey] = $floor;
            $distributed += $floor;
        }

        $residual = $discountKurus - $distributed;

        if ($residual <= 0) {
            return $shares;
        }

        $order = array_keys($groups);
        usort($order, function (int $a, int $b) use ($remainders, $groups): int {
            // 1) büyük kesir
            $byRemainder = bccomp($remainders[$b], $remainders[$a]);

            if ($byRemainder !== 0) {
                return $byRemainder;
            }

            // 2) büyük net
            if ($groups[$a] !== $groups[$b]) {
                return $groups[$b] <=> $groups[$a];
            }

            // 3) yüksek oran
            return $b <=> $a;
        });

        foreach ($order as $rateKey) {
            if ($residual === 0) {
                break;
            }

            if ($groups[$rateKey] <= 0) {
                continue;
            }

            $shares[$rateKey]++;
            $residual--;
        }

        return $shares;
    }

    // -----------------------------------------------------------------
    // Girdi okuma ve doğrulama
    // -----------------------------------------------------------------

    /**
     * Kalemin sayısal alanlarını ölçekli TAM SAYI string'lere çevirir:
     *   quantity        → yüzde birler (10,2 → ×100)
     *   unit_price      → kuruş        (15,2 → ×100)
     *   discount_percent→ yüzde binde  (5,2  → ×100)
     *   tax_rate        → yüzde binde  (5,2  → ×100), grup anahtarı olarak int
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: string, 2: string, 3: int}
     *
     * @throws QuoteCalculationException
     */
    private static function readItem(array $item, int $index): array
    {
        $quantity = self::readScaled($item, 'quantity', $index, '1');
        $unitPrice = self::readScaled($item, 'unit_price', $index, '0');
        $discountPercent = self::readScaled($item, 'discount_percent', $index, '0');
        $taxRate = self::readScaled($item, 'tax_rate', $index, '0');

        if (bccomp($quantity, '0') < 0) {
            self::deny($index, 'quantity', 'Miktar negatif olamaz.');
        }

        if (bccomp($unitPrice, '0') < 0) {
            self::deny($index, 'unit_price', 'Birim fiyat negatif olamaz.');
        }

        if (bccomp($discountPercent, '0') < 0 || bccomp($discountPercent, '10000') > 0) {
            self::deny($index, 'discount_percent', 'Kalem indirim oranı 0 ile 100 arasında olmalıdır.');
        }

        if (bccomp($taxRate, '0') < 0 || bccomp($taxRate, '10000') > 0) {
            self::deny($index, 'tax_rate', 'KDV oranı 0 ile 100 arasında olmalıdır.');
        }

        return [$quantity, $unitPrice, $discountPercent, (int) $taxRate];
    }

    /**
     * @param  array<string, mixed>  $item
     *
     * @throws QuoteCalculationException
     */
    private static function readScaled(array $item, string $key, int $index, string $default): string
    {
        $value = $item[$key] ?? null;

        if ($value === null || $value === '') {
            return self::scale($default, 2);
        }

        if (! is_numeric($value)) {
            self::deny($index, $key, 'Sayısal bir değer bekleniyor.');
        }

        return self::scale(self::normalize($value), 2);
    }

    // -----------------------------------------------------------------
    // bcmath yardımcıları
    // -----------------------------------------------------------------

    /**
     * Ham girdiyi bcmath'in anlayacağı ondalık string'e çevirir.
     *
     * Float SINIR NOKTASIDIR: burada bir kez string'e dönüştürülür ve bir daha
     * float aritmetiğine girmez. Üstel gösterim (`1.0E-5`) bcmath tarafından
     * anlaşılmadığı için sabit noktalı biçime açılır.
     *
     * @throws QuoteCalculationException
     */
    private static function normalize(int|float|string $value, ?string $field = null): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                self::denyField($field ?? 'discount_value', 'Sayısal bir değer bekleniyor.');
            }

            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }

        $value = trim($value);

        if (! is_numeric($value)) {
            self::denyField($field ?? 'discount_value', 'Sayısal bir değer bekleniyor.');
        }

        if (stripos($value, 'e') !== false) {
            return rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.') ?: '0';
        }

        return $value;
    }

    /**
     * Ondalık bir string'i 10^scale ile çarpıp HALF-UP yuvarlayarak tam sayı
     * string'e çevirir. Negatif değerler için sıfırdan uzağa yuvarlar
     * (doğrulama zaten negatifi reddeder; simetri sürprizleri önler).
     */
    private static function scale(string $value, int $scale): string
    {
        $shifted = bcmul($value, bcpow('10', (string) $scale), 10);

        return self::roundHalfUp($shifted);
    }

    /**
     * Ondalık string'i 0 haneye HALF-UP yuvarlar:
     *   floor(n + 0.5)  (n >= 0)   /   ceil(n - 0.5)  (n < 0)
     * bcdiv scale 0 sıfıra doğru kestiği için işaret ayrı ele alınır.
     */
    private static function roundHalfUp(string $value): string
    {
        $negative = bccomp($value, '0', 10) < 0;
        $abs = $negative ? bcmul($value, '-1', 10) : $value;

        // floor(2n + 1 / 2) = floor(n + 0.5)
        $rounded = bcdiv(bcadd(bcmul($abs, '2', 10), '1', 10), '2', 0);

        return $negative ? bcmul($rounded, '-1') : $rounded;
    }

    /**
     * İki tam sayı string'inin bölümünü HALF-UP yuvarlar: round(n / d).
     * `floor((2n + d) / 2d)` — n ≥ 0, d > 0 için half-up'ın tam karşılığı.
     */
    private static function divideHalfUp(string $numerator, string $denominator): string
    {
        return bcdiv(
            bcadd(bcmul($numerator, '2'), $denominator),
            bcmul($denominator, '2'),
            0
        );
    }

    /**
     * Grup anahtarını (yüzde binde tam sayı) görünür orana çevirir: 2000 → 20.0
     */
    private static function rateFromKey(int $rateKey): float
    {
        return round($rateKey / 100, 2);
    }

    private static function fromKurus(int $kurus): float
    {
        return round($kurus / 100, 2);
    }

    // -----------------------------------------------------------------
    // Hata üretimi
    // -----------------------------------------------------------------

    /**
     * @throws QuoteCalculationException
     */
    private static function deny(int $index, string $key, string $message): never
    {
        throw new QuoteCalculationException(
            $message,
            'QUOTE_CALCULATION_INVALID',
            ["items.{$index}.{$key}" => [$message]],
        );
    }

    /**
     * @throws QuoteCalculationException
     */
    private static function denyField(string $field, string $message): never
    {
        throw new QuoteCalculationException(
            $message,
            'QUOTE_CALCULATION_INVALID',
            [$field => [$message]],
        );
    }
}
