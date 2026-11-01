<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
        ];
    }
}
