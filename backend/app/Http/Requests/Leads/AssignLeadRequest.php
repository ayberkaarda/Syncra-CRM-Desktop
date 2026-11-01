<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignLeadRequest extends FormRequest
{
    /**
     * Yetkilendirme LeadController::assign() içinde Policy ile yapılır.
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
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
        ];
    }
}
