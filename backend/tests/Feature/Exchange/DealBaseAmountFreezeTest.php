<?php

namespace Tests\Feature\Exchange;

use App\Models\Deal;
use App\Models\ExchangeRate;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faz 14 / İz E — fırsat kapanışında DONMUŞ temel tutar
 * (docs/PHASE-INTL.md §2.3–§2.4).
 *
 * Buradaki testlerin ağırlık merkezi "doğru çarpma" değil, DONMANIN
 * KENDİSİDİR: kur sonradan değişince rakamın DEĞİŞMEMESİ, yeniden açılan
 * fırsatta donmuş değerin TEMİZLENMESİ ve kur hiç yokken SESSİZ SIFIR
 * yazılmaması.
 */
class DealBaseAmountFreezeTest extends TestCase
{
    use RefreshDatabase;

    private PipelineStage $openStage;

    private PipelineStage $wonStage;

    private PipelineStage $lostStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // Faz 10 bildirim gönderimi bu testin konusu değil (rapor/kur
        // matematiği ölçülüyor) — gerçek kanal devre dışı.
        Notification::fake();

        $this->openStage = PipelineStage::factory()->create(['slug' => 'yeni-firsat', 'position' => 1]);
        $this->wonStage = PipelineStage::factory()->won()->create(['slug' => 'kazanildi', 'position' => 2]);
        $this->lostStage = PipelineStage::factory()->lost()->create(['slug' => 'kaybedildi', 'position' => 3]);
    }

    private function mover(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['deals.view', 'deals.move']);

        return $user;
    }

    private function openDeal(array $attributes = []): Deal
    {
        return Deal::factory()->open()->create(array_merge([
            'pipeline_stage_id' => $this->openStage->getKey(),
            'position' => 'a0001',
            'version' => 1,
        ], $attributes));
    }

    private function moveTo(User $actor, Deal $deal, PipelineStage $stage, array $extra = []): void
    {
        $this->actingAs($actor)
            ->patchJson(route('deals.move', ['deal' => $deal->getKey()]), array_merge([
                'to_stage_id' => $stage->getKey(),
                'version' => (int) $deal->version,
            ], $extra))
            ->assertOk();
    }

    // -------------------------------------------------------------------
    // TRY — kur tanım gereği 1.000000
    // -------------------------------------------------------------------

    public function test_winning_a_try_deal_freezes_base_amount_with_rate_one(): void
    {
        $deal = $this->openDeal(['amount' => 12500.55, 'currency' => 'TRY']);

        $this->moveTo($this->mover(), $deal, $this->wonStage);

        $deal->refresh();

        $this->assertSame('won', $deal->status);
        $this->assertSame('12500.55', (string) $deal->base_amount);
        $this->assertSame('1.000000', (string) $deal->base_rate);
        $this->assertSame(
            $deal->closed_at->toDateString(),
            $deal->base_rate_date->toDateString(),
        );
    }

    public function test_losing_a_deal_also_freezes_base_amount(): void
    {
        $deal = $this->openDeal(['amount' => 4000, 'currency' => 'TRY']);

        $this->moveTo($this->mover(), $deal, $this->lostStage, ['lost_reason' => 'Bütçe onaylanmadı']);

        $deal->refresh();

        $this->assertSame('lost', $deal->status);
        $this->assertSame('4000.00', (string) $deal->base_amount);
        $this->assertSame('1.000000', (string) $deal->base_rate);
    }

    // -------------------------------------------------------------------
    // Yabancı para birimi — kapanış GÜNÜNÜN kuru
    // -------------------------------------------------------------------

    public function test_winning_a_foreign_currency_deal_freezes_the_closing_day_rate(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD',
            'rate' => '41.250000',
            'rate_date' => today()->toDateString(),
        ]);

        $deal = $this->openDeal(['amount' => 1000, 'currency' => 'USD']);

        $this->moveTo($this->mover(), $deal, $this->wonStage);

        $deal->refresh();

        // 1000.00 × 41.250000 = 41250.00 — bcmath, float yok.
        $this->assertSame('41250.00', (string) $deal->base_amount);
        $this->assertSame('41.250000', (string) $deal->base_rate);
        $this->assertSame(today()->toDateString(), $deal->base_rate_date->toDateString());
    }

    /**
     * PHASE-INTL §2.4'ün ASIL vaadi: gerçekleşmiş gelir sabittir. Kapanıştan
     * SONRA yayınlanan yeni bir kur, kapanmış fırsatın donmuş değerini
     * DEĞİŞTİRMEZ.
     */
    public function test_a_later_rate_publication_does_not_change_a_frozen_deal(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '41.250000', 'rate_date' => today()->toDateString(),
        ]);

        $deal = $this->openDeal(['amount' => 1000, 'currency' => 'USD']);
        $this->moveTo($this->mover(), $deal, $this->wonStage);

        $frozen = (string) $deal->refresh()->base_amount;

        // Ertesi gün kur iki katına çıkıyor.
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '82.500000', 'rate_date' => today()->addDay()->toDateString(),
        ]);

        $this->assertSame($frozen, (string) $deal->fresh()->base_amount);
        $this->assertSame('41.250000', (string) $deal->fresh()->base_rate);
    }

    /**
     * KUR HİÇ YOKSA (bilinçli karar): 0 YAZILMAZ, alanlar null kalır.
     * Sıfır "geliri yok" demek olurdu ve raporu sessizce yanıltırdı.
     */
    public function test_closing_without_any_rate_leaves_frozen_fields_null_instead_of_zero(): void
    {
        $deal = $this->openDeal(['amount' => 1000, 'currency' => 'EUR']);

        $this->moveTo($this->mover(), $deal, $this->wonStage);

        $deal->refresh();

        $this->assertSame('won', $deal->status);
        $this->assertNull($deal->base_amount);
        $this->assertNull($deal->base_rate);
        $this->assertNull($deal->base_rate_date);
    }

    /**
     * Kapanış gününde satır yoksa EN SON BİLİNEN kur kullanılır — ve
     * donmuş kur ile donmuş tutar AYNI satırdan gelir (sapma olamaz).
     */
    public function test_closing_falls_back_to_the_latest_known_rate_when_the_closing_day_has_none(): void
    {
        // Yalnızca GELECEK tarihli bir satır var (kapanış gününde yok).
        ExchangeRate::factory()->create([
            'currency' => 'GBP', 'rate' => '55.000000', 'rate_date' => today()->addDays(3)->toDateString(),
        ]);

        $deal = $this->openDeal(['amount' => 200, 'currency' => 'GBP']);

        $this->moveTo($this->mover(), $deal, $this->wonStage);

        $deal->refresh();

        $this->assertSame('11000.00', (string) $deal->base_amount);
        $this->assertSame('55.000000', (string) $deal->base_rate);
        $this->assertSame(today()->addDays(3)->toDateString(), $deal->base_rate_date->toDateString());
    }

    // -------------------------------------------------------------------
    // Yeniden açılma ve yeniden kapanma
    // -------------------------------------------------------------------

    public function test_reopening_a_closed_deal_clears_the_frozen_fields(): void
    {
        $deal = $this->openDeal(['amount' => 1000, 'currency' => 'TRY']);
        $actor = $this->mover();

        $this->moveTo($actor, $deal, $this->wonStage);
        $this->assertNotNull($deal->refresh()->base_amount);

        $this->moveTo($actor, $deal->refresh(), $this->openStage);

        $deal->refresh();

        $this->assertSame('open', $deal->status);
        $this->assertNull($deal->base_amount);
        $this->assertNull($deal->base_rate);
        $this->assertNull($deal->base_rate_date);
    }

    /**
     * Aynı sütun içinde yeniden sıralama, `closed_at` gibi donmuş kuru da
     * TAZELEMEZ — kur bu arada değişse bile.
     */
    public function test_resorting_a_won_deal_does_not_refresh_the_frozen_rate(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '41.250000', 'rate_date' => today()->toDateString(),
        ]);

        $deal = $this->openDeal(['amount' => 1000, 'currency' => 'USD']);
        $actor = $this->mover();

        $this->moveTo($actor, $deal, $this->wonStage);
        $deal->refresh();

        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '99.000000', 'rate_date' => today()->addDay()->toDateString(),
        ]);

        // Aynı (kazanıldı) aşamasında yeniden sıralama.
        $this->moveTo($actor, $deal, $this->wonStage);

        $this->assertSame('41250.00', (string) $deal->fresh()->base_amount);
        $this->assertSame('41.250000', (string) $deal->fresh()->base_rate);
    }

    /**
     * FLOAT YASAĞI (docs/QUOTE-FINANCIALS.md): kuruş hassasiyeti float
     * yuvarlamasıyla kaybolmamalı. 0.1 + 0.2 sınıfı bir hata olsaydı
     * aşağıdaki round-trip bozulurdu.
     */
    public function test_frozen_amount_survives_a_decimal_string_round_trip(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '33.333333', 'rate_date' => today()->toDateString(),
        ]);

        $deal = $this->openDeal(['amount' => 0.30, 'currency' => 'USD']);

        $this->moveTo($this->mover(), $deal, $this->wonStage);

        // 0.30 × 33.333333 = 9.9999999 → half-up 2 hane = 10.00
        $this->assertSame('10.00', (string) $deal->refresh()->base_amount);

        // DB'den okunan değer decimal STRING'dir, float değil.
        $this->assertIsString($deal->fresh()->getAttributes()['base_amount']);
    }
}
