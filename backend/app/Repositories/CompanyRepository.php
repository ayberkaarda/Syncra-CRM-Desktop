<?php

namespace App\Repositories;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Services\Sync\TagSyncService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CompanyRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     * Kullanıcı girdisi doğrudan orderBy'a verilmez (SQL injection/hata riski).
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'name',
        'industry',
        'city',
        'country',
        'employee_count',
        'annual_revenue',
        'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Filtrelenmiş, aranmış ve sıralanmış firma listesini sayfalı döner.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Company::query()
            ->with(['owner:id,name', 'tags', 'customFieldValues.customField'])
            ->withCount(['contacts', 'deals']);

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('website', 'like', "%{$term}%")
                    ->orWhere('industry', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['industry'])) {
            $query->where('industry', $filters['industry']);
        }

        if (! empty($filters['owner_id'])) {
            $query->where('owner_id', $filters['owner_id']);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (! empty($filters['country'])) {
            $query->where('country', $filters['country']);
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

        $paginator = $query->paginate($perPage);

        $this->attachPrimaryContacts($paginator->items());

        return $paginator;
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

    public function findOrFail(int $id): Company
    {
        $company = Company::query()
            ->with(['owner', 'tags', 'customFieldValues.customField'])
            ->withCount(['contacts', 'deals'])
            ->findOrFail($id);

        $this->attachPrimaryContacts([$company]);

        return $company;
    }

    /**
     * `primaryContact` bilerek bir Eloquent ilişkisi (model dosyasında ya da
     * `resolveRelationUsing()` ile) DEĞİL — yalnızca bu repository'nin
     * ihtiyacı olan, tek toplu (batch) bir sorgu. Sebep: `tags()` ve
     * `customFieldValues()` artık Company modelinde gerçek ilişkiler olarak
     * tanımlı; "bazı ilişkiler modelde, bazıları repository'de/resolver'da
     * tanımlanıyor" gibi tutarsız bir kalıp bırakmamak için "is_primary=true
     * olan tek kişi" kuralı burada, doğrudan bir sorguyla çözülüyor.
     *
     * N+1 KORUMASI: firma başına ayrı bir sorgu YOK — sayfadaki tüm firma
     * id'leri için TEK bir `WHERE company_id IN (...) AND is_primary = 1`
     * sorgusu atılır, sonuç `company_id`'ye göre indekslenip
     * `Model::setRelation()` ile ilgili Company nesnesine "yüklenmiş ilişki"
     * gibi iliştirilir. `CompanyResource` hâlâ
     * `relationLoaded('primaryContact')` ile okuyabiliyor; resource kodu
     * değişmedi.
     *
     * @param  iterable<int, Company>  $companies
     */
    protected function attachPrimaryContacts(iterable $companies): void
    {
        $companies = collect($companies);
        $companyIds = $companies->pluck('id')->filter()->unique();

        if ($companyIds->isEmpty()) {
            return;
        }

        $primaryContactsByCompanyId = Contact::query()
            ->where('is_primary', true)
            ->whereIn('company_id', $companyIds)
            ->get(['id', 'company_id', 'first_name', 'last_name', 'email'])
            ->keyBy('company_id');

        foreach ($companies as $company) {
            $company->setRelation('primaryContact', $primaryContactsByCompanyId->get($company->id));
        }
    }

    public function create(array $data): Company
    {
        return Company::create($data);
    }

    public function update(Company $company, array $data): Company
    {
        $company->fill($data);
        $company->save();

        return $company;
    }

    public function delete(Company $company): void
    {
        $company->delete();
    }

    /**
     * Firmanın bağlı açık (status=open) bir fırsatı var mı?
     */
    public function hasOpenDeals(Company $company): bool
    {
        return $company->deals()->where('status', 'open')->exists();
    }

    /**
     * Routed through TagSyncService so the owner's `sync_version` moves with
     * the pivot write - `->tags()->sync()` fires no model event at all, so
     * without it a tag-only edit never reaches a desktop client (protocol
     * §1.4).
     *
     * @param  array<int, int>  $tagIds
     */
    public function syncTags(Company $company, array $tagIds): void
    {
        TagSyncService::apply($company, $tagIds);
    }

    /**
     * @return Collection<int, CustomField>
     */
    public function customFieldDefinitions(): Collection
    {
        return CustomField::query()->forEntity('companies')->get()->keyBy('key');
    }
}
