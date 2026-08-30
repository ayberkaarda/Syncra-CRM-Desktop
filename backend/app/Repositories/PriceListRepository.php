<?php

namespace App\Repositories;

use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PriceListRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'name',
        'code',
        'created_at',
    ];

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = PriceList::query()->withCount('items');

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('is_default', $filters) && $filters['is_default'] !== null) {
            $query->where('is_default', filter_var($filters['is_default'], FILTER_VALIDATE_BOOLEAN));
        }

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);

        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
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

    public function findOrFail(int $id): PriceList
    {
        return PriceList::query()->withCount('items')->findOrFail($id);
    }

    public function fresh(PriceList $priceList): PriceList
    {
        return $this->findOrFail($priceList->id);
    }

    public function create(array $data): PriceList
    {
        return PriceList::create($data);
    }

    public function update(PriceList $priceList, array $data): PriceList
    {
        $priceList->fill($data);
        $priceList->save();

        return $priceList;
    }

    public function delete(PriceList $priceList): void
    {
        // Kalemler BİLEREK burada silinmez. PriceList SoftDeletes kullanır —
        // `delete()` geri alınabilir bir UPDATE'tir (deleted_at), gerçek bir
        // SQL DELETE değildir. Kalemleri elle silersek `restore()` boş bir
        // liste geri getirir; soft delete'in amacı tam olarak bunu önlemek.
        // migration'daki `cascadeOnDelete` FK'sı YANLIŞ değil — yalnızca
        // gerçek DELETE'te (yani `forceDelete()`) tetiklenmesi DOĞRU
        // davranıştır. Projede aynı desen: quotes (softDeletes) →
        // quote_items (cascadeOnDelete), conversations (softDeletes) →
        // messages (cascadeOnDelete) — ikisinde de kalemler elle silinmez.
        $priceList->delete();
    }

    /**
     * Route-model-binding DIŞINDA, `price_list_id` bir sorgu parametresi
     * olarak geldiğinde (ör. fiyat çözümleme) kullanılan, THROW ETMEYEN
     * arama. Eloquent'in varsayılan sorgusu soft-silinmiş kayıtları zaten
     * dışlar, bu yüzden soft-silinmiş bir liste burada `null` döner — çağıran
     * taraf (ProductService::resolvePrice) bunu "kullanılamaz liste" olarak
     * yorumlayıp kataloğa düşer. `findOrFail` KASITLI OLARAK kullanılmıyor:
     * bir 404/ModelNotFoundException patlatmak yerine sessiz fallback
     * isteniyor (aksi halde soft-silinmiş bir listeye referans veren eski
     * bir `price_list_id` sorgu parametresi 500/404 yerine kataloğa düşmesi
     * gerekirken hataya düşerdi).
     */
    public function find(int $id): ?PriceList
    {
        return PriceList::query()->find($id);
    }

    /**
     * Bir listede birden fazla `is_default=true` kalmasın diye, verilen liste
     * dışındaki tüm listeleri `is_default=false` yapar. Tek bir UPDATE
     * sorgusu — N+1 yok, ham SQL yok. (contacts.is_primary deseniyle aynı.)
     */
    public function clearOtherDefaults(int $exceptPriceListId): void
    {
        PriceList::query()
            ->where('id', '!=', $exceptPriceListId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Şu an geçerli tek varsayılan (is_default=true, is_active=true) fiyat
     * listesini döner — yoksa null.
     */
    public function findDefault(): ?PriceList
    {
        return PriceList::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Bir fiyat listesindeki tek bir ürün fiyat kaydını döner — yoksa null.
     */
    public function findItem(int $priceListId, int $productId): ?PriceListItem
    {
        return PriceListItem::query()
            ->where('price_list_id', $priceListId)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * Bir fiyat listesindeki kalemleri (ürün bilgisiyle) sayfalı döner.
     */
    public function paginateItems(PriceList $priceList, int $perPage): LengthAwarePaginator
    {
        return PriceListItem::query()
            ->where('price_list_id', $priceList->id)
            ->with('product')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function setPrice(PriceList $priceList, int $productId, float $unitPrice): PriceListItem
    {
        $item = PriceListItem::query()->updateOrCreate(
            ['price_list_id' => $priceList->id, 'product_id' => $productId],
            ['unit_price' => $unitPrice]
        );

        return $item->load('product');
    }

    public function removePrice(PriceList $priceList, int $productId): void
    {
        PriceListItem::query()
            ->where('price_list_id', $priceList->id)
            ->where('product_id', $productId)
            ->delete();
    }
}
