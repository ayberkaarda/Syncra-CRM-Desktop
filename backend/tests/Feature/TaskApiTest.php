<?php

namespace Tests\Feature;

use App\Events\TaskReminderDue;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $task = Task::factory()->create();

        $this->getJson('/api/tasks')->assertStatus(401);
        $this->getJson('/api/tasks/calendar?from=2026-01-01&to=2026-01-31')->assertStatus(401);
        $this->postJson('/api/tasks', [])->assertStatus(401);
        $this->getJson("/api/tasks/{$task->id}")->assertStatus(401);
        $this->patchJson("/api/tasks/{$task->id}", [])->assertStatus(401);
        $this->deleteJson("/api/tasks/{$task->id}")->assertStatus(401);
        $this->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true])->assertStatus(401);
        $this->patchJson("/api/tasks/{$task->id}/assign", ['assigned_to' => 1])->assertStatus(401);
    }

    // -------------------------------------------------------------------
    // Yetkilendirme (403)
    // -------------------------------------------------------------------

    public function test_user_without_tasks_view_permission_cannot_list_tasks(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/tasks')->assertStatus(403);
    }

    public function test_user_without_tasks_view_permission_cannot_view_calendar(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)
            ->getJson('/api/tasks/calendar?from=2026-01-01&to=2026-01-31')
            ->assertStatus(403);
    }

    public function test_user_without_tasks_view_permission_cannot_show_task(): void
    {
        $actor = User::factory()->create();
        $task = Task::factory()->create();

        $this->actingAs($actor)->getJson("/api/tasks/{$task->id}")->assertStatus(403);
    }

    public function test_user_without_tasks_create_permission_cannot_store_task(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->postJson('/api/tasks', ['title' => 'Test'])->assertStatus(403);
    }

    public function test_user_without_tasks_update_permission_cannot_update_task(): void
    {
        $actor = User::factory()->create();
        $task = Task::factory()->create();

        $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}", ['title' => 'X'])->assertStatus(403);
    }

    public function test_user_without_tasks_delete_permission_cannot_destroy_task(): void
    {
        $actor = User::factory()->create();
        $task = Task::factory()->create();

        $this->actingAs($actor)->deleteJson("/api/tasks/{$task->id}")->assertStatus(403);
    }

    public function test_user_without_tasks_update_permission_cannot_complete_task(): void
    {
        $actor = User::factory()->create();
        $task = Task::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true])
            ->assertStatus(403);
    }

    public function test_user_without_tasks_assign_permission_cannot_assign_task(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);
        $assignee = User::factory()->create();
        $task = Task::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/tasks/{$task->id}/assign", ['assigned_to' => $assignee->id])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Route sırası
    // -------------------------------------------------------------------

    /**
     * `/tasks/calendar` gerçekten calendar ucuna gitmeli, `{task}`
     * route-model-binding'ine YAKALANMAMALI (aksi halde "calendar" bir görev
     * id'si sanılıp 404 üretirdi).
     */
    public function test_calendar_route_is_matched_before_task_show_route(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $response = $this->actingAs($actor)->getJson('/api/tasks/calendar?from=2026-01-01&to=2026-01-31');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['from', 'to', 'count']]);
    }

    // -------------------------------------------------------------------
    // Liste sözleşmesi
    // -------------------------------------------------------------------

    public function test_index_returns_pagination_meta(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);
        Task::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/tasks?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
        ]);
        $this->assertSame(2, $response->json('meta.pagination.per_page'));
    }

    public function test_index_falls_back_to_default_sort_when_sort_is_not_whitelisted(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);
        Task::factory()->create(['due_at' => now()->addDays(5)]);
        Task::factory()->create(['due_at' => now()->addDays(1)]);

        // Beyaz liste dışı bir sütun -> sessizce varsayılan (-due_at) sıralamaya düşer, 500 vermez.
        $response = $this->actingAs($actor)->getJson('/api/tasks?sort=malicious_column');

        $response->assertStatus(200);
    }

    public function test_search_query_does_not_leak_records_outside_other_filters(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $matchingStatus = Task::factory()->create(['title' => 'Müşteriyi ara ACME', 'status' => 'pending']);
        Task::factory()->create(['title' => 'Müşteriyi ara ACME', 'status' => 'completed', 'completed_at' => now()]);

        // q parantezli grupta çalışmalı: status filtresi ile birlikte yalnızca eşleşen kaydı döndürmeli.
        $response = $this->actingAs($actor)->getJson('/api/tasks?q=ACME&filter[status]=pending');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($matchingStatus->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_filter_overdue_returns_only_overdue_open_tasks(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $overdue = Task::factory()->create(['due_at' => now()->subDays(3), 'status' => 'pending']);
        Task::factory()->create(['due_at' => now()->subDays(3), 'status' => 'completed', 'completed_at' => now()]);
        Task::factory()->create(['due_at' => now()->addDays(3), 'status' => 'pending']);

        $response = $this->actingAs($actor)->getJson('/api/tasks?filter[overdue]=1');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$overdue->id], $ids);
    }

    public function test_filter_taskable_type_rejects_values_outside_whitelist(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $this->actingAs($actor)->getJson('/api/tasks?'.http_build_query(['filter' => ['taskable_type' => 'user']]))->assertStatus(422);
        $this->actingAs($actor)->getJson('/api/tasks?'.http_build_query(['filter' => ['taskable_type' => 'App\\Models\\User']]))->assertStatus(422);
    }

    public function test_deleted_taskable_target_does_not_break_listing(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);
        $company = Company::factory()->create();
        $task = Task::factory()->create([
            'taskable_type' => Company::class,
            'taskable_id' => $company->id,
        ]);
        $company->delete();

        $response = $this->actingAs($actor)->getJson('/api/tasks');

        $response->assertStatus(200);
        $body = collect($response->json('data'))->firstWhere('id', $task->id);
        $this->assertNotNull($body);
        $this->assertNull($body['taskable']);
    }

    /**
     * N+1 regresyon testi — taskable (MorphTo) eager loading'i, sayfadaki
     * DİSTİNCT taskable_type sayısı kadar (satır sayısından BAĞIMSIZ) sorgu
     * üretmeli. 3 tip x 3 görev = 9 görev; N+1 olsaydı en az +9 ek sorgu
     * gerekirdi.
     */
    public function test_index_does_not_execute_n_plus_one_queries_for_morph_relations(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);
        $deal = Deal::factory()->create();
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();

        Task::factory()->count(3)->create(['taskable_type' => Deal::class, 'taskable_id' => $deal->id]);
        Task::factory()->count(3)->create(['taskable_type' => Contact::class, 'taskable_id' => $contact->id]);
        Task::factory()->count(3)->create(['taskable_type' => Company::class, 'taskable_id' => $company->id]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/tasks?per_page=50');

        $response->assertStatus(200);

        $this->assertLessThan(
            20,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') - N+1 şüphesi:'.PHP_EOL.implode(PHP_EOL, $queries)
        );

        $dealQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, '`deals`'))->count();
        $contactQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, '`contacts`'))->count();
        $companyQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, '`companies`'))->count();

        $this->assertSame(1, $dealQueries, 'deal taskable ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
        $this->assertSame(1, $contactQueries, 'contact taskable ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
        $this->assertSame(1, $companyQueries, 'company taskable ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
    }

    // -------------------------------------------------------------------
    // Store / Update doğrulama
    // -------------------------------------------------------------------

    public function test_store_rejects_nonexistent_taskable_id(): void
    {
        $actor = $this->actorWithPermissions(['tasks.create']);

        $response = $this->actingAs($actor)->postJson('/api/tasks', [
            'title' => 'Teklif hazırla',
            'taskable_type' => 'deal',
            'taskable_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('taskable_id', $response->json('errors.fields'));
    }

    public function test_store_rejects_reminder_at_after_due_at(): void
    {
        $actor = $this->actorWithPermissions(['tasks.create']);

        $response = $this->actingAs($actor)->postJson('/api/tasks', [
            'title' => 'Teklif hazırla',
            'due_at' => now()->addDays(1)->toIso8601String(),
            'reminder_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('reminder_at', $response->json('errors.fields'));
    }

    public function test_store_sets_creator_to_authenticated_user_and_defaults(): void
    {
        $actor = $this->actorWithPermissions(['tasks.create']);

        $response = $this->actingAs($actor)->postJson('/api/tasks', ['title' => 'Yeni görev']);

        $response->assertStatus(201);
        $this->assertSame($actor->id, $response->json('data.creator.id'));
        $this->assertSame('normal', $response->json('data.priority'));
        $this->assertSame('pending', $response->json('data.status'));
    }

    public function test_store_links_task_to_valid_taskable_target(): void
    {
        $actor = $this->actorWithPermissions(['tasks.create']);
        $deal = Deal::factory()->create(['title' => 'CRM Kurulum']);

        $response = $this->actingAs($actor)->postJson('/api/tasks', [
            'title' => 'Deal ile ilgili görev',
            'taskable_type' => 'deal',
            'taskable_id' => $deal->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame('deal', $response->json('data.taskable.type'));
        $this->assertSame($deal->id, $response->json('data.taskable.id'));
    }

    public function test_update_rejects_completed_at_field(): void
    {
        $actor = $this->actorWithPermissions(['tasks.update']);
        $task = Task::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}", [
            'completed_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('completed_at', $response->json('errors.fields'));
    }

    // -------------------------------------------------------------------
    // /complete
    // -------------------------------------------------------------------

    public function test_complete_endpoint_marks_task_completed_and_is_idempotent(): void
    {
        $actor = $this->actorWithPermissions(['tasks.update']);
        $task = Task::factory()->create(['status' => 'pending', 'completed_at' => null]);

        $first = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true]);
        $first->assertStatus(200);
        $this->assertSame('completed', $first->json('data.status'));
        $completedAt = $first->json('data.completed_at');
        $this->assertNotNull($completedAt);

        // İkinci çağrı hata VERMEMELİ ve completed_at DEĞİŞMEMELİ (idempotent).
        $second = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true]);
        $second->assertStatus(200);
        $this->assertSame('completed', $second->json('data.status'));
        $this->assertSame($completedAt, $second->json('data.completed_at'));

        // completed=false -> pending'e döner, completed_at temizlenir.
        $third = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => false]);
        $third->assertStatus(200);
        $this->assertSame('pending', $third->json('data.status'));
        $this->assertNull($third->json('data.completed_at'));
    }

    public function test_cancelled_task_cannot_be_completed(): void
    {
        $actor = $this->actorWithPermissions(['tasks.update']);
        $task = Task::factory()->cancelled()->create();

        $response = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true]);

        $response->assertStatus(422);
    }

    /**
     * KARAR REVİZE EDİLDİ (Faz 13): bu test eskiden 200 bekliyordu —
     * "tasks.update iznine sahip herkes başkasının görevini tamamlayabilir".
     * Yatay yazma izolasyonuyla birlikte beklenen davranışın KENDİSİ değişti:
     * `tasks.update` TEK BAŞINA yetmiyor, atanan kişi olmak ya da
     * `tasks.assign` taşımak gerekiyor (bkz. TaskPolicy::complete()).
     */
    public function test_tasks_update_permission_alone_cannot_complete_others_task(): void
    {
        $actor = $this->actorWithPermissions(['tasks.update']);
        $otherAssignee = User::factory()->create();
        $task = Task::factory()->create(['assigned_to' => $otherAssignee->id, 'status' => 'pending']);

        $response = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true]);

        $response->assertStatus(403);
    }

    /**
     * Eski kararın KORUNAN yarısı: yönetici (Müdür/Admin, yani `tasks.assign`
     * taşıyan aktör) ekip üyesinin görevini kapatmaya DEVAM EDER — revizyon
     * yalnızca temsilciler arası kapatmayı kesti.
     */
    public function test_user_with_tasks_assign_permission_can_complete_others_task(): void
    {
        $actor = $this->actorWithPermissions(['tasks.update', 'tasks.assign']);
        $otherAssignee = User::factory()->create();
        $task = Task::factory()->create(['assigned_to' => $otherAssignee->id, 'status' => 'pending']);

        $response = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true]);

        $response->assertStatus(200);
    }

    /**
     * Atanmamış görev havuzdadır: `tasks.update` taşıyan herkes kapatabilir.
     */
    public function test_unassigned_task_can_be_completed_by_any_updater(): void
    {
        $actor = $this->actorWithPermissions(['tasks.update']);
        $task = Task::factory()->create(['assigned_to' => null, 'status' => 'pending']);

        $response = $this->actingAs($actor)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true]);

        $response->assertStatus(200);
    }

    // -------------------------------------------------------------------
    // Takvim
    // -------------------------------------------------------------------

    public function test_calendar_requires_from_and_to(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $this->actingAs($actor)->getJson('/api/tasks/calendar')->assertStatus(422);
        $this->actingAs($actor)->getJson('/api/tasks/calendar?from=2026-01-01')->assertStatus(422);
    }

    public function test_calendar_rejects_ranges_over_90_days(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $response = $this->actingAs($actor)->getJson('/api/tasks/calendar?from=2026-01-01&to=2026-12-31');

        $response->assertStatus(422);
    }

    public function test_calendar_excludes_tasks_without_due_at(): void
    {
        $actor = $this->actorWithPermissions(['tasks.view']);

        $withDue = Task::factory()->create(['due_at' => '2026-02-10']);
        Task::factory()->create(['due_at' => null]);

        $response = $this->actingAs($actor)->getJson('/api/tasks/calendar?from=2026-02-01&to=2026-02-28');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$withDue->id], $ids);
    }

    // -------------------------------------------------------------------
    // Hatırlatıcılar
    // -------------------------------------------------------------------

    public function test_dispatch_reminders_command_broadcasts_event_on_personal_channel(): void
    {
        Event::fake([TaskReminderDue::class]);

        $assignee = User::factory()->create();
        $task = Task::factory()->create([
            'reminder_at' => now()->subMinute(),
            'status' => 'pending',
            'assigned_to' => $assignee->id,
        ]);

        Artisan::call('tasks:dispatch-reminders');

        Event::assertDispatched(TaskReminderDue::class, function (TaskReminderDue $event) use ($task, $assignee) {
            return $event->assignedTo === $assignee->id
                && $event->payload['task_id'] === $task->id
                && $event->broadcastOn()[0]->name === 'private-user.'.$assignee->id;
        });
    }

    public function test_dispatch_reminders_command_does_not_redispatch_on_second_run(): void
    {
        Event::fake([TaskReminderDue::class]);

        $assignee = User::factory()->create();
        Task::factory()->create([
            'reminder_at' => now()->subMinute(),
            'status' => 'pending',
            'assigned_to' => $assignee->id,
        ]);

        Artisan::call('tasks:dispatch-reminders');
        Event::assertDispatchedTimes(TaskReminderDue::class, 1);

        Artisan::call('tasks:dispatch-reminders');
        Event::assertDispatchedTimes(TaskReminderDue::class, 1);
    }

    public function test_dispatch_reminders_dry_run_does_not_dispatch_anything(): void
    {
        Event::fake([TaskReminderDue::class]);

        $assignee = User::factory()->create();
        Task::factory()->create([
            'reminder_at' => now()->subMinute(),
            'status' => 'pending',
            'assigned_to' => $assignee->id,
        ]);

        Artisan::call('tasks:dispatch-reminders', ['--dry-run' => true]);

        Event::assertNotDispatched(TaskReminderDue::class);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
