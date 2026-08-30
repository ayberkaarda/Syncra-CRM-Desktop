<?php

namespace App\Http\Resources;

use App\Models\Quote;
use App\Services\Quotes\QuoteCalculationException;
use App\Services\Quotes\QuoteCalculator;
use App\Services\Quotes\QuoteExpiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =============================================================================
 * Teklif gösterimi
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * LİSTE UCUNDA `items` DÖNMEZ
 * -----------------------------------------------------------------------------
 * `items` yalnızca ilişki YÜKLENMİŞSE (detay ucu) doldurulur; liste ucunda
 * `null` olur ve arayüz `items_count` rozetini gösterir. 100 teklifin
 * kalemlerini listede taşımak, yanıtı kalem sayısıyla çarpar ve hiçbir liste
 * ekranının kullanmadığı veriyi ağdan geçirir.
 *
 * `null` seçildi (boş dizi değil): boş dizi "bu teklifin kalemi yok" demektir
 * ve gerçekten kalemsiz bir taslakla ayırt edilemezdi. `null` açıkça "bu uçta
 * gönderilmiyor" der. Faz 8'deki TicketResource'un yüklenmemiş ilişkiler için
 * `null` döndürmesiyle aynı sözleşme.
 *
 * -----------------------------------------------------------------------------
 * `is_expired` TÜRETİLMİŞTİR
 * -----------------------------------------------------------------------------
 * Kalıcı bir kolon değildir; her yanıtta QuoteExpiry ile hesaplanır — Faz
 * 8'deki `sla_breached` ile aynı karar ve aynı gerekçe (bkz. QuoteExpiry
 * sınıf dokümanı). İstemci `Date.now()` ile `valid_until`'i KENDİ
 * karşılaştırmamalıdır: kullanıcının sistem saati/saat dilimi kayarsa iki
 * kullanıcı aynı teklif için farklı şey görür.
 *
 * @property-read Quote $resource
 */
class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Quote $quote */
        $quote = $this->resource;

        $expiry = app(QuoteExpiry::class);

        return [
            'id' => $quote->id,
            'quote_number' => $quote->quote_number,
            'title' => $quote->title,
            'status' => $quote->status,
            'valid_until' => $quote->valid_until?->toDateString(),
            'is_expired' => $expiry->isExpired($quote),

            // Para alanları `(float)` — DealResource::amount ile aynı
            // sözleşme (`decimal:2` cast'i string üretir).
            'subtotal' => (float) $quote->subtotal,
            // İndirim GİRDİSİ (kullanıcının yazdığı) ve ÇIKTISI (TL karşılığı)
            // birlikte döner: arayüz "%5" yazan alanı ham değerle doldurur,
            // toplamlar satırında ise hesaplanmış TL tutarı gösterir
            // (docs/QUOTE-FINANCIALS.md §5).
            'discount_type' => $quote->discount_type ?? 'amount',
            'discount_value' => (float) ($quote->discount_value ?? 0),
            'discount_amount' => (float) $quote->discount_amount,
            'tax_amount' => (float) $quote->tax_amount,
            'total' => (float) $quote->total,
            'currency' => $quote->currency,
            // --- `sent` anında DONMUŞ kur (Faz 14/İz E, PHASE-INTL §2.3) ---
            // Taslakta null (henüz gönderilmedi → donacak bir an yok) ve kur
            // hiç bulunamayan bir gönderimde de null. PDF/arayüz null gördüğünde
            // kur satırını basmaz — uydurma bir kur göstermez.
            'exchange_rate' => $quote->exchange_rate === null ? null : (float) $quote->exchange_rate,
            'exchange_rate_date' => $quote->exchange_rate_date?->toDateString(),

            // --- Revizyon zinciri (sözleşme §6) ---
            'revision' => (int) ($quote->revision ?? 1),
            'parent_quote_id' => $quote->parent_quote_id,

            'notes' => $quote->notes,
            'terms' => $quote->terms,

            'sent_at' => $quote->sent_at?->toIso8601String(),
            'accepted_at' => $quote->accepted_at?->toIso8601String(),
            'rejected_at' => $quote->rejected_at?->toIso8601String(),

            'deal' => $quote->relationLoaded('deal') && $quote->deal
                ? ['id' => $quote->deal->id, 'title' => $quote->deal->title]
                : null,
            'company' => $quote->relationLoaded('company') && $quote->company
                ? ['id' => $quote->company->id, 'name' => $quote->company->name]
                : null,
            'contact' => $quote->relationLoaded('contact') && $quote->contact
                ? ['id' => $quote->contact->id, 'full_name' => $quote->contact->full_name]
                : null,
            'creator' => $quote->relationLoaded('creator') && $quote->creator
                ? ['id' => $quote->creator->id, 'name' => $quote->creator->name]
                : null,

            // Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3).
            // `company`/`deal`/`contact` alanları YUKARIDA zaten var — bunlar
            // `QuoteDetailPage`'in özet alanları için (izinsiz de görünür, bu
            // fazdan önceki bir karar). `related.*` onların TEKRARIDIR ama
            // yalnızca ilgili modülün `viewAny` Policy'si `true` dönerse
            // yüklenir (bkz. QuoteController::loadRelatedRecords()) — izinsiz
            // kullanıcı bu anahtarı HİÇ görmez (boş dizi bile değil). Ortak
            // `RelatedRecordsPanel`/`companyGroupConfig` vb. bu sözleşmeyi
            // (`{total, items}`) bekliyor, ham `company`/`deal`/`contact`
            // alanları değil.
            'related' => array_filter([
                'company' => $quote->relationLoaded('relatedCompany') ? $quote->relatedCompany : null,
                'deal' => $quote->relationLoaded('relatedDeal') ? $quote->relatedDeal : null,
                'contact' => $quote->relationLoaded('relatedContact') ? $quote->relatedContact : null,
            ], fn ($group) => $group !== null),

            'items' => $quote->relationLoaded('items')
                ? QuoteItemResource::collection($quote->items)
                : null,
            // Oran bazlı KDV matrah özeti (sözleşme §3). Yalnızca detayda
            // döner ve KAYITLI kalemlerden yeniden türetilir — başlıktaki
            // `tax_amount` ile aynı fonksiyondan geldiği için ikisi
            // sapamaz. Saf bir hesaptır, ek sorgu üretmez.
            'tax_breakdown' => $quote->relationLoaded('items')
                ? $this->taxBreakdown($quote)
                : null,
            // `withCount` yüklenmediyse ilişkiden sayılır; o da yoksa 0.
            'items_count' => (int) ($quote->items_count
                ?? ($quote->relationLoaded('items') ? $quote->items->count() : 0)),

            'created_at' => $quote->created_at?->toIso8601String(),
            'updated_at' => $quote->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, float>>
     */
    protected function taxBreakdown(Quote $quote): array
    {
        $items = $quote->items->map(fn ($item) => [
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'discount_percent' => (float) $item->discount_percent,
            'tax_rate' => (float) $item->tax_rate,
        ])->all();

        try {
            return QuoteCalculator::taxBreakdown(
                $items,
                $quote->discount_value ?? 0,
                (string) ($quote->discount_type ?? QuoteCalculator::DISCOUNT_AMOUNT),
            );
        } catch (QuoteCalculationException) {
            // Bir GÖSTERİM katmanı, kaydın hesaplanabilirliği yüzünden
            // isteği düşürmemelidir: yazma uçları zaten aynı doğrulamayı
            // 422 ile uygular, buraya ancak elle bozulmuş bir kayıt düşebilir.
            return [];
        }
    }
}
