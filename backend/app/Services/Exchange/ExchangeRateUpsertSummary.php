<?php

namespace App\Services\Exchange;

use Carbon\CarbonImmutable;

/**
 * `ExchangeRateService::applyFetch()` çıktısı — `FetchTcmbRates` komutunun
 * "hafta sonu/tatil = yeni veri yok, ama bu normal" ayrımını LOG mesajında
 * doğru kurabilmesi için `written`/`unchanged` ayrı sayılır (bkz. komut
 * dokümanı).
 */
final class ExchangeRateUpsertSummary
{
    /**
     * @param  array<int, string>  $currencies  işlenen para birimi kodları (TCMB XML'inde bulunan + desteklenen kesişimi)
     */
    public function __construct(
        public readonly CarbonImmutable $rateDate,
        public readonly int $written,
        public readonly int $unchanged,
        public readonly array $currencies,
    ) {}
}
