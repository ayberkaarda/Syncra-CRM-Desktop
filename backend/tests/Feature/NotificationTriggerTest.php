<?php

namespace Tests\Feature;

use App\Events\DealMoved;
use App\Events\TaskReminderDue;
use App\Events\TicketSlaBreached;
use App\Events\TicketSlaWarning;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\DealAssignedNotification;
use App\Notifications\DealLostNotification;
use App\Notifications\DealStageChangedNotification;
use App\Notifications\DealWonNotification;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\QuoteStatusChangedNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReminderNotification;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketSlaBreachedNotification;
use App\Notifications\TicketSlaWarningNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\ActivityLogStatus;
use Tests\TestCase;

/**
 * Faz 10 tetikleyici sözleşmesinin doğrulaması: 5 observer (A grubu) + 4
 * listener (B grubu), "kendine bildirim gitmez", "pasif kullanıcıya
 * bildirim gitmez" ve "toplu import'ta susturma" kuralları.
 *
 * `Notification::fake()` kullanılır, gerçek DB satırı üretimi DEĞİL:
 * `App\Notifications\CrmNotification` kasıtlı olarak `afterCommit()`
 * çağırır (bkz. o sınıfın dokümanı — `DealService::create()/update()` ve
 * `QuoteStatusMachine::transition()` gibi çağıranların transaction'ı
 * İÇİNDE tetiklenen observer'ların, transaction geri alınırsa var
 * olmayan bir kayıt için bildirim göndermemesi gerekir). Bu proje
 * genelinde `RefreshDatabase` kullanıldığı için (her test kendi
 * transaction'ında çalışır ve GERÇEKTEN commit ETMEZ — vendor kaynağı
 * `DatabaseTransactionsManager::commit()` okunarak doğrulandı: callback'ler
 * yalnızca `newTransactionLevel === 0` iken çalışır), `afterCommit()`'li
 * kuyruklu bir job hiçbir testte GERÇEKTEN çalışmaz. `Notification::fake()`
 * bu sorunu turuncudan çözer: `$model->notify()` çağrısını kuyruğa hiç
 * girmeden, senkron olarak yakalar (bkz. `NotificationFake`), yani
 * `afterCommit()` hiç devreye girmez. Uç noktaların gerçek `notifications`
 * satırı ürettiğini (CRUD sözleşmesi) `NotificationApiTest` ayrıca,
 * `Notification::fake()` OLMADAN, doğrudan `DatabaseNotification` satırı
 * ekleyerek doğrular.
 */
class NotificationTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    // =================================================================
    // Deal — observer (A)
    // =================================================================

    public function test_deal_assignment_notifies_new_owner(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $deal = Deal::factory()->create();

        $this->actingAs($actor);
        $deal->update(['owner_id' => $owner->id]);

        Notification::assertSentTo($owner, DealAssignedNotification::class, function ($notification) use ($deal, $owner) {
            $data = $notification->toArray($owner);

            return $data['type'] === 'deal.assigned'
                && $data['link'] === '/deals/'.$deal->id
                && $data['meta']['deal_id'] === $deal->id;
        });
    }

    public function test_deal_assignment_to_self_does_not_notify(): void
    {
        $actor = User::factory()->create();
        $deal = Deal::factory()->create();

        $this->actingAs($actor);
        $deal->update(['owner_id' => $actor->id]);

        Notification::assertNothingSent();
    }

    public function test_deal_assignment_to_inactive_user_does_not_notify(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create(['is_active' => false]);
        $deal = Deal::factory()->create();

        $this->actingAs($actor);
        $deal->update(['owner_id' => $owner->id]);

        Notification::assertNothingSent();
    }

    public function test_deal_created_with_owner_notifies_owner(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();

        $this->actingAs($actor);
        $deal = Deal::factory()->create(['owner_id' => $owner->id]);

        Notification::assertSentTo($owner, DealAssignedNotification::class);
    }

    public function test_deal_won_notifies_owner(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'status' => 'open']);

        $this->actingAs($actor);
        $deal->update(['status' => 'won']);

        Notification::assertSentTo($owner, DealWonNotification::class);
    }

    public function test_deal_lost_notifies_owner(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'status' => 'open']);

        $this->actingAs($actor);
        $deal->update(['status' => 'lost', 'lost_reason' => 'Fiyat yüksek bulundu']);

        Notification::assertSentTo($owner, DealLostNotification::class);
    }

    public function test_deal_status_change_by_owner_does_not_notify(): void
    {
        $owner = User::factory()->create();

        // Sahibin kendisi olarak oluşturulur: aksi halde created() hook'u
        // owner_id atamasını (actor'sız, konsol/test bağlamında) ayrı bir
        // deal.assigned bildirimi olarak sayar ve bu testin asıl kontrol
        // ettiği "status won, actor=owner" senaryosunu kirletir.
        $this->actingAs($owner);
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'status' => 'open']);

        $deal->update(['status' => 'won']);

        Notification::assertNothingSent();
    }

    // =================================================================
    // DealMoved → deal.stage_changed — listener (B)
    // =================================================================

    public function test_deal_moved_event_notifies_owner_when_mover_is_someone_else(): void
    {
        $owner = User::factory()->create();
        $mover = User::factory()->create();
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create(['name' => 'Görüşme']);
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $toStage->id]);

        event(new DealMoved(DealMoved::payload($deal, $fromStage->id, $mover)));

        Notification::assertSentTo($owner, DealStageChangedNotification::class, function ($notification) use ($toStage) {
            $data = $notification->toArray(null);

            return $data['meta']['to_stage_name'] === $toStage->name;
        });
    }

    public function test_deal_moved_event_by_owner_does_not_notify(): void
    {
        $owner = User::factory()->create();
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create();

        // Sahibin kendisi olarak oluşturulur — bkz. yukarıdaki
        // test_deal_status_change_by_owner_does_not_notify ile aynı gerekçe.
        $this->actingAs($owner);
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $toStage->id]);

        event(new DealMoved(DealMoved::payload($deal, $fromStage->id, $owner)));

        Notification::assertNothingSent();
    }

    public function test_deal_moved_event_without_owner_does_not_notify(): void
    {
        $mover = User::factory()->create();
        $fromStage = PipelineStage::factory()->create();
        $toStage = PipelineStage::factory()->create();
        $deal = Deal::factory()->create(['owner_id' => null, 'pipeline_stage_id' => $toStage->id]);

        event(new DealMoved(DealMoved::payload($deal, $fromStage->id, $mover)));

        Notification::assertNothingSent();
    }

    // =================================================================
    // Task
    // =================================================================

    public function test_task_assignment_notifies_assignee(): void
    {
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $task = Task::factory()->create();

        $this->actingAs($actor);
        $task->update(['assigned_to' => $assignee->id]);

        Notification::assertSentTo($assignee, TaskAssignedNotification::class);
    }

    public function test_task_reminder_event_notifies_assignee(): void
    {
        $assignee = User::factory()->create();
        $task = Task::factory()->create(['assigned_to' => $assignee->id]);

        event(new TaskReminderDue((int) $assignee->id, [
            'task_id' => $task->id,
            'title' => $task->title,
            'due_at' => $task->due_at?->toIso8601String(),
            'priority' => $task->priority,
            'taskable_type' => null,
            'taskable_id' => null,
            'taskable_label' => null,
        ]));

        Notification::assertSentTo($assignee, TaskReminderNotification::class);
    }

    // =================================================================
    // Ticket
    // =================================================================

    public function test_ticket_assignment_notifies_assignee(): void
    {
        $actor = User::factory()->create();
        $assignee = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($actor);
        $ticket->update(['assigned_to' => $assignee->id]);

        Notification::assertSentTo($assignee, TicketAssignedNotification::class);
    }

    public function test_ticket_sla_warning_event_notifies_assignee(): void
    {
        $assignee = User::factory()->create();
        $ticket = Ticket::factory()->create(['assigned_to' => $assignee->id]);

        event(new TicketSlaWarning(TicketSlaWarning::payload($ticket, 600)));

        Notification::assertSentTo($assignee, TicketSlaWarningNotification::class);
    }

    public function test_ticket_sla_warning_event_without_assignee_does_not_notify(): void
    {
        $ticket = Ticket::factory()->create(['assigned_to' => null]);

        event(new TicketSlaWarning(TicketSlaWarning::payload($ticket, 600)));

        Notification::assertNothingSent();
    }

    public function test_ticket_sla_breached_event_notifies_assignee(): void
    {
        $assignee = User::factory()->create();
        $ticket = Ticket::factory()->create(['assigned_to' => $assignee->id]);

        event(new TicketSlaBreached(TicketSlaBreached::payload($ticket, 300)));

        Notification::assertSentTo($assignee, TicketSlaBreachedNotification::class);
    }

    public function test_ticket_sla_breached_event_without_assignee_does_not_notify(): void
    {
        $ticket = Ticket::factory()->create(['assigned_to' => null]);

        event(new TicketSlaBreached(TicketSlaBreached::payload($ticket, 300)));

        Notification::assertNothingSent();
    }

    // =================================================================
    // Lead — observer (A) + toplu import susturması
    // =================================================================

    public function test_lead_assignment_notifies_new_owner(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $lead = Lead::factory()->create();

        $this->actingAs($actor);
        $lead->update(['owner_id' => $owner->id]);

        Notification::assertSentTo($owner, LeadAssignedNotification::class);
    }

    /**
     * `NotificationDispatcher`'ın `ActivityLogStatus::disabled()` kontrolü
     * — `LeadImportService::process()`'in audit gürültüsünü susturmak için
     * zaten kullandığı AYNI toggle'ın buradaki yeniden kullanımı (bkz. o
     * sınıfın dokümanı).
     */
    public function test_lead_assignment_is_suppressed_while_activity_log_is_disabled(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();

        $this->actingAs($actor);

        $logStatus = app(ActivityLogStatus::class);
        $logStatus->disable();

        try {
            Lead::factory()->create(['owner_id' => $owner->id]);
        } finally {
            $logStatus->enable();
        }

        Notification::assertNothingSent();
    }

    /**
     * Uçtan uca: gerçek `POST /api/leads/import` akışı, `LeadImportService`
     * hiç değiştirilmeden bildirim yağmuru üretmiyor.
     */
    public function test_bulk_lead_import_does_not_flood_notifications(): void
    {
        Storage::fake('local');

        $actor = User::factory()->create();
        // `leads.assign` (Faz 13 / F8): import gövdesindeki `owner_id` yalnızca
        // devretme iznine sahip aktörden kabul edilir, aksi hâlde sunucu onu
        // aktörün kendisine sabitler. Gerçek izin matrisinde `leads.import`
        // taşıyan iki rol (Admin, Satış Müdürü) `leads.assign` de taşır — bu
        // satır testi o gerçeğe hizalar, testin amacını (import bildirim
        // yağmuru üretmiyor) değiştirmez.
        $actor->givePermissionTo(['leads.import', 'leads.view', 'leads.assign']);
        $owner = User::factory()->create();

        $header = ['first_name', 'last_name', 'email', 'phone', 'company_name', 'position', 'source', 'status', 'score', 'notes'];
        $rows = [
            ['Ayşe', 'Yılmaz', 'ayse.import@example.com', '', '', '', 'website', 'new', '50', ''],
            ['Mehmet', 'Demir', 'mehmet.import@example.com', '', '', '', 'website', 'new', '50', ''],
            ['Elif', 'Kaya', 'elif.import@example.com', '', '', '', 'website', 'new', '50', ''],
        ];
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        $content = implode("\r\n", $lines)."\r\n";

        $response = $this->actingAs($actor)->postJson('/api/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $content)->mimeType('text/csv'),
            'owner_id' => $owner->id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(3, Lead::where('owner_id', $owner->id)->count());

        // Üç lead de aynı sahibe atandı ama import döngüsü boyunca
        // ActivityLogStatus devre dışı olduğu için TEK bir bildirim bile
        // üretilmedi.
        Notification::assertNothingSent();
    }

    // =================================================================
    // Quote — observer (A)
    // =================================================================

    public function test_quote_status_change_notifies_creator(): void
    {
        $creator = User::factory()->create();
        $actor = User::factory()->create();
        $quote = Quote::factory()->create(['created_by' => $creator->id, 'status' => 'draft']);

        $this->actingAs($actor);
        $quote->update(['status' => 'sent']);

        Notification::assertSentTo($creator, QuoteStatusChangedNotification::class, function ($notification) {
            $data = $notification->toArray(null);

            return $data['meta']['from_status'] === 'draft' && $data['meta']['to_status'] === 'sent';
        });
    }

    public function test_quote_status_change_by_creator_does_not_notify(): void
    {
        $creator = User::factory()->create();
        $quote = Quote::factory()->create(['created_by' => $creator->id, 'status' => 'draft']);

        $this->actingAs($creator);
        $quote->update(['status' => 'sent']);

        Notification::assertNothingSent();
    }

    public function test_quote_created_does_not_notify(): void
    {
        $creator = User::factory()->create();

        $this->actingAs($creator);
        Quote::factory()->create(['created_by' => $creator->id]);

        // `created` event'inde tetiklenmez (bkz. QuoteNotificationObserver
        // dokümanı — teklif her zaman `draft` doğar, ilk anlamlı geçiş
        // `updated` event'idir).
        Notification::assertNothingSent();
    }
}
