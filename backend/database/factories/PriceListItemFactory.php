<?php

namespace Database\Factories;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    protected $model = PriceListItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'product_id' => Product::factory(),
            'unit_price' => fake()->randomFloat(2, 100, 50000),
        ];
    }
}
