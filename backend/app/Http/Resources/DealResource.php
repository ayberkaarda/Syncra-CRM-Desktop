<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesAbilities;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detay görünümü — `GET /api/deals/{deal}`, `POST /api/deals`,
 * `PATCH /api/deals/{deal}`, `PATCH /api/deals/{deal}/assign`.
 *
 * DealCardResource'un tüm alanlarını içerir + `description`, `lost_reason`,
 * `won_reason`, `closed_at`, tam `pipeline_stage`, `custom_fields`,
 * zaman damgaları.
 *
 * @property-read Deal $resource
 */
class DealResource extends JsonResource
{
    use ExposesAbilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Deal $deal */
        $deal = $this->resource;

        return [
            'id' => $deal->id,
            'title' => $deal->title,
            'description' => $deal->description,
            'amount' => (float) $deal->amount,
            'currency' => $deal->currency,
            // --- Kapanışta DONMUŞ temel-para-birimi karşılığı (Faz 14/İz E) ---
            // Açık fırsatta ve kuru bulunamamış kapanışta null'dır; arayüz
            // null gördüğünde "kapanış kuru" satırını hiç basmaz.
            // `(float)` cast'i yalnızca GÖSTERİM sınırıdır — mevcut `amount`
            // sözleşmesiyle aynı (otoriter para matematiği sunucuda, bcmath
            // ile ve decimal string üzerinde yapılır).
            'base_amount' => $deal->base_amount === null ? null : (float) $deal->base_amount,
            'base_currency' => $deal->base_amount === null
                ? null
                : strtoupper((string) config('exchange.base_currency', 'TRY')),
            'base_rate' => $deal->base_rate === null ? null : (float) $deal->base_rate,
            'base_rate_date' => $deal->base_rate_date?->toDateString(),
            'position' => $deal->position,
            'version' => $deal->version,
            'probability' => $deal->probability,
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'status' => $deal->status,
            'lost_reason' => $deal->lost_reason,
            'won_reason' => $deal->won_reason,
            'closed_at' => $deal->closed_at?->toIso8601String(),
            'pipeline_stage' => $deal->relationLoaded('pipelineStage') && $deal->pipelineStage
                ? new PipelineStageResource($deal->pipelineStage)
                : null,
            'company' => $deal->relationLoaded('company') && $deal->company
                ? ['id' => $deal->company->id, 'name' => $deal->company->name]
                : null,
            'contact' => $deal->relationLoaded('contact') && $deal->contact
                ? ['id' => $deal->contact->id, 'full_name' => $deal->contact->full_name]
                : null,
            'owner' => $deal->relationLoaded('owner') && $deal->owner
                ? ['id' => $deal->owner->id, 'name' => $deal->owner->name]
                : null,
            'tags' => $deal->relationLoaded('tags')
                ? $deal->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()
                : [],
            'custom_fields' => $deal->relationLoaded('customFieldValues')
                ? $deal->customFieldValues
                    ->mapWithKeys(fn ($value) => [$value->customField->key => $value->value])
                    ->all()
                : [],
            'is_overdue' => $deal->status === 'open'
                && $deal->expected_close_date !== null
                && $deal->expected_close_date->lt(today()),
            // Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
            // `company`/`contact` burada YOK: yukarıdaki alanlar zaten bu yönleri
            // karşılıyor. `quotes.view` izni yoksa anahtar hiç eklenmez (gerekçe
            // DealController::loadRelatedRecords()).
            'related' => array_filter([
                'quotes' => $deal->relationLoaded('relatedQuotes') ? $deal->relatedQuotes : null,
            ], fn ($group) => $group !== null),
            'created_at' => $deal->created_at?->toIso8601String(),
            'updated_at' => $deal->updated_at?->toIso8601String(),
            // Bu kullanıcının bu kayıtta neyi YAPABİLDİĞİ — arayüz kuralı
            // yeniden yazmasın (gerekçe: ExposesAbilities).
            'can' => $this->abilities($request, $deal, [
                'update' => 'update',
                'move' => 'move',
                'delete' => 'delete',
                'assign' => 'assign',
            ]),
        ];
    }
}
