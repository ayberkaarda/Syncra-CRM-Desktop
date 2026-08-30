<?php

namespace App\Http\Requests\Deals;

use App\Http\Requests\Concerns\ForcesRecordOwnerOnCreate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/deals` — Yetkilendirme DealController::store() içinde Policy
 * ile yapılır.
 *
 * `position`, `version` ve `status` KASITLI olarak burada YOK: FormRequest
 * ->validated() yalnızca rules()'ta tanımlı anahtarları döner, dolayısıyla
 * istemci bu alanları gönderse bile sessizce yok sayılır — sunucu tarafında
 * DealService::create() üretir (position: FractionalIndex, version: 1,
 * status: 'open').
 */
class StoreDealRequest extends FormRequest
{
    use ForcesRecordOwnerOnCreate;

    /**
     * `owner_id` yalnızca `deals.assign` iznine sahip aktörden kabul edilir;
     * aksi hâlde isteği yapan kullanıcıya sabitlenir (gerekçe:
     * ForcesRecordOwnerOnCreate).
     */
    protected function prepareForValidation(): void
    {
        $this->forceOwnerUnlessAssigner('owner_id', 'deals.assign');
    }

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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'pipeline_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['nullable', 'array'],
        ];
    }
}
