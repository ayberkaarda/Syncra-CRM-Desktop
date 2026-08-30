<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesAbilities;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Lead $resource
 */
class LeadResource extends JsonResource
{
    use ExposesAbilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        return [
            'id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'full_name' => trim("{$lead->first_name} {$lead->last_name}"),
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company_name' => $lead->company_name,
            'position' => $lead->position,
            'source' => $lead->source,
            'status' => $lead->status,
            'score' => $lead->score,
            'notes' => $lead->notes,
            'owner' => $lead->owner ? [
                'id' => $lead->owner->id,
                'name' => $lead->owner->name,
            ] : null,
            'tags' => $lead->relationLoaded('tags')
                ? $lead->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()
                : [],
            'custom_fields' => $lead->relationLoaded('customFieldValues')
                ? $lead->customFieldValues
                    ->mapWithKeys(fn ($value) => [$value->customField->key => $value->value])
                    ->all()
                : [],
            'converted_at' => $lead->converted_at?->toIso8601String(),
            'converted_contact_id' => $lead->converted_contact_id,
            'converted_company_id' => $lead->converted_company_id,
            'converted_deal_id' => $lead->converted_deal_id,
            // Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
            // Yalnızca dönüşüm hedefi VARSA VE ilgili modülün izniyle
            // yüklendiyse eklenir (gerekçe LeadController::loadRelatedRecords()).
            // Ters yön (bir kişi/firma/fırsatın "hangi lead'den geldiği") şemada
            // yok — UYDURULMADI, atlandı.
            'related' => array_filter([
                'converted_contact' => $lead->relationLoaded('convertedContact') && $lead->convertedContact
                    ? ['total' => 1, 'items' => [[
                        'id' => $lead->convertedContact->id,
                        'full_name' => trim("{$lead->convertedContact->first_name} {$lead->convertedContact->last_name}"),
                    ]]]
                    : null,
                'converted_company' => $lead->relationLoaded('convertedCompany') && $lead->convertedCompany
                    ? ['total' => 1, 'items' => [[
                        'id' => $lead->convertedCompany->id,
                        'name' => $lead->convertedCompany->name,
                    ]]]
                    : null,
                'converted_deal' => $lead->relationLoaded('convertedDeal') && $lead->convertedDeal
                    ? ['total' => 1, 'items' => [[
                        'id' => $lead->convertedDeal->id,
                        'title' => $lead->convertedDeal->title,
                    ]]]
                    : null,
            ], fn ($group) => $group !== null),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
            // Bu kullanıcının bu kayıtta neyi YAPABİLDİĞİ — arayüz kuralı
            // yeniden yazmasın (gerekçe: ExposesAbilities).
            'can' => $this->abilities($request, $lead, [
                'update' => 'update',
                'convert' => 'convert',
                'delete' => 'delete',
                'assign' => 'assign',
            ]),
        ];
    }
}
