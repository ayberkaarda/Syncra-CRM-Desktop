<?php

namespace Database\Factories;

use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Perakende Fiyat Listesi', 'Toptan Fiyat Listesi', 'Bayi Fiyat Listesi',
                'Kurumsal Fiyat Listesi', 'Kampanya Fiyat Listesi', 'İhracat Fiyat Listesi',
            ]).' '.fake()->unique()->numberBetween(1, 100000),
            'code' => strtoupper(fake()->unique()->bothify('LIST-####')),
            'description' => fake()->boolean(60) ? fake('tr_TR')->text(150) : null,
            'currency' => 'TRY',
            'is_default' => false,
            'is_active' => true,
            'valid_from' => null,
            'valid_until' => null,
        ];
    }

    /**
     * Indicate that this is the default price list.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Indicate that the price list is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
