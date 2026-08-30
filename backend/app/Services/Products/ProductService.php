<?php

namespace App\Services\Products;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\PriceList;
use App\Models\Product;
use App\Repositories\PriceListRepository;
use App\Repositories\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(
        protected ProductRepository $products,
        protected PriceListRepository $priceLists,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->products->paginate($filters, $perPage);
    }

    public function find(int $id): Product
    {
        return $this->products->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $tagIds = $data['tag_ids'] ?? null;
            $customFields = $data['custom_fields'] ?? null;
            unset($data['tag_ids'], $data['custom_fields']);

            $product = $this->products->create($data);

            if ($tagIds !== null) {
                $product->tags()->sync($tagIds);
            }

            if ($customFields !== null) {
                $this->syncCustomFields($product, $customFields);
            }

            return $this->products->fresh($product);
        });
    }

    /**
     * @param  array<string, mixed>  $data  'tag_ids' ve 'custom_fields' anahtarları içerebilir.
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $tagIds = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : null;
            $customFields = array_key_exists('custom_fields', $data) ? $data['custom_fields'] : null;
            unset($data['tag_ids'], $data['custom_fields']);

            if (! empty($data)) {
                $this->products->update($product, $data);
            }

            if ($tagIds !== null) {
                $product->tags()->sync($tagIds);
            }

            if ($customFields !== null) {
                $this->syncCustomFields($product, $customFields);
            }

            return $this->products->fresh($product);
        });
    }

    /**
     * Ürün silme: bilinçli olarak ENGELLENMİYOR.
     *
     * `quote_items.product_id` `nullOnDelete` ve her kalem kendi `name`
     * kopyasını taşıyor (Faz 3 kararı) — bir ürün silinse (soft delete) bile
     * geçmiş teklifler bozulmaz, kalemler kendi anlık kopyalarını göstermeye
     * devam eder. Bu koruma zaten var olduğu için burada ayrıca "kullanılan
     * ürün silinemez" kısıtı eklemek, contacts/companies'teki "açık fırsatı
     * olan silinemez" kuralının aksine, gerçek bir veri bütünlüğü riskini
     * önlemez — yalnızca kullanıcıya gereksiz sürtünme ekler. Silme soft
     * delete olduğu için zaten geri alınabilir.
     */
    public function delete(Product $product): void
    {
        $this->products->delete($product);
    }

    /**
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return $this->products->categories();
    }

    /**
     * `GET /api/products/{product}/price` — teklif kalemi eklerken kullanılacak
     * fiyat çözümleme.
     *
     * Öncelik sırası:
     *   1. `price_list_id` verilmişse o liste (aktif + geçerlilik tarihi
     *      aralığında ise) kullanılır.
     *   2. Verilmemişse sistemin varsayılan listesi (`is_default=true`,
     *      `is_active=true`) kullanılır.
     *   3. Kullanılabilir bir liste yoksa, ya da listede bu ürün için kayıt
     *      yoksa, kataloğun kendi `unit_price`'ına düşülür.
     *
     * Soft-silinmiş bir liste `is_active=false` ile AYNI muameleyi görür:
     * kullanılamaz sayılır, kataloğa düşülür. `price_list_id` soft-silinmiş
     * bir listeye işaret ediyorsa bu sessizce (404/hata FIRLATMADAN) olur —
     * bkz. aşağıdaki `find()` notu.
     *
     * `tax_rate` ve `currency` HER ZAMAN üründen gelir: bir fiyat listesi
     * yalnızca satış fiyatını ezer, ürünün vergi rejimini veya para birimini
     * değiştirmez — aksi halde aynı ürün farklı listelerde farklı KDV
     * oranlarıyla görünebilir, ki bu ürünün değil verginin bir özelliğidir.
     *
     * Pasif ürün (`is_active=false`) için de fiyat sorgulanabilir — bilinçli
     * karar: pasif bir ürün YENİ tekliflere eklenmemelidir (bu kısıt teklif
     * tarafında, kalem eklenirken uygulanır), ama BURASI salt bir fiyat
     * okuma ucudur ve mevcut bir teklif kalemini görüntülerken/düzenlerken
     * (ör. "bu kalemin güncel liste fiyatı ne olurdu" karşılaştırması) pasif
     * bir ürünün fiyatına da erişilebilmesi gerekir. Bu yüzden burada
     * `is_active` kontrolü YOK.
     *
     * @return array<string, mixed>
     */
    public function resolvePrice(Product $product, ?int $priceListId): array
    {
        // `find()` (THROW ETMEYEN) kasıtlı: soft-silinmiş bir listeye
        // referans veren bir `price_list_id` — Eloquent'in varsayılan
        // sorgusu onu zaten görünmez kıldığı için — `null` döner ve aşağıda
        // sessizce kataloğa düşülür; `findOrFail` kullansaydık bu durumda
        // 404/ModelNotFoundException patlardı, ki fiyat çözümleme gibi
        // "en kötü ihtimalle kataloğa düş" ucunda istenmeyen bir davranıştır.
        $priceList = $priceListId !== null
            ? $this->priceLists->find($priceListId)
            : $this->priceLists->findDefault();

        if ($priceList !== null && $this->isPriceListUsable($priceList)) {
            $item = $this->priceLists->findItem($priceList->id, $product->id);

            if ($item !== null) {
                return [
                    'product_id' => $product->id,
                    'unit_price' => (float) $item->unit_price,
                    'tax_rate' => (float) $product->tax_rate,
                    'currency' => $product->currency,
                    'source' => 'price_list',
                    'price_list' => ['id' => $priceList->id, 'name' => $priceList->name],
                ];
            }
        }

        return [
            'product_id' => $product->id,
            'unit_price' => (float) $product->unit_price,
            'tax_rate' => (float) $product->tax_rate,
            'currency' => $product->currency,
            'source' => 'catalog',
            'price_list' => null,
        ];
    }

    /**
     * Bir fiyat listesi şu an fiyat çözümlemede kullanılabilir mi?
     * `is_active=false` ya da bugün `valid_from`/`valid_until` aralığının
     * dışındaysa kullanılamaz.
     */
    protected function isPriceListUsable(PriceList $priceList): bool
    {
        if (! $priceList->is_active) {
            return false;
        }

        $today = now()->toDateString();

        if ($priceList->valid_from !== null && $today < $priceList->valid_from->toDateString()) {
            return false;
        }

        if ($priceList->valid_until !== null && $today > $priceList->valid_until->toDateString()) {
            return false;
        }

        return true;
    }

    /**
     * Gönderilen değerleri, `products` entity_type'ına tanımlı özel alanlarla
     * eşleştirip kaydeder. Tanımlı olmayan bir anahtar sessizce yok sayılır.
     *
     * @param  array<string, mixed>  $values
     */
    protected function syncCustomFields(Product $product, array $values): void
    {
        $definitions = CustomField::query()->forEntity('products')->get()->keyBy('key');

        foreach ($values as $key => $value) {
            $field = $definitions->get($key);

            if (! $field) {
                continue;
            }

            CustomFieldValue::query()->updateOrCreate(
                [
                    'custom_field_id' => $field->id,
                    'customizable_type' => Product::class,
                    'customizable_id' => $product->id,
                ],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                ]
            );
        }
    }
}
