<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/reports/sales-performance` — tüm rapor gövdesi
 * (`App\Services\Reports\SalesPerformanceReport::run()` çıktısı).
 */
class SalesPerformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'group_by' => $this->resource['group_by'],
            'data' => array_map(fn (array $row) => [
                'period' => (string) $row['period'],
                'revenue' => (string) $row['revenue'],
                'won_count' => (int) $row['won_count'],
                'lost_count' => (int) $row['lost_count'],
                'deals_count' => (int) $row['deals_count'],
            ], $this->resource['data']),
            'totals' => [
                'revenue' => (string) $this->resource['totals']['revenue'],
                'won_count' => (int) $this->resource['totals']['won_count'],
                'lost_count' => (int) $this->resource['totals']['lost_count'],
                'deals_count' => (int) $this->resource['totals']['deals_count'],
            ],
        ];
    }
}
