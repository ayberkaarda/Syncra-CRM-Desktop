<?php

namespace App\Policies;

use App\Models\PriceList;
use App\Models\User;

/**
 * Fiyat listeleri için AYRI bir `price-lists.*` izin ailesi YOK — bilinçli
 * karar. Bir fiyat listesi, ürün kataloğunun bir uzantısıdır (kataloğun
 * üzerine kanal/müşteri bazlı bir fiyat ezmesi); onu görüntüleyebilen/
 * yönetebilen kişi kümesi kavramsal olarak ürünleri görüntüleyebilen/
 * yönetebilen kişi kümesiyle AYNIDIR. Ayrı bir izin ailesi açmak yalnızca
 * rol tanımlarında (`RolePermissionSeeder`) iki paralel izin listesi
 * senkronize tutma yükü ekler, gerçek bir yetki farkı ifade etmez.
 */
class PriceListPolicy
{
    /**
     * Determine whether the user can view any price lists.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    /**
     * Determine whether the user can view the given price list.
     */
    public function view(User $user, PriceList $priceList): bool
    {
        return $user->can('products.view');
    }

    /**
     * Determine whether the user can create price lists.
     */
    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    /**
     * Determine whether the user can update the given price list.
     */
    public function update(User $user, PriceList $priceList): bool
    {
        return $user->can('products.update');
    }

    /**
     * Determine whether the user can delete the given price list.
     */
    public function delete(User $user, PriceList $priceList): bool
    {
        return $user->can('products.delete');
    }
}
