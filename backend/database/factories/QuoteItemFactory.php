<?php

namespace Database\Factories;

use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 *
 * quote_id is NOT NULL at the database level. It is left null in
 * definition() by design — the caller/seeder must always supply it,
 * e.g. QuoteItem::factory()->create(['quote_id' => $quote->id]).
 */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 20);
        $unitPrice = fake()->randomFloat(2, 250, 75000);
        $discount = fake()->randomElement([0, 0, 0, 5, 10, 15]);

        return [
            'quote_id' => null,
            'product_id' => null,
            'name' => fake()->randomElement([
                'CRM Kullanıcı Lisansı',
                'Sunucu Bakım Paketi',
                'Bulut Depolama 1TB',
                'Yerinde Kurulum Hizmeti',
                'Kullanıcı Eğitimi (Günlük)',
                'Mobil Uygulama Modülü',
                'Entegrasyon Danışmanlığı',
                'SMS Paketi 10.000',
                'E-Fatura Entegrasyonu',
                'Yedekleme Hizmeti',
            ]),
            'description' => fake()->text(100),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_percent' => $discount,
            'tax_rate' => 20,
            'line_total' => round($quantity * $unitPrice * (1 - $discount / 100), 2),
            'position' => 0,
        ];
    }
}
