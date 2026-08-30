<?php

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductRepository
{
    /**
     * Sıralama için izin verilen sütunların beyaz listesi.
     * Kullanıcı girdisi doğrudan orderBy'a verilmez (SQL injection/hata riski).
     *
     * @var array<int, string>
     */
    protected const SORTABLE_COLUMNS = [
        'name',
        'sku',
        'category',
        'unit_price',
        'stock_quantity',
        'created_at',
    ];

    // Varsayılan sıralama isim/artan: katalog alfabetik göz atmak için daha
    // kullanışlıdır — diğer Faz 6/7/8 listelerinin aksine (varsayılan
    // -created_at) burada kullanıcı "en yeni ürün"ü değil, aradığı ürünü
    // isme göre bulmak ister.
    protected const DEFAULT_SORT_COLUMN = 'name';

    protected const DEFAULT_SORT_DIRECTION = 'asc';

    /**
     * Filtrelenmiş, aranmış ve sıralanmış ürün listesini sayfalı döner.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Product::query()->with(['tags', 'customFieldValues.customField']);

        if (! empty($filters['q'])) {
            $term = $filters['q'];

            // Parantezli gruplama şart: yoksa diğer where filtreleri OR ile sızar.
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];
            $query->whereHas('tags', fn (Builder $query) => $query->where('tags.id', $tagId));
        }

        if (! empty($filters['price_min'])) {
            $query->where('unit_price', '>=', $filters['price_min']);
        }

        if (! empty($filters['price_max'])) {
            $query->where('unit_price', '<=', $filters['price_max']);
        }

        if (! empty($filters['in_stock'])) {
            $query->where('stock_quantity', '>', 0);
        }

        [$column, $direction] = $this->resolveSort($filters['sort'] ?? null);

        $query->orderBy($column, $direction);

        return $query->paginate($perPage);
    }

    /**
     * Sıralama parametresini beyaz liste üzerinden çözer.
     * Listede olmayan bir sütun gelirse varsayılana (name/asc) düşer.
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

    public function findOrFail(int $id): Product
    {
        return Product::query()
            ->with(['tags', 'customFieldValues.customField'])
            ->findOrFail($id);
    }

    /**
     * Route-model-binding tarafından çözülmüş bir Product'ı ilişkileriyle
     * yeniden yükler (show/update sonrası tutarlı Resource şekli için).
     */
    public function fresh(Product $product): Product
    {
        return $this->findOrFail($product->id);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $product->fill($data);
        $product->save();

        return $product;
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * Mevcut ürünlerin benzersiz kategori listesi (filtre dropdown'ı için).
     * Ham SQL yok; silinmiş/boş kategoriler hariç tutulur.
     *
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return Product::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }
}
