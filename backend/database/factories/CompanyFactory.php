<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('tr_TR')->company(),
            'email' => fake()->boolean(70) ? fake()->unique()->safeEmail() : null,
            'phone' => fake('tr_TR')->phoneNumber(),
            'website' => fake()->url(),
            'industry' => fake()->randomElement([
                'Bilişim', 'İnşaat', 'Tekstil', 'Otomotiv', 'Gıda', 'Sağlık',
                'Lojistik', 'Enerji', 'Turizm', 'Eğitim', 'Finans', 'Perakende',
            ]),
            'address' => fake('tr_TR')->streetAddress(),
            'city' => fake()->randomElement([
                'İstanbul', 'Ankara', 'İzmir', 'Bursa', 'Antalya',
                'Adana', 'Konya', 'Gaziantep', 'Kayseri', 'Denizli',
            ]),
            'country' => 'Türkiye',
            'employee_count' => fake()->numberBetween(5, 5000),
            'annual_revenue' => fake()->randomFloat(2, 250000, 250000000),
            'owner_id' => null,
            'notes' => fake()->boolean(60) ? fake('tr_TR')->text(200) : null,
        ];
    }

    /**
     * Indicate that the company has no owner assigned.
     */
    public function withoutOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_id' => null,
        ]);
    }
}
