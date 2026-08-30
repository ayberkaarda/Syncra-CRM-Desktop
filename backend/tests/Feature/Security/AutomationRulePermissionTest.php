<?php

namespace Tests\Feature\Security;

use App\Events\DealMoved;
use App\Models\AutomationRule;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * =============================================================================
 * FAZ 14 / İZ F / ATTIO C4 — BAĞLAYICI GÜVENLİK KISITI (PHASE-AUDIT §5.4)
 * =============================================================================
 * "Bir kural, onu OLUŞTURAN kullanıcının kendi yapamayacağı bir eylemi
 * tetikleyememeli ... Bunu İKİ katmanda uygula ve İKİSİNİ DE testle kilitle."
 *
 * KATMAN 1 (YAZMA ANI) — `test_*_cannot_create_*` testleri: seçilen eylemin
 * gerektirdiği izin oluşturucuda yoksa 403.
 *
 * KATMAN 2 (ÇALIŞMA ANI) — `test_*_action_is_skipped_after_creator_loses_*`
 * testleri: kural GEÇERLİ bir izinle oluşturulur, SONRA oluşturucunun izni
 * geri alınır, kural tetiklenir — eylem SESSİZCE YUTULMAZ, ÇALIŞMAZ ve
 * `warning` loglanır (`AutomationRuleRunner::execute()`).
 *
 * İzinler `RolePermissionSeeder`'dan BİREBİR: deals.view, deals.assign,
 * tasks.create, tasks.assign, tickets.view, users.view.
 */
class AutomationRulePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // -------------------------------------------------------------------
    // KATMAN 1 — YAZMA ANI
    // -------------------------------------------------------------------

    public function test_user_without_deals_assign_cannot_create_a_deal_assign_owner_rule(): void
    {
        // settings.manage + deals.view VAR (tetikleyici izni karşılanıyor), deals.assign YOK.
        $actor = $this->userWithPermissions(['settings.manage', 'deals.view']);
        $target = User::factory()->create();

        $payload = [
            'name' => 'Kazanınca sahibi değiştir',
            'trigger_type' => 'deal.status_changed',
            'trigger_config' => ['status' => 'won'],
            'action_type' => 'deal.assign_owner',
            'action_config' => ['user_id' => $target->id],
        ];

        $this->actingAs($actor)->postJson('/api/settings/automation-rules', $payload)->assertStatus(403);
        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_user_with_deals_assign_can_create_a_deal_assign_owner_rule(): void
    {
        $actor = $this->userWithPermissions(['settings.manage', 'deals.view', 'deals.assign']);
        $target = User::factory()->create();

        $payload = [
            'name' => 'Kazanınca sahibi değiştir',
            'trigger_type' => 'deal.status_changed',
            'trigger_config' => ['status' => 'won'],
            'action_type' => 'deal.assign_owner',
            'action_config' => ['user_id' => $target->id],
        ];

        $this->actingAs($actor)->postJson('/api/settings/automation-rules', $payload)->assertStatus(201);
    }

    public function test_user_without_tasks_assign_cannot_create_a_task_create_rule_even_with_tasks_create(): void
    {
        // task.create HER ZAMAN tasks.create + tasks.assign ister (AutomationCatalog
        // dokümanı) — yalnızca tasks.create yeterli DEĞİL.
        $actor = $this->userWithPermissions(['settings.manage', 'deals.view', 'tasks.create']);
        $stage = PipelineStage::factory()->create();

        $payload = [
            'name' => 'Görev aç',
            'trigger_type' => 'deal.stage_changed',
            'trigger_config' => ['pipeline_stage_id' => $stage->id],
            'action_type' => 'task.create',
            'action_config' => [
                'title_template' => 'Takip: {record_title}',
                'assignee_type' => 'record_owner',
                'assignee_user_id' => null,
                'due_in_days' => 2,
            ],
        ];

        $this->actingAs($actor)->postJson('/api/settings/automation-rules', $payload)->assertStatus(403);
    }

    public function test_user_without_users_view_cannot_target_a_fixed_user_notification_recipient(): void
    {
        $actor = $this->userWithPermissions(['settings.manage', 'tickets.view']);
        $recipient = User::factory()->create();

        $payload = [
            'name' => 'Sabit kullanıcıya bildir',
            'trigger_type' => 'ticket.created',
            'trigger_config' => ['priority' => null],
            'action_type' => 'notification.send',
            'action_config' => [
                'message_template' => 'Yeni talep: {record_title}',
                'recipient_type' => 'fixed_user',
                'recipient_user_id' => $recipient->id,
            ],
        ];

        $this->actingAs($actor)->postJson('/api/settings/automation-rules', $payload)->assertStatus(403);
    }

    public function test_update_that_changes_the_action_is_gated_by_the_same_permission_check_as_create(): void
    {
        // Kural GEÇERLİ bir kombinasyonla (task.create, tasks.create+tasks.assign VAR)
        // oluşturulur; SONRA aynı istek içinde eylemi `deal.assign_owner`'a (deals.assign
        // YOK) değiştirmeye çalışır — `AutomationRulePolicy::update()` bunu da `create()`
        // ile AYNI kontrolden geçirmeli.
        $actor = $this->userWithPermissions(['settings.manage', 'deals.view', 'tasks.create', 'tasks.assign']);
        $stage = PipelineStage::factory()->create();
        $target = User::factory()->create();

        $rule = AutomationRule::factory()->for($actor, 'creator')->dealStageChanged($stage->id)->taskCreateAction()->create();

        $this->actingAs($actor)
            ->patchJson("/api/settings/automation-rules/{$rule->id}", [
                'trigger_type' => 'deal.status_changed',
                'trigger_config' => ['status' => 'won'],
                'action_type' => 'deal.assign_owner',
                'action_config' => ['user_id' => $target->id],
            ])
            ->assertStatus(403);

        $rule->refresh();
        $this->assertSame('task.create', $rule->action_type, 'Yetkisiz güncelleme UYGULANMAMALIYDI.');
    }

    public function test_record_owner_recipient_does_not_require_users_view(): void
    {
        $actor = $this->userWithPermissions(['settings.manage', 'tickets.view']);

        $payload = [
            'name' => 'Sahibine bildir',
            'trigger_type' => 'ticket.created',
            'trigger_config' => ['priority' => null],
            'action_type' => 'notification.send',
            'action_config' => [
                'message_template' => 'Yeni talep: {record_title}',
                'recipient_type' => 'record_owner',
                'recipient_user_id' => null,
            ],
        ];

        $this->actingAs($actor)->postJson('/api/settings/automation-rules', $payload)->assertStatus(201);
    }

    // -------------------------------------------------------------------
    // KATMAN 2 — ÇALIŞMA ANI YENİDEN DOĞRULAMA
    // -------------------------------------------------------------------

    public function test_deal_assign_owner_action_is_skipped_after_creator_loses_deals_assign(): void
    {
        $creator = $this->userWithPermissions(['settings.manage', 'deals.view', 'deals.assign']);
        $originalOwner = User::factory()->create();
        $target = User::factory()->create();

        $rule = AutomationRule::factory()
            ->for($creator, 'creator')
            ->dealStatusChanged('won')
            ->assignOwnerAction($target->id)
            ->create();

        // Rol/izin DÜŞÜRÜLÜR — kural YAZILDIKTAN SONRA (tam da PHASE-AUDIT §5.4'ün
        // korktuğu senaryo: "kullanıcının rolü kural yazıldıktan SONRA düşürülebilir").
        $creator->revokePermissionTo('deals.assign');

        $deal = Deal::factory()->create(['owner_id' => $originalOwner->id, 'status' => 'open']);

        Log::spy();

        $deal->update(['status' => 'won']);

        $deal->refresh();
        $this->assertSame($originalOwner->id, $deal->owner_id, 'İzin geri alındıktan sonra sahip DEĞİŞMEMELİYDİ.');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'automation.execute.permission_revoked'
                && $context['automation_rule_id'] === $rule->id)
            ->once();
    }

    public function test_task_create_action_is_skipped_after_creator_loses_tasks_assign(): void
    {
        $creator = $this->userWithPermissions(['settings.manage', 'deals.view', 'tasks.create', 'tasks.assign']);
        $owner = User::factory()->create();
        $stage = PipelineStage::factory()->create();
        $otherStage = PipelineStage::factory()->create();

        AutomationRule::factory()
            ->for($creator, 'creator')
            ->dealStageChanged($stage->id)
            ->taskCreateAction()
            ->create();

        $creator->revokePermissionTo('tasks.assign');

        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $stage->id]);
        $mover = User::factory()->create();

        event(new DealMoved(DealMoved::payload($deal, $otherStage->id, $mover)));

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_notification_send_action_is_skipped_after_creator_loses_users_view(): void
    {
        Notification::fake();

        $creator = $this->userWithPermissions(['settings.manage', 'tickets.view', 'users.view']);
        $recipient = User::factory()->create();

        AutomationRule::factory()
            ->for($creator, 'creator')
            ->create([
                'trigger_type' => 'ticket.created',
                'trigger_config' => ['priority' => null],
                'action_type' => 'notification.send',
                'action_config' => [
                    'message_template' => 'Yeni talep: {record_title}',
                    'recipient_type' => 'fixed_user',
                    'recipient_user_id' => $recipient->id,
                ],
            ]);

        $creator->revokePermissionTo('users.view');

        Ticket::factory()->create();

        Notification::assertNothingSentTo($recipient);
    }
}
