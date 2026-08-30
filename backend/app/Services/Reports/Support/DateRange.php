<?php

namespace App\Services\Reports\Support;

use Carbon\CarbonImmutable;

/**
 * Çözümlenmiş tarih aralığı: mevcut dönem + aynı uzunlukta hemen önceki
 * (previous) dönem. KPI karşılaştırmaları (bkz. MoneyFormatter::deltaPct)
 * bu iki aralığın aynı gün sayısına sahip olmasına dayanır.
 *
 * Tüm alanlar CarbonImmutable — sorgu inşası sırasında yanlışlıkla
 * mutasyona uğrayıp aralığın kaymasını engellemek için.
 */
final class DateRange
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $previousFrom,
        public readonly CarbonImmutable $previousTo,
    ) {}
}
