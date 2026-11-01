<?php

namespace Tests\Feature\Automation;

use App\Events\DealMoved;
use App\Models\AutomationRule;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kural, yazarının GÜNCEL durumuyla çalışır: yazar devre dışı bırakıldıysa
 * ya da izni (doğrudan veya rol üzerinden) geri alındıysa kural hiçbir eylem
 * üretmez. Yazma anındaki kontrol kalıcı bir yetki bırakmamalı.
 */
class AutomationCreatorStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function fireStageRule(User $creator): Deal
    {
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStageChanged($toStage->id)
            ->taskCreateAction(dueInDays: 2)
            ->create();

        $deal = Deal::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'pipeline_stage_id' => $toStage->id,
        ]);

        event(new DealMoved(DealMoved::payload($deal, $fromStage->id, User::factory()->create())));

        return $deal;
    }

    private function creator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['settings.manage', 'deals.view', 'tasks.create', 'tasks.assign']);

        return $user;
    }

    public function test_active_creator_rule_runs(): void
    {
        $deal = $this->fireStageRule($this->creator());

        $this->assertTrue(Task::query()->where('taskable_id', $deal->id)->exists());
    }

    public function test_deactivated_creator_rule_does_not_run(): void
    {
        $creator = $this->creator();
        $creator->forceFill(['is_active' => false])->save();

        $deal = $this->fireStageRule($creator);

        $this->assertFalse(Task::query()->where('taskable_id', $deal->id)->exists());
    }

    public function test_soft_deleted_creator_rule_does_not_run(): void
    {
        $creator = $this->creator();
        $creator->delete();

        $deal = $this->fireStageRule($creator);

        $this->assertFalse(Task::query()->where('taskable_id', $deal->id)->exists());
    }

    public function test_creator_whose_role_loses_the_permission_rule_does_not_run(): void
    {
        $role = Role::create(['name' => 'Otomasyon Yazarı', 'guard_name' => 'web']);
        $role->givePermissionTo(['settings.manage', 'deals.view', 'tasks.create', 'tasks.assign']);

        $creator = User::factory()->create();
        $creator->assignRole($role);

        $role->revokePermissionTo('tasks.assign');

        $deal = $this->fireStageRule($creator);

        $this->assertFalse(Task::query()->where('taskable_id', $deal->id)->exists());
    }
}
