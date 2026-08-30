<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/deals/{deal}` — Yetkilendirme DealController::update()
 * içinde Policy ile yapılır.
 *
 * `pipeline_stage_id`, `position`, `version`, `status` buradan KESİNLİKLE
 * değiştirilemez: `missing` kuralı bu alanlardan biri gövdede bulunursa
 * (değeri ne olursa olsun) 422 üretir. Aşama değişimi yalnızca
 * `PATCH /api/deals/{deal}/move` üzerinden yapılır (A şeridi) — aksi halde
 * optimistic locking (version) baypas edilir ve Kanban `position` sıralaması
 * bozulur.
 *
 * =============================================================================
 * `owner_id` DA BU UÇTAN DEĞİŞTİRİLEMEZ (Faz 13 / F8)
 * =============================================================================
 * Devretme AYRI bir izin kapısıdır (`deals.assign`) ve AYRI bir ucu vardır
 * (`PATCH /api/deals/{deal}/assign`). `owner_id` burada da kabul edildiği
 * sürece o kapı fiilen yoktu: yalnız `deals.update` taşıyan bir temsilci,
 * genel update ucundan deal'i istediği kişiye devredebiliyordu. Artık aynı
 * `missing` deseniyle kapalı.
 */
class UpdateDealRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'probability' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'tag_ids' => ['sometimes', 'nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],

            // Bunların HİÇBİRİ gövdede bulunmamalı (değeri boş/null olsa dahi).
            'pipeline_stage_id' => ['missing'],
            'position' => ['missing'],
            'version' => ['missing'],
            'status' => ['missing'],
            'owner_id' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $stageChangeMessage = __('validation.custom.deals.stage_locked');

        return [
            'pipeline_stage_id.missing' => $stageChangeMessage,
            'position.missing' => $stageChangeMessage,
            'version.missing' => $stageChangeMessage,
            'status.missing' => $stageChangeMessage,
            'owner_id.missing' => __('validation.custom.deals.owner_locked'),
        ];
    }
}
