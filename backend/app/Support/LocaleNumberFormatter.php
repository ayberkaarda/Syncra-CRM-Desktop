<?php

namespace App\Support;

use NumberFormatter;
use Throwable;

/**
 * Teklif PDF'i için dile duyarlı sayı/para gösterimi (Faz 14, docs/PHASE-INTL.md
 * §1.8 + §2.7). GÖSTERİM KATMANIDIR — para matematiğine (bcmath/int-kuruş
 * disiplini, docs/QUOTE-FINANCIALS.md) hiçbir şekilde karışmaz; girdi olarak
 * gelen değer yalnızca biçimlendirilir, hesaba geri YAZILMAZ.
 *
 * NEDEN AYRI BİR SINIF: `resources/views/pdf/quote.blade.php` Faz 9'dan beri
 * `number_format($v, 2, ',', '.')` ile SABİT Türkçe ayraç basıyordu. Frontend
 * (`frontend/src/lib/money.ts`) Faz 14'te `Intl.NumberFormat` + `narrowSymbol`'e
 * geçti; PDF geride kaldı — İngilizce bir teklif PDF'i hâlâ `1.234,56` basıyordu,
 * `1,234.56` basması gerekirken. Bu sınıf o farkı kapatır.
 *
 * KISA DİL KODU → ICU LOCALE: frontend `src/i18n/index.ts`'teki `INTL_TAGS` ile
 * BİREBİR aynı eşleme (`en` → `en-GB`, `en-US` DEĞİL — gerekçesi o dosyada
 * yazılı: tarih sırası gün-ay-yıl korunur). PHP tarafı o TS dosyasını import
 * edemediği için eşleme burada YİNELENİR — yeni bir dil eklenirse HER İKİ
 * dosya da (bu ve `frontend/src/i18n/index.ts`) güncellenmelidir.
 */
final class LocaleNumberFormatter
{
    /** @var array<string, string> kısa dil kodu → ICU locale */
    private const ICU_LOCALES = [
        'tr' => 'tr_TR',
        'en' => 'en_GB',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
    ];

    /** Sembolün TUTARDAN SONRA (dar olmayan boşlukla) basıldığı diller. Diğerleri
     *  (tr/en) sembolü boşluksuz ÖNE basar — bkz. class docblock'taki ölçülen tablo. */
    private const SUFFIX_SYMBOL_LOCALES = ['de', 'fr'];

    /** ICU NumberFormatter örnekleri pahalıdır; istek başına birkaç kez kurulmasın diye
     *  önbelleklenir (anahtar: locale|ondalık|gruplama). Aynı PDF içinde onlarca kalem
     *  satırı aynı formatter'ı tekrar tekrar kullanır. */
    private static array $decimalFormatters = [];

    /**
     * `intl` eklentisi kurulu ve `NumberFormatter` kullanılabilir mi?
     * Kurulu değilse (ör. minimal bir PHP kurulumu) PDF üretimi ÇÖKMEMELİ —
     * bu sınıftaki her genel metot bu kontrolü yapıp eski `number_format`
     * davranışına GERİ DÜŞER.
     */
    public static function isAvailable(): bool
    {
        return class_exists(NumberFormatter::class);
    }

    private static function icuLocale(string $appLocale): string
    {
        return self::ICU_LOCALES[$appLocale] ?? self::ICU_LOCALES['tr'];
    }

    private static function decimalFormatter(string $appLocale, int $decimals, bool $grouping): NumberFormatter
    {
        $key = $appLocale.'|'.$decimals.'|'.($grouping ? '1' : '0');

        if (! isset(self::$decimalFormatters[$key])) {
            $formatter = new NumberFormatter(self::icuLocale($appLocale), NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(NumberFormatter::GROUPING_USED, $grouping ? 1 : 0);
            self::$decimalFormatters[$key] = $formatter;
        }

        return self::$decimalFormatters[$key];
    }

    /**
     * Dile duyarlı ondalık sayı — para birimi SİMGESİZ (miktar, yüzde, kur oranı).
     * `(float)` cast'i YALNIZCA burada, biçimlendiricinin girdisinde yapılır;
     * çağıran taraf hesaplamayı zaten bcmath/int-kuruş disipliniyle bitirmiş olmalı.
     */
    public static function number(string|int|float $value, int $decimals, string $appLocale, bool $grouping = true): string
    {
        $floatValue = (float) $value;

        if (! self::isAvailable()) {
            return number_format($floatValue, $decimals, ',', '.');
        }

        try {
            return self::decimalFormatter($appLocale, $decimals, $grouping)->format($floatValue);
        } catch (Throwable) {
            return number_format($floatValue, $decimals, ',', '.');
        }
    }

    /**
     * Para tutarı + simge, dile göre doğru konumda. Her zaman 2 ondalık basar.
     *
     * NEDEN `NumberFormatter::CURRENCY` + `setSymbol()` KULLANILMADI (ilk denenen
     * yaklaşım, ölçüldü): ICU, locale'in "kendi" para birimi olmayan kodlar için
     * (ör. `en_GB` locale'inde `TRY`, `fr_FR`'de `GBP`) sembolü `setSymbol()`
     * override'ına RAĞMEN yok sayıp ISO koduyla karışık bileşik bir gösterime
     * düşüyor — `en_GB`+`TRY` → "TRY 1,234.56" (simge basılmadı), `fr_FR`+`GBP`
     * → "1 234,56 £GB" (ISO kodu simgeye eklendi), `en_GB`+`USD` → "US$1,234.56"
     * (yine ISO önekli). Bu davranış locale/para birimi kombinasyonuna göre
     * TUTARSIZ olduğundan güvenilir değil. Bunun yerine tutar `DECIMAL` tipiyle
     * (yalnız ayraç/gruplama) biçimlendirilip `QuotePdfService::currencySymbol()`'ün
     * ürettiği simge burada ELLE, konumu locale'e göre, eklenir — frontend
     * `money.ts`'in `narrowSymbol` çıktısıyla birebir eşleşir (bkz. class docblock).
     */
    public static function money(string|int|float $value, string $symbol, string $appLocale): string
    {
        $floatValue = (float) $value;

        if (! self::isAvailable()) {
            // Eski (Faz 9) davranış: sabit TR ayraç, simge sonda, düz boşlukla.
            return number_format($floatValue, 2, ',', '.').' '.$symbol;
        }

        try {
            $formatted = self::decimalFormatter($appLocale, 2, true)->format($floatValue);
        } catch (Throwable) {
            return number_format($floatValue, 2, ',', '.').' '.$symbol;
        }

        if (in_array($appLocale, self::SUFFIX_SYMBOL_LOCALES, true)) {
            // U+00A0 (NBSP): ICU'nun kendi CURRENCY biçimlendiricisinin de-DE/fr-FR
            // için tutar↔simge arasına koyduğu aynı karakter (ölçüldü) — satır
            // sonunda simge yalnız kalıp bir üst satıra taşmasın diye kasıtlı NBSP.
            return $formatted."\u{00A0}".$symbol;
        }

        return $symbol.$formatted;
    }
}
