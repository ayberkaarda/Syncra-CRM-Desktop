<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Exchange\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 *
 * DONMUŞ TEMEL TUTAR (Faz 14 / İz E): `won()`/`lost()` durumları, üretimdeki
 * kapanış davranışını (App\Services\Deals\DealMoveService::freezeBaseAmount)
 * BİREBİR taklit eder — TRY'de kur 1.000000, yabancı para biriminde kapanış
 * gününün kuru (`ExchangeRateService::resolveForFreeze`), hiç kur yoksa null.
 * Aksi hâlde fabrika ile gerçek kapanış yolu farklı veri üretir ve testler
 * ürünün yapmadığı bir şeyi doğrulamış olurdu.
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement([
                'Yıllık Lisans Yenileme', 'CRM Kurulum Projesi', 'Donanım Tedariki', 'Bakım Anlaşması',
                'Danışmanlık Hizmeti', 'Yazılım Geliştirme', 'Bulut Migrasyonu', 'Eğitim Paketi',
            ]).' - '.fake('tr_TR')->company(),
            'description' => fake('tr_TR')->text(200),
            'amount' => fake()->randomFloat(2, 5000, 500000),
            'currency' => 'TRY',
            // Açık fırsatta donmuş değer YOKTUR; kapanış durumları
            // (won/lost) bunu `freezeBaseAmount()` kancasıyla doldurur.
            'base_amount' => null,
            'base_rate' => null,
            'base_rate_date' => null,
            'pipeline_stage_id' => PipelineStage::query()->where('is_won', false)->where('is_lost', false)->inRandomOrder()->value('id')
                ?? PipelineStage::factory(),
            'position' => 'a'.str_pad(base_convert((string) fake()->unique()->numberBetween(0, 46655), 10, 36), 4, '0', STR_PAD_LEFT),
            'version' => 1,
            'probability' => null,
            'expected_close_date' => fake()->dateTimeBetween('now', '+4 months'),
            'closed_at' => null,
            'status' => 'open',
            'lost_reason' => null,
            'won_reason' => null,
            'company_id' => null,
            'contact_id' => null,
            'owner_id' => null,
        ];
    }

    /**
     * Kapanmış bir fırsatın donmuş temel tutarını, üretimdeki kuralla AYNI
     * şekilde doldurur.
     *
     * `afterMaking` kullanılır, `state()` DEĞİL: `state()` kapanışları
     * fabrikanın KENDİ ürettiği rastgele `amount`'ı görür — çağıran
     * `create(['amount' => 15000])` dediğinde donmuş tutar o değerle DEĞİL,
     * atılan rastgele değerle hesaplanırdı (sessiz ve fark edilmesi çok zor
     * bir test yalanı). `afterMaking` ise nihai modeli görür.
     */
    private function freezesBaseAmount(): static
    {
        return $this->afterMaking(function (Deal $deal): void {
            if ($deal->base_amount !== null || $deal->closed_at === null) {
                return;
            }

            /** @var ExchangeRateService $rates */
            $rates = app(ExchangeRateService::class);
            $currency = strtoupper((string) ($deal->currency ?: $rates->baseCurrency()));
            $closedOn = CarbonImmutable::parse($deal->closed_at)->startOfDay();

            if ($rates->isBaseCurrency($currency)) {
                $deal->base_amount = (string) $deal->amount;
                $deal->base_rate = '1.000000';
                $deal->base_rate_date = $closedOn->toDateString();

                return;
            }

            $rate = $rates->resolveForFreeze($currency, $closedOn);

            if ($rate === null) {
                // Kur yok → alanlar null kalır (üretimdeki 3. madde ile aynı).
                return;
            }

            $deal->base_amount = $rates->toBase(
                (string) $deal->amount,
                $currency,
                CarbonImmutable::parse($rate->rate_date),
            );
            $deal->base_rate = (string) $rate->rate;
            $deal->base_rate_date = $rate->rate_date->toDateString();
        });
    }

    /**
     * Indicate that the deal was won.
     */
    public function won(): static
    {
        return $this->freezesBaseAmount()->state(fn (array $attributes) => [
            'status' => 'won',
            'closed_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'won_reason' => fake('tr_TR')->sentence(6),
            'lost_reason' => null,
            'probability' => 100,
            'pipeline_stage_id' => PipelineStage::query()->where('is_won', true)->value('id')
                ?? PipelineStage::factory()->won(),
        ]);
    }

    /**
     * Indicate that the deal was lost.
     */
    public function lost(): static
    {
        return $this->freezesBaseAmount()->state(fn (array $attributes) => [
            'status' => 'lost',
            'closed_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'lost_reason' => fake()->randomElement([
                'Fiyat yüksek bulundu', 'Rakip firma tercih edildi', 'Bütçe onaylanmadı',
                'Proje ertelendi', 'İhtiyaç ortadan kalktı',
            ]),
            'won_reason' => null,
            'probability' => 0,
            'pipeline_stage_id' => PipelineStage::query()->where('is_lost', true)->value('id')
                ?? PipelineStage::factory()->lost(),
        ]);
    }

    /**
     * Indicate that the deal is still open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'closed_at' => null,
            'lost_reason' => null,
            'won_reason' => null,
            'base_amount' => null,
            'base_rate' => null,
            'base_rate_date' => null,
        ]);
    }
}
