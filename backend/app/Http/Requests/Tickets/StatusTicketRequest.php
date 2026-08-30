<?php

namespace App\Http\Requests\Tickets;

use App\Services\Tickets\TicketStatusMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/tickets/{ticket}/status` — Yetkilendirme
 * TicketController::status() içinde Policy ile yapılır.
 *
 * Burada YALNIZCA "böyle bir durum var mı" doğrulanır (`Rule::in`). "Bu
 * geçiş yasal mı" sorusu bir FormRequest sorusu DEĞİLDİR: cevabı ticket'ın
 * O ANKİ durumuna bağlıdır ve o durum, eşzamanlı bir istek yüzünden
 * doğrulama ile yazma arasında değişebilir. Bu yüzden geçiş kararı
 * TicketStatusMachine içinde, `lockForUpdate` ile kilitlenmiş satır üzerinde
 * verilir ve geçersizse 422 `INVALID_STATUS_TRANSITION` döner
 * (docs/SLA-DESIGN.md §4).
 */
class StatusTicketRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(TicketStatusMachine::statuses())],
        ];
    }
}
