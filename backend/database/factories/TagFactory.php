<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Öncelikli', 'Potansiyel', 'VIP', 'Soğuk', 'Sıcak', 'Kurumsal',
                'Bireysel', 'Yeniden Görüşülecek', 'Kayıp Riski', 'Sadık Müşteri',
                'Yeni', 'Referans', 'Şikayetçi', 'Memnun',
            ]),
            'slug' => fake()->unique()->slug(2),
            'color' => fake()->randomElement([
                'primary', 'success', 'warning', 'danger', 'info', 'neutral',
            ]),
        ];
    }
}
