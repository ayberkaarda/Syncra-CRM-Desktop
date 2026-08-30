<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ExposesAbilities;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kanban kartı için hafif gösterim — `GET /api/deals/board`.
 *
 * `version` KASITLI olarak dahil: istemci `/deals/{deal}/move` ile kartı
 * taşırken bu değeri geri gönderir (optimistic locking, A şeridinin
 * DealMoveController'ı `DEAL_VERSION_CONFLICT` ile buna göre 409 üretir).
 *
 * `pipeline_stage_id` de KASITLI olarak dahil: bu kaynak yalnızca board'da
 * değil, A şeridinin `/deals/{deal}/move` 409 çakışma zarfında da kullanılır
 * — orada kartın O AN hangi sütunda olduğu tek doğrudan bilgi kaynağıdır.
 * Board çıktısında zaten sütun altında gruplu geldiği için gereksiz ama
 * zararsızdır; kaynağı her bağlamda (board, move 200, move 409, realtime)
 * kendini tarif eden tek bir şekle sabitler.
 *
 * @property-read Deal $resource
 */
class DealCardResource extends JsonResource
{
    use ExposesAbilities;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Deal $deal */
        $deal = $this->resource;

        return [
            'id' => $deal->id,
            'title' => $deal->title,
            'amount' => (float) $deal->amount,
            'currency' => $deal->currency,
            'pipeline_stage_id' => $deal->pipeline_stage_id,
            'position' => $deal->position,
            'version' => $deal->version,
            'probability' => $deal->probability,
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'status' => $deal->status,
            'company' => $deal->relationLoaded('company') && $deal->company
                ? ['id' => $deal->company->id, 'name' => $deal->company->name]
                : null,
            'contact' => $deal->relationLoaded('contact') && $deal->contact
                ? ['id' => $deal->contact->id, 'full_name' => $deal->contact->full_name]
                : null,
            'owner' => $deal->relationLoaded('owner') && $deal->owner
                ? ['id' => $deal->owner->id, 'name' => $deal->owner->name]
                : null,
            'tags' => $deal->relationLoaded('tags')
                ? $deal->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()
                : [],
            'is_overdue' => $this->isOverdue($deal),
            // Bu kullanıcının bu kayıtta neyi YAPABİLDİĞİ — arayüz kuralı
            // yeniden yazmasın (gerekçe: ExposesAbilities).
            // Panoda yalnızca kartın kendi üzerinde yapılabilen iki eylem var;
            // `delete`/`assign` detay panelinden (DealResource) sorulur —
            // her kart için 4 Gate çağırmanın anlamı yok.
            'can' => $this->abilities($request, $deal, [
                'update' => 'update',
                'move' => 'move',
            ]),
        ];
    }

    protected function isOverdue(Deal $deal): bool
    {
        if ($deal->status !== 'open' || $deal->expected_close_date === null) {
            return false;
        }

        return $deal->expected_close_date->lt(today());
    }
}
