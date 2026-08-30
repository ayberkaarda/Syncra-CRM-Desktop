<?php

namespace App\Http\Requests\Quotes;

use App\Services\Quotes\QuoteStatusMachine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/quotes/{quote}/status` — Yetkilendirme
 * QuoteController::status() içinde Policy ile yapılır.
 *
 * Burada YALNIZCA "böyle bir hedef durum bu uçtan verilebilir mi"
 * doğrulanır. "Bu geçiş yasal mı" sorusu bir FormRequest sorusu DEĞİLDİR:
 * cevabı teklifin O ANKİ durumuna bağlıdır ve o durum, eşzamanlı bir istek
 * yüzünden doğrulama ile yazma arasında değişebilir. Bu yüzden geçiş kararı
 * QuoteStatusMachine içinde, `lockForUpdate` ile kilitlenmiş satır üzerinde
 * verilir (Faz 8'deki StatusTicketRequest ile aynı gerekçe).
 *
 * `sent` ve `draft` KABUL EDİLMEZ: gönderim ayrı bir izin (`quotes.send`) ve
 * ayrı bir ön koşul (kalemi olmayan teklif gönderilemez) taşıdığı için
 * yalnızca `POST /api/quotes/{quote}/send` ucundan yapılır; `draft`'a dönüş
 * ise hiçbir durumdan mümkün değildir (QuoteStatusMachine sınıf dokümanı).
 */
class StatusQuoteRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(QuoteStatusMachine::MANUAL_STATUSES)],
            // Serbest metin gerekçe. Kolonu yoktur; QuoteService bunu
            // `activity_log`'a yazar (bkz. QuoteService::logStatusChange).
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => __('validation.custom.quotes.status_transition', [
                'statuses' => implode(', ', QuoteStatusMachine::MANUAL_STATUSES),
            ]),
        ];
    }
}
