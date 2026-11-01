<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealMoveService;
use App\Services\Settings\PipelineStageService;
use App\Services\Users\UserService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * A broadcast describes a committed write. One sent from inside a transaction
 * that later rolls back tells every open screen about a change the database
 * never kept, so each event below may leave only after the OUTERMOST commit,
 * whoever opened it (a desktop push wraps every service in its own transaction,
 * and the synced repositories open nested ones).
 *
 * The broadcaster here records what reaches it; the queue is `sync`, so a
 * broadcast that is not deferred arrives at once.
 */
class BroadcastAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $sent = &$this->sent;
        $spy = new class($sent) implements Broadcaster
        {
            /** @param  array<int, string>  $sent */
            public function __construct(private array &$sent) {}

            public function auth($request)
            {
                return true;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->sent[] = $event;
            }
        };

        $this->app->make(BroadcastManager::class)->extend('spy', fn () => $spy);
        config([
            'broadcasting.default' => 'spy',
            'broadcasting.connections.spy' => ['driver' => 'spy'],
            'queue.default' => 'sync',
        ]);
    }

    private function actor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);

        return $user;
    }

    /**
     * Run `$write` inside a transaction that is then rolled back.
     */
    private function rolledBack(callable $write): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('roll back', $e->getMessage());
        }
    }

    public function test_a_rolled_back_deal_move_is_not_broadcast(): void
    {
        $actor = $this->actor();
        $deal = Deal::factory()->create();
        $target = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false, 'is_active' => true]);
        $this->sent = [];

        $this->rolledBack(fn () => app(DealMoveService::class)->move(
            $deal->fresh(),
            ['to_stage_id' => $target->id, 'version' => (int) $deal->fresh()->version],
            $actor,
        ));

        $this->assertNotContains('deal.moved', $this->sent);
        $this->assertNotSame($target->id, (int) $deal->fresh()->pipeline_stage_id);
    }

    public function test_a_committed_deal_move_is_broadcast_after_the_outer_commit(): void
    {
        $actor = $this->actor();
        $deal = Deal::factory()->create();
        $target = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false, 'is_active' => true]);
        $this->sent = [];
        $inside = null;

        DB::transaction(function () use ($deal, $target, $actor, &$inside): void {
            app(DealMoveService::class)->move(
                $deal->fresh(),
                ['to_stage_id' => $target->id, 'version' => (int) $deal->fresh()->version],
                $actor,
            );
            $inside = in_array('deal.moved', $this->sent, true);
        });

        $this->assertFalse($inside, 'sent before the outer commit');
        $this->assertContains('deal.moved', $this->sent);
    }

    public function test_a_rolled_back_stage_deactivation_does_not_broadcast_its_moves(): void
    {
        $actor = $this->actor();
        $stage = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false, 'is_active' => true]);
        $target = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false, 'is_active' => true]);
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'status' => 'open']);
        $this->sent = [];

        $this->rolledBack(fn () => app(PipelineStageService::class)->update(
            $stage,
            ['is_active' => false, 'move_to_stage_id' => $target->id],
            $actor,
        ));

        $this->assertNotContains('deal.moved', $this->sent);
    }

    public function test_a_rolled_back_audit_row_is_not_broadcast(): void
    {
        $this->actor();
        $this->sent = [];

        $this->rolledBack(fn () => Company::factory()->create());

        $this->assertNotContains('activity.logged', $this->sent);
    }

    public function test_a_committed_audit_row_is_still_broadcast(): void
    {
        $this->actor();
        $this->sent = [];

        Company::factory()->create();

        $this->assertContains('activity.logged', $this->sent);
    }

    public function test_a_rolled_back_deactivation_is_not_broadcast(): void
    {
        $this->actor();
        $user = User::factory()->create();
        $this->sent = [];

        $this->rolledBack(fn () => app(UserService::class)->toggleActive($user, false));

        $this->assertNotContains('user.deactivated', $this->sent);
        $this->assertTrue((bool) $user->fresh()->is_active);
    }
}
