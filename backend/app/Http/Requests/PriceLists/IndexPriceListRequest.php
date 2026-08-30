<?php

namespace App\Http\Requests\PriceLists;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/price-lists` liste sözleşmesi — Faz 6/7/8 standart liste
 * kuralları (page/per_page/sort/q/filter). Yetkilendirme burada DEĞİL,
 * PriceListController::index() içinde Policy ile yapılır.
 */
class IndexPriceListRequest extends FormRequest
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
            'filter.is_active' => ['sometimes', 'nullable', 'boolean'],
            'filter.is_default' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $filter = $validated['filter'] ?? [];

        return [
            'q' => $validated['q'] ?? null,
            'is_active' => $filter['is_active'] ?? null,
            'is_default' => $filter['is_default'] ?? null,
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
