<?php

namespace App\Repositories;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Sync\TagSyncService;
use App\Sync\SyncVersionBumper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DealRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'title', 'amount', 'expected_close_date', 'closed_at', 'status', 'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Filtrelenmiş, aranmış ve sıralanmış deal listesini sayfalı döner
     * (`GET /api/deals` — tablo görünümü).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)->with(['owner', 'company', 'contact', 'tags']);

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    /**
     * Filtrelenmiş TÜM kümenin (sayfalamadan bağımsız) tutar toplamları —
     * `GET /api/deals` `meta.totals`. `count` burada YOK: DealService zaten
     * `paginate()`'in ürettiği `paginator->total()`'dan (aynı filtreyle
     * çalışan COUNT sorgusu) alıyor, burada ikinci kez saymaya gerek yok.
     *
     * HAM SQL YOK: `groupBy('status')->selectRaw(...)` tek sorguda tüm durum
     * toplamlarını çıkarabilirdi ama ham SQL gerektirirdi. Bu projede `app/`
     * altında sıfır ham SQL garantisi var (bkz. `boardAggregates()` dokümanı,
     * Faz 7 sadeleştirme turu) ve bu tek istisnayı açmaya değmez — bunun
     * yerine Eloquent'in `sum()` aggregate'ini DÖRT kez, HER SEFERİNDE aynı
     * filtrelenmiş sorgunun bir `clone`'u üzerinde çalıştırıyoruz.
     *
     * `clone` ŞART: bir Builder `sum()`/`get()` ile bir kez tüketildikten
     * sonra aynı nesne üzerinde ikinci bir `where()` + `sum()` zinciri ya
     * önceki çağrının eklediği `where`'lerin üstüne bir daha ekler ya da
     * tutarsız sonuç üretir — her aggregate'in KENDİ temiz kopyası gerekir.
     *
     * MALİYET: 4 ek sorgu (total + open + won + lost), filtrelenmiş kayıt
     * sayısı ne olursa olsun SABİT — board'daki `boardAggregates()` ile aynı
     * "sorgu sayısı veriyle büyümez" ilkesi. Tek bir gruplu ham SQL sorgusu
     * bunu 1'e indirebilirdi; sıfır-ham-SQL garantisini (Faz 13 güvenlik
     * taramasının tek bir `grep` ile temiz çıkmasını) 3 sorguluk bir
     * performans kazanımından daha değerli buluyoruz — deal hacmi bu projede
     * böyle bir optimizasyonu gerektirecek ölçekte değil.
     *
     * PARA BİRİMİ: tek bir sayı olarak toplanıyor, para birimine göre
     * GRUPLANMIYOR. Demo veride `deals.currency` hep 'TRY' olduğu için bu
     * bugün doğru sonuç verir. Karışık para birimi (ör. bir kısmı USD)
     * girilirse bu toplamlar ANLAMSIZLAŞIR (100 TRY + 100 USD, 200 değildir)
     * — ileride çoklu para birimi gerekirse `totals` para birimine göre
     * gruplanmalı (ör. `totals: [{currency:'TRY', ...}, {currency:'USD', ...}]`),
     * tek bir toplam DEĞİL.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total_amount: float, open_amount: float, won_amount: float, lost_amount: float}
     */
    public function amountTotals(array $filters): array
    {
        $base = $this->baseQuery($filters);

        return [
            'total_amount' => (float) (clone $base)->sum('amount'),
            'open_amount' => (float) (clone $base)->where('status', 'open')->sum('amount'),
            'won_amount' => (float) (clone $base)->where('status', 'won')->sum('amount'),
            'lost_amount' => (float) (clone $base)->where('status', 'lost')->sum('amount'),
        ];
    }

    /**
     * Filtreleri (arama dahil) uygulayan temel sorgu. Board ve liste
     * uçlarının ikisi de bunu kullanır, sadece hangi filtre anahtarlarını
     * doldurduklarında farklılaşırlar.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        return $this->applyFilters(Deal::query(), $filters);
    }

    /**
     * `baseQuery()`'nin filtre mantığı — hem `Deal::query()` üzerinde
     * (liste/board/toplam sorguları) hem de bir `deals` ilişki alt-sorgusu
     * üzerinde (board'un `withCount`/`withSum` kapanışları, bkz.
     * `boardAggregates()`) aynı şekilde kullanılabilsin diye ayrı bir
     * metotta: ikisi de aynı `Deal` model sorgu tipini (`Builder<Deal>`)
     * alır/döner.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhereHas('company', function (Builder $query) use ($term) {
                        $query->where('name', 'like', "%{$term}%");
                    });
            });
        }

        if (array_key_exists('stage_id', $filters) && $filters['stage_id'] !== null) {
            $query->where('pipeline_stage_id', $filters['stage_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('owner_id', $filters) && $filters['owner_id'] !== null) {
            $query->where('owner_id', $filters['owner_id']);
        }

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null) {
            $query->where('company_id', $filters['company_id']);
        }

        if (array_key_exists('contact_id', $filters) && $filters['contact_id'] !== null) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];

            // whereHas burada N+1 riski TAŞIMAZ: yalnızca sonuç kümesini
            // daraltan bir EXISTS alt sorgusu üretir (ana with('tags')
            // eager-load'unu etkilemez, o hâlâ ayrı ve tek bir sorguda kalır).
            $query->whereHas('tags', function (Builder $query) use ($tagId) {
                $query->where('tags.id', $tagId);
            });
        }

        if (array_key_exists('amount_min', $filters) && $filters['amount_min'] !== null) {
            $query->where('amount', '>=', $filters['amount_min']);
        }

        if (array_key_exists('amount_max', $filters) && $filters['amount_max'] !== null) {
            $query->where('amount', '<=', $filters['amount_max']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('expected_close_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('expected_close_date', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * Sıralama parametresini beyaz liste üzerinden çözer.
     * Listede olmayan bir sütun gelirse varsayılana (-created_at) düşer.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(?string $sort): array
    {
        if (empty($sort)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        $direction = 'asc';
        $column = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $column = substr($sort, 1);
        }

        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return [self::DEFAULT_SORT_COLUMN, self::DEFAULT_SORT_DIRECTION];
        }

        return [$column, $direction];
    }

    public function findOrFail(int $id): Deal
    {
        return Deal::with(['owner', 'company', 'contact', 'tags', 'pipelineStage', 'customFieldValues.customField'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Deal
    {
        return Deal::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Deal $deal, array $data): Deal
    {
        $deal->fill($data);
        $deal->save();

        return $deal;
    }

    public function delete(Deal $deal): void
    {
        $deal->delete();
    }

    /**
     * Routed through TagSyncService so the owner's `sync_version` moves with
     * the pivot write - `->tags()->sync()` fires no model event at all, so
     * without it a tag-only edit never reaches a desktop client (protocol
     * §1.4).
     *
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Deal $deal, array $tagIds): void
    {
        TagSyncService::apply($deal, $tagIds);
    }

    /**
     * `custom_fields` girdisini (key => value) `custom_field_values`
     * tablosuna, yalnızca `deals` entity_type'ı için tanımlı alanlarla
     * eşleştirerek yazar. Tanımsız bir key sessizce yok sayılır.
     *
     * @param  array<string, mixed>  $customFields
     */
    public function syncCustomFieldValues(Deal $deal, array $customFields): void
    {
        if (empty($customFields)) {
            return;
        }

        $fields = CustomField::query()
            ->forEntity('deals')
            ->whereIn('key', array_keys($customFields))
            ->get()
            ->keyBy('key');

        $changed = false;

        foreach ($customFields as $key => $value) {
            $field = $fields->get($key);

            if (! $field) {
                continue;
            }

            $row = CustomFieldValue::updateOrCreate(
                [
                    'custom_field_id' => $field->id,
                    'customizable_type' => Deal::class,
                    'customizable_id' => $deal->id,
                ],
                ['value' => is_array($value) ? json_encode($value) : (string) $value]
            );

            $changed = $changed || $row->wasRecentlyCreated || $row->wasChanged();
        }

        // Embedded child (protocol §1.5): `custom_field_values` is not a pull
        // table - its rows ride inside the owner's `custom_fields` payload.
        // When ONLY a custom field changed, the owner row itself is clean, no
        // observer fires, and the edit would never cross a client's cursor.
        // Bumped only when something actually changed, so a no-op upsert does
        // not manufacture a phantom delta.
        if ($changed) {
            SyncVersionBumper::bump($deal);
        }
    }

    /**
     * `POST /api/deals` — `pipeline_stage_id` verilmediğinde hedef aşama:
     * en küçük `position` değerine sahip aktif aşama.
     */
    public function firstActiveStageId(): ?int
    {
        return PipelineStage::query()->where('is_active', true)->orderBy('position')->value('id');
    }

    /**
     * Bir aşamadaki kartların EN SONUNCUSUNUN `position` değeri —
     * FractionalIndex::last() ile "bunun ardına ekle" hesaplamak için.
     */
    public function lastPositionForStage(int $stageId): ?string
    {
        return Deal::query()
            ->where('pipeline_stage_id', $stageId)
            ->orderByDesc('position')
            ->value('position');
    }

    // ---------------------------------------------------------------
    // Kanban panosu — GET /api/deals/board
    // ---------------------------------------------------------------

    /**
     * Aktif aşamaları `position` sıralı döner (Kanban sütunları).
     */
    public function activeStagesOrdered(): Collection
    {
        return PipelineStage::query()->where('is_active', true)->orderBy('position')->get();
    }

    /**
     * Aşama başına TOPLAM kart sayısı ve tutar toplamı — TEK bir sorgu
     * (aşama sayısından bağımsız, kart yükleme sınırından (`per_stage`)
     * bağımsız). Anahtar: pipeline_stage_id (`id`).
     *
     * Saf Eloquent (`withCount`/`withSum`): ham SQL YOK. İkisi de `deals`
     * ilişkisi üzerinde birer alt-sorgu (subquery-select) üretir ve TEK ana
     * sorguda birleşir — `selectRaw` + `groupBy` ile aynı sorgu sayısı
     * (1), ama proje genelinde ham SQL sıfır kalır.
     *
     * Filtre kapanışları `applyFilters()`'ı, aynı `baseQuery()` filtre
     * mantığını, `deals` ilişki alt-sorgusu üzerinde tekrar kullanır.
     *
     * `deals` modelinde SoftDeletes global scope'u var: alt-sorgular da bu
     * scope'a uyar, silinmiş deal'ler otomatik hariç kalır (`selectRaw`
     * sürümünde de aynıydı — `baseQuery()` zaten `Deal::query()` üzerinden
     * gidiyordu, davranış DEĞİŞMEDİ).
     *
     * `deals_sum_amount` bir aşamada hiç (filtreye uyan) kart yoksa `null`
     * döner — DealService bunu `?? 0`'a çevirir, yanıtta `total_amount`
     * asla `null` çıkmaz.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, PipelineStage>
     */
    public function boardAggregates(array $filters): \Illuminate\Support\Collection
    {
        return PipelineStage::query()
            ->where('is_active', true)
            ->withCount(['deals' => fn (Builder $query) => $this->applyFilters($query, $filters)])
            ->withSum(['deals' => fn (Builder $query) => $this->applyFilters($query, $filters)], 'amount')
            ->orderBy('position')
            ->get()
            ->keyBy('id');
    }

    /**
     * Board'daki TÜM (aşama filtresi olmadan) açık deal'lerin tutar toplamı.
     *
     * @param  array<string, mixed>  $filters
     */
    public function totalOpenAmount(array $filters): float
    {
        return (float) $this->baseQuery($filters)->where('status', 'open')->sum('amount');
    }

    /**
     * Bir aşamanın kartlarını `position` ASC sırayla, `$perStage + 1` limitle
     * getirir (fazlası `has_more` hesaplamak için) — composite index
     * (pipeline_stage_id, position) tam bunun için var.
     *
     * @param  array<string, mixed>  $filters
     */
    public function cardsForStage(int $stageId, array $filters, int $perStage): Collection
    {
        return $this->baseQuery($filters)
            ->where('pipeline_stage_id', $stageId)
            ->orderBy('position')
            ->limit($perStage + 1)
            ->get();
    }
}
