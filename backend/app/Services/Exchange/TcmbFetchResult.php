<?php

namespace App\Services\Exchange;

use Carbon\CarbonImmutable;

/**
 * `TcmbRateFetcher::fetch()` çıktısı — değişmez (immutable) sonuç nesnesi.
 *
 * `success=false` HER ZAMAN "kırılma" anlamına gelmez: TCMB'ye ulaşılamaması,
 * zaman aşımı, XML ayrıştırma reddi (XXE/boyut) veya beklenmeyen yapı hep
 * bu yolla taşınır — çağıran taraf (FetchTcmbRates komutu) bunu `info`
 * loglayıp son bilinen kuru korur; bkz. PHASE-INTL §2.1 "yayın yokluğu
 * hata değil" kararı.
 */
final class TcmbFetchResult
{
    /**
     * @param  array<string, array{rate: string, unit: int}>  $rates  para birimi kodu => ['rate' => 1 birim için TRY (decimal string), 'unit' => TCMB Unit değeri]
     */
    private function __construct(
        public readonly bool $success,
        public readonly ?CarbonImmutable $rateDate,
        public readonly array $rates,
        public readonly ?string $errorMessage,
    ) {}

    /**
     * @param  array<string, array{rate: string, unit: int}>  $rates
     */
    public static function success(CarbonImmutable $rateDate, array $rates): self
    {
        return new self(true, $rateDate, $rates, null);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(false, null, [], $errorMessage);
    }
}
