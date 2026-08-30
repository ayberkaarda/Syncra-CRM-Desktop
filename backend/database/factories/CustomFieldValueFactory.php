<?php

namespace Database\Factories;

use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldValue>
 *
 * custom_field_id, customizable_type and customizable_id are all NOT NULL
 * at the database level. They are left null in definition() by design —
 * the caller/seeder must always supply them, e.g.
 * CustomFieldValue::factory()->create([
 *     'custom_field_id' => $customField->id,
 *     'customizable_type' => $lead->getMorphClass(),
 *     'customizable_id' => $lead->id,
 * ]).
 */
class CustomFieldValueFactory extends Factory
{
    protected $model = CustomFieldValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_field_id' => null,
            'customizable_type' => null,
            'customizable_id' => null,
            'value' => fake()->word(),
        ];
    }
}
