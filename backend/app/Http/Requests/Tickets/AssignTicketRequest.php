<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/tickets/{ticket}/assign` — Yetkilendirme
 * TicketController::assign() içinde Policy ile yapılır.
 *
 * `assigned_to` NULL kabul eder (AssignDealRequest'ten farkı): bir ticket'ın
 * atamasını KALDIRMAK gerçek bir destek akışıdır — yanlış kişiye düşmüş bir
 * talep havuza geri bırakılır. `tickets.assigned_to` kolonu zaten nullable'dır
 * ve demo veride atanmamış ticket'lar mevcuttur. Devrin SLA sayacına etkisi
 * YOKTUR (docs/SLA-DESIGN.md §2) — sayaç ticket'ındır, kişinin değil.
 */
class AssignTicketRequest extends FormRequest
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
            // `present` + `nullable`: anahtar gövdede BULUNMALI ama değeri
            // null olabilir. Sadece `nullable` olsaydı boş bir gövde de
            // "atamayı kaldır" sayılırdı ve kazara gönderilen boş bir PATCH
            // sessizce ticket'ı sahipsiz bırakırdı.
            'assigned_to' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to.present' => __('validation.custom.tickets.assigned_present'),
        ];
    }
}
