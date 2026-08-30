<?php

namespace Tests\Feature;

use App\Http\Requests\Activities\StoreActivityRequest;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\SlaService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

class ActivityApiTest extends TestCase
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
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $activity = Activity::factory()->create();

        $this->getJson('/api/activities')->assertStatus(401);
        $this->postJson('/api/activities', [])->assertStatus(401);
        $this->getJson("/api/activities/{$activity->id}")->assertStatus(401);
        $this->patchJson("/api/activities/{$activity->id}", [])->assertStatus(401);
        $this->deleteJson("/api/activities/{$activity->id}")->assertStatus(401);
    }

    public function test_user_without_activities_view_permission_cannot_list(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/activities')->assertStatus(403);
    }

    public function test_user_without_activities_view_permission_cannot_show(): void
    {
        $actor = User::factory()->create();
        $activity = Activity::factory()->create();

        $this->actingAs($actor)->getJson("/api/activities/{$activity->id}")->assertStatus(403);
    }

    public function test_user_without_activities_create_permission_cannot_store(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'note',
            'subject' => 'Test',
            'occurred_at' => now()->toIso8601String(),
        ])->assertStatus(403);
    }

    public function test_user_without_activities_update_permission_cannot_update(): void
    {
        $actor = User::factory()->create();
        $activity = Activity::factory()->create();

        $this->actingAs($actor)->patchJson("/api/activities/{$activity->id}", ['subject' => 'X'])->assertStatus(403);
    }

    public function test_user_without_activities_delete_permission_and_not_creator_cannot_destroy(): void
    {
        $actor = User::factory()->create();
        $activity = Activity::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($actor)->deleteJson("/api/activities/{$activity->id}")->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // İş kuralları
    // -------------------------------------------------------------------

    public function test_occurred_at_in_the_future_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'Müşteri görüşmesi',
            'occurred_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('occurred_at', $response->json('errors.fields'));
    }

    public function test_user_id_from_client_is_ignored_and_authenticated_user_is_used(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $impersonated = User::factory()->create();

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'note',
            'subject' => 'Not',
            'occurred_at' => now()->toIso8601String(),
            'user_id' => $impersonated->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame($actor->id, $response->json('data.user.id'));
        $this->assertDatabaseHas('activities', [
            'id' => $response->json('data.id'),
            'user_id' => $actor->id,
        ]);
    }

    public function test_store_rejects_nonexistent_activityable_id(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'Görüşme',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'company',
            'activityable_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('activityable_id', $response->json('errors.fields'));
    }

    /**
     * KARAR: yalnızca oluşturan kişi VEYA `activities.delete` iznine sahip
     * bir yönetici silebilir — bkz. ActivityPolicy::delete() dokümanı.
     */
    public function test_creator_can_delete_own_activity_without_delete_permission(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $activity = Activity::factory()->create(['user_id' => $actor->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/activities/{$activity->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('activities', ['id' => $activity->id]);
    }

    public function test_manager_with_delete_permission_can_delete_others_activity(): void
    {
        $manager = $this->actorWithPermissions(['activities.delete']);
        $activity = Activity::factory()->create(['user_id' => User::factory()->create()->id]);

        $response = $this->actingAs($manager)->deleteJson("/api/activities/{$activity->id}");

        $response->assertStatus(204);
    }

    public function test_user_without_delete_permission_cannot_delete_others_activity(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $activity = Activity::factory()->create(['user_id' => User::factory()->create()->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/activities/{$activity->id}");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Liste sözleşmesi
    // -------------------------------------------------------------------

    public function test_index_returns_pagination_meta(): void
    {
        $actor = $this->actorWithPermissions(['activities.view']);
        Activity::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/activities?per_page=2');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('meta.pagination.per_page'));
    }

    public function test_index_falls_back_to_default_sort_when_sort_is_not_whitelisted(): void
    {
        $actor = $this->actorWithPermissions(['activities.view']);
        Activity::factory()->create();

        $response = $this->actingAs($actor)->getJson('/api/activities?sort=malicious_column');

        $response->assertStatus(200);
    }

    public function test_search_query_does_not_leak_records_outside_other_filters(): void
    {
        $actor = $this->actorWithPermissions(['activities.view']);

        $matching = Activity::factory()->create(['subject' => 'ACME görüşmesi', 'type' => 'call']);
        Activity::factory()->create(['subject' => 'ACME görüşmesi', 'type' => 'email']);

        $response = $this->actingAs($actor)->getJson('/api/activities?q=ACME&filter[type]=call');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$matching->id], $ids);
    }

    public function test_filter_activityable_type_rejects_values_outside_whitelist(): void
    {
        $actor = $this->actorWithPermissions(['activities.view']);

        $response = $this->actingAs($actor)->getJson(
            '/api/activities?'.http_build_query(['filter' => ['activityable_type' => 'App\\Models\\User']])
        );

        $response->assertStatus(422);
    }

    public function test_deleted_activityable_target_does_not_break_listing(): void
    {
        $actor = $this->actorWithPermissions(['activities.view']);
        $contact = Contact::factory()->create();
        $activity = Activity::factory()->create([
            'activityable_type' => Contact::class,
            'activityable_id' => $contact->id,
        ]);
        $contact->delete();

        $response = $this->actingAs($actor)->getJson('/api/activities');

        $response->assertStatus(200);
        $body = collect($response->json('data'))->firstWhere('id', $activity->id);
        $this->assertNotNull($body);
        $this->assertNull($body['activityable']);
    }

    /**
     * N+1 regresyon testi — activityable (MorphTo) eager loading'i sayfadaki
     * DİSTİNCT activityable_type sayısı kadar sorgu üretmeli.
     */
    public function test_index_does_not_execute_n_plus_one_queries_for_morph_relations(): void
    {
        $actor = $this->actorWithPermissions(['activities.view']);
        $deal = Deal::factory()->create();
        $company = Company::factory()->create();

        Activity::factory()->count(3)->create(['activityable_type' => Deal::class, 'activityable_id' => $deal->id]);
        Activity::factory()->count(3)->create(['activityable_type' => Company::class, 'activityable_id' => $company->id]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/activities?per_page=50');

        $response->assertStatus(200);

        $this->assertLessThan(
            20,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') - N+1 şüphesi:'.PHP_EOL.implode(PHP_EOL, $queries)
        );

        $dealQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, '`deals`'))->count();
        $companyQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, '`companies`'))->count();

        $this->assertSame(1, $dealQueries, 'deal activityable ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
        $this->assertSame(1, $companyQueries, 'company activityable ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
    }

    // -------------------------------------------------------------------
    // SLA entegrasyonu — Ticket şeridinin SlaService::recordFirstResponse()
    // -------------------------------------------------------------------

    public function test_call_activity_on_ticket_sets_first_response_at(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $ticket = Ticket::factory()->create(['first_response_at' => null]);

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'Müşteri ile görüşüldü',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'ticket',
            'activityable_id' => $ticket->id,
        ]);

        $response->assertStatus(201);
        $ticket->refresh();
        $this->assertNotNull($ticket->first_response_at);
    }

    public function test_note_activity_on_ticket_does_not_set_first_response_at(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $ticket = Ticket::factory()->create(['first_response_at' => null]);

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'note',
            'subject' => 'İç not',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'ticket',
            'activityable_id' => $ticket->id,
        ]);

        $response->assertStatus(201);
        $ticket->refresh();
        $this->assertNull($ticket->first_response_at);
    }

    public function test_second_call_activity_does_not_change_first_response_at(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $ticket = Ticket::factory()->create(['first_response_at' => null]);

        $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'İlk görüşme',
            'occurred_at' => now()->subMinutes(10)->toIso8601String(),
            'activityable_type' => 'ticket',
            'activityable_id' => $ticket->id,
        ])->assertStatus(201);

        $ticket->refresh();
        $firstResponseAt = $ticket->first_response_at;
        $this->assertNotNull($firstResponseAt);

        $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'İkinci görüşme',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'ticket',
            'activityable_id' => $ticket->id,
        ])->assertStatus(201);

        $ticket->refresh();
        $this->assertTrue($firstResponseAt->equalTo($ticket->first_response_at));
    }

    public function test_call_activity_on_deal_does_not_affect_any_ticket(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $deal = Deal::factory()->create();
        $ticket = Ticket::factory()->create(['first_response_at' => null]);

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'Fırsat görüşmesi',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'deal',
            'activityable_id' => $deal->id,
        ]);

        $response->assertStatus(201);
        $ticket->refresh();
        $this->assertNull($ticket->first_response_at);
    }

    /**
     * SLA damgası atılamasa bile (SlaService beklenmedik bir istisna
     * fırlatsa BİLE) aktivite kaydı başarısız OLMAMALI — bkz.
     * ActivityService::maybeRecordFirstResponse() dokümanı ("aktivite
     * birincil iş, SLA damgası yan etki").
     *
     * SlaService'in KENDİSİ değiştirilmiyor (şerit sahipliği dışı); container
     * binding'i yalnızca bu test için, gerçek sınıfı GENİŞLETEN anonim bir
     * sınıfla (aynı public sözleşme, `recordFirstResponse()` fırlatıyor)
     * geçici olarak değiştiriyor.
     */
    public function test_activity_creation_succeeds_even_if_sla_service_throws(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $ticket = Ticket::factory()->create(['first_response_at' => null]);

        $this->app->bind(SlaService::class, function () {
            return new class extends SlaService
            {
                public function recordFirstResponse(Ticket $ticket): void
                {
                    throw new \RuntimeException('SLA servisi beklenmedik şekilde başarısız oldu (test).');
                }
            };
        });

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'Görüşme',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'ticket',
            'activityable_id' => $ticket->id,
        ]);

        $response->assertStatus(201);
        $ticket->refresh();
        $this->assertNull($ticket->first_response_at);
    }

    /**
     * Hedef zaten silinmişse (soft delete) StoreActivityRequest'in varlık
     * kontrolü zaten 422 ile reddeder — silinmiş bir kayda YENİ bir aktivite
     * bağlanamaz. Burada asıl doğrulanan: bu durumda istek asla 500 ile
     * PATLAMAZ, MorphTo `null` dönüp `instanceof Ticket` kontrolü sessizce
     * false olur.
     */
    public function test_activity_creation_against_deleted_target_fails_gracefully_not_with_server_error(): void
    {
        $actor = $this->actorWithPermissions(['activities.create']);
        $ticket = Ticket::factory()->create();
        $ticketId = $ticket->id;
        $ticket->delete();

        $response = $this->actingAs($actor)->postJson('/api/activities', [
            'type' => 'call',
            'subject' => 'Görüşme',
            'occurred_at' => now()->toIso8601String(),
            'activityable_type' => 'ticket',
            'activityable_id' => $ticketId,
        ]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Regresyon kilidi — Faz 14 denetimi: seed/factory verisi API
    // sözleşmesini (StoreActivityRequest) ihlal EDEBİLİYORDU ('visit' /
    // 'task'), bunlar backend validasyonundan asla geçemeyeceği için
    // yalnız bulk-insert/factory validasyon-bypass'ıyla DB'ye giriyor ve
    // frontend'de literal eşlemesi olmayan tipler için `ActivityTypeBadge`'i
    // çökertiyordu. Kabul edilen küme burada `StoreActivityRequest::rules()`
    // İÇİNDEN (bir kopyadan DEĞİL) türetilir ki kural değişirse test de
    // otomatik güncel kalsın.
    // -------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function acceptedActivityTypes(): array
    {
        $rules = (new StoreActivityRequest())->rules();
        $typeRule = collect($rules['type'])->first(
            fn ($rule) => $rule instanceof \Illuminate\Validation\Rules\In
        );

        $this->assertNotNull($typeRule, "StoreActivityRequest'in 'type' kuralında Rule::in bulunamadı.");

        preg_match_all('/"([^"]*)"/', (string) $typeRule, $matches);

        $this->assertNotEmpty($matches[1], "StoreActivityRequest 'type' kabul kümesi ayrıştırılamadı.");

        return $matches[1];
    }

    public function test_activity_factory_only_produces_types_accepted_by_store_request(): void
    {
        $acceptedTypes = $this->acceptedActivityTypes();

        // 300 tekrar: factory'nin randomElement havuzunda kabul edilmeyen bir değer kalmışsa
        // (eskiden 'task'/'visit' gibi) bu sayıda denemede pratik olarak kesin yakalanır.
        $producedTypes = Activity::factory()->count(300)->make()->pluck('type')->unique();

        foreach ($producedTypes as $type) {
            $this->assertContains(
                $type,
                $acceptedTypes,
                "ActivityFactory, StoreActivityRequest'in reddedeceği bir tip üretti: '{$type}'."
            );
        }
    }

    public function test_demo_data_seeder_activity_types_are_accepted_by_store_request(): void
    {
        $acceptedTypes = $this->acceptedActivityTypes();

        // DemoDataSeeder::seedActivities() tam seed olmadan çalıştırılamaz (companies/contacts/
        // deals/tickets'e bağımlı morphPool() gerektirir); bunun yerine seeder'ın kaynağının
        // ta kendisi olan ACTIVITY_TYPES sabiti okunur (bkz. o sabitin dokümantasyonu).
        $seederTypes = (new ReflectionClass(DemoDataSeeder::class))->getConstant('ACTIVITY_TYPES');

        $this->assertNotEmpty($seederTypes, 'DemoDataSeeder::ACTIVITY_TYPES bulunamadı veya boş.');

        foreach ($seederTypes as $type) {
            $this->assertContains(
                $type,
                $acceptedTypes,
                "DemoDataSeeder::ACTIVITY_TYPES, StoreActivityRequest'in reddedeceği bir tip içeriyor: '{$type}'."
            );
        }
    }
}
