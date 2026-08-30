<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/dashboard/revenue-trend` — tek bir dönem noktası.
 */
class RevenueTrendPointResource extends JsonResource
{
    /**
     * @return array{period: string, revenue: string, won_count: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'period' => (string) $this->resource['period'],
            'revenue' => (string) $this->resource['revenue'],
            'won_count' => (int) $this->resource['won_count'],
        ];
    }
}
