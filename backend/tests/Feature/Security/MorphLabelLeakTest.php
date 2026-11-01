<?php

namespace Tests\Feature\Security;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Görev/aktivite listelerinde bağlı kaydın (morph hedefi) kimliği ve başlığı,
 * yalnızca o kaydın modülünü görebilen kullanıcıya gösterilir. Destek
 * temsilcisi `tasks.view`/`activities.view`/`dashboard.view` taşır ama
 * `deals.view` taşımaz; fırsat başlığı ona hiçbir listeden ulaşmamalı.
 */
class MorphLabelLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{0: Deal, 1: Task, 2: Activity}
     */
    private function seedDealChildren(): array
    {
        $deal = Deal::factory()->create(['title' => 'GIZLI_FIRSAT_BASLIGI']);

        $task = Task::factory()->create(['taskable_type' => Deal::class, 'taskable_id' => $deal->id]);
        $activity = Activity::factory()->create([
            'activityable_type' => Deal::class,
            'activityable_id' => $deal->id,
            'occurred_at' => now(),
        ]);

        return [$deal, $task, $activity];
    }

    public function test_support_rep_does_not_see_deal_label_in_task_and_activity_responses(): void
    {
        [$deal, $task, $activity] = $this->seedDealChildren();
        $support = $this->actorWithRole('Destek Temsilcisi');

        $urls = [
            '/api/tasks',
            "/api/tasks/{$task->id}",
            '/api/activities',
            "/api/activities/{$activity->id}",
            '/api/dashboard/recent-activities',
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($support)->getJson($url)->assertOk();

            $this->assertStringNotContainsString('GIZLI_FIRSAT_BASLIGI', $response->getContent(), $url);
        }

        $this->actingAs($support)->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.taskable', null);
        $this->actingAs($support)->getJson("/api/activities/{$activity->id}")->assertJsonPath('data.activityable', null);
    }

    public function test_sales_rep_still_sees_the_deal_label(): void
    {
        [$deal, $task, $activity] = $this->seedDealChildren();
        $rep = $this->actorWithRole('Satış Temsilcisi');

        $this->actingAs($rep)->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.taskable.label', 'GIZLI_FIRSAT_BASLIGI')
            ->assertJsonPath('data.taskable.id', $deal->id);

        $this->actingAs($rep)->getJson("/api/activities/{$activity->id}")
            ->assertOk()
            ->assertJsonPath('data.activityable.label', 'GIZLI_FIRSAT_BASLIGI');

        $related = collect($this->actingAs($rep)->getJson('/api/dashboard/recent-activities')->assertOk()->json('data'))
            ->firstWhere('id', $activity->id)['related'] ?? null;

        $this->assertSame('GIZLI_FIRSAT_BASLIGI', $related['label'] ?? null);
    }
}
