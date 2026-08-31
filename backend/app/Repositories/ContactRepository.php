<?php

namespace App\Repositories;

use App\Models\Contact;
use App\Models\CustomField;
use App\Services\Sync\TagSyncService;
use App\Sync\SyncVersionBumper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ContactRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     * Kullanıcı girdisi doğrudan orderBy'a verilmez (SQL injection/hata riski).
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'first_name',
        'last_name',
        'email',
        'position',
        'city',
        'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Filtrelenmiş, aranmış ve sıralanmış kişi listesini sayfalı döner.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Contact::query()->with([
            'company:id,name',
            'owner:id,name',
            'tags',
            'customFieldValues.customField',
        ]);

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('mobile', 'like', "%{$term}%")
                    ->orWhere('position', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (! empty($filters['owner_id'])) {
            $query->where('owner_id', $filters['owner_id']);
        }

        if (array_key_exists('is_primary', $filters) && $filters['is_primary'] !== null) {
            $query->where('is_primary', filter_var($filters['is_primary'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];
            $query->whereHas('tags', fn (Builder $query) => $query->where('tags.id', $tagId));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);

        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
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

    public function findOrFail(int $id): Contact
    {
        return Contact::query()
            ->with(['company', 'owner', 'tags', 'customFieldValues.customField'])
            ->withCount(['deals', 'tickets'])
            ->findOrFail($id);
    }

    public function create(array $data): Contact
    {
        return Contact::create($data);
    }

    public function update(Contact $contact, array $data): Contact
    {
        $contact->fill($data);
        $contact->save();

        return $contact;
    }

    public function delete(Contact $contact): void
    {
        $contact->delete();
    }

    /**
     * Bir firmada birden fazla `is_primary=true` kişi kalmasın diye, verilen
     * kişi dışındaki tüm firma kişilerini `is_primary=false` yapar.
     * Tek bir UPDATE sorgusu — N+1 yok, ham SQL yok.
     *
     * Protocol §2.3 #5: the bulk UPDATE fires no model event, so the demoted
     * contacts would keep showing as primary on every desktop client. The
     * statement stays (it is the reason this method exists); the affected keys
     * are collected first and versioned afterwards, one distinct version per
     * row as protocol §2.5 requires.
     */
    public function clearOtherPrimaryContacts(int $companyId, int $exceptContactId): void
    {
        $demoted = Contact::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $exceptContactId)
            ->where('is_primary', true)
            ->pluck('id')
            ->all();

        if ($demoted === []) {
            return;
        }

        Contact::query()
            ->whereIn('id', $demoted)
            ->update(['is_primary' => false]);

        SyncVersionBumper::bumpRows('contacts', $demoted);
    }

    /**
     * Kişinin bağlı açık (status=open) bir fırsatı var mı?
     */
    public function hasOpenDeals(Contact $contact): bool
    {
        return $contact->deals()->where('status', 'open')->exists();
    }

    /**
     * Routed through TagSyncService so the owner's `sync_version` moves with
     * the pivot write - `->tags()->sync()` fires no model event at all, so
     * without it a tag-only edit never reaches a desktop client (protocol
     * §1.4).
     *
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Contact $contact, array $tagIds): void
    {
        TagSyncService::apply($contact, $tagIds);
    }

    /**
     * @return Collection<int, CustomField>
     */
    public function customFieldDefinitions(): Collection
    {
        return CustomField::query()->forEntity('contacts')->get()->keyBy('key');
    }
}
