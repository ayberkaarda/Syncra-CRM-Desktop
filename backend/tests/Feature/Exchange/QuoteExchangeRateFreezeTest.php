<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeRate;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faz 14 / İz E — teklifte `sent` anında DONAN kur
 * (docs/PHASE-INTL.md §2.3).
 *
 * Kritik iddia REVİZYON davranışıdır: `QTE-...-R2` YENİ bir belgedir ve
 * kendi gönderim anının kurunu alır; ebeveyn kendi donmuş kurunu KORUR.
 */
class QuoteExchangeRateFreezeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    private function actor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.send',
        ]);

        return $user;
    }

    private function draftWithItem(string $currency = 'TRY'): Quote
    {
        $quote = Quote::factory()->create(['status' => 'draft', 'currency' => $currency]);

        QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'quantity' => 1,
            'unit_price' => 100.00,
            'discount_percent' => 0,
            'tax_rate' => 20,
            'line_total' => 100.00,
            'position' => 1,
        ]);

        return $quote->fresh();
    }

    private function send(User $actor, Quote $quote): void
    {
        $this->actingAs($actor)->postJson("/api/quotes/{$quote->id}/send")->assertOk();
    }

    public function test_a_draft_quote_has_no_frozen_rate(): void
    {
        $quote = $this->draftWithItem();

        $this->assertNull($quote->exchange_rate);
        $this->assertNull($quote->exchange_rate_date);
    }

    public function test_sending_a_try_quote_freezes_rate_one(): void
    {
        $quote = $this->draftWithItem('TRY');

        $this->send($this->actor(), $quote);

        $quote->refresh();

        $this->assertSame('sent', $quote->status);
        $this->assertSame('1.000000', (string) $quote->exchange_rate);
        $this->assertSame(today()->toDateString(), $quote->exchange_rate_date->toDateString());
    }

    public function test_sending_a_foreign_currency_quote_freezes_the_published_rate_and_its_date(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD',
            'rate' => '41.250000',
            'rate_date' => today()->subDay()->toDateString(),
        ]);

        $quote = $this->draftWithItem('USD');

        $this->send($this->actor(), $quote);

        $quote->refresh();

        $this->assertSame('41.250000', (string) $quote->exchange_rate);
        $this->assertSame(today()->subDay()->toDateString(), $quote->exchange_rate_date->toDateString());
    }

    public function test_sending_without_any_rate_leaves_the_frozen_rate_null(): void
    {
        $quote = $this->draftWithItem('EUR');

        $this->send($this->actor(), $quote);

        $quote->refresh();

        $this->assertSame('sent', $quote->status);
        $this->assertNull($quote->exchange_rate);
        $this->assertNull($quote->exchange_rate_date);
    }

    public function test_a_later_rate_change_does_not_alter_an_already_sent_quote(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '41.250000', 'rate_date' => today()->toDateString(),
        ]);

        $quote = $this->draftWithItem('USD');
        $this->send($this->actor(), $quote);

        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '99.990000', 'rate_date' => today()->addDay()->toDateString(),
        ]);

        $this->assertSame('41.250000', (string) $quote->fresh()->exchange_rate);
    }

    /**
     * PHASE-INTL §2.3: revizyon YENİ bir ticari tekliftir — ebeveyninin
     * donmuş kurunu DEVRALMAZ, kendi gönderim anında TAZE kur alır.
     */
    public function test_a_revision_takes_a_fresh_rate_and_the_parent_keeps_its_own(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '41.250000', 'rate_date' => today()->subDays(2)->toDateString(),
        ]);

        $actor = $this->actor();
        $parent = $this->draftWithItem('USD');
        $this->send($actor, $parent);
        $parent->refresh();

        $this->assertSame('41.250000', (string) $parent->exchange_rate);

        // Kur değişiyor.
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '55.500000', 'rate_date' => today()->toDateString(),
        ]);

        $revisionId = $this->actingAs($actor)
            ->postJson("/api/quotes/{$parent->id}/revise")
            ->assertOk()
            ->json('data.id');

        /** @var Quote $revision */
        $revision = Quote::query()->findOrFail($revisionId);

        // Taslak doğar → kur DEVRALINMAZ.
        $this->assertSame('draft', $revision->status);
        $this->assertNull($revision->exchange_rate);
        $this->assertNull($revision->exchange_rate_date);
        $this->assertSame((int) $parent->id, (int) $revision->parent_quote_id);

        $this->send($actor, $revision);

        // Revizyon KENDİ gönderim anının kurunu alır…
        $this->assertSame('55.500000', (string) $revision->fresh()->exchange_rate);
        $this->assertSame(today()->toDateString(), $revision->fresh()->exchange_rate_date->toDateString());

        // …ebeveyn ise kendi donmuş kurunu KORUR.
        $this->assertSame('41.250000', (string) $parent->fresh()->exchange_rate);
        $this->assertSame(
            today()->subDays(2)->toDateString(),
            $parent->fresh()->exchange_rate_date->toDateString(),
        );
    }

    public function test_the_frozen_rate_is_exposed_on_the_quote_resource(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '41.250000', 'rate_date' => today()->toDateString(),
        ]);

        $actor = $this->actor();
        $quote = $this->draftWithItem('USD');
        $this->send($actor, $quote);

        $this->actingAs($actor)
            ->getJson("/api/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.exchange_rate', 41.25)
            ->assertJsonPath('data.exchange_rate_date', today()->toDateString());
    }

    /**
     * §2.7: kur, kalem/KDV matematiğine GİRMEZ. Teklif kendi para biriminde
     * hesaplanmaya devam eder; donmuş kur ayrı bir kolondur.
     */
    public function test_freezing_the_rate_does_not_touch_the_quote_totals(): void
    {
        ExchangeRate::factory()->create([
            'currency' => 'USD', 'rate' => '41.250000', 'rate_date' => today()->toDateString(),
        ]);

        $quote = $this->draftWithItem('USD');
        $totalsBefore = [
            (string) $quote->subtotal, (string) $quote->tax_amount, (string) $quote->total,
        ];

        $this->send($this->actor(), $quote);
        $quote->refresh();

        $this->assertSame($totalsBefore, [
            (string) $quote->subtotal, (string) $quote->tax_amount, (string) $quote->total,
        ]);
    }
}
