<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseNames = [
            'CRM Kullanıcı Lisansı',
            'Sunucu Bakım Paketi',
            'Bulut Depolama 1TB',
            'Yerinde Kurulum Hizmeti',
            'Kullanıcı Eğitimi (Günlük)',
            'Mobil Uygulama Modülü',
            'Entegrasyon Danışmanlığı',
            'SMS Paketi 10.000',
            'E-Fatura Entegrasyonu',
            'Yedekleme Hizmeti',
        ];

        $variants = ['Standart', 'Pro', 'Kurumsal', 'Başlangıç', 'Premium', 'Temel'];

        return [
            'name' => fake()->randomElement($baseNames).' - '.fake()->randomElement($variants),
            'sku' => 'SKU-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'description' => fake()->text(120),
            'category' => fake()->randomElement(['Yazılım', 'Donanım', 'Hizmet', 'Lisans', 'Eğitim', 'Destek']),
            'unit_price' => fake()->randomFloat(2, 250, 75000),
            'currency' => 'TRY',
            'tax_rate' => fake()->randomElement([20, 10, 1]),
            'unit' => fake()->randomElement(['adet', 'ay', 'yıl', 'saat', 'paket']),
            'stock_quantity' => fake()->optional()->numberBetween(0, 500),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
