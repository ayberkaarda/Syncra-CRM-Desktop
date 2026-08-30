<?php

namespace Database\Factories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => 'TKT-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'subject' => fake()->randomElement([
                'Sisteme giriş yapamıyorum', 'Fatura hatası bildirimi', 'Ürün kurulumunda sorun',
                'Hesap bilgilerimi güncelleyemiyorum', 'Ödeme işlemi başarısız', 'Entegrasyon hatası',
                'Şifremi sıfırlayamıyorum', 'Rapor oluşturulamıyor',
            ]),
            'description' => fake('tr_TR')->text(200),
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'status' => 'open',
            'category' => fake()->randomElement([
                'Teknik Destek', 'Faturalandırma', 'Ürün Bilgisi', 'Şikayet', 'Kurulum', 'Eğitim Talebi',
            ]),
            'contact_id' => null,
            'company_id' => null,
            'assigned_to' => null,
            'created_by' => null,
            'sla_due_at' => fake()->dateTimeBetween('now', '+5 days'),
            'first_response_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Indicate that the ticket has been resolved.
     */
    public function resolved(): static
    {
        return $this->state(function (array $attributes) {
            $firstResponseAt = fake()->dateTimeBetween('-10 days', '-1 days');

            return [
                'status' => 'resolved',
                'first_response_at' => $firstResponseAt,
                'resolved_at' => fake()->dateTimeBetween((clone $firstResponseAt)->modify('+1 hour'), 'now'),
                'closed_at' => null,
            ];
        });
    }

    /**
     * Indicate that the ticket has been closed.
     */
    public function closed(): static
    {
        return $this->state(function (array $attributes) {
            $firstResponseAt = fake()->dateTimeBetween('-20 days', '-10 days');
            $resolvedAt = fake()->dateTimeBetween((clone $firstResponseAt)->modify('+1 hour'), '-2 days');

            return [
                'status' => 'closed',
                'first_response_at' => $firstResponseAt,
                'resolved_at' => $resolvedAt,
                'closed_at' => fake()->dateTimeBetween((clone $resolvedAt)->modify('+1 hour'), 'now'),
            ];
        });
    }

    /**
     * Indicate that the ticket has breached its SLA.
     */
    public function slaBreached(): static
    {
        return $this->state(fn (array $attributes) => [
            'sla_due_at' => fake()->dateTimeBetween('-20 days', '-1 days'),
            'status' => 'open',
            'resolved_at' => null,
            'closed_at' => null,
        ]);
    }
}
