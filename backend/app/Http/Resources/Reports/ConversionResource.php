<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/reports/conversion` — tüm rapor gövdesi
 * (`App\Services\Reports\ConversionReport::run()` çıktısı).
 */
class ConversionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'total_leads' => (int) $this->resource['total_leads'],
            'converted_count' => (int) $this->resource['converted_count'],
            'conversion_rate' => (float) $this->resource['conversion_rate'],
            'avg_days_to_convert' => $this->resource['avg_days_to_convert'] === null
                ? null
                : (float) $this->resource['avg_days_to_convert'],
            'by_status' => $this->resource['by_status'],
        ];
    }
}
