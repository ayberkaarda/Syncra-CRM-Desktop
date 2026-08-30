<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadRequest extends FormRequest
{
    /**
     * Yetkilendirme LeadController::update() içinde Policy ile yapılır
     * (dönüşmüş lead güncellenemez kuralı da orada uygulanır).
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
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', Rule::in(StoreLeadRequest::SOURCES)],
            // 'converted' burada KASITLI olarak listede yok: statüyü doğrudan
            // dönüşmüş yapmaya çalışan istek 422 alır — dönüşüm yalnızca
            // POST /api/leads/{lead}/convert üzerinden yapılabilir.
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'unqualified'])],
            'score' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            // `owner_id` gövdede HİÇ bulunmamalı (Faz 13 / F8): devretme AYRI bir
            // izin kapısıdır (`leads.assign`) ve AYRI bir ucu vardır
            // (PATCH /api/leads/{lead}/assign). Burada kabul edildiği sürece
            // yalnız `leads.update` taşıyan bir temsilci lead'i istediği kişiye
            // devredebiliyordu — izin kapısı baypas ediliyordu.
            'owner_id' => ['missing'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tag_ids' => ['sometimes', 'nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => __('validation.custom.leads.status_transition'),
            'owner_id.missing' => __('validation.custom.leads.owner_locked'),
        ];
    }
}
