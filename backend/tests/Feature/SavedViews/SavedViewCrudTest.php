<?php

namespace Tests\Feature\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler: CRUD + `query_json` beyaz liste doğrulaması
 * (docs/PHASE-INTL.md §3). "Confused deputy" ve görünürlük/sahiplik güvenlik testleri
 * AYRIDIR (`tests/Feature/Security/SavedViewSecurityTest.php`, docs/PHASE-AUDIT.md §5.4) —
 * bu dosya yalnızca normal CRUD akışını ve doğrulama davranışını kapsar.
 */
class SavedViewCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Bu uygulamanın hata zarfı `{"errors":{"message":...,"code":...,"fields":{field:[...]}}}`
     * şeklindedir (bkz. `bootstrap/app.php` `$apiError`) — desen
     * `AutomationRuleCrudTest::assertHasFieldError()`'dan alındı, ikinci bir yöntem
     * İCAT EDİLMEDİ.
     */
    private function assertHasFieldError(TestResponse $response, string $field): void
    {
        $fields = $response->json('errors.fields') ?? [];
        $this->assertArrayHasKey($field, $fields, "'{$field}' için bir doğrulama hatası bekleniyordu. Gelen alanlar: ".implode(', ', array_keys($fields)));
    }

    // -------------------------------------------------------------------
    // index()
    // -------------------------------------------------------------------

    public function test_index_requires_module_query_param(): void
    {
        $user = $this->actorWithRole('Admin');

        $this->actingAs($user)->getJson('/api/saved-views')->assertStatus(422);
    }

    public function test_index_rejects_unknown_module(): void
    {
        $user = $this->actorWithRole('Admin');

        $this->actingAs($user)->getJson('/api/saved-views?module=not-a-real-module')->assertStatus(422);
    }

    public function test_index_returns_own_private_and_all_shared_views_for_module_but_not_others_private(): void
    {
        $owner = $this->actorWithRole('Admin');
        $otherUser = $this->actorWithRole('Admin');

        $ownPrivate = SavedView::factory()->forModule('deals')->create(['user_id' => $owner->id, 'name' => 'Kendi görünümüm']);
        $ownShared = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $owner->id, 'name' => 'Kendi paylaşılan']);
        $othersShared = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $otherUser->id, 'name' => 'Başkasının paylaşılanı']);
        $othersPrivate = SavedView::factory()->forModule('deals')->create(['user_id' => $otherUser->id, 'name' => 'Başkasının özeli']);
        // Farklı modül - listede HİÇ görünmemeli.
        SavedView::factory()->forModule('leads')->shared()->create(['user_id' => $owner->id, 'name' => 'Leads görünümü']);

        $response = $this->actingAs($owner)->getJson('/api/saved-views?module=deals')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ownPrivate->id));
        $this->assertTrue($ids->contains($ownShared->id));
        $this->assertTrue($ids->contains($othersShared->id));
        $this->assertFalse($ids->contains($othersPrivate->id));
        $this->assertCount(3, $ids);
    }

    // -------------------------------------------------------------------
    // store()
    // -------------------------------------------------------------------

    public function test_store_creates_a_view_and_normalizes_query_json(): void
    {
        $user = $this->actorWithRole('Admin');

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Açık büyük fırsatlar',
            'query_json' => [
                'q' => 'acme',
                'sort' => '-amount',
                'filter' => ['status' => 'open', 'amount_min' => 10000],
            ],
            'is_shared' => false,
        ])->assertCreated();

        $response->assertJsonPath('data.module', 'deals');
        $response->assertJsonPath('data.name', 'Açık büyük fırsatlar');
        $response->assertJsonPath('data.is_shared', false);
        $response->assertJsonPath('data.is_mine', true);
        $response->assertJsonPath('data.query_json.q', 'acme');
        $response->assertJsonPath('data.query_json.sort', '-amount');
        $response->assertJsonPath('data.query_json.filter.status', 'open');

        $this->assertDatabaseHas('saved_views', [
            'user_id' => $user->id,
            'module' => 'deals',
            'name' => 'Açık büyük fırsatlar',
        ]);
    }

    public function test_store_rejects_unknown_filter_key_instead_of_silently_dropping_it(): void
    {
        $user = $this->actorWithRole('Admin');

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Kötü niyetli görünüm',
            'query_json' => [
                'filter' => ['raw_sql' => "1=1; DROP TABLE users;"],
            ],
        ])->assertStatus(422);

        $this->assertHasFieldError($response, 'query_json');
        $this->assertDatabaseMissing('saved_views', ['name' => 'Kötü niyetli görünüm']);
    }

    public function test_store_rejects_unknown_top_level_query_json_key(): void
    {
        $user = $this->actorWithRole('Admin');

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Şüpheli üst seviye alan',
            'query_json' => ['columns' => ['id', 'title']],
        ])->assertStatus(422);

        $this->assertHasFieldError($response, 'query_json');
    }

    public function test_store_rejects_sort_column_not_in_module_whitelist(): void
    {
        $user = $this->actorWithRole('Admin');

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Geçersiz sıralama',
            // `users`.`password` deals tablosunda yok, ama önemli olan bu: DEALS
            // beyaz listesinde OLMAYAN herhangi bir sütun.
            'query_json' => ['sort' => 'owner_id'],
        ])->assertStatus(422);

        $this->assertHasFieldError($response, 'query_json');
    }

    public function test_store_rejects_invalid_filter_value_type(): void
    {
        $user = $this->actorWithRole('Admin');

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Geçersiz değer',
            // `status` yalnız open/won/lost olabilir.
            'query_json' => ['filter' => ['status' => 'definitely-not-a-status']],
        ])->assertStatus(422);

        $this->assertHasFieldError($response, 'query_json');
    }

    public function test_store_rejects_duplicate_name_for_same_user_and_module(): void
    {
        $user = $this->actorWithRole('Admin');
        SavedView::factory()->forModule('deals')->create(['user_id' => $user->id, 'name' => 'Tekrar eden isim']);

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Tekrar eden isim',
            'query_json' => [],
        ])->assertStatus(422);

        $this->assertHasFieldError($response, 'name');
    }

    public function test_store_allows_same_name_for_different_module(): void
    {
        $user = $this->actorWithRole('Admin');
        SavedView::factory()->forModule('deals')->create(['user_id' => $user->id, 'name' => 'Aynı isim']);

        $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'leads',
            'name' => 'Aynı isim',
            'query_json' => [],
        ])->assertCreated();
    }

    public function test_store_requires_module_view_permission(): void
    {
        // Destek Temsilcisi rolü `deals.view` TAŞIMAZ (RolePermissionSeeder).
        $user = $this->actorWithRole('Destek Temsilcisi');

        $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Erişemediğim modül',
            'query_json' => [],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('saved_views', ['name' => 'Erişemediğim modül']);
    }

    // -------------------------------------------------------------------
    // update() / destroy() — sahiplik
    // -------------------------------------------------------------------

    public function test_owner_can_rename_and_toggle_sharing(): void
    {
        $owner = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->create(['user_id' => $owner->id, 'name' => 'Eski ad']);

        $response = $this->actingAs($owner)->patchJson("/api/saved-views/{$view->id}", [
            'name' => 'Yeni ad',
            'is_shared' => true,
        ])->assertOk();

        $response->assertJsonPath('data.name', 'Yeni ad');
        $response->assertJsonPath('data.is_shared', true);
    }

    public function test_owner_can_update_query_json_and_it_is_revalidated(): void
    {
        $owner = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->patchJson("/api/saved-views/{$view->id}", [
            'query_json' => ['filter' => ['not_a_real_field' => 'x']],
        ])->assertStatus(422);
    }

    public function test_non_owner_cannot_update_even_a_shared_view(): void
    {
        $owner = $this->actorWithRole('Admin');
        $otherUser = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $owner->id]);

        $this->actingAs($otherUser)->patchJson("/api/saved-views/{$view->id}", [
            'name' => 'El koyma girişimi',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('saved_views', ['id' => $view->id, 'name' => 'El koyma girişimi']);
    }

    public function test_owner_can_delete_own_view(): void
    {
        $owner = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->deleteJson("/api/saved-views/{$view->id}")->assertNoContent();

        $this->assertDatabaseMissing('saved_views', ['id' => $view->id]);
    }

    public function test_non_owner_cannot_delete_even_a_shared_view(): void
    {
        $owner = $this->actorWithRole('Admin');
        $otherUser = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $owner->id]);

        $this->actingAs($otherUser)->deleteJson("/api/saved-views/{$view->id}")->assertStatus(403);

        $this->assertDatabaseHas('saved_views', ['id' => $view->id]);
    }

    // -------------------------------------------------------------------
    // Okuma anında yeniden doğrulama (sanitizeForRead)
    // -------------------------------------------------------------------

    /**
     * `SavedViewQueryValidator::sanitizeForRead()` bir şema değişikliği/doğrudan DB
     * müdahalesi sonrası artık geçersiz bir anahtarı istemciye HİÇBİR ZAMAN ham
     * göndermez — burada `query_json`'a Eloquent doğrulamasını BYPASS EDEREK (doğrudan
     * update ile) geçersiz bir anahtar yazılır ve okuma yolunun onu süzdüğü doğrulanır.
     */
    public function test_read_path_strips_a_stale_invalid_key_that_bypassed_write_validation(): void
    {
        $owner = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->create(['user_id' => $owner->id]);
        $view->forceFill(['query_json' => ['filter' => ['legacy_removed_field' => 'x', 'status' => 'open']]])->saveQuietly();

        $response = $this->actingAs($owner)->getJson('/api/saved-views?module=deals')->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $view->id);
        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('legacy_removed_field', $item['query_json']['filter']);
        $this->assertSame('open', $item['query_json']['filter']['status']);
    }
}
