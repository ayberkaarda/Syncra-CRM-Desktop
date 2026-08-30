<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    // -------------------------------------------------------------------
    // Yetkilendirme
    // -------------------------------------------------------------------

    public function test_guest_cannot_access_contacts(): void
    {
        $this->getJson('/api/contacts')->assertStatus(401);
    }

    public function test_user_without_permission_cannot_list_contacts(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/contacts')->assertStatus(403);
    }

    public function test_user_without_permission_cannot_view_contact(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}")->assertStatus(403);
    }

    public function test_user_without_permission_cannot_create_contact(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->postJson('/api/contacts', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertStatus(403);
    }

    public function test_izleyici_cannot_update_or_delete_contact(): void
    {
        $actor = $this->actorWithRole('İzleyici');
        $contact = Contact::factory()->create();

        $this->actingAs($actor)->patchJson("/api/contacts/{$contact->id}", ['first_name' => 'Yeni'])
            ->assertStatus(403);

        $this->actingAs($actor)->deleteJson("/api/contacts/{$contact->id}")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Liste sözleşmesi
    // -------------------------------------------------------------------

    public function test_index_returns_pagination_meta(): void
    {
        $actor = $this->actorWithRole('Admin');
        Contact::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/contacts?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.total', 3);
        $response->assertJsonPath('meta.pagination.last_page', 2);
        $response->assertJsonPath('meta.pagination.current_page', 1);
    }

    public function test_invalid_sort_column_silently_falls_back_to_default(): void
    {
        $actor = $this->actorWithRole('Admin');

        $older = Contact::factory()->create();
        $older->forceFill(['created_at' => now()->subDays(5)])->save();

        $newer = Contact::factory()->create();
        $newer->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($actor)->getJson('/api/contacts?sort=not_a_real_column');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        // Hata fırlatmadan -created_at'e (en yeni önce) düşer.
        $this->assertSame($newer->id, $ids[0]);
        $this->assertSame($older->id, $ids[1]);
    }

    public function test_search_term_does_not_leak_across_other_filters(): void
    {
        $actor = $this->actorWithRole('Admin');

        $companyX = Company::factory()->create();
        $companyY = Company::factory()->create();

        Contact::factory()->create([
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'company_id' => $companyX->id,
        ]);

        // q "ada" ile eşleşiyor ama company_id filtresine göre farklı bir firmada.
        // Parantezli gruplama doğruysa sonuç 0 olmalı; OR sızıntısı varsa 1 döner.
        $response = $this->actingAs($actor)->getJson("/api/contacts?q=ada&filter[company_id]={$companyY->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_filters_company_owner_is_primary_and_city(): void
    {
        $actor = $this->actorWithRole('Admin');
        $owner = User::factory()->create();
        $company = Company::factory()->create();

        $matching = Contact::factory()->create([
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'is_primary' => true,
            'city' => 'İstanbul',
        ]);

        Contact::factory()->create([
            'company_id' => null,
            'owner_id' => null,
            'is_primary' => false,
            'city' => 'Ankara',
        ]);

        $byCompany = $this->actingAs($actor)->getJson("/api/contacts?filter[company_id]={$company->id}");
        $byCompany->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($matching->id, $byCompany->json('data.0.id'));

        $byOwner = $this->actingAs($actor)->getJson("/api/contacts?filter[owner_id]={$owner->id}");
        $byOwner->assertStatus(200)->assertJsonCount(1, 'data');

        $byPrimary = $this->actingAs($actor)->getJson('/api/contacts?filter[is_primary]=1');
        $byPrimary->assertStatus(200)->assertJsonCount(1, 'data');

        $byCity = $this->actingAs($actor)->getJson('/api/contacts?filter[city]=İstanbul');
        $byCity->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_soft_deleted_contact_does_not_appear_in_list(): void
    {
        $actor = $this->actorWithRole('Admin');
        $contact = Contact::factory()->create();
        $contact->delete();

        $response = $this->actingAs($actor)->getJson('/api/contacts');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    /**
     * N+1 doğrulaması: `Contact` modelinde artık gerçek bir `tags()` ilişkisi
     * var (M1 tarafından eklendi). `ContactRepository::paginate()` bunu
     * `with(['tags'])` ile eager-load ediyor — 25 kişi, 25 ayrı sorgu değil,
     * TEK bir `tags`/`taggables` join sorgusu üretmeli.
     */
    public function test_listing_contacts_eager_loads_tags_without_n_plus_one(): void
    {
        $actor = $this->actorWithRole('Admin');
        $tag = Tag::factory()->create();

        Contact::factory()->count(25)->create()->each(function (Contact $contact) use ($tag) {
            $contact->tags()->attach($tag->id);
        });

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/contacts?per_page=25');

        $response->assertStatus(200);
        $this->assertCount(25, $response->json('data'));

        $tagQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'taggables')));

        $this->assertCount(
            1,
            $tagQueries,
            "Etiketler tek sorguda eager-load edilmeli (N+1 değil). Çalışan `taggables` sorguları:\n".implode("\n", $tagQueries)
        );
    }

    // -------------------------------------------------------------------
    // Oluşturma / Güncelleme
    // -------------------------------------------------------------------

    public function test_admin_can_create_contact_with_tags_and_custom_fields(): void
    {
        $actor = $this->actorWithRole('Admin');
        $tag = Tag::factory()->create();
        $field = CustomField::factory()->create([
            'entity_type' => 'contacts',
            'key' => 'test_alani',
            'type' => 'text',
        ]);

        $response = $this->actingAs($actor)->postJson('/api/contacts', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'tag_ids' => [$tag->id],
            'custom_fields' => ['test_alani' => 'değer'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.full_name', 'Ada Lovelace');
        $response->assertJsonPath('data.tags.0.id', $tag->id);
        $response->assertJsonPath('data.custom_fields.test_alani', 'değer');

        $contactId = $response->json('data.id');
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_type' => Contact::class,
            'taggable_id' => $contactId,
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $field->id,
            'customizable_type' => Contact::class,
            'customizable_id' => $contactId,
            'value' => 'değer',
        ]);
    }

    public function test_create_contact_validation_error(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->postJson('/api/contacts', [
            'last_name' => 'Lovelace',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_update_contact(): void
    {
        $actor = $this->actorWithRole('Admin');
        $contact = Contact::factory()->create(['first_name' => 'Eski']);

        $response = $this->actingAs($actor)->patchJson("/api/contacts/{$contact->id}", [
            'first_name' => 'Yeni',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.first_name', 'Yeni');
    }

    public function test_setting_second_contact_as_primary_unsets_the_first(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();

        $first = Contact::factory()->create([
            'company_id' => $company->id,
            'is_primary' => true,
        ]);

        $second = Contact::factory()->create([
            'company_id' => $company->id,
            'is_primary' => false,
        ]);

        $response = $this->actingAs($actor)->patchJson("/api/contacts/{$second->id}", [
            'is_primary' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_primary', true);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_contact_with_open_deal_cannot_be_deleted(): void
    {
        $actor = $this->actorWithRole('Admin');
        $contact = Contact::factory()->create();
        Deal::factory()->create(['contact_id' => $contact->id, 'status' => 'open']);

        $response = $this->actingAs($actor)->deleteJson("/api/contacts/{$contact->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'deleted_at' => null]);
    }

    public function test_contact_without_open_deal_can_be_deleted(): void
    {
        $actor = $this->actorWithRole('Admin');
        $contact = Contact::factory()->create();
        Deal::factory()->create(['contact_id' => $contact->id, 'status' => 'won']);

        $response = $this->actingAs($actor)->deleteJson("/api/contacts/{$contact->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    // -------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------

    public function test_timeline_returns_sorted_multi_source_history(): void
    {
        $actor = $this->actorWithRole('Admin');
        $contact = Contact::factory()->create();

        $activity = Activity::factory()->create([
            'activityable_type' => Contact::class,
            'activityable_id' => $contact->id,
            'occurred_at' => now()->subDays(1),
        ]);

        $task = Task::factory()->create([
            'taskable_type' => Contact::class,
            'taskable_id' => $contact->id,
            'due_at' => now()->subDays(3),
        ]);

        $ticket = Ticket::factory()->create([
            'contact_id' => $contact->id,
        ]);
        $ticket->forceFill(['created_at' => now()->subDays(2)])->save();

        $deal = Deal::factory()->create(['contact_id' => $contact->id]);
        $deal->forceFill(['created_at' => now()->subDays(4)])->save();

        $response = $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}/timeline");

        $response->assertStatus(200);
        $types = collect($response->json('data'))->pluck('type')->all();

        $this->assertContains('activity', $types);
        $this->assertContains('task', $types);
        $this->assertContains('ticket', $types);
        $this->assertContains('deal', $types);

        $occurredAtValues = collect($response->json('data'))->pluck('occurred_at')
            ->map(fn ($value) => strtotime($value))
            ->all();
        $sorted = $occurredAtValues;
        rsort($sorted);
        $this->assertSame($sorted, $occurredAtValues, 'Timeline en yeni kayıt önce olacak şekilde sıralı olmalı.');
    }

    public function test_guest_cannot_access_timeline(): void
    {
        $contact = Contact::factory()->create();

        $this->getJson("/api/contacts/{$contact->id}/timeline")->assertStatus(401);
    }
}
