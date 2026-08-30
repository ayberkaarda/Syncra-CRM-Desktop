<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/deals` liste sözleşmesi — bkz. ROADMAP standart liste kuralları
 * (page/per_page/sort/q/filter). Yetkilendirme burada DEĞİL,
 * DealController::index() içinde Policy ile yapılır.
 */
class IndexDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:64'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.stage_id' => ['sometimes', 'nullable', 'integer', 'exists:pipeline_stages,id'],
            'filter.status' => ['sometimes', 'nullable', Rule::in(['open', 'won', 'lost'])],
            'filter.owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'filter.contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'filter.tag_id' => ['sometimes', 'nullable', 'integer', 'exists:tags,id'],
            'filter.amount_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'filter.amount_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * Repository/Service katmanının beklediği düz filtre dizisini üretir.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        return [
            'q' => $validated['q'] ?? null,
            'stage_id' => $filter['stage_id'] ?? null,
            'status' => $filter['status'] ?? null,
            'owner_id' => $filter['owner_id'] ?? null,
            'company_id' => $filter['company_id'] ?? null,
            'contact_id' => $filter['contact_id'] ?? null,
            'tag_id' => $filter['tag_id'] ?? null,
            'amount_min' => $filter['amount_min'] ?? null,
            'amount_max' => $filter['amount_max'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
