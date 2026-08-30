<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Conversation::TYPE_DM,
            'name' => null,
            'conversable_type' => null,
            'conversable_id' => null,
            'created_by' => null,
            'last_message_at' => null,
        ];
    }

    /**
     * Indicate that the conversation is a direct message.
     */
    public function dm(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Conversation::TYPE_DM,
            'name' => null,
        ]);
    }

    /**
     * Indicate that the conversation is a group chat.
     */
    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Conversation::TYPE_GROUP,
            'name' => fake()->randomElement([
                'Satış Ekibi',
                'Destek Vardiyası',
                'Proje Koordinasyon',
                'Yönetim',
            ]),
        ]);
    }

    /**
     * Indicate that the conversation is attached to a record.
     *
     * The `conversable` argument is optional: the Phase 3 seeder wires the
     * morph columns itself, while Phase 12 tests pass the deal/ticket in
     * directly.
     */
    public function record(?Model $conversable = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Conversation::TYPE_RECORD,
            'name' => null,
            'conversable_type' => $conversable === null ? null : $conversable::class,
            'conversable_id' => $conversable?->getKey(),
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->getKey(),
        ]);
    }

    /**
     * Attach the given users as participants once the row exists.
     *
     * `joined_at` matters for Phase 12: when a group founder leaves,
     * ownership passes to the OLDEST member, and that order is read from this
     * column. Seconds are spread so the successor is deterministic in tests
     * instead of depending on insert order.
     *
     * @param  array<int, User>  $users
     */
    public function withMembers(array $users): static
    {
        return $this->afterCreating(function (Conversation $conversation) use ($users): void {
            $joinedAt = now()->subMinutes(count($users));

            foreach ($users as $index => $user) {
                $conversation->users()->attach($user->getKey(), [
                    'joined_at' => $joinedAt->copy()->addMinutes($index),
                ]);
            }
        });
    }
}
