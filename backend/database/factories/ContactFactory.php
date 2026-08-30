<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

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
            'mobile' => fake('tr_TR')->phoneNumber(),
            'position' => fake()->randomElement([
                'Genel Müdür', 'Satın Alma Müdürü', 'Proje Yöneticisi', 'İnsan Kaynakları Uzmanı',
                'Bilgi İşlem Müdürü', 'Muhasebe Müdürü', 'Pazarlama Uzmanı', 'Operasyon Müdürü',
                'Teknik Destek Uzmanı', 'Yönetim Kurulu Üyesi',
            ]),
            'company_id' => null,
            'owner_id' => null,
            'is_primary' => false,
            'address' => fake('tr_TR')->streetAddress(),
            'city' => fake()->randomElement([
                'İstanbul', 'Ankara', 'İzmir', 'Bursa', 'Antalya',
                'Adana', 'Konya', 'Gaziantep', 'Kayseri', 'Denizli',
            ]),
            'country' => 'Türkiye',
            'notes' => fake()->boolean(60) ? fake('tr_TR')->text(200) : null,
        ];
    }

    /**
     * Indicate that the contact is the primary contact for its company.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
