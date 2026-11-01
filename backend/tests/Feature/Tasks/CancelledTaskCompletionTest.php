<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sync\InteractsWithDeviceTokens;
use Tests\TestCase;

/**
 * İptal edilmiş görev `/complete` ile tamamlanamıyorsa genel PATCH'le de
 * tamamlanamamalıdır.
 */
class CancelledTaskCompletionTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['tasks.view', 'tasks.update']);

        return $user;
    }

    public function test_generic_patch_cannot_complete_a_cancelled_task(): void
    {
        $owner = $this->owner();
        $task = Task::factory()->create(['status' => 'cancelled', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->patchJson("/api/tasks/{$task->id}", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.fields.status.0', 'İptal edilmiş bir görev tamamlanamaz.');

        $this->assertSame('cancelled', $task->fresh()->status);
    }

    /**
     * Meşru davranış: iptal açıkça geri alınabilir ve sonra tamamlanabilir;
     * iptal edilmemiş görev PATCH ile durum değiştirebilir.
     */
    public function test_reopening_then_completing_and_other_transitions_stay_allowed(): void
    {
        $owner = $this->owner();
        $task = Task::factory()->create(['status' => 'cancelled', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->actingAs($owner)->patchJson("/api/tasks/{$task->id}", ['status' => 'pending'])->assertOk();
        $this->actingAs($owner)->patchJson("/api/tasks/{$task->id}", ['status' => 'completed'])->assertOk();
        $this->assertSame('completed', $task->fresh()->status);

        $other = Task::factory()->create(['status' => 'in_progress', 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
        $this->actingAs($owner)->patchJson("/api/tasks/{$other->id}", ['status' => 'cancelled', 'title' => 'Vazgeçildi'])->assertOk();
        $this->assertSame('cancelled', $other->fresh()->status);
    }

    /**
     * Baypas denemesi: senkron push'un `update` yolu.
     */
    public function test_sync_update_cannot_complete_a_cancelled_task(): void
    {
        [$admin, $token] = $this->deviceUser('Admin');
        $task = Task::factory()->create(['status' => 'cancelled', 'assigned_to' => $admin->id, 'created_by' => $admin->id]);

        $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [[
                'seq' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now()->toIso8601String(),
                'op' => 'update',
                'entity' => 'task',
                'server_id' => $task->id,
                'base_sync_version' => $task->sync_version,
                'changed_fields' => ['status'],
                'payload' => ['status' => 'completed'],
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_MUTATION');

        $this->assertSame('cancelled', $task->fresh()->status);
    }
}
