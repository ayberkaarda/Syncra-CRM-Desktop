<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyApiTest extends TestCase
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

    public function test_guest_cannot_access_companies(): void
    {
        $this->getJson('/api/companies')->assertStatus(401);
    }

    public function test_user_without_permission_cannot_list_companies(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/companies')->assertStatus(403);
    }

    public function test_user_without_permission_cannot_view_company(): void
    {
        $actor = User::factory()->create();
        $company = Company::factory()->create();

        $this->actingAs($actor)->getJson("/api/companies/{$company->id}")->assertStatus(403);
    }

    public function test_user_without_permission_cannot_create_company(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->postJson('/api/companies', ['name' => 'Yeni A.Ş.'])
            ->assertStatus(403);
    }

    public function test_izleyici_cannot_update_or_delete_company(): void
    {
        $actor = $this->actorWithRole('İzleyici');
        $company = Company::factory()->create();

        $this->actingAs($actor)->patchJson("/api/companies/{$company->id}", ['name' => 'Yeni'])
            ->assertStatus(403);

        $this->actingAs($actor)->deleteJson("/api/companies/{$company->id}")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Liste sözleşmesi
    // -------------------------------------------------------------------

    public function test_index_returns_pagination_meta(): void
    {
        $actor = $this->actorWithRole('Admin');
        Company::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/companies?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.total', 3);
        $response->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_invalid_sort_column_silently_falls_back_to_default(): void
    {
        $actor = $this->actorWithRole('Admin');

        $older = Company::factory()->create();
        $older->forceFill(['created_at' => now()->subDays(5)])->save();

        $newer = Company::factory()->create();
        $newer->forceFill(['created_at' => now()])->save();

        $response = $this->actingAs($actor)->getJson('/api/companies?sort=not_a_real_column');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame($newer->id, $ids[0]);
        $this->assertSame($older->id, $ids[1]);
    }

    public function test_search_term_does_not_leak_across_other_filters(): void
    {
        $actor = $this->actorWithRole('Admin');

        Company::factory()->create([
            'name' => 'Acme Teknoloji',
            'industry' => 'Bilişim',
        ]);

        // q "acme" ile eşleşiyor ama industry filtresine göre farklı bir sektörde.
        // Parantezli gruplama doğruysa sonuç 0 olmalı; OR sızıntısı varsa 1 döner.
        $response = $this->actingAs($actor)->getJson('/api/companies?q=acme&filter[industry]=Tekstil');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_filters_industry_owner_city_and_country(): void
    {
        $actor = $this->actorWithRole('Admin');
        $owner = User::factory()->create();

        $matching = Company::factory()->create([
            'industry' => 'Bilişim',
            'owner_id' => $owner->id,
            'city' => 'İstanbul',
            'country' => 'Türkiye',
        ]);

        Company::factory()->create([
            'industry' => 'Tekstil',
            'owner_id' => null,
            'city' => 'Ankara',
            'country' => 'Türkiye',
        ]);

        $byIndustry = $this->actingAs($actor)->getJson('/api/companies?filter[industry]=Bilişim');
        $byIndustry->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($matching->id, $byIndustry->json('data.0.id'));

        $byOwner = $this->actingAs($actor)->getJson("/api/companies?filter[owner_id]={$owner->id}");
        $byOwner->assertStatus(200)->assertJsonCount(1, 'data');

        $byCity = $this->actingAs($actor)->getJson('/api/companies?filter[city]=İstanbul');
        $byCity->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_soft_deleted_company_does_not_appear_in_list(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();
        $company->delete();

        $response = $this->actingAs($actor)->getJson('/api/companies');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_index_includes_counts_and_primary_contact(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();
        $primary = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);
        Contact::factory()->create(['company_id' => $company->id, 'is_primary' => false]);
        Deal::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($actor)->getJson('/api/companies');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.contacts_count', 2);
        $response->assertJsonPath('data.0.deals_count', 1);
        $response->assertJsonPath('data.0.primary_contact.id', $primary->id);
    }

    /**
     * N+1 doğrulaması: `Company` modelinde artık gerçek bir `tags()` ilişkisi
     * var (M1 tarafından eklendi) — `with(['tags'])` ile TEK sorguda gelmeli.
     * `primary_contact` ise CompanyRepository'de bilerek bir ilişki değil,
     * elle toplu (batch) bir sorgu (bkz. `attachPrimaryContacts()`) — o da
     * firma sayısından bağımsız olarak TEK sorgu üretmeli.
     */
    public function test_listing_companies_eager_loads_tags_and_batches_primary_contact_without_n_plus_one(): void
    {
        $actor = $this->actorWithRole('Admin');
        $tag = Tag::factory()->create();

        Company::factory()->count(10)->create()->each(function (Company $company) use ($tag) {
            $company->tags()->attach($tag->id);
            Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);
        });

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/companies?per_page=10');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));

        $tagQueries = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'taggables')));
        $this->assertCount(
            1,
            $tagQueries,
            "Etiketler tek sorguda eager-load edilmeli (N+1 değil). Çalışan `taggables` sorguları:\n".implode("\n", $tagQueries)
        );

        $primaryContactQueries = array_values(array_filter(
            $queries,
            fn ($sql) => str_contains($sql, 'is_primary') && str_contains($sql, 'contacts')
        ));
        $this->assertCount(
            1,
            $primaryContactQueries,
            "primary_contact tek toplu sorguda getirilmeli (N+1 değil). Çalışan sorgular:\n".implode("\n", $primaryContactQueries)
        );
    }

    // -------------------------------------------------------------------
    // Oluşturma / Güncelleme
    // -------------------------------------------------------------------

    public function test_admin_can_create_company_with_tags_and_custom_fields(): void
    {
        $actor = $this->actorWithRole('Admin');
        $tag = Tag::factory()->create();
        $field = CustomField::factory()->create([
            'entity_type' => 'companies',
            'key' => 'vergi_no',
            'type' => 'text',
        ]);

        $response = $this->actingAs($actor)->postJson('/api/companies', [
            'name' => 'Acme Teknoloji',
            'website' => 'https://acme.example.com',
            'tag_ids' => [$tag->id],
            'custom_fields' => ['vergi_no' => '1234567890'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Acme Teknoloji');
        $response->assertJsonPath('data.tags.0.id', $tag->id);
        $response->assertJsonPath('data.custom_fields.vergi_no', '1234567890');

        $companyId = $response->json('data.id');
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_type' => Company::class,
            'taggable_id' => $companyId,
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $field->id,
            'customizable_type' => Company::class,
            'customizable_id' => $companyId,
            'value' => '1234567890',
        ]);
    }

    public function test_create_company_validation_error(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->postJson('/api/companies', [
            'website' => 'not-a-valid-url',
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_update_company(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create(['name' => 'Eski Ad']);

        $response = $this->actingAs($actor)->patchJson("/api/companies/{$company->id}", [
            'name' => 'Yeni Ad',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Yeni Ad');
    }

    public function test_setting_second_contact_of_company_as_primary_unsets_the_first_via_contacts_endpoint(): void
    {
        // Firma altındaki tekillik kuralı ContactService'te uygulanır; burada
        // firma perspektifinden doğrulanır: firma iki kişiye sahip olduğunda
        // primary_contact her zaman tek bir kişiyi göstermeli.
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();

        $first = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);
        $second = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => false]);

        $this->actingAs($actor)->patchJson("/api/contacts/{$second->id}", ['is_primary' => true])
            ->assertStatus(200);

        $response = $this->actingAs($actor)->getJson("/api/companies/{$company->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.primary_contact.id', $second->id);
        $this->assertFalse($first->fresh()->is_primary);
    }

    public function test_company_with_open_deal_cannot_be_deleted(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();
        Deal::factory()->create(['company_id' => $company->id, 'status' => 'open']);

        $response = $this->actingAs($actor)->deleteJson("/api/companies/{$company->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'deleted_at' => null]);
    }

    public function test_company_without_open_deal_can_be_deleted(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();
        Deal::factory()->create(['company_id' => $company->id, 'status' => 'lost']);

        $response = $this->actingAs($actor)->deleteJson("/api/companies/{$company->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    // -------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------

    public function test_timeline_includes_related_contacts_activities(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $companyActivity = Activity::factory()->create([
            'activityable_type' => Company::class,
            'activityable_id' => $company->id,
            'occurred_at' => now()->subDays(1),
        ]);

        $contactActivity = Activity::factory()->create([
            'activityable_type' => Contact::class,
            'activityable_id' => $contact->id,
            'occurred_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($actor)->getJson("/api/companies/{$company->id}/timeline");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))
            ->where('type', 'activity')
            ->pluck('id')
            ->all();

        $this->assertContains($companyActivity->id, $ids);
        $this->assertContains($contactActivity->id, $ids);
    }

    public function test_timeline_is_sorted_by_occurred_at_descending(): void
    {
        $actor = $this->actorWithRole('Admin');
        $company = Company::factory()->create();

        Activity::factory()->create([
            'activityable_type' => Company::class,
            'activityable_id' => $company->id,
            'occurred_at' => now()->subDays(10),
        ]);

        Activity::factory()->create([
            'activityable_type' => Company::class,
            'activityable_id' => $company->id,
            'occurred_at' => now()->subDays(1),
        ]);

        $deal = Deal::factory()->create(['company_id' => $company->id]);
        $deal->forceFill(['created_at' => now()->subDays(5)])->save();

        $response = $this->actingAs($actor)->getJson("/api/companies/{$company->id}/timeline");

        $response->assertStatus(200);
        $occurredAtValues = collect($response->json('data'))->pluck('occurred_at')
            ->map(fn ($value) => strtotime($value))
            ->all();

        $sorted = $occurredAtValues;
        rsort($sorted);
        $this->assertSame($sorted, $occurredAtValues, 'Timeline en yeni kayıt önce olacak şekilde sıralı olmalı.');
    }

    public function test_guest_cannot_access_timeline(): void
    {
        $company = Company::factory()->create();

        $this->getJson("/api/companies/{$company->id}/timeline")->assertStatus(401);
    }
}
