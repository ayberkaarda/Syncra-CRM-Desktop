<?php

namespace App\Services\Deals;

use App\Http\Resources\DealCardResource;
use App\Http\Resources\PipelineStageResource;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Repositories\DealRepository;
use App\Support\FractionalIndex;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class DealService
{
    public function __construct(protected DealRepository $deals) {}

    /**
     * `GET /api/deals`. `totals` FİLTRELENMİŞ TÜM kümenin toplamlarıdır,
     * yalnızca o sayfaya yüklenen kayıtların değil — `paginator->total()`
     * ile aynı filtre setine karşı çalışan `DealRepository::amountTotals()`
     * kullanılır (bkz. o metodun dokümanı: 4 ek `sum()` sorgusu, ham SQL
     * yok, `clone` ile tekrar kullanılabilir taban sorgu).
     *
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     * @return array{paginator: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        $paginator = $this->deals->paginate($filters, $perPage);
        $amountTotals = $this->deals->amountTotals($filters);

        return [
            'paginator' => $paginator,
            'totals' => [
                'count' => $paginator->total(),
                'total_amount' => $amountTotals['total_amount'],
                'open_amount' => $amountTotals['open_amount'],
                'won_amount' => $amountTotals['won_amount'],
                'lost_amount' => $amountTotals['lost_amount'],
                // Demo veride TÜM deal'ler TRY; tek para birimi varsayımı
                // (bkz. DealRepository::amountTotals() dokümanı).
                'currency' => 'TRY',
            ],
        ];
    }

    public function find(int $id): Deal
    {
        return $this->deals->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function create(array $data): Deal
    {
        return DB::transaction(function () use ($data) {
            $tagIds = $data['tag_ids'] ?? [];
            $customFields = $data['custom_fields'] ?? [];
            unset($data['tag_ids'], $data['custom_fields']);

            $stageId = $data['pipeline_stage_id'] ?? $this->deals->firstActiveStageId();

            if ($stageId === null) {
                throw new \RuntimeException(
                    'Aktif bir pipeline aşaması bulunamadı — deal oluşturulamıyor.'
                );
            }

            $stage = PipelineStage::query()->findOrFail($stageId);

            $data['pipeline_stage_id'] = $stage->id;
            $data['position'] = $this->nextPositionForStage($stage->id);
            // Optimistic locking sayacı — her deal 1'den başlar, yalnızca
            // /move ucu (A şeridi) artırır.
            $data['version'] = 1;
            // Durum yalnızca move veya kazanma/kaybetme ile değişir; store
            // isteği status göndermiş olsa bile StoreDealRequest bunu
            // rules()'ta tanımlamadığı için validated() içinde zaten yoktur.
            $data['status'] = 'open';
            $data['probability'] = $data['probability'] ?? $stage->probability;
            $data['amount'] = $data['amount'] ?? 0;
            $data['currency'] = $data['currency'] ?? 'TRY';

            $deal = $this->deals->create($data);

            if (! empty($tagIds)) {
                $this->deals->syncTags($deal, $tagIds);
            }

            if (! empty($customFields)) {
                $this->deals->syncCustomFieldValues($deal, $customFields);
            }

            $deal->load(['owner', 'company', 'contact', 'tags', 'pipelineStage', 'customFieldValues.customField']);

            return $deal;
        });
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     *                                      `pipeline_stage_id`/`position`/`version`/`status`
     *                                      BURAYA HİÇ ULAŞMAZ — UpdateDealRequest bunları
     *                                      `missing` kuralıyla 422'e çevirir.
     */
    public function update(Deal $deal, array $data): Deal
    {
        return DB::transaction(function () use ($deal, $data) {
            $hasTagIds = array_key_exists('tag_ids', $data);
            $tagIds = $data['tag_ids'] ?? [];
            $hasCustomFields = array_key_exists('custom_fields', $data);
            $customFields = $data['custom_fields'] ?? [];
            unset($data['tag_ids'], $data['custom_fields']);

            if (! empty($data)) {
                $this->deals->update($deal, $data);
            }

            if ($hasTagIds) {
                $this->deals->syncTags($deal, $tagIds ?? []);
            }

            if ($hasCustomFields && ! empty($customFields)) {
                $this->deals->syncCustomFieldValues($deal, $customFields);
            }

            $deal->load(['owner', 'company', 'contact', 'tags', 'pipelineStage', 'customFieldValues.customField']);

            return $deal;
        });
    }

    public function delete(Deal $deal): void
    {
        $this->deals->delete($deal);
    }

    public function assign(Deal $deal, int $ownerId): Deal
    {
        $this->deals->update($deal, ['owner_id' => $ownerId]);

        $deal->load(['owner', 'company', 'contact', 'tags', 'pipelineStage']);

        return $deal;
    }

    /**
     * `GET /api/deals/board` — Kanban panosu.
     *
     * N+1 ÖNLEME STRATEJİSİ: her aşama için kart sorgusu ayrı çalışır (sabit
     * sayıda aşama olduğu için bu SABİT, deal sayısıyla BÜYÜMEZ), ama
     * owner/company/contact/tags ilişkileri TÜM aşamalardan toplanan kartlar
     * üzerinde TEK SEFERDE (`Collection::load()`) yüklenir — deal sayısı
     * artsa da, aşama sayısı artsa da ilişki başına sorgu sayısı SABİT kalır.
     * `total_amount`/`count` ise per_stage sınırından bağımsız, ayrı bir
     * `withCount`/`withSum` sorgusuyla (DealRepository::boardAggregates,
     * ham SQL YOK) hesaplanır.
     *
     * @param  array<string, mixed>  $filters
     * @return array{columns: array<int, array<string, mixed>>, currency: string, total_open_amount: float}
     */
    public function board(array $filters, int $perStage): array
    {
        $stages = $this->deals->activeStagesOrdered();
        $aggregates = $this->deals->boardAggregates($filters);
        $totalOpenAmount = $this->deals->totalOpenAmount($filters);

        $cardsByStage = [];
        $allModels = [];

        foreach ($stages as $stage) {
            $cards = $this->deals->cardsForStage($stage->id, $filters, $perStage);
            $hasMore = $cards->count() > $perStage;

            if ($hasMore) {
                $cards = $cards->slice(0, $perStage)->values();
            }

            $cardsByStage[$stage->id] = ['cards' => $cards, 'has_more' => $hasMore];

            foreach ($cards as $card) {
                $allModels[] = $card;
            }
        }

        // Aynı model örnekleri $cardsByStage içinde de referans tutulduğu
        // için burada yüklenen ilişkiler oradaki kartlara da yansır.
        (new EloquentCollection($allModels))->load(['owner', 'company', 'contact', 'tags']);

        $columns = [];

        foreach ($stages as $stage) {
            $aggregate = $aggregates->get($stage->id);
            $stageData = $cardsByStage[$stage->id];

            $columns[] = [
                'stage' => new PipelineStageResource($stage),
                'deals' => DealCardResource::collection($stageData['cards']),
                'meta' => [
                    'count' => (int) ($aggregate->deals_count ?? 0),
                    // withSum boş bir aşamada (hiç eşleşen kart yoksa) null
                    // döner — burada 0'a çevrilmezse yanıtta total_amount
                    // null çıkar.
                    'total_amount' => (float) ($aggregate->deals_sum_amount ?? 0),
                    'has_more' => $stageData['has_more'],
                ],
            ];
        }

        return [
            'columns' => $columns,
            'currency' => 'TRY',
            'total_open_amount' => $totalOpenAmount,
        ];
    }

    /**
     * A şeridinin `App\Support\FractionalIndex::last()` metodunu kullanır —
     * bir aşamanın SONUNA eklenecek yeni bir `position` üretir. İstemciden
     * `position` KABUL EDİLMEZ (bkz. StoreDealRequest).
     *
     * Kendi fractional-index algoritmamızı YAZMIYORUZ: FractionalIndex sınıfı
     * henüz mevcut değilse (A şeridi paralel çalışıyor olabilir), açık bir
     * hata fırlatılır — sessizce yanlış/uyumsuz bir position üretmek,
     * A'nın move ucuyla çakışan iki farklı sıralama şeması yaratır.
     */
    protected function nextPositionForStage(int $stageId): string
    {
        if (! class_exists(FractionalIndex::class)) {
            throw new \RuntimeException(
                'App\\Support\\FractionalIndex henüz hazır değil (A şeridi) — POST /api/deals kullanılamıyor.'
            );
        }

        $last = $this->deals->lastPositionForStage($stageId);

        return FractionalIndex::last($last);
    }
}
