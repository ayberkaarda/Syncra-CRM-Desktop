<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word().'.'.fake()->word(),
            'value' => fake()->word(),
            'type' => 'string',
            'group' => fake()->randomElement(['general', 'company', 'quote', 'ticket']),
            'is_public' => false,
            'description' => fake()->boolean(60) ? fake('tr_TR')->sentence(8) : null,
        ];
    }

    /**
     * Indicate that the setting stores a boolean value.
     */
    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'boolean',
            'value' => fake()->boolean() ? '1' : '0',
        ]);
    }

    /**
     * Indicate that the setting stores an integer value.
     */
    public function integer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'integer',
            'value' => (string) fake()->numberBetween(0, 1000),
        ]);
    }

    /**
     * Indicate that the setting stores a JSON value.
     */
    public function json(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'json',
            'value' => json_encode([
                fake()->word() => fake()->word(),
                fake()->word() => fake()->numberBetween(1, 100),
            ]),
        ]);
    }
}
