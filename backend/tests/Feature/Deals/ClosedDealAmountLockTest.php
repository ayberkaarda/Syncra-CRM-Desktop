<?php

namespace Tests\Feature\Deals;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Sync\InteractsWithDeviceTokens;
use Tests\TestCase;

/**
 * Kapanmış (won/lost) fırsatta `amount`/`currency` değişmez; donmuş
 * `base_amount` ile kaydın tutarı ayrışamaz. Diğer düzenlemeler ve `move`
 * ile yeniden açıp düzeltme serbesttir.
 */
class ClosedDealAmountLockTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    private PipelineStage $openStage;

    private PipelineStage $wonStage;

    private PipelineStage $lostStage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->openStage = PipelineStage::factory()->create(['slug' => 'yeni-firsat', 'position' => 1]);
        $this->wonStage = PipelineStage::factory()->won()->create(['slug' => 'kazanildi', 'position' => 2]);
        $this->lostStage = PipelineStage::factory()->lost()->create(['slug' => 'kaybedildi', 'position' => 3]);
    }

    private function rep(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['deals.view', 'deals.update', 'deals.move']);

        return $user;
    }

    private function closedDeal(User $owner, PipelineStage $stage, array $extra = []): Deal
    {
        $deal = Deal::factory()->open()->create([
            'pipeline_stage_id' => $this->openStage->getKey(),
            'position' => 'a0001',
            'version' => 1,
            'owner_id' => $owner->getKey(),
            'amount' => 12500,
            'currency' => 'TRY',
        ]);

        $this->actingAs($owner)
            ->patchJson(route('deals.move', ['deal' => $deal->getKey()]), array_merge([
                'to_stage_id' => $stage->getKey(),
                'version' => 1,
            ], $extra))
            ->assertOk();

        return $deal->refresh();
    }

    public function test_won_deal_amount_and_currency_cannot_be_edited(): void
    {
        $owner = $this->rep();
        $deal = $this->closedDeal($owner, $this->wonStage);
        $this->assertSame('12500.00', (string) $deal->base_amount);

        $this->actingAs($owner)
            ->patchJson("/api/deals/{$deal->id}", ['amount' => 99999])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'DEAL_CLOSED_AMOUNT_LOCKED');

        $this->actingAs($owner)
            ->patchJson("/api/deals/{$deal->id}", ['currency' => 'EUR'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'DEAL_CLOSED_AMOUNT_LOCKED');

        $fresh = $deal->fresh();
        $this->assertSame('12500.00', (string) $fresh->amount);
        $this->assertSame('TRY', $fresh->currency);
        $this->assertSame('12500.00', (string) $fresh->base_amount);
    }

    public function test_lost_deal_is_locked_too(): void
    {
        $owner = $this->rep();
        $deal = $this->closedDeal($owner, $this->lostStage, ['lost_reason' => 'Bütçe yok']);

        $this->actingAs($owner)
            ->patchJson("/api/deals/{$deal->id}", ['amount' => '1.00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'DEAL_CLOSED_AMOUNT_LOCKED');
    }

    /**
     * Meşru davranış: başlık düzenlenir, aynı tutarı geri gönderen tam kayıt
     * reddedilmez, yeniden açılan fırsatın tutarı düzeltilebilir.
     */
    public function test_other_edits_and_reopening_stay_allowed(): void
    {
        $owner = $this->rep();
        $deal = $this->closedDeal($owner, $this->wonStage);

        $this->actingAs($owner)
            ->patchJson("/api/deals/{$deal->id}", ['title' => 'Yeni başlık', 'amount' => '12500', 'currency' => 'try'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Yeni başlık');

        $this->actingAs($owner)
            ->patchJson(route('deals.move', ['deal' => $deal->getKey()]), [
                'to_stage_id' => $this->openStage->getKey(),
                'version' => (int) $deal->fresh()->version,
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->patchJson("/api/deals/{$deal->id}", ['amount' => 15000])
            ->assertOk();

        $this->assertSame('15000.00', (string) $deal->fresh()->amount);
        $this->assertNull($deal->fresh()->base_amount);
    }

    /**
     * Baypas denemesi: senkron push aynı servisten geçer.
     */
    public function test_sync_push_cannot_edit_a_won_deal_amount(): void
    {
        [$admin, $token] = $this->deviceUser('Admin');
        $deal = $this->closedDeal($this->rep(), $this->wonStage);

        // closedDeal() authenticated through the session guard; drop it so
        // the push is a real device-token request.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [[
                'seq' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now()->toIso8601String(),
                'op' => 'update',
                'entity' => 'deal',
                'server_id' => $deal->id,
                'base_sync_version' => $deal->sync_version,
                'changed_fields' => ['amount'],
                'payload' => ['amount' => 1],
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'DEAL_CLOSED_AMOUNT_LOCKED');

        $this->assertSame('12500.00', (string) $deal->fresh()->amount);
    }
}
