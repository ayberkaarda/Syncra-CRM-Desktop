<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET|POST|PATCH|DELETE /api/price-lists*` — uç sözleşmesi, izinler,
 * `is_default` tekilliği, varsayılan listenin silinememesi, kalem
 * yönetimi (`setPrice`/`removePrice`) ve `unique(price_list_id, product_id)`.
 */
class PriceListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    //
    // PriceListPolicy izinleri AYRI bir `price-lists.*` ailesi üzerinden
    // DEĞİL, `products.*` üzerinden çalışır (gerekçe PriceListPolicy'de) —
    // bu yüzden testler de `products.*` izinlerini kullanır.
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/price-lists')->assertStatus(401);
    }

    public function test_user_without_products_view_permission_cannot_list_price_lists(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/price-lists')->assertStatus(403);
    }

    public function test_user_without_products_create_permission_cannot_create_price_list(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);

        $this->actingAs($actor)->postJson('/api/price-lists', ['name' => 'X', 'code' => 'X1'])
            ->assertStatus(403);
    }

    public function test_user_without_products_update_permission_cannot_update_price_list(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $priceList = PriceList::factory()->create();

        $this->actingAs($actor)->patchJson("/api/price-lists/{$priceList->id}", ['name' => 'Y'])
            ->assertStatus(403);
    }

    public function test_user_without_products_delete_permission_cannot_delete_price_list(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $priceList = PriceList::factory()->create();

        $this->actingAs($actor)->deleteJson("/api/price-lists/{$priceList->id}")
            ->assertStatus(403);
    }

    public function test_user_without_products_update_permission_cannot_set_price(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($actor)->putJson("/api/price-lists/{$priceList->id}/products/{$product->id}", ['unit_price' => 10])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Liste / filtre
    // -------------------------------------------------------------------

    public function test_list_returns_paginated_price_lists(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        PriceList::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/price-lists');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure(['data', 'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);
    }

    public function test_filter_by_is_active(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        PriceList::factory()->create(['is_active' => true]);
        PriceList::factory()->inactive()->create();

        $response = $this->actingAs($actor)->getJson('/api/price-lists?filter[is_active]=0');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertFalse($response->json('data.0.is_active'));
    }

    public function test_filter_by_is_default(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        PriceList::factory()->default()->create();
        PriceList::factory()->create();

        $response = $this->actingAs($actor)->getJson('/api/price-lists?filter[is_default]=1');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertTrue($response->json('data.0.is_default'));
    }

    public function test_search_matches_name_and_code(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        PriceList::factory()->create(['name' => 'Perakende Listesi', 'code' => 'PERAKENDE']);
        PriceList::factory()->create(['name' => 'Toptan Listesi', 'code' => 'TOPTAN']);

        $response = $this->actingAs($actor)->getJson('/api/price-lists?q=TOPTAN');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'TOPTAN');
    }

    // -------------------------------------------------------------------
    // Oluşturma / güncelleme / kod benzersizliği
    // -------------------------------------------------------------------

    public function test_admin_can_create_price_list(): void
    {
        $actor = $this->actorWithPermissions(['products.create', 'products.view']);

        $response = $this->actingAs($actor)->postJson('/api/price-lists', [
            'name' => 'Perakende Fiyat Listesi',
            'code' => 'PERAKENDE',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'PERAKENDE');
        $this->assertDatabaseHas('price_lists', ['code' => 'PERAKENDE']);
    }

    public function test_code_must_be_unique(): void
    {
        $actor = $this->actorWithPermissions(['products.create']);
        PriceList::factory()->create(['code' => 'DUP']);

        $response = $this->actingAs($actor)->postJson('/api/price-lists', [
            'name' => 'İkinci Liste',
            'code' => 'DUP',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('code', $response->json('errors.fields'));
    }

    public function test_valid_until_must_not_precede_valid_from(): void
    {
        $actor = $this->actorWithPermissions(['products.create']);

        $response = $this->actingAs($actor)->postJson('/api/price-lists', [
            'name' => 'Kampanya',
            'code' => 'KAMP',
            'valid_from' => now()->addDays(10)->toDateString(),
            'valid_until' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('valid_until', $response->json('errors.fields'));
    }

    // -------------------------------------------------------------------
    // is_default tekilliği
    // -------------------------------------------------------------------

    public function test_creating_a_second_default_price_list_unsets_the_first(): void
    {
        $actor = $this->actorWithPermissions(['products.create', 'products.view']);
        $first = PriceList::factory()->default()->create();

        $response = $this->actingAs($actor)->postJson('/api/price-lists', [
            'name' => 'Yeni Varsayılan',
            'code' => 'YENIVARSAYILAN',
            'is_default' => true,
        ]);

        $response->assertStatus(201);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($response->json('data.is_default'));
    }

    public function test_updating_a_price_list_to_default_unsets_the_previous_default(): void
    {
        $actor = $this->actorWithPermissions(['products.update', 'products.view']);
        $first = PriceList::factory()->default()->create();
        $second = PriceList::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/price-lists/{$second->id}", [
            'is_default' => true,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    // -------------------------------------------------------------------
    // Silme kuralları
    // -------------------------------------------------------------------

    public function test_default_price_list_cannot_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['products.delete']);
        $priceList = PriceList::factory()->default()->create();

        $response = $this->actingAs($actor)->deleteJson("/api/price-lists/{$priceList->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('price_lists', ['id' => $priceList->id, 'deleted_at' => null]);
    }

    public function test_non_default_price_list_can_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['products.delete']);
        $priceList = PriceList::factory()->create();

        $response = $this->actingAs($actor)->deleteJson("/api/price-lists/{$priceList->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('price_lists', ['id' => $priceList->id]);
    }

    /**
     * DÜZELTME: önceki sürüm burada "liste silinince kalemler cascade
     * silinir" iddiasındaydı ve `PriceListRepository::delete()` bunu kalemleri
     * ELLE silerek sağlıyordu. Bu YANLIŞTI: `PriceList` `SoftDeletes`
     * kullanır, yani silme geri alınabilir olmalıdır. Kalemleri kalıcı
     * silersek liste `restore()` edildiğinde fiyatların tamamı kaybolmuş
     * olarak geri gelirdi — soft delete'in tam olarak önlemesi gereken şey.
     * Proje deseniyle tutarlı: quotes (softDeletes) → quote_items
     * (cascadeOnDelete), conversations (softDeletes) → messages
     * (cascadeOnDelete) — ikisinde de kalemler soft delete'te elle silinmez.
     */
    public function test_soft_deleting_a_price_list_preserves_its_items_and_restore_brings_them_back(): void
    {
        $actor = $this->actorWithPermissions(['products.delete']);
        $priceList = PriceList::factory()->create();
        $item = PriceListItem::factory()->create(['price_list_id' => $priceList->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/price-lists/{$priceList->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('price_lists', ['id' => $priceList->id]);
        // Kalem hâlâ tabloda duruyor — cascade tetiklenmedi (UPDATE, DELETE değil).
        $this->assertDatabaseHas('price_list_items', ['id' => $item->id]);

        $priceList->restore();

        $this->assertDatabaseHas('price_list_items', [
            'id' => $item->id,
            'price_list_id' => $priceList->id,
            'unit_price' => $item->unit_price,
        ]);
    }

    /**
     * FK'daki `cascadeOnDelete` gerçekten çalışıyor — yalnızca GERÇEK bir
     * SQL DELETE'te (yani `forceDelete()`), soft delete'in UPDATE'inde DEĞİL.
     */
    public function test_force_deleting_a_price_list_cascades_its_items(): void
    {
        $priceList = PriceList::factory()->create();
        $item = PriceListItem::factory()->create(['price_list_id' => $priceList->id]);

        $priceList->forceDelete();

        $this->assertDatabaseMissing('price_list_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('price_lists', ['id' => $priceList->id]);
    }

    // -------------------------------------------------------------------
    // Kalem yönetimi: setPrice / removePrice
    // -------------------------------------------------------------------

    public function test_set_price_creates_a_new_item(): void
    {
        $actor = $this->actorWithPermissions(['products.update', 'products.view']);
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create(['unit_price' => 500]);

        $response = $this->actingAs($actor)->putJson(
            "/api/price-lists/{$priceList->id}/products/{$product->id}",
            ['unit_price' => 425]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.product_id', $product->id);
        $response->assertJsonPath('data.unit_price', 425);
        $response->assertJsonPath('data.catalog_price', 500);
        $this->assertDatabaseHas('price_list_items', [
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 425,
        ]);
    }

    public function test_set_price_upserts_existing_item_without_creating_duplicate(): void
    {
        $actor = $this->actorWithPermissions(['products.update']);
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create();
        PriceListItem::factory()->create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 100,
        ]);

        $response = $this->actingAs($actor)->putJson(
            "/api/price-lists/{$priceList->id}/products/{$product->id}",
            ['unit_price' => 250]
        );

        $response->assertStatus(200);
        $this->assertDatabaseCount('price_list_items', 1);
        $this->assertDatabaseHas('price_list_items', ['unit_price' => 250]);
    }

    public function test_remove_price_deletes_the_item_but_not_the_product(): void
    {
        $actor = $this->actorWithPermissions(['products.update']);
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/price-lists/{$priceList->id}/products/{$product->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('price_list_items', ['price_list_id' => $priceList->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    public function test_products_endpoint_lists_items_with_catalog_price(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create(['name' => 'Test Ürün', 'sku' => 'SKU-T1', 'unit_price' => 300]);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id, 'unit_price' => 275]);

        $response = $this->actingAs($actor)->getJson("/api/price-lists/{$priceList->id}/products");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.product_id', $product->id);
        $response->assertJsonPath('data.0.product_name', 'Test Ürün');
        $response->assertJsonPath('data.0.product_sku', 'SKU-T1');
        $response->assertJsonPath('data.0.unit_price', 275);
        $response->assertJsonPath('data.0.catalog_price', 300);
    }

    // -------------------------------------------------------------------
    // unique(price_list_id, product_id)
    // -------------------------------------------------------------------

    public function test_unique_constraint_rejects_duplicate_price_list_item_at_database_level(): void
    {
        // API'nin tek yazma yolu (`setPrice`) bir upsert olduğu için asla bu
        // ihlali üretmez — bu yüzden kısıt doğrudan veritabanı seviyesinde,
        // migration'daki `unique(['price_list_id','product_id'])` üzerinden
        // doğrulanır.
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id]);

        $this->expectException(QueryException::class);

        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id]);
    }

    public function test_items_count_reflects_number_of_priced_products(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->count(4)->create(['price_list_id' => $priceList->id]);

        $response = $this->actingAs($actor)->getJson("/api/price-lists/{$priceList->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.items_count', 4);
    }
}
