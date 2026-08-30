<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake('tr_TR')->firstName(),
            'last_name' => fake('tr_TR')->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake('tr_TR')->phoneNumber(),
            'company_name' => fake('tr_TR')->company(),
            'position' => fake()->randomElement([
                'Genel Müdür', 'Satın Alma Müdürü', 'Proje Yöneticisi', 'İnsan Kaynakları Uzmanı',
                'Bilgi İşlem Müdürü', 'Muhasebe Müdürü', 'Pazarlama Uzmanı', 'Operasyon Müdürü',
                'Teknik Destek Uzmanı', 'Yönetim Kurulu Üyesi',
            ]),
            'source' => fake()->randomElement(['web', 'referral', 'phone', 'email', 'event', 'social', 'other']),
            'status' => fake()->randomElement(['new', 'contacted', 'qualified', 'unqualified']),
            'score' => fake()->numberBetween(0, 100),
            'owner_id' => null,
            'converted_at' => null,
            'converted_contact_id' => null,
            'converted_company_id' => null,
            'converted_deal_id' => null,
            'notes' => fake()->boolean(60) ? fake('tr_TR')->text(200) : null,
        ];
    }

    /**
     * Indicate that the lead is newly created and not yet worked on.
     *
     * NOT: metot adi bilerek `new()` DEGILDIR — `Factory::new()` Laravel'in
     * statik fabrika metodudur; ayni adi instance metodu olarak tanimlamak
     * PHP'de fatal error uretir.
     */
    public function newLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'new',
            'score' => fake()->numberBetween(0, 30),
            'converted_at' => null,
            'converted_contact_id' => null,
            'converted_company_id' => null,
            'converted_deal_id' => null,
        ]);
    }

    /**
     * Indicate that the lead has been converted.
     *
     * Note: the seeder is responsible for filling converted_contact_id and
     * converted_company_id with real IDs after creating the related records.
     */
    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'converted',
            'converted_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'score' => fake()->numberBetween(80, 100),
        ]);
    }
}
