<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/leads/{lead}/convert` gövdesi — App\Services\Leads\LeadConversionService::convert()
 * bu doğrulanmış diziyi `$options` olarak alır. Yetkilendirme
 * LeadController::convert() içinde Policy ile yapılır.
 */
class ConvertLeadRequest extends FormRequest
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
            'create_deal' => ['sometimes', 'boolean'],
            'deal_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'deal_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
        ];
    }
}
