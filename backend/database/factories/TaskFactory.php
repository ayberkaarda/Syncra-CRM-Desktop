<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dueAt = fake()->dateTimeBetween('-1 month', '+2 months');

        return [
            'title' => fake()->randomElement([
                'Müşteriyi ara', 'Teklif hazırla', 'Toplantı planla', 'Sözleşme taslağını gönder',
                'Demo sunumu yap', 'Fatura takibi', 'Referans kontrolü', 'Fiyat revizyonu',
            ]),
            'description' => fake('tr_TR')->text(200),
            'due_at' => $dueAt,
            'reminder_at' => fake()->boolean(50) ? fake()->dateTimeBetween('-2 months', $dueAt) : null,
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'status' => 'pending',
            'completed_at' => null,
            'assigned_to' => null,
            'created_by' => null,
            'taskable_type' => null,
            'taskable_id' => null,
        ];
    }

    /**
     * Indicate that the task is overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_at' => fake()->dateTimeBetween('-60 days', '-1 days'),
            'status' => 'pending',
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the task has been completed.
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $dueAt = $attributes['due_at'] ?? fake()->dateTimeBetween('-1 month', 'now');
            $dueAt = $dueAt instanceof \DateTimeInterface ? $dueAt : new \DateTime((string) $dueAt);
            $end = $dueAt > new \DateTime ? (clone $dueAt)->modify('+'.fake()->numberBetween(1, 5).' days') : 'now';

            return [
                'status' => 'completed',
                'completed_at' => fake()->dateTimeBetween($dueAt, $end),
            ];
        });
    }

    /**
     * Indicate that the task was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'completed_at' => null,
        ]);
    }
}
