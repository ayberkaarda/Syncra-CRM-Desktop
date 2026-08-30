<?php

namespace App\Repositories;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Ticket;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketStatusMachine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ticket sorgu katmanı.
 *
 * SlaService BURAYA ENJEKTE EDİLİR (ters bağımlılık DEĞİL): SlaService saf
 * hesap + sorgu-kapsamı üreten bir sınıftır, hiçbir repository'ye bağlı
 * değildir; dolayısıyla döngü oluşmaz. İhlal/risk predicate'lerinin TEK bir
 * yerde (SlaService) yaşaması, `filter[sla_breached]`, `stats.breached_count`
 * ve `tickets:scan-sla`'nın aynı tanımı paylaşmasını garanti eder.
 */
class TicketRepository
{
    /**
     * Sıralama beyaz listesi. Dışındaki her değer SESSİZCE varsayılana
     * (`-created_at`) düşer — istemciye 422 döndürmek, kaydedilmiş bir
     * görünümün kolon adı değişince tamamen kırılmasına yol açardı.
     *
     * `sla_due_at` bu listedeki EN ÖNEMLİ kolondur: `sort=sla_due_at`
     * "en acil önce" görünümünü verir ve mevcut `sla_due_at` index'ini
     * kullanır (§5.4). Duraklamadaki ticket'ların araya karışması kabul
     * edilmiş bir yaklaşıklıktır — donmuş kalan süreye göre mutlak sıralama
     * kolon aritmetiği (ham SQL) gerektirirdi.
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'ticket_number', 'subject', 'priority', 'status', 'sla_due_at', 'created_at', 'resolved_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Liste ve detay uçlarının ortak eager-load seti — N+1'in tek savunması.
     *
     * `notes_count` bir ALT SORGU olarak (withCount) eklenir: ticket başına
     * ayrı bir COUNT sorgusu değil, ana sorgunun içinde tek bir
     * subquery-select. Not sayısı `activities` tablosundan `type='note'` ile
     * türetilir — iç notlar için AYRI TABLO AÇILMADI (bkz. TicketResource
     * dokümanı).
     *
     * @var array<int, string>
     */
    protected const LIST_RELATIONS = ['contact', 'company', 'assignee', 'creator', 'tags'];

    public function __construct(protected SlaService $sla) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->with(self::LIST_RELATIONS)
            ->withCount($this->notesCountRelation());

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    public function findOrFail(int $id): Ticket
    {
        return Ticket::query()
            ->with([...self::LIST_RELATIONS, 'customFieldValues.customField'])
            ->withCount($this->notesCountRelation())
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Ticket
    {
        $ticket = new Ticket;
        $ticket->fill($data);

        // `created_at` AÇIKÇA yazılır ki `sla_due_at = created_at + hedef`
        // invariant'ı (§5.1) saniye hassasiyetinde TAM sağlansın. Laravel'in
        // `updateTimestamps()` metodu `created_at` dirty ise ona DOKUNMAZ,
        // bu yüzden burada verilen değer korunur. Aksi halde servis
        // katmanındaki `now()` ile modelin kendi `freshTimestamp()`'i farklı
        // saniyelere düşebilir ve invariant bir saniye kayardı.
        if (isset($data['created_at'])) {
            $ticket->created_at = $data['created_at'];
            $ticket->updated_at = $data['created_at'];
        }

        $ticket->save();

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
        $ticket->fill($data);
        $ticket->save();

        return $ticket;
    }

    public function delete(Ticket $ticket): void
    {
        $ticket->delete();
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Ticket $ticket, array $tagIds): void
    {
        $ticket->tags()->sync($tagIds);
    }

    /**
     * `custom_fields` girdisini (key => value) `custom_field_values`
     * tablosuna, yalnızca `tickets` entity_type'ı için tanımlı alanlarla
     * eşleştirerek yazar. Tanımsız bir key sessizce yok sayılır
     * (DealRepository::syncCustomFieldValues() ile aynı sözleşme).
     *
     * @param  array<string, mixed>  $customFields
     */
    public function syncCustomFieldValues(Ticket $ticket, array $customFields): void
    {
        if (empty($customFields)) {
            return;
        }

        $fields = CustomField::query()
            ->forEntity('tickets')
            ->whereIn('key', array_keys($customFields))
            ->get()
            ->keyBy('key');

        foreach ($customFields as $key => $value) {
            $field = $fields->get($key);

            if (! $field) {
                continue;
            }

            CustomFieldValue::updateOrCreate(
                [
                    'custom_field_id' => $field->id,
                    'customizable_type' => Ticket::class,
                    'customizable_id' => $ticket->id,
                ],
                ['value' => is_array($value) ? json_encode($value) : (string) $value]
            );
        }
    }

    /**
     * `ticket_number` SUNUCU üretir — istemciden asla kabul edilmez
     * (StoreTicketRequest'te tanımlı değildir).
     *
     * Sıra numarası, mevcut EN BÜYÜK id'den türetilir ve çakışma olursa bir
     * sonrakine geçilir. Soft-delete edilmiş ticket'lar da sayılır
     * (`withTrashed`): silinmiş bir ticket'ın numarasını yeniden kullanmak,
     * denetim izinde aynı numaranın iki farklı kaydı işaret etmesine yol
     * açardı. `ticket_number` üzerindeki unique index son savunmadır.
     */
    public function nextTicketNumber(): string
    {
        $sequence = (int) Ticket::withTrashed()->max('id') + 1;

        do {
            $number = 'TKT-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Ticket::withTrashed()->where('ticket_number', $number)->exists());

        return $number;
    }

    // -----------------------------------------------------------------
    // GET /api/tickets/stats
    // -----------------------------------------------------------------

    /**
     * Genel özet — FİLTRELERDEN VE SAYFALAMADAN BAĞIMSIZ (Faz 7'deki
     * `meta.totals` ile aynı ilke: özet, o sayfada ne olduğuna göre
     * değişmez).
     *
     * HAM SQL YOK. `groupBy('status')->selectRaw('count(*)')` tek sorguda
     * biterdi ama `app/` altındaki sıfır-ham-SQL garantisini kırardı
     * (bkz. DealRepository::amountTotals() dokümanı, aynı ödün). Bunun
     * yerine her sayaç, filtresiz taban sorgunun KENDİ `clone`'u üzerinde
     * çalışır.
     *
     * `clone` ŞART: bir Builder `count()` ile tüketildikten sonra aynı nesne
     * üzerine ikinci bir `where()` eklemek, önceki çağrının koşullarının
     * ÜSTÜNE ekler ve sessizce yanlış sayı üretir.
     *
     * MALİYET: 12 sorgu + ortalama çözüm süresi taraması. Ticket sayısından
     * BAĞIMSIZ sabit bir sayıdır (`avg_resolution_hours` hariç, bkz. o
     * metodun dokümanı).
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $base = Ticket::query();

        $byStatus = [];
        foreach (TicketStatusMachine::statuses() as $status) {
            $byStatus[$status] = (clone $base)->where('status', $status)->count();
        }

        $byPriority = [];
        foreach (SlaService::PRIORITIES as $priority) {
            $byPriority[$priority] = (clone $base)->where('priority', $priority)->count();
        }

        return [
            'total' => (clone $base)->count(),
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'breached_count' => $this->sla->scopeActivelyBreached(clone $base)->count(),
            'at_risk_count' => $this->sla->scopeAtRisk(clone $base)->count(),
            'avg_resolution_hours' => $this->averageResolutionHours(),
        ];
    }

    /**
     * Çözülmüş ticket'ların ortalama çözüm süresi (saat), DURAKLAMA DÜŞÜLMÜŞ
     * — §2'deki SLA tanımıyla aynı ölçü. Böylece "ortalama çözüm süresi" ile
     * "SLA hedefi" aynı birimde karşılaştırılabilir olur; ham duvar saati
     * farkı, müşteri beklemesini ekibin üstüne yazardı.
     *
     * TEK YER ki ham SQL cazip: `AVG(TIMESTAMPDIFF(...))` bunu tek satırda
     * yapardı. Sıfır-ham-SQL garantisi korunduğu için fark PHP'de
     * hesaplanıyor; bellek `lazyById()` ile 500 kayıtlık pencerelere
     * bağlanmıştır (tüm tablo belleğe alınmaz), ancak SORGU SAYISI çözülmüş
     * ticket sayısıyla doğrusal büyür. Bu, projedeki tek "veriyle büyüyen"
     * özet hesabıdır ve bilinçli bir ödündür: ticket hacmi bu üründe (kapalı
     * devre, iç kullanım) on binler mertebesine çıkmaz. Çıkarsa doğru çözüm
     * ham SQL değil, çözümde yazılan bir `resolution_seconds` kolonudur.
     *
     * `null` döner (0 değil) — hiç çözülmüş ticket yoksa "ortalama 0 saat"
     * demek yanlış olurdu; arayüz "veri yok" gösterebilsin.
     */
    public function averageResolutionHours(): ?float
    {
        $sum = 0;
        $count = 0;

        Ticket::query()
            ->whereNotNull('resolved_at')
            ->select(['id', 'created_at', 'resolved_at', 'sla_paused_seconds'])
            ->lazyById(500)
            ->each(function (Ticket $ticket) use (&$sum, &$count): void {
                if ($ticket->created_at === null || $ticket->resolved_at === null) {
                    return;
                }

                $seconds = $ticket->resolved_at->getTimestamp()
                    - $ticket->created_at->getTimestamp()
                    - (int) $ticket->sla_paused_seconds;

                $sum += max(0, $seconds);
                $count++;
            });

        return $count === 0 ? null : round($sum / $count / 3600, 2);
    }

    // -----------------------------------------------------------------
    // Sorgu kurulumu
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    protected function baseQuery(array $filters): Builder
    {
        return $this->applyFilters(Ticket::query(), $filters);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // PARANTEZLİ GRUPLAMA ŞART: yoksa buradaki OR'lar, dışarıdaki
            // `filter[...]` where'lerinin yanına düz OR olarak eklenir ve
            // filtreler sızar (aranan kelime tüm filtreleri geçersiz kılar).
            $query->where(function (Builder $query) use ($term) {
                $query->where('ticket_number', 'like', "%{$term}%")
                    ->orWhere('subject', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (array_key_exists('assigned_to', $filters) && $filters['assigned_to'] !== null) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null) {
            $query->where('company_id', $filters['company_id']);
        }

        if (array_key_exists('contact_id', $filters) && $filters['contact_id'] !== null) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];

            // whereHas N+1 riski taşımaz: sonuç kümesini daraltan bir EXISTS
            // alt sorgusudur, `with('tags')` eager-load'unu etkilemez.
            $query->whereHas('tags', function (Builder $query) use ($tagId) {
                $query->where('tags.id', $tagId);
            });
        }

        // §5.4 — aktif ihlal predicate'i SlaService'ten gelir; burada
        // KOPYALANMAZ (tek tanım, tek doğruluk kaynağı).
        if (! empty($filters['sla_breached'])) {
            $this->sla->scopeActivelyBreached($query);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * `withCount` kapanışı — `activities` morph ilişkisinden yalnızca
     * `type='note'` olanlar sayılır ve sonuç `notes_count` olarak takılır.
     *
     * @return array<string, \Closure>
     */
    protected function notesCountRelation(): array
    {
        return [
            'activities as notes_count' => fn (Builder $query) => $query->where('type', 'note'),
        ];
    }

    /**
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
}
