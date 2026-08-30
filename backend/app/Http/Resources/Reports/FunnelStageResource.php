<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/dashboard/funnel` — tek bir aşama satırı.
 */
class FunnelStageResource extends JsonResource
{
    /**
     * @return array{stage_id: int, stage_name: string, stage_name_key: ?string, color: ?string, count: int, value: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'stage_id' => (int) $this->resource['stage_id'],
            'stage_name' => (string) $this->resource['stage_name'],
            // Bkz. PipelineStageResource — DOLUYSA arayüz `enums:pipelineStage.<key>`yi
            // `stage_name`i defaultValue yaparak çevirir; NULL'sa müşteri verisidir.
            'stage_name_key' => $this->resource['stage_name_key'] ?? null,
            'color' => $this->resource['color'],
            'count' => (int) $this->resource['count'],
            'value' => (string) $this->resource['value'],
        ];
    }
}
