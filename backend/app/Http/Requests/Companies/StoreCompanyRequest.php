<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    /**
     * Yetkilendirme CompanyController::store() içinde Policy ile yapılır.
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            // Yalnızca http/https: düz `url` kuralı `javascript:`, `file:`
            // gibi şemaları da geçerli sayar ve bu değer arayüzde bağlantı
            // olarak açılır.
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'employee_count' => ['nullable', 'integer', 'min:0'],
            'annual_revenue' => ['nullable', 'numeric', 'min:0'],
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'array'],
        ];
    }
}
