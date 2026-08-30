<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP']),
            // Kaba ama gerçekçi bir TRY karşılığı aralığı; float'a asla
            // dönüşmeden sabit haneli string olarak yazılır.
            'rate' => number_format(fake()->randomFloat(6, 5, 45), 6, '.', ''),
            'unit' => 1,
            'rate_date' => Carbon::today()->toDateString(),
            'source' => 'tcmb',
            'entered_by' => null,
        ];
    }

    /**
     * Elle (Ayarlar'dan) girilmiş kur — `entered_by` dolu bir kullanıcıya
     * bağlanır.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'manual',
            'entered_by' => User::factory(),
        ]);
    }

    public function forCurrency(string $currency, string $rate): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => strtoupper($currency),
            'rate' => $rate,
        ]);
    }

    public function onDate(CarbonInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'rate_date' => $date->toDateString(),
        ]);
    }

    /**
     * `Unit=100` senaryosu (ör. JPY benzeri) — bölme mantığının genel
     * yazıldığını doğrulayan testlerde kullanılır.
     */
    public function withUnit(int $unit): static
    {
        return $this->state(fn (array $attributes) => [
            'unit' => $unit,
        ]);
    }
}
