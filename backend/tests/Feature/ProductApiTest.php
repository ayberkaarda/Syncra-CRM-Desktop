<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET|POST|PATCH|DELETE /api/products*` — uç sözleşmesi, izinler, liste
 * davranışı, kategori ucu ve fiyat çözümleme (`/price`).
 *
 * Fiyat listesi CRUD'u PriceListApiTest'tedir.
 */
class ProductApiTest extends TestCase
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
        $this->getJson('/api/products')->assertStatus(401);
        $this->getJson('/api/products/categories')->assertStatus(401);
    }

    public function test_user_without_products_view_permission_cannot_list_products(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/products')->assertStatus(403);
    }

    public function test_user_without_products_create_permission_cannot_create_product(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);

        $this->actingAs($actor)->postJson('/api/products', ['name' => 'X', 'unit_price' => 10])
            ->assertStatus(403);
    }

    public function test_user_without_products_update_permission_cannot_update_product(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create();

        $this->actingAs($actor)->patchJson("/api/products/{$product->id}", ['name' => 'Y'])
            ->assertStatus(403);
    }

    public function test_user_without_products_delete_permission_cannot_delete_product(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create();

        $this->actingAs($actor)->deleteJson("/api/products/{$product->id}")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Route sırası: /products/categories, /products/{product}'ten ÖNCE
    // eşleşmeli
    // -------------------------------------------------------------------

    /**
     * Faz 6 (`leads/check-duplicates`), Faz 7 (`deals/board`) ve Faz 8
     * (`tasks/calendar`, `tickets/stats`) ile AYNI tuzak: sabit segment
     * parametreli rotadan sonra tanımlanırsa Laravel `categories`'i bir
     * product id'si sanır ve route-model-binding 404 üretir. Bu test o
     * sırayı KİLİTLER.
     */
    public function test_categories_route_is_not_captured_by_product_show_route(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['category' => 'Yazılım']);

        $response = $this->actingAs($actor)->getJson('/api/products/categories');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_categories_endpoint_returns_unique_categories(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['category' => 'Yazılım']);
        Product::factory()->create(['category' => 'Yazılım']);
        Product::factory()->create(['category' => 'Donanım']);
        Product::factory()->create(['category' => null]);

        $response = $this->actingAs($actor)->getJson('/api/products/categories');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertContains('Yazılım', $data);
        $this->assertContains('Donanım', $data);
    }

    // -------------------------------------------------------------------
    // Liste / filtre / sıralama / arama
    // -------------------------------------------------------------------

    public function test_list_returns_paginated_products(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->count(3)->create();

        $response = $this->actingAs($actor)->getJson('/api/products');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure(['data', 'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);
    }

    public function test_list_defaults_to_sorting_by_name_ascending(): void
    {
        // Katalog alfabetik göz atmak için isim/artan daha kullanışlıdır —
        // Faz 6/7/8'in aksine (varsayılan -created_at) burada kullanıcı
        // "en yeni ürün"ü değil aradığı ürünü isme göre bulmak ister.
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['name' => 'Zebra Ürün']);
        Product::factory()->create(['name' => 'Alfa Ürün']);
        Product::factory()->create(['name' => 'Mistik Ürün']);

        $response = $this->actingAs($actor)->getJson('/api/products');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Alfa Ürün', 'Mistik Ürün', 'Zebra Ürün'], $names);
    }

    public function test_filter_by_category(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['category' => 'Yazılım']);
        Product::factory()->create(['category' => 'Donanım']);

        $response = $this->actingAs($actor)->getJson('/api/products?filter[category]=Yazılım');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.category', 'Yazılım');
    }

    public function test_filter_by_is_active(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['is_active' => true]);
        Product::factory()->inactive()->create();

        $response = $this->actingAs($actor)->getJson('/api/products?filter[is_active]=0');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertFalse($response->json('data.0.is_active'));
    }

    public function test_filter_by_price_range(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['unit_price' => 100]);
        Product::factory()->create(['unit_price' => 5000]);
        Product::factory()->create(['unit_price' => 10000]);

        $response = $this->actingAs($actor)->getJson('/api/products?filter[price_min]=1000&filter[price_max]=8000');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals(5000, $response->json('data.0.unit_price'));
    }

    public function test_filter_by_in_stock(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['stock_quantity' => 0]);
        Product::factory()->create(['stock_quantity' => 5]);
        Product::factory()->create(['stock_quantity' => null]);

        $response = $this->actingAs($actor)->getJson('/api/products?filter[in_stock]=1');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals(5, $response->json('data.0.stock_quantity'));
    }

    public function test_filter_by_tag(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $tag = Tag::factory()->create();
        $tagged = Product::factory()->create();
        $tagged->tags()->attach($tag->id);
        Product::factory()->create();

        $response = $this->actingAs($actor)->getJson("/api/products?filter[tag_id]={$tag->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $tagged->id);
    }

    public function test_search_matches_name_sku_and_description(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['name' => 'Bulut Depolama', 'sku' => 'SKU-AAA', 'description' => 'Depolama hizmeti']);
        Product::factory()->create(['name' => 'Diğer Ürün', 'sku' => 'SKU-BBB', 'description' => 'Alakasız açıklama']);

        $response = $this->actingAs($actor)->getJson('/api/products?q=Bulut');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Bulut Depolama');
    }

    public function test_sort_whitelist_falls_back_to_default_on_unknown_column(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        Product::factory()->create(['name' => 'B Ürün']);
        Product::factory()->create(['name' => 'A Ürün']);

        // "id" sıralama beyaz listesinde değil — varsayılana (name asc) düşmeli.
        $response = $this->actingAs($actor)->getJson('/api/products?sort=-id');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['A Ürün', 'B Ürün'], $names);
    }

    // -------------------------------------------------------------------
    // N+1 ölçümü
    // -------------------------------------------------------------------

    public function test_index_does_not_trigger_n_plus_one_queries(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $tag = Tag::factory()->create();
        $products = Product::factory()->count(15)->create();

        foreach ($products as $product) {
            $product->tags()->attach($tag->id);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/products?per_page=100');

        $response->assertStatus(200);
        $response->assertJsonCount(15, 'data');

        $this->assertLessThan(
            15,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') — N+1 şüphesi:'.PHP_EOL.implode(PHP_EOL, $queries)
        );

        // tags TAM OLARAK bir sorguda (tüm ürünler için toplu) yüklenmeli.
        $tagQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'taggables'))->count();
        $this->assertSame(1, $tagQueries, 'tags ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
    }

    // -------------------------------------------------------------------
    // Oluşturma / güncelleme / SKU benzersizliği
    // -------------------------------------------------------------------

    public function test_admin_can_create_product(): void
    {
        $actor = $this->actorWithPermissions(['products.create', 'products.view']);

        $response = $this->actingAs($actor)->postJson('/api/products', [
            'name' => 'Yeni Ürün',
            'sku' => 'SKU-NEW-1',
            'unit_price' => 1500.50,
            'category' => 'Yazılım',
            'tax_rate' => 20,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Yeni Ürün');
        $response->assertJsonPath('data.sku', 'SKU-NEW-1');
        $this->assertDatabaseHas('products', ['sku' => 'SKU-NEW-1']);
    }

    public function test_create_product_validation_error(): void
    {
        $actor = $this->actorWithPermissions(['products.create']);

        $response = $this->actingAs($actor)->postJson('/api/products', [
            'unit_price' => -5,
        ]);

        $response->assertStatus(422);
    }

    public function test_sku_must_be_unique(): void
    {
        $actor = $this->actorWithPermissions(['products.create']);
        Product::factory()->create(['sku' => 'SKU-DUP']);

        $response = $this->actingAs($actor)->postJson('/api/products', [
            'name' => 'İkinci Ürün',
            'sku' => 'SKU-DUP',
            'unit_price' => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('sku', $response->json('errors.fields'));
    }

    public function test_multiple_products_can_have_null_sku(): void
    {
        // MySQL unique index null'ları çoklu kabul eder — bu, `sku`'nun
        // `nullable` OLMASININ tam olarak amacıdır: her ürünün SKU'su olmak
        // zorunda değil, ama SKU'su olanlar birbirinden farklı olmalı.
        Product::factory()->create(['sku' => null]);
        Product::factory()->create(['sku' => null]);

        $this->assertDatabaseCount('products', 2);
    }

    public function test_admin_can_update_product(): void
    {
        $actor = $this->actorWithPermissions(['products.update', 'products.view']);
        $product = Product::factory()->create(['name' => 'Eski Ad']);

        $response = $this->actingAs($actor)->patchJson("/api/products/{$product->id}", [
            'name' => 'Yeni Ad',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Yeni Ad');
    }

    public function test_admin_can_delete_product(): void
    {
        // Kasıtlı: kullanılan-ürün silme ENGELİ yok (gerekçe ProductService
        // dokümanında) — soft delete + quote_items.name kopyası zaten
        // geçmiş teklifleri korur.
        $actor = $this->actorWithPermissions(['products.delete']);
        $product = Product::factory()->create();

        $response = $this->actingAs($actor)->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    // -------------------------------------------------------------------
    // Fiyat çözümleme — GET /api/products/{product}/price
    // -------------------------------------------------------------------

    public function test_price_falls_back_to_catalog_when_no_price_list_and_no_default(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 999.99, 'tax_rate' => 18, 'currency' => 'TRY']);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'catalog');
        $response->assertJsonPath('data.unit_price', 999.99);
        $response->assertJsonPath('data.tax_rate', 18);
        $response->assertJsonPath('data.currency', 'TRY');
        $response->assertJsonPath('data.price_list', null);
    }

    public function test_price_uses_default_price_list_when_no_explicit_list_given(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 1000]);
        $priceList = PriceList::factory()->default()->create();
        PriceListItem::factory()->create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 750,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'price_list');
        $response->assertJsonPath('data.unit_price', 750);
        $response->assertJsonPath('data.price_list.id', $priceList->id);
    }

    public function test_price_falls_back_to_catalog_when_default_list_has_no_item_for_product(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 500]);
        PriceList::factory()->default()->create();

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'catalog');
        $response->assertJsonPath('data.unit_price', 500);
    }

    public function test_price_uses_explicit_price_list_id(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 1000]);
        $defaultList = PriceList::factory()->default()->create();
        PriceListItem::factory()->create(['price_list_id' => $defaultList->id, 'product_id' => $product->id, 'unit_price' => 900]);
        $otherList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $otherList->id, 'product_id' => $product->id, 'unit_price' => 650]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price?price_list_id={$otherList->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'price_list');
        $response->assertJsonPath('data.unit_price', 650);
        $response->assertJsonPath('data.price_list.id', $otherList->id);
    }

    public function test_price_falls_back_to_catalog_when_price_list_is_inactive(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 200]);
        $priceList = PriceList::factory()->inactive()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id, 'unit_price' => 150]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price?price_list_id={$priceList->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'catalog');
        $response->assertJsonPath('data.unit_price', 200);
    }

    /**
     * Soft-silinmiş bir liste `is_active=false` ile AYNI muameleyi görür:
     * kullanılamaz sayılır, sessizce kataloğa düşülür — 404/hata FIRLAMAZ.
     * `exists:price_lists,id` doğrulama kuralı soft delete'ten habersizdir
     * (satır hâlâ tabloda), bu yüzden istek 422'de durmaz ve gerçekten
     * ProductService::resolvePrice()'ın `find()` (throw etmeyen) davranışını
     * sınar.
     */
    public function test_price_falls_back_to_catalog_when_price_list_is_soft_deleted(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 200]);
        $priceList = PriceList::factory()->create();
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id, 'unit_price' => 150]);
        $priceList->delete();

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price?price_list_id={$priceList->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'catalog');
        $response->assertJsonPath('data.unit_price', 200);
        $response->assertJsonPath('data.price_list', null);
    }

    public function test_price_falls_back_to_catalog_when_outside_validity_window(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 300]);
        $priceList = PriceList::factory()->create([
            'valid_from' => now()->addDays(5)->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ]);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id, 'unit_price' => 250]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price?price_list_id={$priceList->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'catalog');
        $response->assertJsonPath('data.unit_price', 300);
    }

    public function test_price_within_validity_window_uses_price_list(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 300]);
        $priceList = PriceList::factory()->create([
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id, 'unit_price' => 250]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price?price_list_id={$priceList->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'price_list');
        $response->assertJsonPath('data.unit_price', 250);
    }

    public function test_price_rejects_nonexistent_price_list_id(): void
    {
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create();

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price?price_list_id=999999");

        $response->assertStatus(422);
    }

    public function test_tax_rate_and_currency_always_come_from_product_not_price_list(): void
    {
        // Fiyat listesi yalnızca satış fiyatını ezer; ürünün vergi rejimini
        // veya para birimini DEĞİŞTİRMEZ.
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->create(['unit_price' => 100, 'tax_rate' => 10, 'currency' => 'TRY']);
        $priceList = PriceList::factory()->default()->create(['currency' => 'USD']);
        PriceListItem::factory()->create(['price_list_id' => $priceList->id, 'product_id' => $product->id, 'unit_price' => 80]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price");

        $response->assertStatus(200);
        $response->assertJsonPath('data.tax_rate', 10);
        $response->assertJsonPath('data.currency', 'TRY');
    }

    public function test_inactive_product_price_can_still_be_queried(): void
    {
        // Bilinçli karar: pasif bir ürün YENİ tekliflere eklenemez (bu kısıt
        // teklif tarafında uygulanır), ama mevcut bir teklif kalemini
        // görüntülerken/karşılaştırırken pasif ürünün fiyatına da
        // erişilebilmelidir — bu yüzden burada is_active kontrolü YOK.
        $actor = $this->actorWithPermissions(['products.view']);
        $product = Product::factory()->inactive()->create(['unit_price' => 42]);

        $response = $this->actingAs($actor)->getJson("/api/products/{$product->id}/price");

        $response->assertStatus(200);
        $response->assertJsonPath('data.source', 'catalog');
        $response->assertJsonPath('data.unit_price', 42);
    }
}
