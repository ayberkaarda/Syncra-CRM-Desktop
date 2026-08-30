<?php

namespace Tests\Feature\Automation;

use App\Events\DealMoved;
use App\Models\AutomationRule;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Automation\Notifications\AutomationRuleNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faz 14 / İz F — C4 otomasyon kurallarının GERÇEK yürütülmesi: 3 tetikleyici × 3 eylem
 * kombinasyonlarından temsili örnekler + "pasif kural tetiklenmez" + "öncelik filtresi".
 *
 * `Notification::fake()` KULLANILIR (NotificationTriggerTest'in aynı gerekçesi):
 * `CrmNotification::afterCommit()` + `RefreshDatabase`'in commit ETMEYEN transaction'ı
 * birleşince gerçek kuyruklu gönderim hiçbir testte çalışmaz; `Notification::fake()`
 * `notify()`'ı senkron yakalar.
 */
class AutomationRuleExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function creatorWithAllPermissions(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'settings.manage', 'deals.view', 'deals.assign',
            'tasks.create', 'tasks.assign', 'tickets.view', 'users.view',
        ]);

        return $user;
    }

    public function test_deal_stage_changed_creates_a_task_assigned_to_the_record_owner(): void
    {
        $creator = $this->creatorWithAllPermissions();
        $owner = User::factory()->create();
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create(['name' => 'Görüşme']);

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStageChanged($toStage->id)
            ->taskCreateAction(dueInDays: 5)
            ->create();

        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $toStage->id, 'title' => 'Yıllık Sözleşme']);
        $mover = User::factory()->create();

        event(new DealMoved(DealMoved::payload($deal, $fromStage->id, $mover)));

        $task = Task::query()->where('taskable_id', $deal->id)->first();
        $this->assertNotNull($task, 'Görev oluşturulmalıydı.');
        $this->assertSame($owner->id, $task->assigned_to);
        $this->assertSame('Takip: Yıllık Sözleşme', $task->title);
        $this->assertSame(now()->addDays(5)->toDateString(), $task->due_at->toDateString());
    }

    public function test_deal_status_changed_to_won_notifies_the_record_owner(): void
    {
        Notification::fake();

        $creator = $this->creatorWithAllPermissions();
        $owner = User::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStatusChanged('won')
            ->create([
                'action_type' => 'notification.send',
                'action_config' => [
                    'message_template' => 'Kazanıldı: {record_title}',
                    'recipient_type' => 'record_owner',
                    'recipient_user_id' => null,
                ],
            ]);

        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'status' => 'open', 'title' => 'Bulut Migrasyonu']);
        $deal->update(['status' => 'won']);

        Notification::assertSentTo($owner, AutomationRuleNotification::class, function ($notification) {
            $data = $notification->toArray(null);

            return $data['body'] === 'Kazanıldı: Bulut Migrasyonu';
        });
    }

    public function test_deal_status_changed_to_lost_does_not_match_a_won_only_rule(): void
    {
        Notification::fake();

        $creator = $this->creatorWithAllPermissions();
        $owner = User::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStatusChanged('won')
            ->create([
                'action_type' => 'notification.send',
                'action_config' => [
                    'message_template' => 'Kazanıldı: {record_title}',
                    'recipient_type' => 'record_owner',
                    'recipient_user_id' => null,
                ],
            ]);

        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'status' => 'open']);
        $deal->update(['status' => 'lost']);

        // Faz 10'un KENDİ (bu şeridin alanı DIŞINDAKİ) `deal.lost` bildirimi burada
        // beklenen şekilde ateşlenir — kontrol edilen yalnızca BİZİM otomasyon
        // bildirimimizin ("won"a özel kural) YANLIŞLIKLA tetiklenmediğidir.
        Notification::assertNotSentTo($owner, AutomationRuleNotification::class);
    }

    public function test_ticket_created_priority_filter_only_matches_configured_priority(): void
    {
        Notification::fake();

        $creator = $this->creatorWithAllPermissions();
        $recipient = User::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->create([
                'trigger_type' => 'ticket.created',
                'trigger_config' => ['priority' => 'urgent'],
                'action_type' => 'notification.send',
                'action_config' => [
                    'message_template' => 'Acil talep: {record_title}',
                    'recipient_type' => 'fixed_user',
                    'recipient_user_id' => $recipient->id,
                ],
            ]);

        Ticket::factory()->create(['priority' => 'low']);
        Notification::assertNotSentTo($recipient, AutomationRuleNotification::class);

        Ticket::factory()->create(['priority' => 'urgent', 'subject' => 'Sunucu çöktü']);
        Notification::assertSentTo($recipient, AutomationRuleNotification::class, fn ($n) => $n->toArray(null)['body'] === 'Acil talep: Sunucu çöktü');
    }

    public function test_ticket_created_without_priority_filter_matches_every_priority(): void
    {
        Notification::fake();

        $creator = $this->creatorWithAllPermissions();
        $recipient = User::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
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

        Ticket::factory()->create(['priority' => 'low']);

        Notification::assertSentToTimes($recipient, AutomationRuleNotification::class, 1);
    }

    public function test_deal_assign_owner_action_reassigns_the_deal_owner(): void
    {
        $creator = $this->creatorWithAllPermissions();
        $target = User::factory()->create();
        $originalOwner = User::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStatusChanged('won')
            ->assignOwnerAction($target->id)
            ->create();

        $deal = Deal::factory()->create(['owner_id' => $originalOwner->id, 'status' => 'open']);
        $deal->update(['status' => 'won']);

        $deal->refresh();
        $this->assertSame($target->id, $deal->owner_id);
    }

    public function test_inactive_rule_never_fires(): void
    {
        Notification::fake();

        $creator = $this->creatorWithAllPermissions();

        AutomationRule::factory()->for($creator, 'creator')
            ->inactive()
            ->create([
                'trigger_type' => 'ticket.created',
                'trigger_config' => ['priority' => null],
                'action_type' => 'notification.send',
                'action_config' => [
                    'message_template' => 'Yeni talep: {record_title}',
                    'recipient_type' => 'fixed_user',
                    'recipient_user_id' => $creator->id,
                ],
            ]);

        Ticket::factory()->create();

        Notification::assertNothingSent();
    }

    public function test_task_create_with_record_owner_assignee_is_skipped_when_deal_has_no_owner(): void
    {
        $creator = $this->creatorWithAllPermissions();
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create();

        AutomationRule::factory()->for($creator, 'creator')
            ->dealStageChanged($toStage->id)
            ->taskCreateAction()
            ->create();

        $deal = Deal::factory()->create(['owner_id' => null, 'pipeline_stage_id' => $toStage->id]);
        $mover = User::factory()->create();

        event(new DealMoved(DealMoved::payload($deal, $fromStage->id, $mover)));

        $this->assertDatabaseCount('tasks', 0);
    }
}
