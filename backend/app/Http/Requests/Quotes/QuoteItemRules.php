<?php

namespace App\Http\Requests\Quotes;

/**
 * `items.*` doğrulama kuralları — StoreQuoteRequest ve UpdateQuoteRequest
 * arasında PAYLAŞILAN tek tanım.
 *
 * Kopyalanmış iki kural listesi, "oluştururken kabul edilen ama
 * güncellerken reddedilen kalem" gibi sessiz tutarsızlıkların en verimli
 * kaynağıdır; kalem sözleşmesi bir belgede tek başına anlamlıdır, hangi HTTP
 * fiiliyle geldiğine göre değişmez.
 *
 * `line_total` BİLEREK YOKTUR: kalem toplamını sunucu hesaplar
 * (QuoteCalculator). İstemciden kabul edilseydi, teklif toplamıyla kalem
 * toplamlarının çelişmesi mümkün olurdu.
 */
final class QuoteItemRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items.*' => ['array'],
            'items.*.product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            // Ürün seçilmediyse ad ZORUNLUDUR (serbest kalem); ürün
            // seçildiyse boş bırakılabilir, o zaman ürünün o anki adı
            // kopyalanır (QuoteService::hydrateFromProducts).
            'items.*.name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
            'items.*.description' => ['sometimes', 'nullable', 'string'],
            // decimal(10,2): en fazla 99.999.999,99.
            'items.*.quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // decimal(15,2). Üst sınır, kalem tutarının kolonu taşırmaması
            // için QuoteCalculator'daki eşikle uyumlu tutuldu.
            'items.*.unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            // Sıra SUNUCU tarafından, dizideki sıraya göre yeniden yazılır
            // (QuoteRepository::replaceItems): istemcinin gönderdiği
            // `position` ile dizi sırası çelişebilirdi.
            'items.*.line_total' => ['missing'],
            'items.*.position' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'items.*.line_total.missing' => __('validation.custom.quotes.item_line_total_locked'),
            'items.*.position.missing' => __('validation.custom.quotes.item_position_locked'),
        ];
    }
}
