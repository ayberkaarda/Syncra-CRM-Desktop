<?php

namespace App\Services\Reports\Support;

/**
 * `deals.amount` decimal(15,2) üzerinde SQL agregasyonu (SUM/COALESCE)
 * sonuçlarını API sözleşmesinin istediği "2 ondalıklı, nokta ayraçlı
 * STRING" biçimine çevirir — asla float'a dönmeden.
 *
 * NOT bcmath ile int-kuruş dönüşümü YAPMAZ: bu, Faz 9'daki teklif motorunun
 * (App\Services\Quotes\QuoteCalculator) iş bölümüdür ve deals.amount zaten
 * decimal(15,2) olarak saklanır — kuruşa çevirmeye gerek yok. Burada bcmath
 * yalnızca "PDO'nun döndürdüğü sayısal string'i normalize et" ve
 * "yüzde değişimini kayıpsız hesapla" için kullanılır.
 */
class MoneyFormatter
{
    /**
     * MySQL SUM()/COALESCE() sonucu PDO'dan numeric string (veya null)
     * olarak gelir. `null` → "0.00"; her ihtimale karşı sayısal olmayan bir
     * değer sızarsa da "0.00"a düşülür (asla exception ile raporu kırma).
     */
    public static function normalize(mixed $rawSum): string
    {
        $value = $rawSum === null ? '0' : (string) $rawSum;

        if (! is_numeric($value)) {
            $value = '0';
        }

        return bcadd($value, '0', 2);
    }

    /**
     * İki normalize edilmiş para/sayı string'i arasındaki yüzde değişimi.
     * `previous` sıfırsa (veya sıfıra çok yakınsa) null — bölme hatası /
     * yanıltıcı %∞ yerine (VERİ SÖZLEŞMESİ).
     */
    public static function deltaPct(string $current, string $previous): ?float
    {
        if (bccomp($previous, '0', 10) === 0) {
            return null;
        }

        $diff = bcsub($current, $previous, 10);
        $ratio = bcdiv($diff, $previous, 10);

        return round((float) bcmul($ratio, '100', 10), 2);
    }

    /**
     * Tamsayı sayaçlar (won_count, activities_count, ...) için aynı kural.
     */
    public static function deltaPctInt(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * Bir tutarı bir sayaca bölerek ortalama üretir (ör. avg_deal_size =
     * revenue / won_count). Sayaç sıfırsa "0.00" — bölme hatası yerine.
     */
    public static function average(string $sum, int $count): string
    {
        if ($count === 0) {
            return '0.00';
        }

        return bcdiv($sum, (string) $count, 2);
    }

    /**
     * Yüzde oranı: pay/payda * 100, payda sıfırsa 0.0.
     */
    public static function ratio(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }
}
