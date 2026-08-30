<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/leads` liste sözleşmesi — bkz. ROADMAP standart liste kuralları
 * (page/per_page/sort/q/filter). Yetkilendirme burada DEĞİL,
 * LeadController::index() içinde Policy ile yapılır.
 */
class IndexLeadRequest extends FormRequest
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
            'filter.status' => ['sometimes', 'nullable', Rule::in(StoreLeadRequest::STATUSES)],
            'filter.source' => ['sometimes', 'nullable', Rule::in(StoreLeadRequest::SOURCES)],
            'filter.owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'filter.score_min' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'filter.score_max' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'filter.from' => ['sometimes', 'nullable', 'date'],
            'filter.to' => ['sometimes', 'nullable', 'date'],
            'filter.tag_id' => ['sometimes', 'nullable', 'integer', 'exists:tags,id'],
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
            'status' => $filter['status'] ?? null,
            'source' => $filter['source'] ?? null,
            'owner_id' => $filter['owner_id'] ?? null,
            'score_min' => $filter['score_min'] ?? null,
            'score_max' => $filter['score_max'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'tag_id' => $filter['tag_id'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
