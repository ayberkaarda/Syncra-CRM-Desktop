<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rol/izin sözlüğünü kur — ayrı test veritabanı (phpunit.xml), ana
        // syncra_crm verisine dokunulmaz.
        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithRole(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if (! empty($extraPermissions)) {
            $user->givePermissionTo($extraPermissions);
        }

        return $user;
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
        $response = $this->getJson('/api/leads');

        $response->assertStatus(401);
    }

    public function test_user_without_leads_view_permission_cannot_list_leads(): void
    {
        $actor = User::factory()->create(); // rolsüz / izinsiz

        $response = $this->actingAs($actor)->getJson('/api/leads');

        $response->assertStatus(403);
    }

    public function test_user_without_leads_view_permission_cannot_show_lead(): void
    {
        $actor = User::factory()->create();
        $lead = Lead::factory()->create();

        $response = $this->actingAs($actor)->getJson("/api/leads/{$lead->id}");

        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Liste: sayfalama, sıralama, arama, filtreler
    // -------------------------------------------------------------------

    public function test_list_returns_pagination_meta(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        Lead::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/leads?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonCount(2, 'data');
    }

    public function test_invalid_sort_column_falls_back_to_default(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        Lead::factory()->create(['first_name' => 'Eski', 'created_at' => now()->subDays(2)]);
        Lead::factory()->create(['first_name' => 'Yeni', 'created_at' => now()]);

        // 'notes' beyaz listede yok -> sessizce -created_at'e düşmeli.
        $response = $this->actingAs($actor)->getJson('/api/leads?sort=notes');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('first_name')->all();
        $this->assertSame(['Yeni', 'Eski'], $names);
    }

    public function test_search_does_not_leak_records_outside_other_filters(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);

        // q eşleşir ama status eşleşmez -> dönmemeli.
        Lead::factory()->create(['first_name' => 'Ahmet', 'status' => 'contacted']);
        // q ve status ikisi de eşleşir -> dönmeli.
        $match = Lead::factory()->create(['first_name' => 'Ahmet', 'status' => 'new']);
        // q eşleşmez -> dönmemeli.
        Lead::factory()->create(['first_name' => 'Mehmet', 'status' => 'new']);

        $response = $this->actingAs($actor)->getJson('/api/leads?q=Ahmet&filter[status]=new');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($match->id, $response->json('data.0.id'));
    }

    public function test_filter_by_status(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        Lead::factory()->create(['status' => 'new']);
        $qualified = Lead::factory()->create(['status' => 'qualified']);

        $response = $this->actingAs($actor)->getJson('/api/leads?filter[status]=qualified');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($qualified->id, $response->json('data.0.id'));
    }

    public function test_filter_by_source(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        Lead::factory()->create(['source' => 'referral']);
        $website = Lead::factory()->create(['source' => 'website']);

        $response = $this->actingAs($actor)->getJson('/api/leads?filter[source]=website');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($website->id, $response->json('data.0.id'));
    }

    public function test_filter_by_owner_id(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        $owner = User::factory()->create();
        Lead::factory()->create(['owner_id' => null]);
        $owned = Lead::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($actor)->getJson("/api/leads?filter[owner_id]={$owner->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($owned->id, $response->json('data.0.id'));
    }

    public function test_filter_by_score_range(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        Lead::factory()->create(['score' => 10]);
        $inRange = Lead::factory()->create(['score' => 55]);
        Lead::factory()->create(['score' => 95]);

        $response = $this->actingAs($actor)->getJson('/api/leads?filter[score_min]=40&filter[score_max]=60');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($inRange->id, $response->json('data.0.id'));
    }

    /**
     * N+1 regresyon testi — `tags` ve `customFieldValues` artık gerçek
     * Eloquent eager loading ile (`with([...])`) yükleniyor (bkz.
     * LeadRepository). Bu test, lead sayısı arttıkça sorgu sayısının DA
     * artmadığını kanıtlar: N+1 olsaydı 10 lead için en az +20 ek sorgu
     * (10 tags + 10 customFieldValues) gerekirdi.
     */
    public function test_list_does_not_execute_n_plus_one_queries_for_tags_and_custom_fields(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        $tag = Tag::factory()->create();
        $field = CustomField::factory()->create([
            'entity_type' => 'leads',
            'key' => 'oncelik',
        ]);

        $leads = Lead::factory()->count(10)->create();
        foreach ($leads as $lead) {
            $lead->tags()->attach($tag->id);
            $lead->customFieldValues()->create([
                'custom_field_id' => $field->id,
                'value' => 'yuksek',
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/leads?per_page=25');

        $response->assertStatus(200)->assertJsonCount(10, 'data');

        // Eager loading ile sorgu sayısı SABİTTİR (count + select + owner +
        // tags + customFieldValues + customField + izin/oturum sorguları).
        // N+1 olsaydı en az 10 (tags) + 10 (customFieldValues) = 20 EK sorgu
        // gerekirdi; eşik 10 lead sayısının altında tutuldu.
        $this->assertLessThan(
            10,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') - N+1 şüphesi:
'.implode('
', $queries)
        );

        // Ek kanıt: 'taggables' ve 'custom_field_values' tablolarına karşı
        // TAM OLARAK birer eager-load sorgusu (WHERE IN ...) çalıştı, lead
        // başına bir sorgu değil.
        $taggableQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'taggables'))->count();
        $customFieldValueQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'custom_field_values'))->count();

        $this->assertSame(1, $taggableQueries, 'tags ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
        $this->assertSame(1, $customFieldValueQueries, 'customFieldValues ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
    }

    public function test_filter_by_tag_id(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        $tag = Tag::factory()->create();

        $tagged = Lead::factory()->create();
        $tagged->morphToMany(Tag::class, 'taggable')->attach($tag->id);

        Lead::factory()->create(); // etiketsiz

        $response = $this->actingAs($actor)->getJson("/api/leads?filter[tag_id]={$tag->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($tagged->id, $response->json('data.0.id'));
    }

    // -------------------------------------------------------------------
    // Oluşturma
    // -------------------------------------------------------------------

    public function test_user_without_leads_create_permission_cannot_create_lead(): void
    {
        $actor = User::factory()->create();

        $response = $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Yeni',
            'last_name' => 'Aday',
            'source' => 'website',
        ]);

        $response->assertStatus(403);
    }

    public function test_valid_lead_can_be_created(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);

        $response = $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Yeni',
            'last_name' => 'Aday',
            'email' => 'yeni.aday@example.com',
            'source' => 'website',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.first_name', 'Yeni')
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('leads', ['email' => 'yeni.aday@example.com']);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);

        $response = $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Yeni',
            'last_name' => 'Aday',
            'source' => 'invalid-source',
        ]);

        $response->assertStatus(422);
    }

    public function test_create_binds_tag_ids(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);
        $tags = Tag::factory()->count(2)->create();

        $response = $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Etiketli',
            'last_name' => 'Aday',
            'source' => 'referral',
            'tag_ids' => $tags->pluck('id')->all(),
        ]);

        $response->assertStatus(201);

        $leadId = $response->json('data.id');
        $tagIds = collect($response->json('data.tags'))->pluck('id')->sort()->values()->all();

        $this->assertSame($tags->pluck('id')->sort()->values()->all(), $tagIds);
        $this->assertDatabaseHas('taggables', [
            'taggable_id' => $leadId,
            'taggable_type' => Lead::class,
            'tag_id' => $tags->first()->id,
        ]);
    }

    public function test_create_saves_custom_fields(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);
        $field = CustomField::factory()->create([
            'entity_type' => 'leads',
            'key' => 'butce',
            'type' => 'text',
        ]);

        $response = $this->actingAs($actor)->postJson('/api/leads', [
            'first_name' => 'Özel',
            'last_name' => 'Alanlı',
            'source' => 'other',
            'custom_fields' => ['butce' => '50000-100000'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.custom_fields.butce', '50000-100000');

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $field->id,
            'customizable_type' => Lead::class,
            'value' => '50000-100000',
        ]);
    }

    // -------------------------------------------------------------------
    // Güncelleme / dönüşüm koruması
    // -------------------------------------------------------------------

    public function test_status_cannot_be_set_directly_to_converted(): void
    {
        $actor = $this->actorWithPermissions(['leads.update']);
        $lead = Lead::factory()->create(['status' => 'new']);

        $response = $this->actingAs($actor)->patchJson("/api/leads/{$lead->id}", [
            'status' => 'converted',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'new']);
    }

    public function test_converted_lead_cannot_be_updated(): void
    {
        $actor = $this->actorWithPermissions(['leads.update']);
        $lead = Lead::factory()->converted()->create();

        $response = $this->actingAs($actor)->patchJson("/api/leads/{$lead->id}", [
            'notes' => 'değişiklik denemesi',
        ]);

        $response->assertStatus(403);
    }

    public function test_converted_lead_cannot_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['leads.delete']);
        $lead = Lead::factory()->converted()->create();

        $response = $this->actingAs($actor)->deleteJson("/api/leads/{$lead->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'deleted_at' => null]);
    }

    public function test_non_converted_lead_can_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['leads.delete']);
        $lead = Lead::factory()->create(['status' => 'new']);

        $response = $this->actingAs($actor)->deleteJson("/api/leads/{$lead->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
    }

    // -------------------------------------------------------------------
    // check-duplicates
    // -------------------------------------------------------------------

    public function test_check_duplicates_requires_at_least_one_field(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);

        $response = $this->actingAs($actor)->postJson('/api/leads/check-duplicates', []);

        $response->assertStatus(422);
    }

    public function test_check_duplicates_finds_candidate_by_email(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);
        $existing = Lead::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->actingAs($actor)->postJson('/api/leads/check-duplicates', [
            'email' => 'duplicate@example.com',
        ]);

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($existing->id, $ids);
    }

    public function test_check_duplicates_route_is_not_captured_by_lead_id_binding(): void
    {
        $actor = $this->actorWithPermissions(['leads.create']);

        // Gövde boş olduğundan 422 bekleniyor (en az bir alan zorunlu) — ama
        // KRİTİK olan durum kodu değil, isteğin gerçekten checkDuplicates'e
        // gitmesi: {lead} route-model-binding'ine düşseydi Lead::class'ın
        // 'check-duplicates' id'siyle aranmaya çalışılması söz konusu bile
        // olamazdı çünkü o rota yalnızca GET/PATCH/DELETE kabul eder; bu POST
        // isteğinin 404 DEĞİL 422 dönmesi doğru uca gittiğini kanıtlar.
        $response = $this->actingAs($actor)->postJson('/api/leads/check-duplicates', []);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // assign
    // -------------------------------------------------------------------

    public function test_user_without_leads_assign_permission_cannot_assign(): void
    {
        $actor = $this->actorWithPermissions(['leads.view']);
        $lead = Lead::factory()->create();
        $newOwner = User::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/leads/{$lead->id}/assign", [
            'owner_id' => $newOwner->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_lead_can_be_assigned(): void
    {
        $actor = $this->actorWithPermissions(['leads.assign']);
        $lead = Lead::factory()->create(['owner_id' => null]);
        $newOwner = User::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/leads/{$lead->id}/assign", [
            'owner_id' => $newOwner->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.owner.id', $newOwner->id);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'owner_id' => $newOwner->id]);
    }
}
