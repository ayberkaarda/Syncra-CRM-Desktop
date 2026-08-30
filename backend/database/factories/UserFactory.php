<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'department' => fake()->randomElement([
                'Satış', 'Destek', 'Pazarlama', 'Yönetim', 'Muhasebe', 'Operasyon',
            ]),
            'last_login_at' => null,
            'must_change_password' => false,
            /*
             * Kişisel tercihler (Faz 14) — RASTGELE DEĞİL, uygulama varsayılanı.
             *
             * `fake()->randomElement(['tr','en','de','fr'])` cazip görünür ama testleri
             * belirlenimsiz yapardı: yanıt metinleri kullanıcının diline göre değişiyor
             * (`SetLocale`), yani rastgele bir locale mesaj iddialarını rastgele kırardı.
             * Farklı dil isteyen test bunu AÇIKÇA (`User::factory()->create(['locale' => 'en'])`)
             * belirtir — niyet görünür olur.
             */
            'locale' => 'tr',
            'preferred_currency' => 'TRY',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
