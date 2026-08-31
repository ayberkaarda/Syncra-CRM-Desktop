<?php

namespace App\Repositories;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Lead;
use App\Services\Sync\TagSyncService;
use App\Sync\SyncVersionBumper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LeadRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'first_name', 'last_name', 'email', 'company_name', 'source', 'status', 'score', 'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Filtrelenmiş, aranmış ve sıralanmış lead listesini sayfalı döner.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters);

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        $query = Lead::query()->with(['owner', 'tags', 'customFieldValues.customField']);

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (array_key_exists('owner_id', $filters) && $filters['owner_id'] !== null) {
            $query->where('owner_id', $filters['owner_id']);
        }

        if (array_key_exists('score_min', $filters) && $filters['score_min'] !== null) {
            $query->where('score', '>=', $filters['score_min']);
        }

        if (array_key_exists('score_max', $filters) && $filters['score_max'] !== null) {
            $query->where('score', '<=', $filters['score_max']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];

            // `whereHas` burada N+1 riski TAŞIMAZ: yalnızca sonuç kümesini
            // daraltan bir EXISTS alt sorgusu üretir (ana `with('tags')`
            // eager-load'unu etkilemez, o hâlâ ayrı ve tek bir sorguda kalır).
            $query->whereHas('tags', function (Builder $query) use ($tagId) {
                $query->where('tags.id', $tagId);
            });
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

    public function findOrFail(int $id): Lead
    {
        return Lead::with(['owner', 'tags', 'customFieldValues.customField'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Lead
    {
        return Lead::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Lead $lead, array $data): Lead
    {
        $lead->fill($data);
        $lead->save();

        return $lead;
    }

    public function delete(Lead $lead): void
    {
        $lead->delete();
    }

    /**
     * Routed through TagSyncService so the owner's `sync_version` moves with
     * the pivot write - `->tags()->sync()` fires no model event at all, so
     * without it a tag-only edit never reaches a desktop client (protocol
     * §1.4).
     *
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Lead $lead, array $tagIds): void
    {
        TagSyncService::apply($lead, $tagIds);
    }

    /**
     * `custom_fields` girdisini (key => value) `custom_field_values`
     * tablosuna, yalnızca `leads` entity_type'ı için tanımlı alanlarla
     * eşleştirerek yazar. Tanımsız bir key sessizce yok sayılır.
     *
     * @param  array<string, mixed>  $customFields
     */
    public function syncCustomFieldValues(Lead $lead, array $customFields): void
    {
        if (empty($customFields)) {
            return;
        }

        $fields = CustomField::query()
            ->forEntity('leads')
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
                    'customizable_type' => Lead::class,
                    'customizable_id' => $lead->id,
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
            SyncVersionBumper::bump($lead);
        }
    }
}
