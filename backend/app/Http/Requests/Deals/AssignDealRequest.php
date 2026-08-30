<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/deals/{deal}/assign` — Yetkilendirme DealController::assign()
 * içinde Policy ile yapılır.
 */
class AssignDealRequest extends FormRequest
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
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
