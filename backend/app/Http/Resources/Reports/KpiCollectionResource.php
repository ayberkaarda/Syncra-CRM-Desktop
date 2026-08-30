<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/dashboard/kpis` gövdesi. `App\Services\Reports\DashboardService
 * ::kpis()` zaten VERİ SÖZLEŞMESİ'ndeki tam şekli (8 metrik, her biri
 * `{value, previous, delta_pct}`) üretir — bu kaynak, biçimin API sınırında
 * KAZARA bozulmayacağını garanti eden ince bir tip-güvenlik katmanıdır.
 */
class KpiCollectionResource extends JsonResource
{
    /**
     * @var array<int, string>
     */
    private const METRICS = [
        'revenue', 'open_deals_count', 'open_deals_value', 'conversion_rate',
        'activities_count', 'won_count', 'lost_count', 'avg_deal_size',
    ];

    /**
     * @return array<string, array{value: mixed, previous: mixed, delta_pct: float|null}>
     */
    public function toArray(Request $request): array
    {
        $kpis = [];

        foreach (self::METRICS as $metric) {
            $entry = $this->resource[$metric];

            $kpis[$metric] = [
                'value' => $entry['value'],
                'previous' => $entry['previous'],
                'delta_pct' => $entry['delta_pct'] === null ? null : (float) $entry['delta_pct'],
            ];
        }

        return $kpis;
    }
}
