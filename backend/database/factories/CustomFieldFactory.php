<?php

namespace Database\Factories;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => fake()->randomElement(['leads', 'companies', 'contacts', 'deals', 'tickets']),
            'name' => fake()->randomElement([
                'Sektör',
                'Referans Kaynağı',
                'Öncelik Seviyesi',
                'Sözleşme Tarihi',
                'Bütçe Aralığı',
                'Bölge',
                'Müşteri Segmenti',
                'Kampanya Kodu',
            ]),
            'key' => fake()->unique()->word().'_'.fake()->unique()->numberBetween(1, 99999),
            'type' => fake()->randomElement(['text', 'number', 'date', 'select', 'boolean']),
            'options' => null,
            'is_required' => false,
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the field is a select field with Turkish options.
     */
    public function select(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'select',
            'options' => fake()->randomElements([
                'Düşük', 'Orta', 'Yüksek', 'Kritik', 'Beklemede', 'Tamamlandı',
            ], fake()->numberBetween(3, 4)),
        ]);
    }

    /**
     * Indicate that the field is a number field.
     */
    public function number(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'number',
        ]);
    }

    /**
     * Indicate that the field is required.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }
}
