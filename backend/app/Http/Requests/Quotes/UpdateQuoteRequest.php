<?php

namespace App\Http\Requests\Quotes;

use App\Services\Quotes\QuoteCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/quotes/{quote}` — Yetkilendirme QuoteController::update()
 * içinde Policy ile yapılır.
 *
 * =============================================================================
 * `status` VE TÜM TUTAR ALANLARI BU UÇTAN DEĞİŞTİRİLEMEZ
 * =============================================================================
 * `missing` kuralı, alan gövdede BULUNURSA (değeri null/boş olsa dahi) 422
 * üretir. Gerekçe Faz 7'deki `PATCH /api/deals/{deal}` -> `/move` ve Faz
 * 8'deki `PATCH /api/tickets/{ticket}` -> `/status` ayrımlarının aynısıdır:
 * genel update ucu ham kolon yazımıdır.
 *
 *  - `status` buradan geçirilebilseydi QuoteStatusMachine'in TÜM kontrolleri
 *    (geçiş tablosu, `lockForUpdate`, damgalar) ve `POST /send` ucundaki iki
 *    ek kural (`quotes.send` izni + "kalemi olmayan teklif gönderilemez")
 *    SESSİZCE baypas edilirdi. Bir kullanıcı `quotes.update` iznine sahip
 *    olarak boş bir teklifi "gönderilmiş" yapabilirdi.
 *  - `subtotal`/`tax_amount`/`total` elle yazılabilseydi teklifin toplamı
 *    kalemleriyle çelişebilirdi. Bunlar sunucunun HESAPLADIĞI değerlerdir;
 *    girdi değil, çıktıdırlar.
 *  - `quote_number` belge kimliğidir; değişmesi denetim izinde aynı numaranın
 *    iki farklı belgeyi işaret etmesi demektir.
 *
 * İNDİRİM: GİRDİ `discount_type` + `discount_value`, ÇIKTI
 * `discount_amount` (docs/QUOTE-FINANCIALS.md §5). Kullanıcı "%5 kır" ya da
 * "1.000 TL kır" der; TL karşılığını QuoteCalculator yazar. `discount_amount`
 * doğrudan kabul edilseydi, yüzde tipi bir teklifte kalem eklendiğinde tutar
 * sabit kalır ve "%5" anlamını yitirirdi.
 *
 * İndirim girdisi yalnızca `draft` durumunda değiştirilebilir; `sent` sonrası
 * kilidi QuoteService::assertAmountsEditable() 422 ile uygular. O kural burada
 * DEĞİL servis katmanındadır, çünkü cevabı teklifin O ANKİ durumuna bağlıdır
 * ve bir FormRequest'in bilmediği bir bilgidir (Faz 8'deki
 * StatusTicketRequest ile aynı gerekçe).
 */
class UpdateQuoteRequest extends FormRequest
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
        return array_merge([
            'title' => ['sometimes', 'string', 'max:255'],
            'deal_id' => ['sometimes', 'nullable', 'integer', 'exists:deals,id'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'contact_id' => ['sometimes', 'nullable', 'integer', 'exists:contacts,id'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'discount_type' => ['sometimes', Rule::in(QuoteCalculator::DISCOUNT_TYPES)],
            'discount_value' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'terms' => ['sometimes', 'nullable', 'string'],
            'items' => ['sometimes', 'array', 'max:200'],

            // Bunların HİÇBİRİ gövdede bulunmamalı (değeri boş/null olsa dahi).
            'status' => ['missing'],
            'quote_number' => ['missing'],
            'subtotal' => ['missing'],
            'discount_amount' => ['missing'],
            'tax_amount' => ['missing'],
            'total' => ['missing'],
            'sent_at' => ['missing'],
            'accepted_at' => ['missing'],
            'rejected_at' => ['missing'],
            'created_by' => ['missing'],
            'parent_quote_id' => ['missing'],
            'revision' => ['missing'],
        ], QuoteItemRules::rules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $totalsMessage = __('validation.custom.quotes.totals_locked');

        return array_merge([
            'discount_type.in' => __('validation.custom.quotes.discount_type_invalid'),

            'status.missing' => __('validation.custom.quotes.status_locked'),
            'quote_number.missing' => __('validation.custom.quotes.quote_number_locked'),
            'subtotal.missing' => $totalsMessage,
            'discount_amount.missing' => __('validation.custom.quotes.discount_amount_locked'),
            'tax_amount.missing' => $totalsMessage,
            'total.missing' => $totalsMessage,
            'sent_at.missing' => __('validation.custom.quotes.sent_at_locked'),
            'accepted_at.missing' => __('validation.custom.quotes.accepted_at_locked'),
            'rejected_at.missing' => __('validation.custom.quotes.rejected_at_locked'),
            'created_by.missing' => __('validation.custom.quotes.created_by_locked'),
            'parent_quote_id.missing' => __('validation.custom.quotes.parent_quote_id_locked'),
            'revision.missing' => __('validation.custom.quotes.revision_locked'),
        ], QuoteItemRules::messages());
    }
}
