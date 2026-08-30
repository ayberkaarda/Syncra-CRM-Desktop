<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/products/{product}/price?price_list_id=` — Yetkilendirme
 * ProductController::price() içinde Policy ile yapılır.
 */
class PriceProductRequest extends FormRequest
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
            'price_list_id' => ['sometimes', 'nullable', 'integer', 'exists:price_lists,id'],
        ];
    }
}
