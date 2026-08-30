<?php

namespace App\Http\Requests\Quotes;

use App\Services\Quotes\QuoteCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/quotes/calculate` — KALICI OLMAYAN hesap ucu.
 *
 * =============================================================================
 * NEDEN AYRI (VE DAHA DAR) BİR KURAL SETİ
 * =============================================================================
 * Bu uç QuoteItemRules'u KULLANMAZ, bilinçli olarak. Oradaki iki kural burada
 * yanlış olurdu:
 *
 *   - `items.*.product_id` => `exists:products,id`: hiçbir şey KAYDEDİLMEDİĞİ
 *     için referans bütünlüğünün burada koruyacağı bir şey yoktur. Kalem
 *     başına bir `exists` sorgusu, formda her tuş vuruşunda çağrılan bir uca
 *     veritabanı yükü bindirirdi — üstelik hesabın sonucunu hiç
 *     değiştirmeden.
 *   - `items.*.name` => `required_without:items.*.product_id`: ad, hesabın
 *     GİRDİSİ DEĞİLDİR. Kullanıcı henüz kalem adını yazmadan miktar ve fiyat
 *     girdiğinde canlı toplamı görebilmelidir; adı zorunlu kılmak, formu
 *     hesaplanabilir olmayan bir ara duruma hapsederdi.
 *
 * Kaydetme uçlarında bu iki kural elbette geçerlidir — orada gerçekten bir
 * kayıt doğuyor. Burada girdinin tek anlamı "şu sayılarla toplam ne olur".
 *
 * =============================================================================
 * DOĞRULAMANIN İKİ KATMANI
 * =============================================================================
 * Buradaki kurallar UCUZ ve YAPISAL olanlardır (tip, aralık, kalem sayısı).
 * İş kuralı doğrulaması — `discount_amount > subtotal` reddi, yüzde tipinde
 * `discount_value > 100` — QuoteCalculator'ın KENDİSİNDEDİR ve orada
 * kalmalıdır: cevabı ancak ara toplam hesaplandıktan SONRA bilinebilir,
 * dolayısıyla bir FormRequest sorusu değildir. Controller o hatayı aynı 422
 * zarfına çevirir.
 */
class CalculateQuoteRequest extends FormRequest
{
    /**
     * Tek istekte hesaplanabilecek en fazla kalem.
     *
     * StoreQuoteRequest/UpdateQuoteRequest'teki `items` sınırıyla AYNI (200):
     * 200 kalemlik bir teklif kaydedilebiliyorsa, kaydedilmeden önce
     * önizlenebilmelidir de. Sınırın varlık sebebi CPU'dur — bu uç kimlik
     * doğrulamalı olsa bile sınırsız girdi, bcmath ile keyfi hassasiyette
     * çarpım yapan bir döngüyü besleyen ucuz bir yük vektörüdür.
     */
    public const MAX_ITEMS = 200;

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
            // BOŞ DİZİ GEÇERLİDİR (`min` kuralı YOK) — gerekçe
            // QuoteController::calculate() dokümanında.
            'items' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],

            // Hesabın gerçek girdileri.
            'items.*.quantity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'items.*.unit_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            // Yalnızca GERİ YANKILANAN alanlar: QuoteCalculator tanımadığı
            // anahtarları dokunmadan geçirir, böylece istemci yanıttaki
            // satırları formdaki satırlarla eşleştirebilir. Hesaba
            // GİRMEZLER; `exists` kuralı da yoktur (bkz. sınıf dokümanı).
            'items.*.product_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'discount_type' => ['sometimes', 'nullable', Rule::in(QuoteCalculator::DISCOUNT_TYPES)],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discount_type.in' => __('validation.custom.quotes.discount_type_invalid'),
        ];
    }
}
