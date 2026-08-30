<?php

namespace Database\Factories;

use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('tr_TR')->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'position' => fake()->unique()->numberBetween(1, 1000),
            'probability' => fake()->numberBetween(0, 100),
            'color' => fake()->randomElement([
                'primary', 'success', 'warning', 'danger', 'info', 'neutral',
            ]),
            'is_won' => false,
            'is_lost' => false,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the stage represents a won deal.
     */
    public function won(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_won' => true,
            'is_lost' => false,
            'probability' => 100,
        ]);
    }

    /**
     * Indicate that the stage represents a lost deal.
     */
    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_won' => false,
            'is_lost' => true,
            'probability' => 0,
        ]);
    }

    /**
     * Indicate that the stage is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
