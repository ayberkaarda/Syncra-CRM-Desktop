<?php

namespace Tests\Unit;

use App\Services\Quotes\QuoteCalculationException;
use App\Services\Quotes\QuoteCalculator;
use PHPUnit\Framework\TestCase;

/**
 * =============================================================================
 * docs/QUOTE-FINANCIALS.md §8 — 13 kabul kriterinin birim test karşılığı
 * =============================================================================
 *
 * `PHPUnit\Framework\TestCase` (Laravel'in `Tests\TestCase`'i DEĞİL): bu, para
 * hesabının gerçekten SAF olduğunun testidir. Konteyner, veritabanı ya da
 * `now()` gerektirseydi bu dosya çalışmazdı.
 *
 * TOLERANS YOKTUR. `assertSame` kullanılır, `assertEquals` değil: `assertEquals`
 * gevşek karşılaştırma yaptığı için `"235.65" == 235.65` ve hatta
 * `235.650000001 == 235.65` gibi durumları geçirebilir; para testinin tam da
 * yakalaması gereken hatalar bunlardır.
 */
class QuoteCalculatorTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function calc(array $items, int|float|string $value = 0, string $type = 'amount'): array
    {
        return QuoteCalculator::calculate($items, $value, $type);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(float|int|string $quantity, float|int|string $unitPrice, float|int|string $taxRate, float|int|string $discountPercent = 0): array
    {
        return [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $taxRate,
            'discount_percent' => $discountPercent,
        ];
    }

    // =================================================================
    // §8.1 — Karışık oran + teklif indirimi (ANA SENARYO)
    // =================================================================

    public function test_criterion_1_mixed_rates_with_quote_discount(): void
    {
        $result = $this->calc([
            $this->item(2, 100.00, 20, 10),   // line_total 180.00
            $this->item(1, 50.00, 10),        // line_total  50.00
        ], 30.00);

        $this->assertSame(180.00, $result['items'][0]['line_total']);
        $this->assertSame(50.00, $result['items'][1]['line_total']);
        $this->assertSame(230.00, $result['subtotal']);
        $this->assertSame(30.00, $result['discount_amount']);
        $this->assertSame(35.65, $result['tax_amount']);
        $this->assertSame(235.65, $result['total']);

        // Dağıtım: %20 grubuna 23.48, %10 grubuna 6.52.
        $breakdown = $result['tax_breakdown'];
        $this->assertSame(20.0, $breakdown[0]['rate']);
        $this->assertSame(23.48, $breakdown[0]['discount']);
        $this->assertSame(156.52, $breakdown[0]['base']);
        $this->assertSame(31.30, $breakdown[0]['tax']);
        $this->assertSame(10.0, $breakdown[1]['rate']);
        $this->assertSame(6.52, $breakdown[1]['discount']);
        $this->assertSame(43.48, $breakdown[1]['base']);
        $this->assertSame(4.35, $breakdown[1]['tax']);
    }

    /**
     * NEGATİF KONTROL (§8.1): eski formül KDV'yi indirim ÖNCESİ tutardan
     * hesaplıyordu ve 41.00 KDV / 241.00 toplam üretiyordu. Bu test o
     * değerlerin ARTIK ÜRETİLMEDİĞİNİ kilitler — hesap sessizce eski kurala
     * dönerse burası kırılır.
     */
    public function test_criterion_1_does_not_reproduce_the_old_pre_discount_tax(): void
    {
        $result = $this->calc([
            $this->item(2, 100.00, 20, 10),
            $this->item(1, 50.00, 10),
        ], 30.00);

        $this->assertNotSame(41.00, $result['tax_amount'], 'KDV indirim ÖNCESİ matrahtan hesaplanmış.');
        $this->assertNotSame(241.00, $result['total'], 'Toplam eski (fazla KDV'.'li) formülle üretilmiş.');
    }

    // =================================================================
    // §8.2 — Üç grup + kuruş artığı tie-break (yüksek oran kazanır)
    // =================================================================

    public function test_criterion_2_three_groups_residual_goes_to_highest_rate(): void
    {
        $result = $this->calc([
            $this->item(1, 100.00, 20),
            $this->item(1, 100.00, 10),
            $this->item(1, 100.00, 1),
        ], 10.00);

        $this->assertSame(300.00, $result['subtotal']);
        $this->assertSame(29.97, $result['tax_amount']);
        $this->assertSame(319.97, $result['total']);

        $shares = [];
        foreach ($result['tax_breakdown'] as $row) {
            $shares[(string) $row['rate']] = $row['discount'];
        }

        // Kesirler (1/3) ve netler (100.00) eşit → YÜKSEK oran artık kuruşu alır.
        $this->assertSame(3.34, $shares['20']);
        $this->assertSame(3.33, $shares['10']);
        $this->assertSame(3.33, $shares['1']);
    }

    // =================================================================
    // §8.3 — Artık asla negatif olamaz
    // =================================================================

    public function test_criterion_3_residual_never_produces_negative_share(): void
    {
        $result = $this->calc([
            $this->item(1, 100.00, 20),
            $this->item(1, 100.00, 10),
        ], 10.01);

        $this->assertSame(200.00, $result['subtotal']);
        $this->assertSame(10.01, $result['discount_amount']);
        $this->assertSame(28.50, $result['tax_amount']);
        $this->assertSame(218.49, $result['total']);

        $this->assertSame(5.01, $result['tax_breakdown'][0]['discount']); // %20
        $this->assertSame(5.00, $result['tax_breakdown'][1]['discount']); // %10

        foreach ($result['tax_breakdown'] as $row) {
            $this->assertGreaterThanOrEqual(0.0, $row['discount']);
            $this->assertGreaterThanOrEqual(0.0, $row['base']);
        }
    }

    // =================================================================
    // §8.4 — Yalnız kalem indirimi (D = 0)
    // =================================================================

    public function test_criterion_4_line_discounts_only(): void
    {
        $result = $this->calc([
            $this->item(3, 2500.00, 20),
            $this->item(10, 120.00, 10, 5),
        ], 0);

        $this->assertSame(7500.00, $result['items'][0]['line_total']);
        $this->assertSame(1140.00, $result['items'][1]['line_total']);
        $this->assertSame(8640.00, $result['subtotal']);
        $this->assertSame(0.00, $result['discount_amount']);
        $this->assertSame(1614.00, $result['tax_amount']);
        $this->assertSame(10254.00, $result['total']);

        // D = 0 → tüm paylar 0.
        foreach ($result['tax_breakdown'] as $row) {
            $this->assertSame(0.00, $row['discount']);
        }
    }

    // =================================================================
    // §8.5 — Yüzde tipi teklif indirimi
    // =================================================================

    public function test_criterion_5_percent_type_discount(): void
    {
        $result = $this->calc([$this->item(1, 1234.56, 20)], 5.00, 'percent');

        $this->assertSame('percent', $result['discount_type']);
        $this->assertSame(5.0, $result['discount_value']);
        $this->assertSame(1234.56, $result['subtotal']);
        $this->assertSame(61.73, $result['discount_amount']);   // round2(61.728)
        $this->assertSame(1172.83, $result['tax_breakdown'][0]['base']);
        $this->assertSame(234.57, $result['tax_amount']);       // round2(234.566)
        $this->assertSame(1407.40, $result['total']);
    }

    // =================================================================
    // §8.6 — Half-up kanıtı
    // =================================================================

    public function test_criterion_6_half_up_rounding_on_line_total(): void
    {
        // 1.50 × 0.03 = 0.045 → HALF-UP 0.05. Banker's rounding 0.04 verirdi.
        $result = $this->calc([$this->item(1.50, 0.03, 0)]);

        $this->assertSame(0.05, $result['items'][0]['line_total']);
        $this->assertNotSame(0.04, $result['items'][0]['line_total'], 'Banker\'s rounding uygulanmış.');
    }

    public function test_criterion_6_half_up_rounding_chain(): void
    {
        // 149.90 × 0.85 = 127.415 → 127.42; KDV 25.484 → 25.48.
        $result = $this->calc([$this->item(1, 149.90, 20, 15)], 0);

        $this->assertSame(127.42, $result['items'][0]['line_total']);
        $this->assertSame(25.48, $result['tax_amount']);
        $this->assertSame(152.90, $result['total']);
    }

    // =================================================================
    // §8.7 — Tam indirim
    // =================================================================

    public function test_criterion_7_full_discount_zeroes_the_total(): void
    {
        $result = $this->calc([$this->item(1, 500.00, 20)], 500.00);

        $this->assertSame(500.00, $result['subtotal']);
        $this->assertSame(500.00, $result['discount_amount']);
        $this->assertSame(0.00, $result['tax_amount']);
        $this->assertSame(0.00, $result['total']);
        $this->assertSame(0.00, $result['tax_breakdown'][0]['base']);
    }

    // =================================================================
    // §8.8 — Doğrulama
    // =================================================================

    public function test_criterion_8_discount_greater_than_subtotal_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, 100.00, 20)], 100.01);
    }

    public function test_criterion_8_discount_exceeding_subtotal_carries_its_own_code(): void
    {
        try {
            $this->calc([$this->item(1, 100.00, 20)], 100.01);
            $this->fail('İndirim ara toplamı aştığı hâlde hesap tamamlandı.');
        } catch (QuoteCalculationException $e) {
            $this->assertSame('QUOTE_DISCOUNT_EXCEEDS_SUBTOTAL', $e->errorCode);
            $this->assertArrayHasKey('discount_amount', $e->fields);
        }
    }

    public function test_criterion_8_negative_quantity_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(-1, 100.00, 20)]);
    }

    public function test_criterion_8_negative_unit_price_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, -100.00, 20)]);
    }

    public function test_criterion_8_negative_line_discount_percent_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, 100.00, 20, -5)]);
    }

    public function test_criterion_8_line_discount_percent_above_100_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, 100.00, 20, 100.01)]);
    }

    public function test_criterion_8_negative_discount_value_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, 100.00, 20)], -1);
    }

    public function test_criterion_8_percent_discount_above_100_is_rejected(): void
    {
        try {
            $this->calc([$this->item(1, 100.00, 20)], 100.01, 'percent');
            $this->fail('%100 üzeri yüzde indirim kabul edildi.');
        } catch (QuoteCalculationException $e) {
            $this->assertArrayHasKey('discount_value', $e->fields);
        }
    }

    public function test_criterion_8_tax_rate_out_of_range_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, 100.00, 100.01)]);
    }

    public function test_unknown_discount_type_is_rejected(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([$this->item(1, 100.00, 20)], 10, 'kdv_dahil');
    }

    // =================================================================
    // §8.9 — KDV dahil görünüm (kolon YOK, türetilir)
    // =================================================================

    /**
     * §8.9: "KDV dahil" sütununun toplamı `total`'a EŞİT OLMAK ZORUNDA
     * DEĞİLDİR. Bu test o sapmanın bilinçli olduğunu kilitler — birisi
     * "toplamlar tutmuyor" diye dipnotu sütun toplamından türetmeye
     * kalkarsa burası uyarır.
     */
    public function test_criterion_9_gross_column_sum_may_differ_from_total(): void
    {
        $result = $this->calc([
            $this->item(2, 100.00, 20, 10),
            $this->item(1, 50.00, 10),
        ], 30.00);

        $grossSum = 0.0;

        foreach ($result['items'] as $index => $item) {
            $rate = $index === 0 ? 20 : 10;
            $grossSum += round($item['line_total'] * (1 + $rate / 100), 2);
        }

        $this->assertSame(271.00, round($grossSum, 2));
        $this->assertSame(235.65, $result['total']);
        $this->assertNotSame($result['total'], round($grossSum, 2));
    }

    // =================================================================
    // §8.10 — Toplam bütünlüğü (property-bazlı)
    // =================================================================

    public function test_criterion_10_invariants_hold_for_randomised_quotes(): void
    {
        mt_srand(20260824); // Tekrarlanabilir: bir başarısızlık aynı girdiyle yeniden üretilebilir.

        $rates = [0, 1, 10, 20];

        for ($run = 0; $run < 300; $run++) {
            $items = [];
            $itemCount = mt_rand(1, 10);

            for ($i = 0; $i < $itemCount; $i++) {
                $items[] = $this->item(
                    mt_rand(1, 2000) / 100,
                    mt_rand(0, 500000) / 100,
                    $rates[mt_rand(0, 3)],
                    mt_rand(0, 10000) / 100,
                );
            }

            $subtotalKurus = 0;
            foreach (QuoteCalculator::calculate($items)['items'] as $item) {
                $subtotalKurus += (int) round($item['line_total'] * 100);
            }

            $discount = mt_rand(0, max(0, $subtotalKurus)) / 100;
            $result = QuoteCalculator::calculate($items, $discount);

            $context = 'run='.$run.' discount='.$discount;

            // 1) subtotal = Σ line_total (TAM)
            $lineSum = 0;
            foreach ($result['items'] as $item) {
                $lineSum += (int) round($item['line_total'] * 100);
            }
            $this->assertSame((int) round($result['subtotal'] * 100), $lineSum, $context);

            // 2) tax_amount = Σ taxBreakdown().tax (TAM)
            // 3) Σ dağıtılan pay = discount_amount (TAM)
            $taxSum = 0;
            $shareSum = 0;
            foreach ($result['tax_breakdown'] as $row) {
                $taxSum += (int) round($row['tax'] * 100);
                $shareSum += (int) round($row['discount'] * 100);
                $this->assertGreaterThanOrEqual(0.0, $row['base'], $context);
            }
            $this->assertSame((int) round($result['tax_amount'] * 100), $taxSum, $context);
            $this->assertSame((int) round($result['discount_amount'] * 100), $shareSum, $context);

            // 4) total = subtotal - discount + tax (TAM)
            $this->assertSame(
                (int) round($result['subtotal'] * 100)
                    - (int) round($result['discount_amount'] * 100)
                    + (int) round($result['tax_amount'] * 100),
                (int) round($result['total'] * 100),
                $context
            );
        }
    }

    // =================================================================
    // §8.11 — Determinizm
    // =================================================================

    public function test_criterion_11_result_is_bit_identical_across_repeats(): void
    {
        $items = [
            $this->item(1, 100.00, 20),
            $this->item(1, 100.00, 10),
            $this->item(1, 100.00, 1),
        ];

        $expected = $this->calc($items, 10.00);

        for ($i = 0; $i < 1000; $i++) {
            $this->assertSame($expected, $this->calc($items, 10.00));
        }
    }

    /**
     * Kalem SIRASI sonucu değiştirmemelidir: dağıtım oran GRUPLARINA
     * yapıldığı için aynı kalemler farklı sırayla gönderildiğinde de aynı
     * toplamlar çıkar. Sıraya duyarlı bir dağıtım (ör. "ilk kalemden düş")
     * burada kırılırdı.
     */
    public function test_item_order_does_not_change_totals(): void
    {
        $a = $this->calc([
            $this->item(2, 100.00, 20, 10),
            $this->item(1, 50.00, 10),
        ], 30.00);

        $b = $this->calc([
            $this->item(1, 50.00, 10),
            $this->item(2, 100.00, 20, 10),
        ], 30.00);

        $this->assertSame($a['subtotal'], $b['subtotal']);
        $this->assertSame($a['tax_amount'], $b['tax_amount']);
        $this->assertSame($a['total'], $b['total']);
        $this->assertSame($a['tax_breakdown'], $b['tax_breakdown']);
    }

    // =================================================================
    // Kenar durumlar (§2)
    // =================================================================

    public function test_quote_without_items_is_all_zero(): void
    {
        $result = $this->calc([], 0);

        $this->assertSame(0.00, $result['subtotal']);
        $this->assertSame(0.00, $result['discount_amount']);
        $this->assertSame(0.00, $result['tax_amount']);
        $this->assertSame(0.00, $result['total']);
        $this->assertSame([], $result['tax_breakdown']);
    }

    public function test_quote_without_items_rejects_a_discount(): void
    {
        $this->expectException(QuoteCalculationException::class);

        $this->calc([], 1.00);
    }

    public function test_zero_tax_rate_is_a_valid_group_and_still_takes_a_discount_share(): void
    {
        $result = $this->calc([
            $this->item(1, 100.00, 0),
            $this->item(1, 100.00, 20),
        ], 20.00);

        $this->assertSame(200.00, $result['subtotal']);

        $byRate = [];
        foreach ($result['tax_breakdown'] as $row) {
            $byRate[(string) $row['rate']] = $row;
        }

        // İstisna/ihracat grubu payını ALIR (§2 kenar durumu) ama KDV üretmez.
        $this->assertSame(10.00, $byRate['0']['discount']);
        $this->assertSame(0.00, $byRate['0']['tax']);
        $this->assertSame(10.00, $byRate['20']['discount']);
        $this->assertSame(18.00, $byRate['20']['tax']);
        $this->assertSame(198.00, $result['total']);
    }

    public function test_zero_quantity_and_zero_price_lines_are_valid(): void
    {
        $result = $this->calc([
            $this->item(0, 100.00, 20),
            $this->item(5, 0, 20),
            $this->item(1, 100.00, 20),
        ], 0);

        $this->assertSame(0.00, $result['items'][0]['line_total']);
        $this->assertSame(0.00, $result['items'][1]['line_total']);
        $this->assertSame(100.00, $result['subtotal']);
        $this->assertSame(20.00, $result['tax_amount']);
    }

    /**
     * §2 son kenar durumu: 20.00 ile 20.0 AYNI gruptur. Ayrı gruplansalardı
     * her biri kendi kuruş yuvarlamasını yapar ve aynı teklif oranın nasıl
     * yazıldığına göre farklı KDV üretirdi.
     */
    public function test_equal_rates_written_differently_land_in_one_group(): void
    {
        $result = $this->calc([
            $this->item(1, 100.00, '20.00'),
            $this->item(1, 100.00, 20.0),
            $this->item(1, 100.00, 20),
        ], 0);

        $this->assertCount(1, $result['tax_breakdown']);
        $this->assertSame(300.00, $result['tax_breakdown'][0]['net']);
        $this->assertSame(60.00, $result['tax_amount']);
    }

    /**
     * Kuruşun altındaki tutarlar: 100 kalemlik bir teklifte birikimli hata
     * OLUŞMAZ, çünkü toplama tam sayı kuruş üzerinden yapılır ve KDV oran
     * GRUBU başına bir kez yuvarlanır. Kalem başına yuvarlansaydı 100 × 1
     * kuruşluk kalemin KDV'si 0.00 çıkardı (her satırda round(0.2) = 0).
     */
    public function test_hundred_tiny_lines_do_not_accumulate_rounding_error(): void
    {
        $items = array_fill(0, 100, $this->item(1, 0.01, 20));

        $result = $this->calc($items, 0);

        $this->assertSame(1.00, $result['subtotal']);
        $this->assertSame(0.20, $result['tax_amount']);
        $this->assertSame(1.20, $result['total']);
    }

    public function test_defaults_are_applied_for_missing_item_fields(): void
    {
        // quantity yok → 1, discount_percent yok → 0, tax_rate yok → 0.
        $result = $this->calc([['unit_price' => 250.00]]);

        $this->assertSame(250.00, $result['items'][0]['line_total']);
        $this->assertSame(250.00, $result['subtotal']);
        $this->assertSame(0.00, $result['tax_amount']);
    }

    /**
     * `tax_breakdown()` ile `calculate()` AYNI hesaptan gelir. İkinci bir
     * hesap yolu olsaydı PDF'teki özet tablo ile başlıktaki `tax_amount`
     * birbirinden sapabilirdi.
     */
    public function test_tax_breakdown_matches_calculate(): void
    {
        $items = [
            $this->item(2, 100.00, 20, 10),
            $this->item(1, 50.00, 10),
        ];

        $this->assertSame(
            $this->calc($items, 30.00)['tax_breakdown'],
            QuoteCalculator::taxBreakdown($items, 30.00)
        );
    }

    /**
     * Girdi string olarak geldiğinde (Eloquent'in `decimal:2` cast'i string
     * döndürür) sonuç float girdiyle AYNI olmalıdır. Aksi hâlde bir teklifi
     * kaydetmek ile veritabanından okuyup yeniden hesaplamak farklı tutar
     * verirdi.
     */
    public function test_string_input_matches_float_input(): void
    {
        $fromFloats = $this->calc([$this->item(2.5, 149.99, 20, 12.5)], 10.00);
        $fromStrings = $this->calc([$this->item('2.50', '149.99', '20.00', '12.50')], '10.00');

        $this->assertSame($fromFloats['subtotal'], $fromStrings['subtotal']);
        $this->assertSame($fromFloats['tax_amount'], $fromStrings['tax_amount']);
        $this->assertSame($fromFloats['total'], $fromStrings['total']);
    }

    /**
     * Kalemin diğer anahtarları (name, product_id, ...) hesaptan
     * DOKUNULMADAN geçer — QuoteService bu diziyi doğrudan kalem satırına
     * çevirir.
     */
    public function test_unrelated_item_keys_pass_through_untouched(): void
    {
        $result = $this->calc([[
            'product_id' => 7,
            'name' => 'CRM Lisansı',
            'description' => 'Yıllık',
            'quantity' => 2,
            'unit_price' => 100.00,
            'tax_rate' => 20,
        ]], 0);

        $this->assertSame(7, $result['items'][0]['product_id']);
        $this->assertSame('CRM Lisansı', $result['items'][0]['name']);
        $this->assertSame('Yıllık', $result['items'][0]['description']);
        $this->assertSame(200.00, $result['items'][0]['line_total']);
    }
}
