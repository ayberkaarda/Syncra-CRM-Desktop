<?php

namespace App\Http\Resources;

use App\Models\PipelineStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PipelineStage $resource
 */
class PipelineStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PipelineStage $stage */
        $stage = $this->resource;

        return [
            'id' => $stage->id,
            'name' => $stage->name,
            // DOLUYSA arayüz `enums:pipelineStage.<name_key>`yi `name`i defaultValue yaparak
            // çevirir; NULL'sa (admin yeniden adlandırmış ya da yeni aşama) `name` MÜŞTERİ
            // VERİSİDİR ve OLDUĞU GİBİ basılır (bkz. PipelineStage modeli, migration
            // 2026_08_25_960001).
            'name_key' => $stage->name_key,
            'slug' => $stage->slug,
            'position' => $stage->position,
            'probability' => $stage->probability,
            'color' => $stage->color,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
            'is_active' => $stage->is_active,
            // withCount('deals') ile yüklendiyse görünür (GET /api/pipeline-stages);
            // Kanban panosunda (board) yüklenmez, orada aşama başına sayım
            // ayrı bir sorgu ile (DealRepository::boardAggregates) gelir.
            'deals_count' => $this->whenCounted('deals'),
        ];
    }
}
