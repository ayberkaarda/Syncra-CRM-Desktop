<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Company $resource
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'industry' => $company->industry,
            'address' => $company->address,
            'city' => $company->city,
            'country' => $company->country,
            'employee_count' => $company->employee_count,
            'annual_revenue' => $company->annual_revenue,
            'notes' => $company->notes,
            'owner' => $company->relationLoaded('owner') && $company->owner
                ? ['id' => $company->owner->id, 'name' => $company->owner->name]
                : null,
            'tags' => $company->relationLoaded('tags')
                ? $company->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()
                : [],
            'custom_fields' => $company->relationLoaded('customFieldValues')
                ? $company->customFieldValues
                    ->mapWithKeys(fn ($value) => [$value->customField->key => $value->value])
                    ->all()
                : [],
            'contacts_count' => $this->whenCounted('contacts'),
            'deals_count' => $this->whenCounted('deals'),
            // Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
            // `contacts` burada YOK: `CompanyDetailPage` bunu zaten ayrı bir uçtan
            // (`GET /api/contacts?filter[company_id]=`) tam liste olarak çiziyor
            // (gerekçe CompanyController::loadRelatedRecords() dokümanında).
            // Her anahtar yalnızca ilgili modülün izniyle YÜKLENDİYSE (Gate::allows
            // viewAny) var olur — izinsiz modül anahtarı yanıtta HİÇ görünmez.
            'related' => array_filter([
                'deals' => $company->relationLoaded('relatedDeals') ? $company->relatedDeals : null,
                'quotes' => $company->relationLoaded('relatedQuotes') ? $company->relatedQuotes : null,
                'tickets' => $company->relationLoaded('relatedTickets') ? $company->relatedTickets : null,
            ], fn ($group) => $group !== null),
            'primary_contact' => $company->relationLoaded('primaryContact') && $company->primaryContact
                ? [
                    'id' => $company->primaryContact->id,
                    'full_name' => $company->primaryContact->full_name,
                    'email' => $company->primaryContact->email,
                ]
                : null,
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }
}
