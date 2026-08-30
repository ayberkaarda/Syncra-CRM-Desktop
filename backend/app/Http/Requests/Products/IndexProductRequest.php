<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/products` liste sözleşmesi — Faz 6/7/8 standart liste kuralları
 * (page/per_page/sort/q/filter). Yetkilendirme burada DEĞİL,
 * ProductController::index() içinde Policy ile yapılır.
 */
class IndexProductRequest extends FormRequest
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
            'filter.category' => ['sometimes', 'nullable', 'string', 'max:255'],
            // `boolean` kuralı "1"/"0"/"true"/"false"/1/0/true/false kabul
            // eder — query string'den her zaman string geldiği için bu şart.
            'filter.is_active' => ['sometimes', 'nullable', 'boolean'],
            'filter.tag_id' => ['sometimes', 'nullable', 'integer', 'exists:tags,id'],
            'filter.price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'filter.price_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'filter.in_stock' => ['sometimes', 'nullable', 'boolean'],
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
            'category' => $filter['category'] ?? null,
            'is_active' => $filter['is_active'] ?? null,
            'tag_id' => $filter['tag_id'] ?? null,
            'price_min' => $filter['price_min'] ?? null,
            'price_max' => $filter['price_max'] ?? null,
            'in_stock' => filter_var($filter['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sort' => $validated['sort'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
