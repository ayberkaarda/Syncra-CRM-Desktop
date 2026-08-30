<?php

namespace App\Services\Products;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Repositories\PriceListRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PriceListService
{
    public function __construct(protected PriceListRepository $priceLists) {}

    /**
     * @param  array<string, mixed>  $filters  'per_page' anahtarı dahil edilebilir.
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return $this->priceLists->paginate($filters, $perPage);
    }

    public function find(int $id): PriceList
    {
        return $this->priceLists->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PriceList
    {
        return DB::transaction(function () use ($data) {
            $priceList = $this->priceLists->create($data);

            // İş kuralı: yalnızca bir liste varsayılan olabilir
            // (contacts.is_primary deseniyle aynı).
            if (($data['is_default'] ?? false) === true) {
                $this->priceLists->clearOtherDefaults($priceList->id);
            }

            return $this->priceLists->fresh($priceList);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PriceList $priceList, array $data): PriceList
    {
        return DB::transaction(function () use ($priceList, $data) {
            if (! empty($data)) {
                $this->priceLists->update($priceList, $data);
            }

            if (($data['is_default'] ?? false) === true) {
                $this->priceLists->clearOtherDefaults($priceList->id);
            }

            return $this->priceLists->fresh($priceList);
        });
    }

    /**
     * Varsayılan fiyat listesi SİLİNEMEZ (422). Aksi halde fiyat çözümleme
     * (`ProductService::resolvePrice`) sessizce kataloğa düşer ve kimse bunun
     * farkına varmaz — kullanıcı önce başka bir listeyi varsayılan yapmalıdır.
     */
    public function delete(PriceList $priceList): void
    {
        if ($priceList->is_default) {
            throw ValidationException::withMessages([
                'is_default' => 'Varsayılan fiyat listesi silinemez. Önce başka bir listeyi varsayılan yapın.',
            ]);
        }

        $this->priceLists->delete($priceList);
    }

    public function products(PriceList $priceList, int $perPage): LengthAwarePaginator
    {
        return $this->priceLists->paginateItems($priceList, $perPage);
    }

    public function setPrice(PriceList $priceList, int $productId, float $unitPrice): PriceListItem
    {
        return $this->priceLists->setPrice($priceList, $productId, $unitPrice);
    }

    public function removePrice(PriceList $priceList, int $productId): void
    {
        $this->priceLists->removePrice($priceList, $productId);
    }
}
