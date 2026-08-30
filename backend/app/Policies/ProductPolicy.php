<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can view any products.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    /**
     * Determine whether the user can view the given product.
     */
    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view');
    }

    /**
     * Determine whether the user can create products.
     */
    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    /**
     * Determine whether the user can update the given product.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->can('products.update');
    }

    /**
     * Determine whether the user can delete the given product.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete');
    }
}
