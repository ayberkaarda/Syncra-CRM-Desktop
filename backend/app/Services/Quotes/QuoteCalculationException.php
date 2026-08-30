<?php

namespace App\Services\Quotes;

use RuntimeException;

/**
 * QuoteCalculator'ın doğrulama noktası.
 *
 * NEDEN HttpResponseException DEĞİL: QuoteCalculator bilinçli olarak SAF bir
 * sınıftır — ne veritabanına ne de HTTP katmanına bağlıdır, bu yüzden birim
 * testleri Laravel konteynerine ihtiyaç duymadan çalışır. `response()`
 * helper'ını çağıran bir exception fırlatmak bu bağımsızlığı sessizce
 * bozardı.
 *
 * Çeviri TEK yerde yapılır: QuoteService, bu exception'ı yakalayıp
 * bootstrap/app.php'deki standart hata zarfına (422) dönüştürür. Böylece
 * hesap kuralı bir yerde, HTTP sözleşmesi başka bir yerde yaşar.
 */
class QuoteCalculationException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $fields  Hata zarfının `fields` bölümü.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'QUOTE_CALCULATION_INVALID',
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
