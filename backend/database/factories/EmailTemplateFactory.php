<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word().'_'.fake()->unique()->numberBetween(1, 99999),
            'name' => fake()->randomElement([
                'Teklif Gönderimi',
                'Talep Açıldı Bilgilendirmesi',
                'Hoş Geldiniz',
                'Görev Hatırlatması',
                'Teklif Hatırlatması',
            ]),
            'subject' => 'Sayın {{ contact.name }}, {{ company.name }} teklifiniz hazır',
            'body_html' => '<p>Sayın {{ contact.name }},</p><p>{{ quote.title }} numaralı teklifiniz ektedir.</p>',
            'variables' => ['contact.name', 'company.name', 'quote.title'],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the template is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
