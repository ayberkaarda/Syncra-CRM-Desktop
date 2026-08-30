<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/reports/user-performance` — tüm rapor gövdesi
 * (`App\Services\Reports\UserPerformanceReport::run()` çıktısı).
 */
class UserPerformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'data' => array_map(fn (array $row) => [
                'user_id' => (int) $row['user_id'],
                'user_name' => $row['user_name'],
                'revenue' => (string) $row['revenue'],
                'won_count' => (int) $row['won_count'],
                'lost_count' => (int) $row['lost_count'],
                'conversion_rate' => (float) $row['conversion_rate'],
                'avg_deal_size' => (string) $row['avg_deal_size'],
                'open_deals_count' => (int) $row['open_deals_count'],
                'open_deals_value' => (string) $row['open_deals_value'],
                'activities_count' => (int) $row['activities_count'],
            ], $this->resource['data']),
        ];
    }
}
