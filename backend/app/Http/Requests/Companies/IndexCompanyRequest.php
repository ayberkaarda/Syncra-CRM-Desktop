<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

class IndexCompanyRequest extends FormRequest
{
    /**
     * Yetkilendirme CompanyController::index() içinde Policy ile yapılır.
     */
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
            'sort' => ['sometimes', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
            'filter' => ['sometimes', 'array'],
            'filter.industry' => ['nullable', 'string', 'max:255'],
            'filter.owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'filter.city' => ['nullable', 'string', 'max:255'],
            'filter.country' => ['nullable', 'string', 'max:255'],
            'filter.tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date'],
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

        return [
            'q' => $validated['q'] ?? null,
            'industry' => $validated['filter']['industry'] ?? null,
            'owner_id' => $validated['filter']['owner_id'] ?? null,
            'city' => $validated['filter']['city'] ?? null,
            'country' => $validated['filter']['country'] ?? null,
            'tag_id' => $validated['filter']['tag_id'] ?? null,
            'from' => $validated['filter']['from'] ?? null,
            'to' => $validated['filter']['to'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
