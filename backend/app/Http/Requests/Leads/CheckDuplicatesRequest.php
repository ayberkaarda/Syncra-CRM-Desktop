<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/leads/check-duplicates` gövdesi —
 * App\Services\Leads\DuplicateDetector::findCandidates() bu doğrulanmış
 * diziyi `$input` olarak alır. En az bir alan dolu olmalı, aksi halde
 * tespit tüm tabloyu tarardı.
 */
class CheckDuplicatesRequest extends FormRequest
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
        $others = ['email', 'phone', 'first_name', 'last_name', 'company_name'];

        return [
            'email' => ['nullable', 'required_without_all:'.implode(',', array_diff($others, ['email'])), 'email'],
            'phone' => ['nullable', 'string'],
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
            'company_name' => ['nullable', 'string'],
            'exclude_lead_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without_all' => __('validation.custom.leads.duplicate_required'),
        ];
    }

    /**
     * Repository/Service katmanının beklediği `$input` dizisini üretir.
     *
     * @return array<string, mixed>
     */
    public function duplicateInput(): array
    {
        $validated = $this->validated();

        return [
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
        ];
    }

    public function excludeLeadId(): ?int
    {
        $id = $this->validated()['exclude_lead_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }
}
