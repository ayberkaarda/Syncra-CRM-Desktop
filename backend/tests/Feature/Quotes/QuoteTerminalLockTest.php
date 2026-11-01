<?php

namespace Tests\Feature\Quotes;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sync\InteractsWithDeviceTokens;
use Tests\TestCase;

/**
 * Sonuçlanmış (accepted/rejected/expired) teklifte `terms` ve `valid_until`
 * de tutar alanları gibi kilitlidir; `sent` teklifte serbest kalırlar.
 */
class QuoteTerminalLockTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function editor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['quotes.view', 'quotes.update']);

        return $user;
    }

    private function quote(string $status): Quote
    {
        $quote = Quote::factory()->create([
            'status' => $status,
            'terms' => 'Ödeme 30 gün içinde.',
            'valid_until' => now()->addDays(20)->toDateString(),
        ]);

        QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_percent' => 0,
            'tax_rate' => 20,
            'line_total' => 100,
            'position' => 1,
        ]);

        return $quote->fresh();
    }

    public function test_accepted_quote_terms_and_valid_until_are_locked(): void
    {
        $actor = $this->editor();
        $quote = $this->quote('accepted');
        $terms = $quote->terms;
        $validUntil = $quote->valid_until->toDateString();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['terms' => 'Ödeme 365 gün içinde.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_LOCKED')
            ->assertJsonStructure(['errors' => ['fields' => ['terms']]]);

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['valid_until' => now()->addYear()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_LOCKED')
            ->assertJsonStructure(['errors' => ['fields' => ['valid_until']]]);

        $fresh = $quote->fresh();
        $this->assertSame($terms, $fresh->terms);
        $this->assertSame($validUntil, $fresh->valid_until->toDateString());
    }

    public function test_rejected_and_expired_quotes_are_locked_the_same_way(): void
    {
        $actor = $this->editor();

        foreach (['rejected', 'expired'] as $status) {
            $quote = $this->quote($status);

            $this->actingAs($actor)
                ->patchJson("/api/quotes/{$quote->id}", ['terms' => null])
                ->assertStatus(422)
                ->assertJsonPath('errors.code', 'QUOTE_LOCKED');
        }
    }

    /**
     * Meşru davranış: `sent` teklifin geçerliliği uzatılabilir, koşul metni
     * düzeltilebilir; sonuçlanmış teklifte başlık ve not hâlâ düzenlenebilir.
     */
    public function test_sent_quote_stays_editable_and_titles_stay_editable_after_acceptance(): void
    {
        $actor = $this->editor();
        $sent = $this->quote('sent');
        $extended = now()->addDays(45)->toDateString();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$sent->id}", ['valid_until' => $extended, 'terms' => 'Güncel koşullar.'])
            ->assertOk();

        $this->assertSame($extended, $sent->fresh()->valid_until->toDateString());
        $this->assertSame('Güncel koşullar.', $sent->fresh()->terms);

        $accepted = $this->quote('accepted');

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$accepted->id}", ['title' => 'Yeni başlık', 'notes' => 'İç not'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Yeni başlık');
    }

    /**
     * Baypas denemesi: aynı alan senkron push yolundan da kilitlidir.
     */
    public function test_sync_push_cannot_rewrite_terms_of_an_accepted_quote(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $quote = $this->quote('accepted');

        $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [[
                'seq' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now()->toIso8601String(),
                'op' => 'update',
                'entity' => 'quote',
                'server_id' => $quote->id,
                'base_sync_version' => $quote->sync_version,
                'changed_fields' => ['terms'],
                'payload' => ['terms' => 'Sonradan yazılmış koşul.'],
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'QUOTE_LOCKED');

        $this->assertSame('Ödeme 30 gün içinde.', $quote->fresh()->terms);
    }
}
