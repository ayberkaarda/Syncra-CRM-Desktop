<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/reports/source-analysis` — tüm rapor gövdesi
 * (`App\Services\Reports\SourceAnalysisReport::run()` çıktısı).
 */
class SourceAnalysisResource extends JsonResource
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
                'source' => (string) $row['source'],
                'leads_count' => (int) $row['leads_count'],
                'converted_count' => (int) $row['converted_count'],
                'conversion_rate' => (float) $row['conversion_rate'],
                'revenue' => (string) $row['revenue'],
            ], $this->resource['data']),
        ];
    }
}
