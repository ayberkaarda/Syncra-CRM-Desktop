<?php

namespace App\Http\Requests\PriceLists;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceListRequest extends FormRequest
{
    /**
     * Yetkilendirme PriceListController::update() içinde Policy ile yapılır.
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
        $priceListId = $this->route('priceList')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('price_lists', 'code')->ignore($priceListId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
