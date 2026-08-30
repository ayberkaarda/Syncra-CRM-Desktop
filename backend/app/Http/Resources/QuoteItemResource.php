<?php

namespace App\Http\Resources;

use App\Models\QuoteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teklif kalemi gösterimi.
 *
 * `name`, `description`, `unit_price` ve `tax_rate` ürünün ANLIK KOPYASIDIR
 * (Faz 3 kararı), canlı ürün kaydından okunmaz. Bu yüzden burada
 * `$item->product->name` gibi bir erişim YOKTUR — olsaydı ürün adı sonradan
 * değiştiğinde geçmiş teklifler de değişmiş görünür, ürün silindiğinde ise
 * kalem adsız kalırdı. `product_id` yalnızca "bu kalem hangi katalog
 * kaydından geldi" izini taşır; ürün kalıcı olarak silinirse
 * `nullOnDelete` ile null'a düşer ve kalem yine bozulmaz.
 *
 * Para alanları `(float)` olarak döner — Faz 7'deki DealResource'un `amount`
 * alanıyla aynı sözleşme (Eloquent'in `decimal:2` cast'i string üretir,
 * arayüz sayı bekler).
 *
 * @property-read QuoteItem $resource
 */
class QuoteItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var QuoteItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->name,
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount_percent' => (float) $item->discount_percent,
            'tax_rate' => (float) $item->tax_rate,
            // `line_total` KDV HARİÇTİR (sözleşme §5b) ve öyle kalır.
            'line_total' => (float) $item->line_total,
            // KDV DAHİL satır tutarı — TÜRETİLMİŞ gösterim değeri, kolonu
            // YOKTUR (sözleşme §5b). Arayüz/PDF kalem tablosunda "KDV Dahil"
            // sütununu bununla çizer.
            //
            // DİKKAT: teklif geneli indirim varken bu sütunun TOPLAMI
            // `total`'a eşit ÇIKMAYABİLİR (indirim kalemlere değil, oran
            // gruplarına dağıtılır). Bu bir hata değildir; dipnot toplamları
            // DAİMA teklif başlığındaki subtotal/discount_amount/tax_amount/
            // total alanlarından basılır, bu sütunun toplamından ASLA
            // türetilmez.
            'line_gross' => $this->grossTotal($item),
            'position' => (int) $item->position,
        ];
    }

    /**
     * `round2(line_total × (1 + tax_rate/100))` — half-up, kuruş tabanlı.
     *
     * Tam sayı kuruş üzerinden hesaplanır ki gösterim değeri de teklifin geri
     * kalanıyla aynı yuvarlama kuralına uysun.
     */
    protected function grossTotal(QuoteItem $item): float
    {
        $netKurus = (int) round(((float) $item->line_total) * 100);
        $rateBasis = (int) round(((float) $item->tax_rate) * 100);

        $grossKurus = (int) bcdiv(
            bcadd(bcmul((string) $netKurus, (string) (10000 + $rateBasis)), '5000'),
            '10000',
            0
        );

        return round($grossKurus / 100, 2);
    }
}
