<?php

namespace App\Http\Requests\Tickets;

use App\Services\Tickets\SlaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/tickets/{ticket}` — Yetkilendirme TicketController::update()
 * içinde Policy ile yapılır.
 *
 * =============================================================================
 * `status` VE TÜM SLA ALANLARI BU UÇTAN DEĞİŞTİRİLEMEZ
 * =============================================================================
 * `missing` kuralı, alan gövdede BULUNURSA (değeri null/boş olsa dahi) 422
 * üretir. Gerekçe, Faz 7'deki `PATCH /api/deals/{deal}` -> `/move` ayrımının
 * aynısıdır (docs/SLA-DESIGN.md §4): genel update ucu ham kolon yazımıdır.
 * Buradan `status` geçirilebilseydi TicketStatusMachine'in TÜM SLA yan
 * etkileri — duraklama başlat/bitir, `sla_due_at` kaydırma, `resolved_at`,
 * yeniden açmada raf süresi telafisi — SESSİZCE baypas edilir ve sayaç
 * kalıcı olarak yanlış kalırdı. Aynı şekilde `sla_due_at`/`sla_paused_seconds`
 * elle yazılabilseydi ihlal "düzeltilebilir" bir alan hâline gelirdi.
 *
 * `priority` TEK İSTİSNADIR ve bilerek buradadır: değişimi
 * TicketService::update() içinde §5.2'ye göre `sla_due_at`'in yeniden
 * hesaplanmasını tetikler.
 *
 * `assigned_to` DA BU UÇTAN DEĞİŞTİRİLEMEZ (Faz 13 / F8): eskiden kabul
 * ediliyordu — "Faz 7'de UpdateDealRequest'in `owner_id`'yi kabul etmesiyle
 * aynı desen" gerekçesiyle. O emsalin KENDİSİ bir açıktı ve aynı fazda
 * kapatıldı: devretme AYRI bir izin kapısıdır (`tickets.assign`) ve AYRI bir
 * ucu vardır (`PATCH /api/tickets/{ticket}/assign`); alan burada kabul
 * edildiği sürece o kapı, yalnız `tickets.update` iznine sahip biri tarafından
 * baypas edilebiliyordu. Devrin SLA'ya etkisi yoktur (§2), ama yetkiye vardır.
 */
class UpdateTicketRequest extends FormRequest
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
            'subject' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'priority' => ['sometimes', Rule::in(SlaService::PRIORITIES)],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'tag_ids' => ['sometimes', 'nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],

            // Bunların HİÇBİRİ gövdede bulunmamalı (değeri boş/null olsa dahi).
            'status' => ['missing'],
            'assigned_to' => ['missing'],
            'ticket_number' => ['missing'],
            'sla_due_at' => ['missing'],
            'sla_paused_at' => ['missing'],
            'sla_paused_seconds' => ['missing'],
            'sla_warning_notified_at' => ['missing'],
            'sla_breach_notified_at' => ['missing'],
            'first_response_at' => ['missing'],
            'resolved_at' => ['missing'],
            'closed_at' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $statusMessage = __('validation.custom.tickets.status_locked');
        $slaMessage = __('validation.custom.tickets.sla_locked');

        return [
            'status.missing' => $statusMessage,
            'assigned_to.missing' => __('validation.custom.tickets.assigned_locked'),
            'ticket_number.missing' => __('validation.custom.tickets.number_locked'),
            'sla_due_at.missing' => $slaMessage,
            'sla_paused_at.missing' => $slaMessage,
            'sla_paused_seconds.missing' => $slaMessage,
            'sla_warning_notified_at.missing' => $slaMessage,
            'sla_breach_notified_at.missing' => $slaMessage,
            'first_response_at.missing' => $slaMessage,
            'resolved_at.missing' => $slaMessage,
            'closed_at.missing' => $slaMessage,
        ];
    }
}
