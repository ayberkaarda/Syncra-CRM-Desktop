<?php

namespace App\Http\Requests\Tickets;

use App\Http\Requests\Concerns\ForcesRecordOwnerOnCreate;
use App\Services\Tickets\SlaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/tickets` — Yetkilendirme TicketController::store() içinde Policy
 * ile yapılır.
 *
 * `ticket_number`, `status`, `created_by` ve TÜM SLA alanları KASITLI olarak
 * burada YOK: FormRequest->validated() yalnızca rules()'ta tanımlı
 * anahtarları döner, dolayısıyla istemci bunları gönderse bile SESSİZCE yok
 * sayılır — sunucu üretir (bkz. TicketService::create()). Yeni bir ticket her
 * zaman `open` doğar; başka bir durumda doğması, o duruma ait SLA yan
 * etkilerinin (duraklama başlangıcı, `resolved_at`) hiç çalışmadan kaydın
 * oluşması demek olurdu.
 */
class StoreTicketRequest extends FormRequest
{
    use ForcesRecordOwnerOnCreate;

    /**
     * `assigned_to` yalnızca `tickets.assign` iznine sahip aktörden kabul edilir;
     * aksi hâlde isteği yapan kullanıcıya sabitlenir (gerekçe:
     * ForcesRecordOwnerOnCreate).
     */
    protected function prepareForValidation(): void
    {
        $this->forceOwnerUnlessAssigner('assigned_to', 'tickets.assign');
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
            'subject' => ['required', 'string', 'max:255'],
            // `tickets.description` kolonu NOT NULL bir `text`'tir — nullable
            // kabul etmek veritabanı hatasına dönüşürdü.
            'description' => ['required', 'string'],
            'priority' => ['sometimes', 'nullable', Rule::in(SlaService::PRIORITIES)],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'tag_ids' => ['sometimes', 'nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
