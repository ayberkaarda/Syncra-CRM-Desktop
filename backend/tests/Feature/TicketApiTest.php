<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET|POST|PATCH|DELETE /api/tickets*` — uç sözleşmesi, izinler, liste
 * davranışı ve silme kuralı.
 *
 * SLA sayacının kendisi (duraklama, yeniden hesap, ihlal türetimi, durum
 * makinesi, tarayıcı) TicketSlaTest'tedir — docs/SLA-DESIGN.md §8'in 15
 * kabul kriteri oradadır.
 */
class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rol/izin sözlüğünü kur — ayrı test veritabanı (phpunit.xml), ana
        // syncra_crm verisine dokunulmaz.
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/tickets')->assertStatus(401);
        $this->getJson('/api/tickets/stats')->assertStatus(401);
    }

    public function test_user_without_tickets_view_permission_cannot_list_tickets(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/tickets')->assertStatus(403);
    }

    public function test_user_without_tickets_view_permission_cannot_read_stats(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/tickets/stats')->assertStatus(403);
    }

    public function test_user_without_tickets_view_permission_cannot_show_ticket(): void
    {
        $actor = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}")->assertStatus(403);
    }

    public function test_user_without_tickets_create_permission_cannot_store_ticket(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)
            ->postJson('/api/tickets', ['subject' => 'Test', 'description' => 'Test'])
            ->assertStatus(403);
    }

    public function test_user_without_tickets_update_permission_cannot_update_ticket(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}", ['subject' => 'Güncel'])
            ->assertStatus(403);
    }

    public function test_user_without_tickets_update_permission_cannot_change_status(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $ticket = Ticket::factory()->create(['status' => 'open']);

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertStatus(403);
    }

    public function test_user_without_tickets_delete_permission_cannot_destroy_ticket(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $ticket = Ticket::factory()->create(['status' => 'open']);

        $this->actingAs($actor)->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(403);
    }

    public function test_user_without_tickets_assign_permission_cannot_assign_ticket(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view', 'tickets.update']);
        $assignee = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}/assign", ['assigned_to' => $assignee->id])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Route sırası: /tickets/stats, /tickets/{ticket}'ten ÖNCE eşleşmeli
    // -------------------------------------------------------------------

    /**
     * Faz 6 (`leads/check-duplicates`), Faz 7 (`deals/board`) ve Faz 8/A
     * (`tasks/calendar`) ile AYNI tuzak: sabit segment parametreli rotadan
     * sonra tanımlanırsa Laravel `stats`'ı bir ticket id'si sanar ve
     * route-model-binding 404 üretir. Bu test o sırayı KİLİTLER.
     */
    public function test_stats_route_is_not_captured_by_ticket_show_route(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $response = $this->actingAs($actor)->getJson('/api/tickets/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['total', 'by_status', 'by_priority', 'breached_count', 'at_risk_count', 'avg_resolution_hours']]);
    }

    public function test_show_route_still_resolves_a_numeric_id(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($actor)
            ->getJson("/api/tickets/{$ticket->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $ticket->id);
    }

    // -------------------------------------------------------------------
    // Liste: sayfalama, filtre, sıralama, arama
    // -------------------------------------------------------------------

    public function test_index_returns_paginated_envelope(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        Ticket::factory()->count(5)->create();

        $response = $this->actingAs($actor)->getJson('/api/tickets?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.total', 5);
        $response->assertJsonPath('meta.pagination.last_page', 3);
        $response->assertJsonCount(2, 'data');
    }

    public function test_per_page_is_capped_at_one_hundred(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $this->actingAs($actor)->getJson('/api/tickets?per_page=101')->assertStatus(422);
    }

    public function test_index_filters_by_status_priority_and_category(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $match = Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'category' => 'Teknik Destek',
        ]);
        Ticket::factory()->create(['status' => 'open', 'priority' => 'low', 'category' => 'Şikayet']);
        Ticket::factory()->create(['status' => 'resolved', 'priority' => 'urgent', 'category' => 'Teknik Destek']);

        $this->actingAs($actor)
            ->getJson('/api/tickets?filter[status]=open&filter[priority]=urgent&filter[category]=Teknik+Destek')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_index_filters_by_assignee_company_contact_and_tag(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $assignee = User::factory()->create();
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $tag = Tag::factory()->create();

        $match = Ticket::factory()->create([
            'assigned_to' => $assignee->id,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);
        $match->tags()->attach($tag->id);

        Ticket::factory()->count(3)->create();

        $this->actingAs($actor)
            ->getJson("/api/tickets?filter[assigned_to]={$assignee->id}&filter[company_id]={$company->id}&filter[contact_id]={$contact->id}&filter[tag_id]={$tag->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_index_filters_by_created_at_range(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $old = Ticket::factory()->create();
        $old->forceFill(['created_at' => now()->subDays(30)])->save();

        $recent = Ticket::factory()->create();
        $recent->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($actor)
            ->getJson('/api/tickets?filter[from]='.now()->subDays(3)->toDateString())
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_index_search_covers_ticket_number_subject_and_description(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $byNumber = Ticket::factory()->create(['ticket_number' => 'TKT-909090']);
        $bySubject = Ticket::factory()->create(['subject' => 'Zebrasistem arızası']);
        $byDescription = Ticket::factory()->create(['description' => 'Müşteri zebrasistem hatası bildirdi.']);
        Ticket::factory()->create(['subject' => 'Alakasız', 'description' => 'Alakasız']);

        $this->actingAs($actor)
            ->getJson('/api/tickets?q=909090')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byNumber->id);

        $ids = collect(
            $this->actingAs($actor)->getJson('/api/tickets?q=zebrasistem')->json('data')
        )->pluck('id')->all();

        sort($ids);
        $expected = [$bySubject->id, $byDescription->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    /**
     * Arama, `filter[...]` koşullarını EZMEMELİDİR — repository'deki
     * parantezli `where` grubu tam bunun içindir.
     */
    public function test_search_does_not_leak_past_filters(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        Ticket::factory()->create(['subject' => 'kayıp paket', 'status' => 'open']);
        Ticket::factory()->create(['subject' => 'kayıp paket', 'status' => 'resolved']);

        $this->actingAs($actor)
            ->getJson('/api/tickets?q=kayıp&filter[status]=open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_sorts_by_sla_due_at_ascending_most_urgent_first(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $later = Ticket::factory()->create(['sla_due_at' => now()->addDays(3)]);
        $soonest = Ticket::factory()->create(['sla_due_at' => now()->addHours(2)]);
        $middle = Ticket::factory()->create(['sla_due_at' => now()->addDay()]);

        $ids = collect(
            $this->actingAs($actor)->getJson('/api/tickets?sort=sla_due_at')->json('data')
        )->pluck('id')->all();

        $this->assertSame([$soonest->id, $middle->id, $later->id], $ids);
    }

    public function test_unknown_sort_column_falls_back_to_newest_first(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $older = Ticket::factory()->create();
        $older->forceFill(['created_at' => now()->subDays(5)])->save();
        $newer = Ticket::factory()->create();
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        // `password` sıralanabilir kolon değil — sessizce -created_at'e düşer.
        $ids = collect(
            $this->actingAs($actor)->getJson('/api/tickets?sort=password')->json('data')
        )->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_sla_breached_filter_returns_only_actively_breached_tickets(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        $breached = Ticket::factory()->create([
            'status' => 'open',
            'sla_due_at' => now()->subHours(3),
            'resolved_at' => null,
        ]);
        // Vadesi gelmemiş.
        Ticket::factory()->create(['status' => 'open', 'sla_due_at' => now()->addHours(3)]);
        // Vadesi geçmiş AMA çözülmüş -> aktif ihlal değil (tarihsel ihlal).
        Ticket::factory()->create([
            'status' => 'resolved',
            'sla_due_at' => now()->subHours(5),
            'resolved_at' => now()->subHour(),
        ]);

        $this->actingAs($actor)
            ->getJson('/api/tickets?filter[sla_breached]=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $breached->id)
            ->assertJsonPath('data.0.sla_breached', true);
    }

    /**
     * Liste ilişkilerinin (contact/company/assignee/creator/tags) ve
     * `notes_count`'un TOPLU yüklendiğini ölçer. N+1 olsaydı 15 ticket x 5
     * ilişki = 75 ek sorgu gerekirdi.
     */
    public function test_index_does_not_trigger_n_plus_one_queries(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $assignee = User::factory()->create();
        $creator = User::factory()->create();
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $tag = Tag::factory()->create();

        $tickets = Ticket::factory()->count(15)->create([
            'assigned_to' => $assignee->id,
            'created_by' => $creator->id,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);

        foreach ($tickets as $ticket) {
            $ticket->tags()->attach($tag->id);
            Activity::factory()->count(2)->create([
                'type' => 'note',
                'activityable_type' => Ticket::class,
                'activityable_id' => $ticket->id,
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/tickets?per_page=100');

        $response->assertStatus(200);
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('data.0.notes_count', 2);

        $this->assertLessThan(
            20,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') — N+1 şüphesi:'.PHP_EOL.implode(PHP_EOL, $queries)
        );

        // tags TAM OLARAK bir sorguda (tüm ticket'lar için toplu) yüklenmeli.
        $tagQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'taggables'))->count();
        $this->assertSame(1, $tagQueries, 'tags ilişkisi tam olarak 1 sorguda eager-load edilmeli.');

        // notes_count bir ALT SORGU olmalı — ticket başına ayrı bir COUNT değil.
        $activityQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'activities'))->count();
        $this->assertLessThanOrEqual(1, $activityQueries, 'notes_count withCount alt sorgusuyla gelmeli.');
    }

    // -------------------------------------------------------------------
    // Oluşturma / güncelleme / atama
    // -------------------------------------------------------------------

    public function test_store_generates_ticket_number_and_opens_the_ticket(): void
    {
        $actor = $this->actorWithPermissions(['tickets.create', 'tickets.view']);

        $response = $this->actingAs($actor)->postJson('/api/tickets', [
            'subject' => 'Yazıcı bağlanmıyor',
            'description' => 'Ağ yazıcısı listede görünmüyor.',
            'priority' => 'high',
            // Sunucunun yok saymasını beklediğimiz alanlar:
            'status' => 'closed',
            'ticket_number' => 'HACK-1',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.priority', 'high');
        $response->assertJsonPath('data.creator.id', $actor->id);
        $response->assertJsonPath('data.notes_count', 0);

        $this->assertNotSame('HACK-1', $response->json('data.ticket_number'));
        $this->assertStringStartsWith('TKT-', (string) $response->json('data.ticket_number'));
    }

    public function test_store_requires_subject_and_description(): void
    {
        $actor = $this->actorWithPermissions(['tickets.create']);

        $this->actingAs($actor)
            ->postJson('/api/tickets', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    public function test_update_can_change_subject_and_tags(): void
    {
        $actor = $this->actorWithPermissions(['tickets.update', 'tickets.view']);
        $ticket = Ticket::factory()->create();
        $tag = Tag::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}", [
                'subject' => 'Güncellenmiş konu',
                'tag_ids' => [$tag->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.subject', 'Güncellenmiş konu')
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    /**
     * docs/SLA-DESIGN.md §4 + kabul kriteri 10: durum yalnızca `/status`
     * ucundan değişir. Genel update ucundan geçirilebilseydi TÜM SLA yan
     * etkileri baypas edilirdi.
     */
    public function test_update_rejects_status_in_the_body(): void
    {
        $actor = $this->actorWithPermissions(['tickets.update']);
        $ticket = Ticket::factory()->create(['status' => 'open']);

        $response = $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved']);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('status', $response->json('errors.fields'));

        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_update_rejects_every_sla_field_in_the_body(): void
    {
        $actor = $this->actorWithPermissions(['tickets.update']);
        $ticket = Ticket::factory()->create();

        foreach (['sla_due_at', 'sla_paused_at', 'sla_paused_seconds', 'first_response_at', 'resolved_at', 'closed_at'] as $field) {
            $this->actingAs($actor)
                ->patchJson("/api/tickets/{$ticket->id}", [$field => now()->toIso8601String()])
                ->assertStatus(422)
                ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        }
    }

    public function test_assign_sets_and_clears_the_assignee(): void
    {
        $actor = $this->actorWithPermissions(['tickets.assign', 'tickets.view']);
        $assignee = User::factory()->create();
        $ticket = Ticket::factory()->create(['assigned_to' => null]);

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}/assign", ['assigned_to' => $assignee->id])
            ->assertStatus(200)
            ->assertJsonPath('data.assignee.id', $assignee->id);

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}/assign", ['assigned_to' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.assignee', null);
    }

    public function test_assign_requires_the_field_to_be_present(): void
    {
        $actor = $this->actorWithPermissions(['tickets.assign']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/tickets/{$ticket->id}/assign", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.fields.assigned_to.0', 'Atanan kişi alanı gönderilmelidir (atamayı kaldırmak için null).');
    }

    // -------------------------------------------------------------------
    // Silme kuralı — TicketPolicy::delete()
    // -------------------------------------------------------------------

    public function test_open_ticket_can_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['tickets.delete']);
        $ticket = Ticket::factory()->create(['status' => 'open']);

        $this->actingAs($actor)->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(204);

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    /**
     * KARAR: çözülmüş/kapanmış bir ticket SİLİNEMEZ (403). `resolved_at` ile
     * `sla_due_at` karşılaştırması Faz 11'in SLA uyum raporunun tek
     * girdisidir; soft delete bile olsa kayıt varsayılan sorgulardan düşer ve
     * geçmiş bir dönemin uyum yüzdesi sessizce değişir. Bir ihlali yok
     * etmenin en kolay yolu "ticket'ı sil" olmamalıdır. Gerekçenin tamamı
     * TicketPolicy::delete() dokümanındadır.
     */
    public function test_resolved_ticket_cannot_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['tickets.delete']);
        $ticket = Ticket::factory()->create(['status' => 'resolved', 'resolved_at' => now()->subHour()]);

        $this->actingAs($actor)->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(403);

        $this->assertNotSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_closed_ticket_cannot_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['tickets.delete']);
        $ticket = Ticket::factory()->create([
            'status' => 'closed',
            'resolved_at' => now()->subDay(),
            'closed_at' => now()->subHours(2),
        ]);

        $this->actingAs($actor)->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // GET /api/tickets/stats
    // -------------------------------------------------------------------

    public function test_stats_counts_are_correct_and_ignore_list_filters(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        Ticket::factory()->count(2)->create(['status' => 'open', 'priority' => 'low', 'sla_due_at' => now()->addDays(2)]);
        Ticket::factory()->create(['status' => 'in_progress', 'priority' => 'high', 'sla_due_at' => now()->addDays(1)]);
        Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->subHours(2),
            'resolved_at' => null,
        ]);

        // Çözülmüş: 10 saat sürmüş.
        $resolved = Ticket::factory()->create([
            'status' => 'resolved',
            'priority' => 'normal',
            'sla_due_at' => now()->subDays(1),
            'resolved_at' => now()->subHours(2),
        ]);
        $resolved->forceFill(['created_at' => now()->subHours(12)])->save();

        // `filter[status]=open` gönderiyoruz: özet BUNDAN ETKİLENMEMELİ.
        $response = $this->actingAs($actor)->getJson('/api/tickets/stats?filter[status]=open');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 5);
        $response->assertJsonPath('data.by_status.open', 3);
        $response->assertJsonPath('data.by_status.in_progress', 1);
        $response->assertJsonPath('data.by_status.resolved', 1);
        $response->assertJsonPath('data.by_status.pending', 0);
        $response->assertJsonPath('data.by_status.closed', 0);
        $response->assertJsonPath('data.by_priority.low', 2);
        $response->assertJsonPath('data.by_priority.urgent', 1);
        $response->assertJsonPath('data.breached_count', 1);

        // 12 saat - 2 saat = 10 saat.
        $this->assertEqualsWithDelta(10.0, $response->json('data.avg_resolution_hours'), 0.05);
    }

    /**
     * `at_risk_count`, docs/SLA-DESIGN.md §5.5'teki uyarı eşiğiyle AYNI
     * predicate'i kullanır: kalan süre hedefin %20'sinin altında ve HENÜZ
     * ihlal yok.
     */
    public function test_stats_at_risk_count_uses_the_twenty_percent_warning_threshold(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);

        // urgent = 4 saat hedef -> %20 eşiği 48 dakika.
        $atRisk = Ticket::factory()->create([
            'status' => 'open',
            'priority' => 'urgent',
            'sla_due_at' => now()->addMinutes(30),
        ]);
        // Aynı öncelikte ama eşiğin dışında.
        Ticket::factory()->create(['status' => 'open', 'priority' => 'urgent', 'sla_due_at' => now()->addHours(2)]);
        // Zaten ihlalde -> "risk altında" DEĞİL.
        Ticket::factory()->create(['status' => 'open', 'priority' => 'urgent', 'sla_due_at' => now()->subHour()]);
        // Duraklamada -> hiç sayılmaz.
        Ticket::factory()->create([
            'status' => 'pending',
            'priority' => 'urgent',
            'sla_due_at' => now()->addMinutes(20),
            'sla_paused_at' => now(),
        ]);

        $response = $this->actingAs($actor)->getJson('/api/tickets/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.at_risk_count', 1);

        // Doğru ticket'ın risk altında olduğunu ayrıca doğrula.
        $this->assertSame('urgent', $atRisk->priority);
    }

    public function test_stats_average_resolution_hours_is_null_when_nothing_is_resolved(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        Ticket::factory()->count(2)->create(['status' => 'open']);

        $this->actingAs($actor)
            ->getJson('/api/tickets/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.avg_resolution_hours', null);
    }

    // -------------------------------------------------------------------
    // Gösterim sözleşmesi
    // -------------------------------------------------------------------

    public function test_show_returns_the_full_sla_contract_and_notes_count(): void
    {
        $actor = $this->actorWithPermissions(['tickets.view']);
        $ticket = Ticket::factory()->create(['status' => 'open', 'sla_due_at' => now()->addHours(5)]);

        Activity::factory()->count(3)->create([
            'type' => 'note',
            'activityable_type' => Ticket::class,
            'activityable_id' => $ticket->id,
        ]);
        // `note` OLMAYAN aktiviteler `notes_count`'a girmez.
        Activity::factory()->create([
            'type' => 'call',
            'activityable_type' => Ticket::class,
            'activityable_id' => $ticket->id,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id', 'ticket_number', 'subject', 'description', 'priority', 'status', 'category',
                'sla_due_at', 'sla_total_seconds', 'sla_remaining_seconds', 'sla_paused',
                'sla_breached', 'sla_paused_seconds', 'sla_target_hours',
                'first_response_at', 'resolved_at', 'closed_at', 'notes_count',
                'contact', 'company', 'assignee', 'creator', 'tags', 'custom_fields',
                'created_at', 'updated_at',
            ],
        ]);
        $response->assertJsonPath('data.notes_count', 3);
        $response->assertJsonPath('data.sla_paused', false);
        $response->assertJsonPath('data.sla_breached', false);
        $this->assertGreaterThan(0, $response->json('data.sla_remaining_seconds'));
    }
}
