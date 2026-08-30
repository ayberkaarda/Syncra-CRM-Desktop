<?php

namespace App\Http\Resources;

use App\Models\ExchangeRate;
use App\Services\Exchange\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ayarlar > Kur ekranının satırı — para birimi başına EN GÜNCEL kur +
 * bayatlık bilgisi (docs/PHASE-INTL.md §2.6). `is_stale`/`days_stale`
 * BURADA hesaplanır (sunucu otoritesi): istemci "> 4 gün" eşiğini kendi
 * başına tekrar hesaplamaz, yalnızca gelen bayrağı gösterir — eşik
 * `ExchangeRateService::STALE_THRESHOLD_DAYS` ile aynı kalır.
 *
 * @property-read ExchangeRate $resource
 */
class ExchangeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ExchangeRate $rate */
        $rate = $this->resource;

        /** @var ExchangeRateService $service */
        $service = app(ExchangeRateService::class);

        return [
            'currency' => $rate->currency,
            'rate' => (string) $rate->rate,
            'unit' => $rate->unit,
            'rate_date' => $rate->rate_date->toDateString(),
            'source' => $rate->source,
            'entered_by' => $rate->entered_by,
            'is_stale' => $service->isStale($rate),
            'days_stale' => $service->daysStale($rate),
        ];
    }
}
