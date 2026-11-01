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
            // 'converted' kabul edilmez: dönüşüm yalnızca dönüşüm servisinden
            // geçer (kişi/firma/fırsat üretir, lead'i kilitler). Doğrudan
            // `converted` oluşturulan lead hem bu izi atlar hem de
            // LeadPolicy'nin "dönüşmüş lead düzenlenemez" kilidine anında girer.
            'status' => ['nullable', Rule::in(self::WRITABLE_STATUSES)],
            'score' => ['nullable', 'integer', 'between:0,100'],
            // Yalnızca aktif ve silinmemiş bir kullanıcıya atanabilir: düz
            // `exists:users,id` devre dışı/silinmiş hesabı da kabul ediyordu.
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->withoutTrashed()],
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

    /**
     * Bir istemcinin (form, CSV, senkron) doğrudan yazabileceği durumlar.
     * STATUSES filtreleme sözlüğü olarak `converted`'ı içermeye devam eder.
     *
     * @var array<int, string>
     */
    public const WRITABLE_STATUSES = [
        'new', 'contacted', 'qualified', 'unqualified',
    ];
}
