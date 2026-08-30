<?php

namespace Tests\Feature;

use App\Events\TicketSlaBreached;
use App\Events\TicketSlaWarning;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\SlaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * =============================================================================
 * docs/SLA-DESIGN.md §8 — 15 kabul kriterinin tamamı
 * =============================================================================
 *
 * Her test metodunun adı hangi kriteri karşıladığını taşır. Zaman kontrolü
 * gereken her yerde `travelTo()` / `travel()` kullanılır (§8 girişi).
 *
 * NOT (kriter 15): `migrate:fresh --seed` bu testte KOŞTURULMAZ — bu şerit
 * demo veriye dokunmama talimatı altındadır ve testler ayrı bir veritabanında
 * (phpunit.xml -> syncra_crm_test) çalışır. Kriter 15'in özü — "yeni kolonların
 * varsayılanları (null / 0) altında demo verideki ihlal dağılımı §5.3 ile
 * tutarlı kalır" — burada, migration varsayılanlarıyla oluşturulmuş
 * ticket'lardan demo verinin dağılımı (8 ihlalli / 7 açık / 8 çözülmüş /
 * 7 kapalı) birebir kurularak doğrulanır.
 */
class TicketSlaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedSlaSettings();
    }

    /**
     * `SettingSeeder`'daki `ticket.sla_hours_*` değerlerinin aynısı.
     * SlaService bunları okur; ayar yoksa FALLBACK_HOURS'a düşerdi ve test
     * "ayar okunuyor mu" sorusunu hiç sormamış olurdu.
     */
    protected function seedSlaSettings(): void
    {
        foreach (['low' => 72, 'normal' => 48, 'high' => 24, 'urgent' => 4] as $priority => $hours) {
            Setting::query()->updateOrCreate(
                ['key' => "ticket.sla_hours_{$priority}"],
                ['value' => (string) $hours, 'type' => 'integer', 'group' => 'ticket', 'is_public' => false]
            );
        }
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function actor(array $permissions = ['tickets.view', 'tickets.create', 'tickets.update', 'tickets.delete', 'tickets.assign']): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * `POST /api/tickets` ile gerçek akıştan bir ticket üretir — SLA
     * hesabının servis katmanında yapıldığını doğrulamak için factory ile
     * DEĞİL, uçtan gidilir.
     */
    protected function createTicket(User $actor, string $priority = 'normal'): Ticket
    {
        $response = $this->actingAs($actor)->postJson('/api/tickets', [
            'subject' => 'SLA testi',
            'description' => 'SLA sayacı doğrulaması.',
            'priority' => $priority,
        ]);

        $response->assertStatus(201);

        return Ticket::findOrFail($response->json('data.id'));
    }

    protected function changeStatus(User $actor, Ticket $ticket, string $status): TestResponse
    {
        return $this->actingAs($actor)->patchJson("/api/tickets/{$ticket->id}/status", ['status' => $status]);
    }

    // =================================================================
    // Kriter 1 — oluşturmada sla_due_at = created_at + hedef saat
    // =================================================================

    public function test_criterion_1_due_date_is_created_at_plus_the_configured_hours(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        foreach (['low' => 72, 'normal' => 48, 'high' => 24, 'urgent' => 4] as $priority => $hours) {
            $ticket = $this->createTicket($actor, $priority);

            $this->assertNotNull($ticket->sla_due_at);
            $this->assertSame(
                $ticket->created_at->copy()->addHours($hours)->toDateTimeString(),
                $ticket->sla_due_at->toDateTimeString(),
                "{$priority} önceliği için hedef {$hours} saat olmalı."
            );
            $this->assertSame(0, (int) $ticket->sla_paused_seconds);
            $this->assertNull($ticket->sla_paused_at);
        }
    }

    // =================================================================
    // Kriter 2 — ayar değişimi MEVCUT ticket'ları etkilemez
    // =================================================================

    public function test_criterion_2_changing_the_setting_does_not_move_existing_due_dates(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $before = $this->createTicket($actor, 'urgent');
        $originalDue = $before->sla_due_at->toDateTimeString();

        Setting::query()->where('key', 'ticket.sla_hours_urgent')->update(['value' => '8']);

        $after = $this->createTicket($actor, 'urgent');

        // Mevcut ticket kımıldamadı.
        $this->assertSame($originalDue, $before->fresh()->sla_due_at->toDateTimeString());

        // Yeni ticket yeni hedefi aldı.
        $this->assertSame(
            $after->created_at->copy()->addHours(8)->toDateTimeString(),
            $after->sla_due_at->toDateTimeString()
        );
    }

    // =================================================================
    // Kriter 3 — pending sayacı durdurur, kalan süre DONAR
    // =================================================================

    public function test_criterion_3_pending_freezes_the_remaining_seconds(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'high'); // 24 saat

        $this->changeStatus($actor, $ticket, 'pending')->assertStatus(200);

        $frozen = $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}");
        $frozen->assertJsonPath('data.sla_paused', true);
        $frozenRemaining = $frozen->json('data.sla_remaining_seconds');

        $this->assertNotNull($ticket->fresh()->sla_paused_at);

        $this->travel(1)->hours();

        $later = $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}");
        $later->assertJsonPath('data.sla_paused', true);
        $this->assertSame(
            $frozenRemaining,
            $later->json('data.sla_remaining_seconds'),
            'Duraklamada kalan süre DONMUŞ olmalı — duvar saati ilerlese de değişmez.'
        );
    }

    // =================================================================
    // Kriter 4 — pending'den çıkış sla_due_at'i duraklama kadar kaydırır
    // =================================================================

    public function test_criterion_4_resuming_shifts_the_due_date_by_the_pause_duration(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'high');
        $dueBefore = $ticket->sla_due_at->copy();

        $this->changeStatus($actor, $ticket, 'pending')->assertStatus(200);

        $this->travel(2)->hours();

        $this->changeStatus($actor, $ticket, 'in_progress')->assertStatus(200);

        $fresh = $ticket->fresh();

        $this->assertNull($fresh->sla_paused_at);
        $this->assertSame(7200, (int) $fresh->sla_paused_seconds);
        $this->assertSame(
            $dueBefore->copy()->addSeconds(7200)->toDateTimeString(),
            $fresh->sla_due_at->toDateTimeString()
        );
    }

    // =================================================================
    // Kriter 5 — duraklama ihlali ne yaratır ne de iyileştirir
    // =================================================================

    public function test_criterion_5_pausing_with_time_left_never_breaches_and_pausing_while_breached_stays_breached(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        // (a) POZİTİF kalanla duraklamaya girildi.
        $healthy = $this->createTicket($actor, 'urgent'); // 4 saat
        $this->travel(1)->hours();
        $this->changeStatus($actor, $healthy, 'pending')->assertStatus(200);

        // (b) ZATEN İHLALDEYKEN duraklamaya girildi.
        $late = $this->createTicket($actor, 'urgent');
        $this->travel(5)->hours(); // 4 saatlik hedef aşıldı
        $this->changeStatus($actor, $late, 'pending')->assertStatus(200);

        // Duvar saati her ikisinin de eski hedefini çoktan geçti.
        $this->travel(10)->hours();

        $this->actingAs($actor)->getJson("/api/tickets/{$healthy->id}")
            ->assertJsonPath('data.sla_paused', true)
            ->assertJsonPath('data.sla_breached', false);

        $this->actingAs($actor)->getJson("/api/tickets/{$late->id}")
            ->assertJsonPath('data.sla_paused', true)
            ->assertJsonPath('data.sla_breached', true);

        // `filter[sla_breached]=1` de duraklama-farkındalı olmalı.
        $ids = collect($this->actingAs($actor)->getJson('/api/tickets?filter[sla_breached]=1')->json('data'))
            ->pluck('id')->all();

        $this->assertSame([$late->id], $ids);
    }

    // =================================================================
    // Kriter 6 — öncelik yükseltmesi hedefi yeniden hesaplar
    // =================================================================

    public function test_criterion_6_raising_priority_recalculates_the_target_and_can_breach_immediately(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'normal'); // 48 saat

        // Bildirim damgalarını doldur ki sıfırlandıklarını görebilelim.
        $ticket->forceFill([
            'sla_warning_notified_at' => now(),
            'sla_breach_notified_at' => now(),
        ])->save();

        $this->travel(6)->hours();

        $response = $this->actingAs($actor)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.priority', 'urgent');
        // 6. saatte 4 saatlik hedefe çekildi -> ANINDA ihlal (istenen davranış).
        $response->assertJsonPath('data.sla_breached', true);
        $this->assertLessThan(0, $response->json('data.sla_remaining_seconds'));

        $fresh = $ticket->fresh();

        $this->assertSame(
            $fresh->created_at->copy()->addHours(4)->addSeconds((int) $fresh->sla_paused_seconds)->toDateTimeString(),
            $fresh->sla_due_at->toDateTimeString()
        );
        $this->assertNull($fresh->sla_warning_notified_at, 'Öncelik değişiminde uyarı damgası sıfırlanmalı.');
        $this->assertNull($fresh->sla_breach_notified_at, 'Öncelik değişiminde ihlal damgası sıfırlanmalı.');
    }

    public function test_lowering_priority_extends_the_target_with_the_same_formula(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'urgent'); // 4 saat
        $this->travel(5)->hours(); // ihlalde

        $this->actingAs($actor)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'low'])
            ->assertStatus(200)
            // 72 saatlik hedefe geçti -> artık ihlalde değil.
            ->assertJsonPath('data.sla_breached', false);

        $fresh = $ticket->fresh();
        $this->assertSame(
            $fresh->created_at->copy()->addHours(72)->toDateTimeString(),
            $fresh->sla_due_at->toDateTimeString()
        );
    }

    // =================================================================
    // Kriter 7 — tarihsel ihlal + çözülmüşte remaining = null
    // =================================================================

    public function test_criterion_7_resolved_tickets_report_historical_breach_and_null_remaining(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $onTime = $this->createTicket($actor, 'urgent');
        $this->travel(2)->hours();
        $this->changeStatus($actor, $onTime, 'resolved')->assertStatus(200);

        $this->actingAs($actor)->getJson("/api/tickets/{$onTime->id}")
            ->assertJsonPath('data.sla_breached', false)
            ->assertJsonPath('data.sla_remaining_seconds', null);

        $late = $this->createTicket($actor, 'urgent');
        $this->travel(9)->hours();
        $this->changeStatus($actor, $late, 'resolved')->assertStatus(200);

        $this->actingAs($actor)->getJson("/api/tickets/{$late->id}")
            ->assertJsonPath('data.sla_breached', true)
            ->assertJsonPath('data.sla_remaining_seconds', null);
    }

    // =================================================================
    // Kriter 8 — resolved -> open yeniden açma
    // =================================================================

    public function test_criterion_8_reopening_shifts_the_due_date_by_the_time_spent_resolved(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'high'); // 24 saat

        $this->travel(2)->hours();
        $this->changeStatus($actor, $ticket, 'resolved')->assertStatus(200);

        $dueAtResolve = $ticket->fresh()->sla_due_at->copy();
        $remainingAtResolve = 24 * 3600 - 2 * 3600;

        // 3 gün rafta bekledi.
        $this->travel(72)->hours();

        $response = $this->changeStatus($actor, $ticket, 'open');
        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.resolved_at', null);
        // Sırf beklediği için ihlale DÜŞMEZ.
        $response->assertJsonPath('data.sla_breached', false);

        $fresh = $ticket->fresh();
        $this->assertSame(72 * 3600, (int) $fresh->sla_paused_seconds);
        $this->assertSame(
            $dueAtResolve->copy()->addHours(72)->toDateTimeString(),
            $fresh->sla_due_at->toDateTimeString()
        );
        // Kalan süre, çözüm anındaki kalanla aynı.
        $this->assertSame($remainingAtResolve, $response->json('data.sla_remaining_seconds'));
    }

    // =================================================================
    // Kriter 9 — geçersiz geçişler 422 INVALID_STATUS_TRANSITION
    // =================================================================

    public function test_criterion_9_closed_is_terminal(): void
    {
        $actor = $this->actor();
        $ticket = Ticket::factory()->create([
            'status' => 'closed',
            'resolved_at' => now()->subDay(),
            'closed_at' => now()->subHours(2),
        ]);

        foreach (['open', 'pending', 'in_progress', 'resolved', 'closed'] as $target) {
            $this->changeStatus($actor, $ticket, $target)
                ->assertStatus(422)
                ->assertJsonPath('errors.code', 'INVALID_STATUS_TRANSITION')
                ->assertJsonStructure(['errors' => ['message', 'code', 'fields' => ['status']]]);
        }

        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_criterion_9_other_illegal_transitions_are_rejected(): void
    {
        $actor = $this->actor();

        $illegal = [
            ['open', 'closed'],
            ['open', 'open'],            // aynı duruma geçiş
            ['pending', 'closed'],
            ['pending', 'pending'],
            ['in_progress', 'closed'],
            ['in_progress', 'in_progress'],
            ['resolved', 'pending'],
            ['resolved', 'in_progress'],
            ['resolved', 'resolved'],
        ];

        foreach ($illegal as [$from, $to]) {
            $ticket = Ticket::factory()->create([
                'status' => $from,
                'resolved_at' => $from === 'resolved' ? now()->subHour() : null,
                'sla_paused_at' => $from === 'pending' ? now()->subHour() : null,
            ]);

            $this->changeStatus($actor, $ticket, $to)
                ->assertStatus(422)
                ->assertJsonPath('errors.code', 'INVALID_STATUS_TRANSITION');

            $this->assertSame($from, $ticket->fresh()->status, "{$from} -> {$to} geçişi durumu değiştirmemeliydi.");
        }
    }

    public function test_all_legal_transitions_are_accepted(): void
    {
        $actor = $this->actor();

        $legal = [
            ['open', 'in_progress'],
            ['open', 'pending'],
            ['open', 'resolved'],
            ['in_progress', 'open'],
            ['in_progress', 'pending'],
            ['in_progress', 'resolved'],
            ['pending', 'open'],
            ['pending', 'in_progress'],
            ['pending', 'resolved'],
            ['resolved', 'open'],
            ['resolved', 'closed'],
        ];

        foreach ($legal as [$from, $to]) {
            $ticket = Ticket::factory()->create([
                'status' => $from,
                'sla_due_at' => now()->addDay(),
                'resolved_at' => $from === 'resolved' ? now()->subHour() : null,
                'sla_paused_at' => $from === 'pending' ? now()->subHour() : null,
            ]);

            $this->changeStatus($actor, $ticket, $to)
                ->assertStatus(200)
                ->assertJsonPath('data.status', $to);
        }
    }

    public function test_closing_a_resolved_ticket_stamps_closed_at(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'normal');
        $this->changeStatus($actor, $ticket, 'resolved')->assertStatus(200);
        $this->travel(1)->hours();

        $response = $this->changeStatus($actor, $ticket, 'closed');

        $response->assertStatus(200);
        $this->assertNotNull($ticket->fresh()->closed_at);
        // SLA `resolved`'da bitmişti; kapama sayaca dokunmaz.
        $response->assertJsonPath('data.sla_remaining_seconds', null);
    }

    // =================================================================
    // Kriter 10 — genel PATCH status/SLA alanlarını reddeder
    // =================================================================

    public function test_criterion_10_general_patch_rejects_status_and_sla_fields(): void
    {
        $actor = $this->actor();
        $ticket = Ticket::factory()->create(['status' => 'open']);

        foreach (['status' => 'resolved', 'sla_due_at' => now()->addYear()->toIso8601String(), 'sla_paused_seconds' => 0, 'resolved_at' => now()->toIso8601String()] as $field => $value) {
            $this->actingAs($actor)
                ->patchJson("/api/tickets/{$ticket->id}", [$field => $value])
                ->assertStatus(422)
                ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        }

        $fresh = $ticket->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->resolved_at);
    }

    // =================================================================
    // Kriter 11 — first_response_at bir kez yazılır
    // =================================================================

    public function test_criterion_11_first_response_is_written_once_on_the_first_in_progress_transition(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'high');
        $this->assertNull($ticket->first_response_at);

        $this->travel(30)->minutes();
        $this->changeStatus($actor, $ticket, 'in_progress')->assertStatus(200);

        $firstResponse = $ticket->fresh()->first_response_at;
        $this->assertNotNull($firstResponse);

        // Sonraki geçişler değeri DEĞİŞTİRMEZ.
        $this->travel(2)->hours();
        $this->changeStatus($actor, $ticket, 'pending')->assertStatus(200);
        $this->travel(1)->hours();
        $this->changeStatus($actor, $ticket, 'in_progress')->assertStatus(200);

        $this->assertSame(
            $firstResponse->toDateTimeString(),
            $ticket->fresh()->first_response_at->toDateTimeString(),
            'first_response_at bir kez yazılır ve bir daha ASLA değişmez.'
        );
    }

    /**
     * Kriter 11'in (b) şıkkı — "ilk `call|email|meeting` aktivitesi" —
     * `POST /api/activities` ucuna bağlanmalıdır; o uç (ActivityService) bu
     * şeridin sahipliği DIŞINDADIR ve bu turda bağlanmamıştır (raporlandı).
     *
     * Bağlanacak mekanizma burada doğrudan test edilir: `recordFirstResponse()`
     * idempotenttir — ilk çağrıda yazar, sonrakilerde dokunmaz. ActivityService
     * yalnızca `note` DIŞINDAKİ tipler için bu metodu çağırmalıdır.
     */
    public function test_criterion_11_record_first_response_is_idempotent(): void
    {
        $this->travelTo(now()->startOfHour());

        $sla = app(SlaService::class);
        $ticket = Ticket::factory()->create(['first_response_at' => null]);

        $sla->recordFirstResponse($ticket);
        $ticket->save();
        $written = $ticket->fresh()->first_response_at;
        $this->assertNotNull($written);

        $this->travel(3)->hours();

        $sla->recordFirstResponse($ticket);
        $ticket->save();

        $this->assertSame($written->toDateTimeString(), $ticket->fresh()->first_response_at->toDateTimeString());
    }

    // =================================================================
    // Kriter 12 — demo dağılımı: tam olarak 8 aktif ihlal
    // =================================================================

    /**
     * Demo verideki (DemoDataSeeder::seedTickets) dağılımın aynısı, YENİ
     * kolonların migration varsayılanlarıyla (`sla_paused_at = null`,
     * `sla_paused_seconds = 0`) kurulur: 8 ihlalli + 7 açık + 8 çözülmüş +
     * 7 kapalı = 30. `filter[sla_breached]=1` TAM OLARAK 8 döndürmelidir.
     *
     * Aynı zamanda kriter 15'in özüdür: varsayılanlar altında §5.3 kuralları
     * demo dağılımıyla tutarlı kalır.
     */
    public function test_criterion_12_and_15_demo_distribution_yields_exactly_eight_active_breaches(): void
    {
        $actor = $this->actor();

        Ticket::factory()->count(8)->create([
            'status' => 'open',
            'sla_due_at' => now()->subDays(2),
            'resolved_at' => null,
            'closed_at' => null,
        ]);
        Ticket::factory()->count(7)->create([
            'status' => 'open',
            'sla_due_at' => now()->addHours(10),
            'resolved_at' => null,
            'closed_at' => null,
        ]);
        Ticket::factory()->count(8)->create([
            'status' => 'resolved',
            'sla_due_at' => now()->subDays(3),
            'resolved_at' => now()->subDays(2),
            'closed_at' => null,
        ]);
        Ticket::factory()->count(7)->create([
            'status' => 'closed',
            'sla_due_at' => now()->subDays(5),
            'resolved_at' => now()->subDays(4),
            'closed_at' => now()->subDays(3),
        ]);

        $this->assertSame(30, Ticket::count());
        $this->assertSame(30, Ticket::where('sla_paused_seconds', 0)->whereNull('sla_paused_at')->count());

        $response = $this->actingAs($actor)->getJson('/api/tickets?filter[sla_breached]=1&per_page=100');

        $response->assertStatus(200);
        $response->assertJsonCount(8, 'data');
        $response->assertJsonPath('meta.pagination.total', 8);

        $this->actingAs($actor)->getJson('/api/tickets/stats')
            ->assertJsonPath('data.breached_count', 8)
            ->assertJsonPath('data.total', 30);
    }

    // =================================================================
    // Kriter 13 — tickets:scan-sla bir kez event üretir
    // =================================================================

    public function test_criterion_13_scanner_dispatches_warning_and_breach_events_exactly_once(): void
    {
        Event::fake([TicketSlaWarning::class, TicketSlaBreached::class]);
        $this->travelTo(now()->startOfHour());

        // urgent = 4 saat, %20 eşiği 48 dakika.
        $atRisk = Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->addMinutes(30),
            'resolved_at' => null,
        ]);
        $breached = Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->subHour(),
            'resolved_at' => null,
        ]);
        // Sağlıklı — hiçbir olay üretmemeli.
        Ticket::factory()->create(['status' => 'open', 'priority' => 'urgent', 'sla_due_at' => now()->addHours(3)]);
        // Duraklamada — §5.5 kapsamı dışı, ne uyarı ne ihlal.
        Ticket::factory()->create([
            'status' => 'pending',
            'priority' => 'urgent',
            'sla_due_at' => now()->subHours(2),
            'sla_paused_at' => now()->subHours(3),
        ]);
        // Çözülmüş — kapsam dışı.
        Ticket::factory()->create([
            'status' => 'resolved',
            'priority' => 'urgent',
            'sla_due_at' => now()->subDay(),
            'resolved_at' => now()->subHours(6),
        ]);

        $this->artisan('tickets:scan-sla')->assertSuccessful();

        Event::assertDispatchedTimes(TicketSlaWarning::class, 1);
        Event::assertDispatchedTimes(TicketSlaBreached::class, 1);

        Event::assertDispatched(TicketSlaWarning::class, fn (TicketSlaWarning $e) => $e->payload['ticket_id'] === $atRisk->id);
        Event::assertDispatched(TicketSlaBreached::class, fn (TicketSlaBreached $e) => $e->payload['ticket_id'] === $breached->id
            && $e->payload['overdue_seconds'] > 0);

        $this->assertNotNull($atRisk->fresh()->sla_warning_notified_at);
        $this->assertNotNull($breached->fresh()->sla_breach_notified_at);

        // İKİNCİ çalıştırma hiçbir şey tekrar etmemeli.
        $this->artisan('tickets:scan-sla')->assertSuccessful();

        Event::assertDispatchedTimes(TicketSlaWarning::class, 1);
        Event::assertDispatchedTimes(TicketSlaBreached::class, 1);
    }

    public function test_criterion_13_dry_run_dispatches_nothing_and_writes_no_stamp(): void
    {
        Event::fake([TicketSlaWarning::class, TicketSlaBreached::class]);
        $this->travelTo(now()->startOfHour());

        $atRisk = Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->addMinutes(20),
        ]);
        $breached = Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->subHours(2),
        ]);

        $this->artisan('tickets:scan-sla --dry-run')->assertSuccessful();

        Event::assertNotDispatched(TicketSlaWarning::class);
        Event::assertNotDispatched(TicketSlaBreached::class);

        $this->assertNull($atRisk->fresh()->sla_warning_notified_at);
        $this->assertNull($breached->fresh()->sla_breach_notified_at);
    }

    /**
     * Öncelik değişimi damgaları sıfırladığı için tarayıcı YENİ hedefe göre
     * bir kez daha olay üretebilmelidir (§5.2 ile §5.5'in birlikte çalışması).
     */
    public function test_scanner_fires_again_after_a_priority_change_resets_the_stamps(): void
    {
        Event::fake([TicketSlaBreached::class]);
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'urgent');
        $this->travel(5)->hours();

        $this->artisan('tickets:scan-sla')->assertSuccessful();
        Event::assertDispatchedTimes(TicketSlaBreached::class, 1);

        // Önceliği düşür (hedef uzar, damgalar sıfırlanır), sonra tekrar yükselt.
        $this->actingAs($actor)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'low'])->assertStatus(200);
        $this->assertNull($ticket->fresh()->sla_breach_notified_at);

        $this->actingAs($actor)->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent'])->assertStatus(200);

        $this->artisan('tickets:scan-sla')->assertSuccessful();
        Event::assertDispatchedTimes(TicketSlaBreached::class, 2);
    }

    // =================================================================
    // Kriter 14 — TicketResource'un SLA alanları
    // =================================================================

    public function test_criterion_14_resource_exposes_every_sla_field_with_the_documented_meaning(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'urgent'); // 4 saat = 14400 sn

        $healthy = $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}");

        $healthy->assertStatus(200);
        $healthy->assertJsonPath('data.sla_total_seconds', 14400);
        $healthy->assertJsonPath('data.sla_target_hours', 4);
        $healthy->assertJsonPath('data.sla_paused', false);
        $healthy->assertJsonPath('data.sla_breached', false);
        $healthy->assertJsonPath('data.sla_paused_seconds', 0);
        $this->assertSame(14400, $healthy->json('data.sla_remaining_seconds'));
        $this->assertIsString($healthy->json('data.sla_due_at'));

        // İhlalde: kalan süre NEGATİF olmalı.
        $this->travel(6)->hours();

        $late = $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}");
        $late->assertJsonPath('data.sla_breached', true);
        $this->assertLessThan(0, $late->json('data.sla_remaining_seconds'));
        $this->assertSame(-2 * 3600, $late->json('data.sla_remaining_seconds'));
    }

    /**
     * Duraklama süresi hedefin PAYDASINI bozmamalıdır: `sla_total_seconds`
     * ticket'ın kendi hedefinden türetilir ve duraklamadan etkilenmez.
     */
    public function test_total_seconds_is_stable_across_pause_and_reopen(): void
    {
        $actor = $this->actor();
        $this->travelTo(now()->startOfHour());

        $ticket = $this->createTicket($actor, 'high'); // 24 saat = 86400 sn

        $this->changeStatus($actor, $ticket, 'pending')->assertStatus(200);
        $this->travel(5)->hours();
        $this->changeStatus($actor, $ticket, 'open')->assertStatus(200);

        $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}")
            ->assertJsonPath('data.sla_total_seconds', 86400)
            ->assertJsonPath('data.sla_paused_seconds', 5 * 3600);
    }
}
