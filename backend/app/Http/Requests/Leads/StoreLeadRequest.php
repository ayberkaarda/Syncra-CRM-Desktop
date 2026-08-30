<?php

namespace App\Http\Requests\Leads;

use App\Http\Requests\Concerns\ForcesRecordOwnerOnCreate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    use ForcesRecordOwnerOnCreate;

    /**
     * `owner_id` yalnızca `leads.assign` iznine sahip aktörden kabul edilir;
     * aksi hâlde isteği yapan kullanıcıya sabitlenir (gerekçe:
     * ForcesRecordOwnerOnCreate).
     */
    protected function prepareForValidation(): void
    {
        $this->forceOwnerUnlessAssigner('owner_id', 'leads.assign');
    }

    /**
     * Yetkilendirme LeadController::store() içinde Policy ile yapılır.
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::in(self::SOURCES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'score' => ['nullable', 'integer', 'between:0,100'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['nullable', 'array'],
        ];
    }

    /**
     * @var array<int, string>
     */
    public const SOURCES = [
        'website', 'referral', 'cold_call', 'email_campaign', 'social_media', 'event', 'other',
    ];

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        'new', 'contacted', 'qualified', 'unqualified', 'converted',
    ];
}
