<?php

namespace App\Notifications\Support;

/**
 * Bildirim gövdelerinde ("Acme Ltd. — 250.000,00 ₺") kullanılan tek para
 * biçimlendirme yeri.
 *
 * `App\Services\Quotes\QuotePdfService::currencySymbol()` ile AYNI eşleme
 * kasıtlı olarak burada TEKRARLANIR, import EDİLMEZ: o metod `private
 * static` ve Faz 9/B şeridinin dosyasında yaşıyor — bu şeridin dosya
 * sahipliği yalnızca `app/Notifications/**`'tir (bkz. Faz 10 görev
 * sözleşmesi "SENİN DOSYALARIN"). İki yerin bağımsız kopyası, paylaşılan
 * bir dosyaya dokunmaktan daha güvenlidir.
 */
final class Money
{
    public static function format(string|int|float $amount, string $currency): string
    {
        return number_format((float) $amount, 2, ',', '.').' '.self::symbol($currency);
    }

    private static function symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'TRY' => '₺',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($currency),
        };
    }
}
