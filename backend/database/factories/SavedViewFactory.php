<?php

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\User;
use App\Services\SavedViews\SavedViewModules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
class SavedViewFactory extends Factory
{
    protected $model = SavedView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'module' => fake()->randomElement(SavedViewModules::names()),
            'name' => fake('tr_TR')->unique()->words(2, true),
            'query_json' => [
                'q' => null,
                'sort' => null,
                'per_page' => null,
                'filter' => [],
            ],
            'is_shared' => false,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn (array $attributes) => ['is_shared' => true]);
    }

    public function forModule(string $module): static
    {
        return $this->state(fn (array $attributes) => ['module' => $module]);
    }
}
