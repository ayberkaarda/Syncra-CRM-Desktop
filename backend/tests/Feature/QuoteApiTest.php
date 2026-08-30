<?php

namespace Tests\Feature;

use App\Http\Requests\Quotes\CalculateQuoteRequest;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET|POST|PATCH|DELETE /api/quotes*` — uç sözleşmesi, izinler, liste
 * davranışı, tutar kilidi ve revizyon zinciri.
 *
 * Para hesabının kendisi (docs/QUOTE-FINANCIALS.md §8'in 13 kabul kriteri)
 * QuoteCalculatorTest'tedir; buradaki sayısal beklentiler o kriterlerin UÇTAN
 * UCA doğrulanmış hâlidir — aynı senaryo HTTP üzerinden geçtiğinde de aynı
 * kuruşları üretmelidir.
 */
class QuoteApiTest extends TestCase
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
     * Faz 9'un ON ucu da `routes/api.php`'de kayıtlı olmalıdır.
     *
     * Bu test bir ZAMANLAR gerekli olan geçici bir rota şiminin yerini aldı:
     * `/revise` ve `/pdf` uçları paralel çalışan başka bir şeridin dosyasında
     * henüz tanımlı değilken testler kendi rotalarını kaydediyordu. Rotalar
     * eklendiği için şim kaldırıldı; geriye kalan, aynı rotaların ileride
     * SESSİZCE silinmesini engelleyen bu kilit. Bir uç kaybolursa buradaki
     * liste, testlerin geri kalanının 404 yağmuruna dönüşmesinden ÖNCE
     * uyarır.
     */
    public function test_all_quote_routes_are_registered(): void
    {
        foreach ([
            'quotes.index', 'quotes.store', 'quotes.show', 'quotes.update', 'quotes.destroy',
            'quotes.send', 'quotes.status', 'quotes.revise', 'quotes.pdf', 'quotes.calculate',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Rota tanımlı değil: {$name}");
        }
    }

    /**
     * Para alanları için TAM eşitlik.
     *
     * `assertJsonPath` katı karşılaştırma yapar ve JSON, `230.00`'ı `230`
     * (int) olarak çözer — bu yüzden değer önce `float`'a çevrilip
     * `assertSame` ile karşılaştırılır. Tolerans YOKTUR
     * (docs/QUOTE-FINANCIALS.md §4).
     */
    protected function assertMoney(TestResponse $response, string $path, float $expected): void
    {
        $actual = $response->json($path);

        $this->assertIsNumeric($actual, $path.' sayısal bir değer değil.');
        $this->assertSame($expected, (float) $actual, $path.' beklenen tutarı taşımıyor.');
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

    protected function fullActor(): User
    {
        return $this->actorWithPermissions([
            'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.send',
        ]);
    }

    /**
     * §8.1 ana senaryosunun kalem gövdesi: 180.00 + 50.00 = 230.00.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function scenarioItems(): array
    {
        return [
            ['name' => 'Danışmanlık', 'quantity' => 2, 'unit_price' => 100.00, 'discount_percent' => 10, 'tax_rate' => 20],
            ['name' => 'Eğitim', 'quantity' => 1, 'unit_price' => 50.00, 'discount_percent' => 0, 'tax_rate' => 10],
        ];
    }

    protected function quoteWithItems(string $status = 'draft'): Quote
    {
        $quote = Quote::factory()->create(['status' => $status]);

        QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'quantity' => 1,
            'unit_price' => 100.00,
            'discount_percent' => 0,
            'tax_rate' => 20,
            'line_total' => 100.00,
            'position' => 1,
        ]);

        return $quote->fresh();
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $quote = Quote::factory()->create();

        $this->getJson('/api/quotes')->assertStatus(401);
        $this->getJson("/api/quotes/{$quote->id}")->assertStatus(401);
        $this->postJson('/api/quotes', ['title' => 'Test'])->assertStatus(401);
        $this->postJson("/api/quotes/{$quote->id}/send")->assertStatus(401);
    }

    public function test_user_without_quotes_view_permission_cannot_list_quotes(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/quotes')->assertStatus(403);
    }

    public function test_user_without_quotes_view_permission_cannot_show_quote(): void
    {
        $quote = Quote::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/quotes/{$quote->id}")
            ->assertStatus(403);
    }

    public function test_user_without_quotes_create_permission_cannot_store_quote(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/quotes', ['title' => 'Test'])
            ->assertStatus(403);
    }

    public function test_user_without_quotes_update_permission_cannot_update_quote(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);
        $quote = Quote::factory()->create();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['title' => 'Güncel'])
            ->assertStatus(403);
    }

    public function test_user_without_quotes_update_permission_cannot_change_status(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);
        $quote = Quote::factory()->create(['status' => 'sent']);

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'accepted'])
            ->assertStatus(403);
    }

    public function test_user_without_quotes_delete_permission_cannot_destroy_quote(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);
        $quote = Quote::factory()->create();

        $this->actingAs($actor)->deleteJson("/api/quotes/{$quote->id}")->assertStatus(403);
    }

    /**
     * `quotes.send` AYRI bir izindir: teklifi düzenleyebilen herkes onu
     * müşteriye gönderemez (QuotePolicy::send dokümanı).
     */
    public function test_user_without_quotes_send_permission_cannot_send_quote(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view', 'quotes.update']);
        $quote = $this->quoteWithItems();

        $this->actingAs($actor)->postJson("/api/quotes/{$quote->id}/send")->assertStatus(403);
    }

    /**
     * Revizyon YENİ kayıt üretir; bu yüzden `quotes.create` ister,
     * `quotes.update` yetmez.
     */
    public function test_user_without_quotes_create_permission_cannot_revise_quote(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view', 'quotes.update']);
        $quote = $this->quoteWithItems('sent');

        $this->actingAs($actor)->postJson("/api/quotes/{$quote->id}/revise")->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Oluşturma ve para hesabı (uçtan uca)
    // -------------------------------------------------------------------

    public function test_store_computes_totals_from_items(): void
    {
        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Karışık oranlı teklif',
            'items' => $this->scenarioItems(),
            'discount_type' => 'amount',
            'discount_value' => 30.00,
        ]);

        $response->assertStatus(201);

        // docs/QUOTE-FINANCIALS.md §8.1 ile BİREBİR aynı kuruşlar.
        $this->assertMoney($response, 'data.subtotal', 230.00);
        $this->assertMoney($response, 'data.discount_amount', 30.00);
        $this->assertMoney($response, 'data.tax_amount', 35.65);
        $this->assertMoney($response, 'data.total', 235.65);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.revision', 1);
        $response->assertJsonPath('data.parent_quote_id', null);
        $response->assertJsonPath('data.items_count', 2);
        $this->assertMoney($response, 'data.items.0.line_total', 180.00);
        $this->assertMoney($response, 'data.items.1.line_total', 50.00);

        // §8.9: KDV dahil sütunu türetilir, kolonu yoktur.
        $this->assertMoney($response, 'data.items.0.line_gross', 216.00);
        $this->assertMoney($response, 'data.items.1.line_gross', 55.00);

        // §3: oran bazlı matrah özeti.
        $this->assertMoney($response, 'data.tax_breakdown.0.rate', 20.0);
        $this->assertMoney($response, 'data.tax_breakdown.0.discount', 23.48);
        $this->assertMoney($response, 'data.tax_breakdown.1.rate', 10.0);
        $this->assertMoney($response, 'data.tax_breakdown.1.discount', 6.52);
    }

    /**
     * §8.5: yüzde tipi indirimde `discount_amount` SUNUCU tarafından
     * hesaplanır ve ham yüzde (`discount_value`) korunur.
     */
    public function test_store_with_percent_discount_computes_the_amount(): void
    {
        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Yüzde indirimli',
            'items' => [['name' => 'Lisans', 'quantity' => 1, 'unit_price' => 1234.56, 'tax_rate' => 20]],
            'discount_type' => 'percent',
            'discount_value' => 5.00,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.discount_type', 'percent');
        $this->assertMoney($response, 'data.discount_value', 5.0);
        $this->assertMoney($response, 'data.discount_amount', 61.73);
        $this->assertMoney($response, 'data.tax_amount', 234.57);
        $this->assertMoney($response, 'data.total', 1407.40);
    }

    /**
     * Yüzde tipinde kalem eklendiğinde `discount_amount` YENİDEN hesaplanır —
     * `discount_value` olmadan "%5" anlamını yitirirdi (sözleşme §5).
     */
    public function test_percent_discount_is_recomputed_when_items_change(): void
    {
        $actor = $this->fullActor();

        $created = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Yüzde indirimli',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 1000.00, 'tax_rate' => 20]],
            'discount_type' => 'percent',
            'discount_value' => 5.00,
        ])->json('data');

        $this->assertSame(50.00, (float) $created['discount_amount']);

        $updated = $this->actingAs($actor)->patchJson("/api/quotes/{$created['id']}", [
            'items' => [
                ['name' => 'A', 'quantity' => 1, 'unit_price' => 1000.00, 'tax_rate' => 20],
                ['name' => 'B', 'quantity' => 1, 'unit_price' => 1000.00, 'tax_rate' => 20],
            ],
        ])->assertStatus(200)->json('data');

        $this->assertSame(2000.00, (float) $updated['subtotal']);
        $this->assertSame(100.00, (float) $updated['discount_amount']);
    }

    /**
     * §8.8: `discount_amount > subtotal` reddedilir ve KAYIT YAZILMAZ.
     */
    public function test_discount_greater_than_subtotal_is_rejected_and_nothing_is_persisted(): void
    {
        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Aşırı indirim',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
            'discount_type' => 'amount',
            'discount_value' => 100.01,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'QUOTE_DISCOUNT_EXCEEDS_SUBTOTAL');

        $this->assertSame(0, Quote::count());
        $this->assertSame(0, QuoteItem::count());
    }

    public function test_negative_item_values_are_rejected(): void
    {
        $actor = $this->fullActor();

        $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Negatif miktar',
            'items' => [['name' => 'A', 'quantity' => -1, 'unit_price' => 100.00, 'tax_rate' => 20]],
        ])->assertStatus(422);

        $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Negatif fiyat',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => -100.00, 'tax_rate' => 20]],
        ])->assertStatus(422);

        $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Negatif yüzde',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'discount_percent' => -5, 'tax_rate' => 20]],
        ])->assertStatus(422);
    }

    public function test_percent_discount_above_100_is_rejected(): void
    {
        $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Aşırı yüzde',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
            'discount_type' => 'percent',
            'discount_value' => 100.01,
        ])->assertStatus(422);
    }

    /**
     * Toplamlar İSTEMCİDEN ALINMAZ: gönderilen `total`/`subtotal`/
     * `line_total` 422 üretir (StoreQuoteRequest'te `line_total` `missing`,
     * başlık toplamları ise hiç tanımlı değil ve sessizce düşer).
     */
    public function test_client_supplied_line_total_is_rejected(): void
    {
        $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Elle toplam',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20, 'line_total' => 999999.00]],
        ])->assertStatus(422);
    }

    public function test_client_supplied_header_totals_are_ignored(): void
    {
        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Elle toplam',
            'quote_number' => 'HACK-1',
            'status' => 'accepted',
            'subtotal' => 999999.00,
            'total' => 999999.00,
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
        ]);

        $response->assertStatus(201);
        $this->assertMoney($response, 'data.subtotal', 100.00);
        $this->assertMoney($response, 'data.total', 120.00);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.quote_number', 'QTE-000001');
    }

    // -------------------------------------------------------------------
    // Ürün anlık kopyası
    // -------------------------------------------------------------------

    public function test_item_snapshots_product_values_at_creation_time(): void
    {
        $product = Product::factory()->create([
            'name' => 'CRM Lisansı',
            'description' => 'Yıllık kullanım',
            'unit_price' => 500.00,
            'tax_rate' => 10,
        ]);

        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Ürünlü teklif',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.items.0.name', 'CRM Lisansı');
        $response->assertJsonPath('data.items.0.description', 'Yıllık kullanım');
        $this->assertMoney($response, 'data.items.0.unit_price', 500.00);
        $this->assertMoney($response, 'data.items.0.tax_rate', 10.0);
        $this->assertMoney($response, 'data.items.0.line_total', 1000.00);
    }

    /**
     * Kopyalanan değer VARSAYILANDIR, KİLİT DEĞİL: pazarlıkla verilen özel
     * fiyat ürün fiyatını ezer.
     */
    public function test_user_supplied_values_override_the_product_snapshot(): void
    {
        $product = Product::factory()->create(['name' => 'CRM Lisansı', 'unit_price' => 500.00, 'tax_rate' => 20]);

        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Özel fiyat',
            'items' => [[
                'product_id' => $product->id,
                'name' => 'CRM Lisansı (özel)',
                'unit_price' => 400.00,
                'tax_rate' => 10,
                'quantity' => 1,
            ]],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.items.0.name', 'CRM Lisansı (özel)');
        $this->assertMoney($response, 'data.items.0.unit_price', 400.00);
        $this->assertMoney($response, 'data.items.0.tax_rate', 10.0);
    }

    public function test_free_form_item_without_product_requires_a_name(): void
    {
        $actor = $this->fullActor();

        $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Adsız kalem',
            'items' => [['quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
        ])->assertStatus(422);

        $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Serbest kalem',
            'items' => [['name' => 'Özel iş', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
        ])->assertStatus(201);
    }

    /**
     * ÜRÜN SİLİNSE BİLE KALEM BOZULMAZ (Faz 3 kararı).
     *
     * İki silme biçimi de sınanır: soft delete'te `product_id` durur, kalıcı
     * silmede `nullOnDelete` ile null'a düşer — HER İKİ durumda da kalemin
     * adı ve fiyatı yerinde kalır, çünkü bunlar ürünün ANLIK KOPYASIDIR.
     */
    public function test_item_survives_product_deletion(): void
    {
        $actor = $this->fullActor();
        $product = Product::factory()->create(['name' => 'Silinecek Ürün', 'unit_price' => 250.00, 'tax_rate' => 20]);

        $quoteId = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Ürünlü teklif',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->json('data.id');

        $product->delete(); // soft delete

        $afterSoft = $this->actingAs($actor)->getJson("/api/quotes/{$quoteId}")->assertStatus(200);
        $afterSoft->assertJsonPath('data.items.0.name', 'Silinecek Ürün');
        $this->assertMoney($afterSoft, 'data.items.0.unit_price', 250.00);
        $this->assertMoney($afterSoft, 'data.items.0.line_total', 1000.00);
        $afterSoft->assertJsonPath('data.items.0.product_id', $product->id);
        $this->assertMoney($afterSoft, 'data.total', 1200.00);

        $product->forceDelete(); // kalıcı silme → FK nullOnDelete

        $afterForce = $this->actingAs($actor)->getJson("/api/quotes/{$quoteId}")->assertStatus(200);
        $afterForce->assertJsonPath('data.items.0.name', 'Silinecek Ürün');
        $this->assertMoney($afterForce, 'data.items.0.unit_price', 250.00);
        $this->assertMoney($afterForce, 'data.items.0.line_total', 1000.00);
        $afterForce->assertJsonPath('data.items.0.product_id', null);
        $this->assertMoney($afterForce, 'data.total', 1200.00);
    }

    // -------------------------------------------------------------------
    // Güncelleme ve tutar kilidi
    // -------------------------------------------------------------------

    public function test_update_replaces_items_and_recomputes_totals(): void
    {
        $actor = $this->fullActor();

        $quoteId = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'İlk hâli',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
        ])->json('data.id');

        $response = $this->actingAs($actor)->patchJson("/api/quotes/{$quoteId}", [
            'title' => 'Güncel hâli',
            'items' => [
                ['name' => 'B', 'quantity' => 3, 'unit_price' => 2500.00, 'tax_rate' => 20],
                ['name' => 'C', 'quantity' => 10, 'unit_price' => 120.00, 'discount_percent' => 5, 'tax_rate' => 10],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Güncel hâli');
        // §8.4 senaryosu.
        $this->assertMoney($response, 'data.subtotal', 8640.00);
        $this->assertMoney($response, 'data.tax_amount', 1614.00);
        $this->assertMoney($response, 'data.total', 10254.00);
        $response->assertJsonPath('data.items_count', 2);

        // Eski kalem gerçekten silindi (quote_items softDeletes taşımaz).
        $this->assertSame(2, QuoteItem::where('quote_id', $quoteId)->count());
        $this->assertSame(0, QuoteItem::where('name', 'A')->count());
    }

    public function test_update_positions_follow_the_submitted_order(): void
    {
        $actor = $this->fullActor();

        $quoteId = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Sıra',
            'items' => [
                ['name' => 'Birinci', 'quantity' => 1, 'unit_price' => 10.00, 'tax_rate' => 20],
                ['name' => 'İkinci', 'quantity' => 1, 'unit_price' => 20.00, 'tax_rate' => 20],
            ],
        ])->json('data.id');

        $response = $this->actingAs($actor)->getJson("/api/quotes/{$quoteId}");

        $response->assertJsonPath('data.items.0.name', 'Birinci');
        $response->assertJsonPath('data.items.0.position', 1);
        $response->assertJsonPath('data.items.1.name', 'İkinci');
        $response->assertJsonPath('data.items.1.position', 2);
    }

    public function test_client_supplied_item_position_is_rejected(): void
    {
        $this->actingAs($this->fullActor())->postJson('/api/quotes', [
            'title' => 'Elle sıra',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20, 'position' => 7]],
        ])->assertStatus(422);
    }

    /**
     * KRİTİK İŞ KURALI: gönderilmiş bir teklifin KALEMLERİ DEĞİŞTİRİLEMEZ.
     */
    public function test_sent_quote_items_cannot_be_changed(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('sent');

        $response = $this->actingAs($actor)->patchJson("/api/quotes/{$quote->id}", [
            'items' => [['name' => 'Yeni', 'quantity' => 1, 'unit_price' => 1.00, 'tax_rate' => 20]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'QUOTE_LOCKED');

        $this->assertSame(1, QuoteItem::where('quote_id', $quote->id)->count());
        $this->assertSame(0, QuoteItem::where('name', 'Yeni')->count());
    }

    public function test_sent_quote_discount_cannot_be_changed(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('sent');

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['discount_value' => 10.00])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_LOCKED');

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['discount_type' => 'percent'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_LOCKED');

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['currency' => 'EUR'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_LOCKED');
    }

    /**
     * Gönderilmiş teklifte SUNUM alanları hâlâ düzenlenebilir; kilit yalnızca
     * tutarı etkileyen alanlardadır.
     */
    public function test_sent_quote_still_allows_presentation_fields(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('sent');

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['title' => 'Yeni başlık', 'notes' => 'Not'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Yeni başlık');
    }

    public function test_general_patch_rejects_status_field(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}", ['status' => 'accepted'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['status']]]);

        $this->assertSame('draft', $quote->fresh()->status);
    }

    public function test_general_patch_rejects_computed_total_fields(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total', 'quote_number', 'revision', 'parent_quote_id'] as $field) {
            $this->actingAs($actor)
                ->patchJson("/api/quotes/{$quote->id}", [$field => 1])
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['fields' => [$field]]]);
        }
    }

    // -------------------------------------------------------------------
    // Gönderim ve durum akışı
    // -------------------------------------------------------------------

    public function test_send_moves_draft_to_sent_and_stamps_the_time(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        $response = $this->actingAs($actor)->postJson("/api/quotes/{$quote->id}/send");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'sent');
        $this->assertNotNull($response->json('data.sent_at'));
    }

    public function test_quote_without_items_cannot_be_sent(): void
    {
        $actor = $this->fullActor();
        $quote = Quote::factory()->create(['status' => 'draft']);

        $response = $this->actingAs($actor)->postJson("/api/quotes/{$quote->id}/send");

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'QUOTE_HAS_NO_ITEMS');
        $this->assertSame('draft', $quote->fresh()->status);
    }

    public function test_already_sent_quote_cannot_be_sent_again(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('sent');

        $this->actingAs($actor)
            ->postJson("/api/quotes/{$quote->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_status_endpoint_accepts_and_stamps_acceptance(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('sent');

        $response = $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'accepted', 'reason' => 'Müşteri onayladı']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull($response->json('data.accepted_at'));
    }

    public function test_status_endpoint_stamps_rejection(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('sent');

        $response = $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'rejected']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'rejected');
        $this->assertNotNull($response->json('data.rejected_at'));
    }

    /**
     * `sent` bu uçtan verilemez — gönderim ayrı izin ve ayrı ön koşul taşır.
     */
    public function test_status_endpoint_refuses_sent(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'sent'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['status']]]);
    }

    public function test_accepted_quote_is_terminal(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('accepted');

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'rejected'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_draft_quote_cannot_be_accepted_directly(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'accepted'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'INVALID_STATUS_TRANSITION');
    }

    /**
     * Teklif kabul edilince bağlı fırsata OTOMATİK HİÇBİR YAZMA yapılmaz
     * (QuoteService::changeStatus dokümanı) — Faz 7'nin optimistik kilidi
     * baypas edilmez.
     */
    public function test_accepting_a_quote_does_not_touch_the_linked_deal(): void
    {
        $actor = $this->fullActor();
        $deal = Deal::factory()->create();
        $before = $deal->only(['stage_id', 'status', 'amount', 'version']);

        $quote = $this->quoteWithItems('sent');
        $quote->deal_id = $deal->id;
        $quote->save();

        $this->actingAs($actor)
            ->patchJson("/api/quotes/{$quote->id}/status", ['status' => 'accepted'])
            ->assertStatus(200);

        $this->assertSame($before, $deal->fresh()->only(['stage_id', 'status', 'amount', 'version']));
    }

    // -------------------------------------------------------------------
    // Silme kuralı
    // -------------------------------------------------------------------

    public function test_draft_quote_can_be_deleted(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        $this->actingAs($actor)->deleteJson("/api/quotes/{$quote->id}")->assertStatus(204);
        $this->assertSoftDeleted('quotes', ['id' => $quote->id]);
    }

    public function test_accepted_and_rejected_quotes_cannot_be_deleted(): void
    {
        $actor = $this->fullActor();

        foreach (['accepted', 'rejected'] as $status) {
            $quote = $this->quoteWithItems($status);

            $this->actingAs($actor)->deleteJson("/api/quotes/{$quote->id}")->assertStatus(403);
            $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'deleted_at' => null]);
        }
    }

    // -------------------------------------------------------------------
    // `quote_number`
    // -------------------------------------------------------------------

    public function test_quote_numbers_are_sequential_and_unique(): void
    {
        $actor = $this->fullActor();
        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $this->actingAs($actor)->postJson('/api/quotes', [
                'title' => 'Teklif '.$i,
                'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 10.00, 'tax_rate' => 20]],
            ])->json('data.quote_number');
        }

        $this->assertSame(
            ['QTE-000001', 'QTE-000002', 'QTE-000003', 'QTE-000004', 'QTE-000005'],
            $numbers
        );
        $this->assertCount(5, array_unique($numbers));
    }

    /**
     * Soft-delete edilmiş bir teklifin numarası YENİDEN KULLANILMAZ: denetim
     * izinde aynı numara iki farklı belgeyi işaret edemez.
     */
    public function test_deleted_quote_number_is_not_reused(): void
    {
        $actor = $this->fullActor();

        $first = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'İlk',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 10.00, 'tax_rate' => 20]],
        ])->json('data');

        $this->actingAs($actor)->deleteJson("/api/quotes/{$first['id']}")->assertStatus(204);

        $second = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'İkinci',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 10.00, 'tax_rate' => 20]],
        ])->json('data');

        $this->assertSame('QTE-000001', $first['quote_number']);
        $this->assertSame('QTE-000002', $second['quote_number']);
    }

    // -------------------------------------------------------------------
    // Revizyon zinciri — sözleşme §6 / §8.12
    // -------------------------------------------------------------------

    public function test_revising_a_sent_quote_creates_a_linked_draft_copy(): void
    {
        $actor = $this->fullActor();

        $parent = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Pazarlık turu 1',
            'items' => $this->scenarioItems(),
            'discount_type' => 'amount',
            'discount_value' => 30.00,
        ])->json('data');

        $this->actingAs($actor)->postJson("/api/quotes/{$parent['id']}/send")->assertStatus(200);

        $response = $this->actingAs($actor)->postJson("/api/quotes/{$parent['id']}/revise");

        $response->assertStatus(200);
        $response->assertJsonPath('data.quote_number', $parent['quote_number'].'-R2');
        $response->assertJsonPath('data.revision', 2);
        $response->assertJsonPath('data.parent_quote_id', $parent['id']);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.sent_at', null);
        $response->assertJsonPath('data.accepted_at', null);
        $response->assertJsonPath('data.rejected_at', null);

        // Kalemler BİREBİR kopya; toplamlar aynı.
        $response->assertJsonPath('data.items_count', 2);
        $response->assertJsonPath('data.items.0.name', 'Danışmanlık');
        $this->assertMoney($response, 'data.items.0.line_total', 180.00);
        $response->assertJsonPath('data.items.1.name', 'Eğitim');
        $this->assertMoney($response, 'data.subtotal', 230.00);
        $this->assertMoney($response, 'data.discount_amount', 30.00);
        $this->assertMoney($response, 'data.total', 235.65);

        // ESKİ KAYIT DEĞİŞMEDİ.
        $original = Quote::find($parent['id']);
        $this->assertSame('sent', $original->status);
        $this->assertSame(1, (int) $original->revision);
        $this->assertNull($original->parent_quote_id);
        $this->assertNotNull($original->sent_at);
    }

    public function test_accepted_quote_cannot_be_revised(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems('accepted');

        $this->actingAs($actor)
            ->postJson("/api/quotes/{$quote->id}/revise")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_NOT_REVISABLE');

        $this->assertSame(1, Quote::count());
    }

    public function test_draft_quote_cannot_be_revised(): void
    {
        $actor = $this->fullActor();
        $quote = $this->quoteWithItems();

        $this->actingAs($actor)
            ->postJson("/api/quotes/{$quote->id}/revise")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_NOT_REVISABLE');
    }

    public function test_rejected_and_expired_quotes_can_be_revised(): void
    {
        $actor = $this->fullActor();

        foreach (['rejected', 'expired'] as $status) {
            $quote = $this->quoteWithItems($status);

            $this->actingAs($actor)
                ->postJson("/api/quotes/{$quote->id}/revise")
                ->assertStatus(200)
                ->assertJsonPath('data.revision', 2);
        }
    }

    /**
     * §8.12 son madde: parent'ın zaten `draft` bir revizyonu varken ikinci
     * çağrı YENİ KAYIT AÇMAZ, mevcut olanı döndürür.
     */
    public function test_second_revise_call_returns_the_existing_draft(): void
    {
        $actor = $this->fullActor();
        $parent = $this->quoteWithItems('sent');

        $first = $this->actingAs($actor)->postJson("/api/quotes/{$parent->id}/revise")->json('data');
        $second = $this->actingAs($actor)->postJson("/api/quotes/{$parent->id}/revise")->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(2, Quote::count());
    }

    /**
     * Zincir: R2 gönderilip revize edilince R3 doğar — `-R2-R3` DEĞİL.
     * Kök numara, ekin atılmasıyla bulunur.
     */
    public function test_revision_number_chains_from_the_root(): void
    {
        $actor = $this->fullActor();
        $parent = $this->quoteWithItems('sent');

        $r2 = $this->actingAs($actor)->postJson("/api/quotes/{$parent->id}/revise")->json('data');
        $this->actingAs($actor)->postJson("/api/quotes/{$r2['id']}/send")->assertStatus(200);

        $r3 = $this->actingAs($actor)->postJson("/api/quotes/{$r2['id']}/revise")->json('data');

        $this->assertSame($parent->quote_number.'-R2', $r2['quote_number']);
        $this->assertSame($parent->quote_number.'-R3', $r3['quote_number']);
        $this->assertSame(3, $r3['revision']);
        $this->assertSame($r2['id'], $r3['parent_quote_id']);
    }

    /**
     * Revizyon numarası KÖK DİZİYİ İLERLETMEZ: `-R2` açtıktan sonra
     * oluşturulan yeni teklif `QTE-000002` olmalıdır.
     */
    public function test_revision_does_not_advance_the_root_sequence(): void
    {
        $actor = $this->fullActor();

        $first = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'İlk',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 10.00, 'tax_rate' => 20]],
        ])->json('data');

        $this->actingAs($actor)->postJson("/api/quotes/{$first['id']}/send")->assertStatus(200);
        $this->actingAs($actor)->postJson("/api/quotes/{$first['id']}/revise")->assertStatus(200);

        $next = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Sonraki',
            'items' => [['name' => 'A', 'quantity' => 1, 'unit_price' => 10.00, 'tax_rate' => 20]],
        ])->json('data');

        $this->assertSame('QTE-000002', $next['quote_number']);
    }

    // -------------------------------------------------------------------
    // Liste sözleşmesi
    // -------------------------------------------------------------------

    public function test_index_returns_pagination_meta_and_omits_items(): void
    {
        Quote::factory()->count(3)->create();

        $response = $this->actingAs($this->actorWithPermissions(['quotes.view']))
            ->getJson('/api/quotes?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.pagination.total', 3);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.last_page', 2);
        // Liste ucu kalemleri TAŞIMAZ (ağır) — yalnızca sayı.
        $response->assertJsonPath('data.0.items', null);
        $response->assertJsonPath('data.0.tax_breakdown', null);
    }

    public function test_index_rejects_per_page_above_the_cap(): void
    {
        $this->actingAs($this->actorWithPermissions(['quotes.view']))
            ->getJson('/api/quotes?per_page=101')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['fields' => ['per_page']]]);
    }

    public function test_index_filters_by_status_and_relations(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create();

        Quote::factory()->create(['status' => 'draft']);
        $target = Quote::factory()->create([
            'status' => 'sent',
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
        ]);

        $this->actingAs($actor)->getJson('/api/quotes?filter[status]=sent')
            ->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);

        $this->actingAs($actor)->getJson("/api/quotes?filter[company_id]={$company->id}")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->actingAs($actor)->getJson("/api/quotes?filter[contact_id]={$contact->id}")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->actingAs($actor)->getJson("/api/quotes?filter[deal_id]={$deal->id}")
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    /**
     * `is_expired` TÜRETİLMİŞTİR: `sent` + geçmiş `valid_until` yeterlidir,
     * durumun `expired` olması gerekmez.
     */
    public function test_expired_filter_and_flag_are_derived(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);

        $stale = Quote::factory()->create(['status' => 'sent', 'valid_until' => now()->subDay()->toDateString()]);
        $fresh = Quote::factory()->create(['status' => 'sent', 'valid_until' => now()->addDays(10)->toDateString()]);
        $closed = Quote::factory()->create(['status' => 'expired', 'valid_until' => now()->addDays(10)->toDateString()]);
        // Taslak süresi DOLAMAZ: müşteriye hiç ulaşmamıştır.
        $draft = Quote::factory()->create(['status' => 'draft', 'valid_until' => now()->subDays(5)->toDateString()]);

        $expired = $this->actingAs($actor)->getJson('/api/quotes?filter[expired]=1')->json('data');
        $expiredIds = array_column($expired, 'id');

        sort($expiredIds);
        $expected = [$stale->id, $closed->id];
        sort($expected);
        $this->assertSame($expected, $expiredIds);

        $notExpired = $this->actingAs($actor)->getJson('/api/quotes?filter[expired]=0')->json('data');
        $notExpiredIds = array_column($notExpired, 'id');

        sort($notExpiredIds);
        $expectedNot = [$fresh->id, $draft->id];
        sort($expectedNot);
        $this->assertSame($expectedNot, $notExpiredIds);

        $this->actingAs($actor)->getJson("/api/quotes/{$stale->id}")
            ->assertJsonPath('data.is_expired', true);
        $this->actingAs($actor)->getJson("/api/quotes/{$fresh->id}")
            ->assertJsonPath('data.is_expired', false);
        $this->actingAs($actor)->getJson("/api/quotes/{$draft->id}")
            ->assertJsonPath('data.is_expired', false);
    }

    public function test_index_search_matches_number_and_title(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);

        $byTitle = Quote::factory()->create(['title' => 'Bulut Altyapı Yenileme', 'quote_number' => 'QTE-000901']);
        Quote::factory()->create(['title' => 'Eğitim Paketi', 'quote_number' => 'QTE-000902']);

        $this->actingAs($actor)->getJson('/api/quotes?q=Bulut')
            ->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byTitle->id);

        $this->actingAs($actor)->getJson('/api/quotes?q=000901')
            ->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $byTitle->id);
    }

    /**
     * Arama, `filter[...]` koşullarını SIZDIRMAMALIDIR — parantezli
     * gruplama olmasaydı aranan kelime tüm filtreleri geçersiz kılardı.
     */
    public function test_search_does_not_leak_past_filters(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);

        Quote::factory()->create(['title' => 'Bulut', 'status' => 'draft']);
        Quote::factory()->create(['title' => 'Bulut', 'status' => 'sent']);

        $this->actingAs($actor)->getJson('/api/quotes?q=Bulut&filter[status]=sent')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'sent');
    }

    public function test_index_sorting_uses_the_whitelist_and_falls_back_silently(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);

        $low = Quote::factory()->create(['total' => 100.00, 'created_at' => now()->subDays(2)]);
        $high = Quote::factory()->create(['total' => 900.00, 'created_at' => now()->subDay()]);

        $this->actingAs($actor)->getJson('/api/quotes?sort=total')
            ->assertJsonPath('data.0.id', $low->id);

        $this->actingAs($actor)->getJson('/api/quotes?sort=-total')
            ->assertJsonPath('data.0.id', $high->id);

        // Beyaz liste dışı bir kolon 422 DEĞİL, sessizce varsayılana düşer.
        $this->actingAs($actor)->getJson('/api/quotes?sort=notes')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $high->id);
    }

    public function test_index_filters_by_created_date_range(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);

        $old = Quote::factory()->create(['created_at' => now()->subDays(30)]);
        $recent = Quote::factory()->create(['created_at' => now()->subDay()]);

        $this->actingAs($actor)
            ->getJson('/api/quotes?filter[from]='.now()->subDays(5)->toDateString())
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);

        $this->actingAs($actor)
            ->getJson('/api/quotes?filter[to]='.now()->subDays(5)->toDateString())
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);
    }

    /**
     * N+1 ÖLÇÜMÜ: 15 teklif × 4 kalem. İlişkiler eager-load edilir,
     * `items_count` ise `withCount` ALT SORGUSUYLA gelir — kalemler listede
     * hiç YÜKLENMEZ.
     */
    public function test_index_does_not_trigger_n_plus_one_queries(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view']);
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create();
        $creator = User::factory()->create();

        $quotes = Quote::factory()->count(15)->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'created_by' => $creator->id,
        ]);

        foreach ($quotes as $quote) {
            QuoteItem::factory()->count(4)->create(['quote_id' => $quote->id]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/quotes?per_page=100');

        $response->assertStatus(200);
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('data.0.items_count', 4);
        $response->assertJsonPath('data.0.items', null);

        $this->assertLessThan(
            15,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') — N+1 şüphesi:'.PHP_EOL.implode(PHP_EOL, $queries)
        );

        // Kalemler listede AYRI bir sorgu ile çekilmemeli (withCount alt
        // sorgusu ana SELECT'in içindedir).
        $itemSelects = collect($queries)
            ->filter(fn (string $sql) => str_contains($sql, 'from `quote_items`') && ! str_contains($sql, 'select * from `quotes`'))
            ->filter(fn (string $sql) => str_starts_with($sql, 'select * from `quote_items`'))
            ->count();

        $this->assertSame(0, $itemSelects, 'Kalemler liste ucunda ayrıca yüklendi — N+1.');
    }

    public function test_show_returns_items_and_breakdown(): void
    {
        $actor = $this->fullActor();

        $created = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'Detay',
            'items' => $this->scenarioItems(),
            'discount_type' => 'amount',
            'discount_value' => 30.00,
        ])->json('data');

        $response = $this->actingAs($actor)->getJson("/api/quotes/{$created['id']}");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonCount(2, 'data.tax_breakdown');
        $this->assertMoney($response, 'data.tax_breakdown.0.tax', 31.30);
        $this->assertMoney($response, 'data.tax_breakdown.1.tax', 4.35);
        $this->assertMoney($response, 'data.tax_amount', 35.65);
    }

    // -------------------------------------------------------------------
    // POST /api/quotes/calculate — kalıcı olmayan canlı hesap
    // -------------------------------------------------------------------

    public function test_calculate_requires_authentication(): void
    {
        $this->postJson('/api/quotes/calculate', ['items' => []])->assertStatus(401);
    }

    /**
     * `quotes.view` TEK BAŞINA yetmez: bu uç "teklif oku" değil "teklif
     * kurgula" eylemine aittir.
     */
    public function test_calculate_rejects_a_viewer_without_create_or_update(): void
    {
        $this->actingAs($this->actorWithPermissions(['quotes.view']))
            ->postJson('/api/quotes/calculate', ['items' => []])
            ->assertStatus(403);
    }

    /**
     * `quotes.create` VEYA `quotes.update` — ikisinden biri yeterli.
     * "Güncelleyebilir ama oluşturamaz" bir rol, düzenleme formunda canlı
     * toplam görebilmelidir.
     */
    public function test_calculate_accepts_either_create_or_update_permission(): void
    {
        foreach ([['quotes.create'], ['quotes.update'], ['quotes.create', 'quotes.update']] as $permissions) {
            $this->actingAs($this->actorWithPermissions($permissions))
                ->postJson('/api/quotes/calculate', ['items' => []])
                ->assertStatus(200);
        }
    }

    /**
     * KALICI YOL İLE GEÇİCİ YOLUN AYNI SONUCU VERDİĞİNİN KANITI — §8.1.
     *
     * Aynı gövde hem `POST /api/quotes` (kaydeder) hem
     * `POST /api/quotes/calculate` (kaydetmez) uçlarına gönderilir ve dört
     * toplamın da kuruşu kuruşuna aynı çıktığı doğrulanır. Bu testin varlık
     * sebebi, formdaki canlı toplam ile veritabanına yazılan toplamın
     * ayrışamayacağını kilitlemektir.
     */
    public function test_calculate_matches_the_persisted_path_for_criterion_1(): void
    {
        $actor = $this->fullActor();
        $payload = ['items' => $this->scenarioItems(), 'discount_type' => 'amount', 'discount_value' => 30.00];

        $preview = $this->actingAs($actor)->postJson('/api/quotes/calculate', $payload);
        $preview->assertStatus(200);

        $this->assertMoney($preview, 'data.subtotal', 230.00);
        $this->assertMoney($preview, 'data.discount_amount', 30.00);
        $this->assertMoney($preview, 'data.tax_amount', 35.65);
        $this->assertMoney($preview, 'data.total', 235.65);
        $this->assertMoney($preview, 'data.items.0.line_total', 180.00);
        $this->assertMoney($preview, 'data.items.1.line_total', 50.00);

        $saved = $this->actingAs($actor)->postJson('/api/quotes', $payload + ['title' => 'Aynı gövde']);
        $saved->assertStatus(201);

        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total'] as $field) {
            $this->assertSame(
                (float) $preview->json('data.'.$field),
                (float) $saved->json('data.'.$field),
                "Önizleme ve kaydedilen teklif {$field} alanında ayrıştı."
            );
        }
    }

    /**
     * §8.5 — yüzde tipi indirim, iki yoldan da aynı.
     */
    public function test_calculate_matches_the_persisted_path_for_criterion_5(): void
    {
        $actor = $this->fullActor();
        $payload = [
            'items' => [['name' => 'Lisans', 'quantity' => 1, 'unit_price' => 1234.56, 'tax_rate' => 20]],
            'discount_type' => 'percent',
            'discount_value' => 5.00,
        ];

        $preview = $this->actingAs($actor)->postJson('/api/quotes/calculate', $payload);
        $preview->assertStatus(200);

        $this->assertMoney($preview, 'data.discount_amount', 61.73);
        $this->assertMoney($preview, 'data.tax_amount', 234.57);
        $this->assertMoney($preview, 'data.total', 1407.40);

        $saved = $this->actingAs($actor)->postJson('/api/quotes', $payload + ['title' => 'Yüzde']);

        $this->assertSame((float) $preview->json('data.total'), (float) $saved->json('data.total'));
        $this->assertSame((float) $preview->json('data.tax_amount'), (float) $saved->json('data.tax_amount'));
    }

    /**
     * Çok-oranlı indirim dağıtımı `tax_breakdown`'da görünür — arayüz
     * "hangi KDV grubuna ne kadar indirim düştü" tablosunu bununla çizer.
     *
     * Anahtar adları `GET /api/quotes/{quote}` yanıtındakiyle AYNIDIR
     * (rate/net/discount/base/tax); iki uç aynı kavram için farklı anahtar
     * kullansaydı istemci iki ayrı eşleyici yazmak zorunda kalırdı.
     */
    public function test_calculate_exposes_the_multi_rate_tax_breakdown(): void
    {
        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes/calculate', [
            'items' => $this->scenarioItems(),
            'discount_type' => 'amount',
            'discount_value' => 30.00,
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.tax_breakdown');
        $response->assertJsonStructure([
            'data' => ['tax_breakdown' => [['rate', 'net', 'discount', 'base', 'tax']]],
        ]);

        // §8.1: yuzde 20 grubuna 23.48, yuzde 10 grubuna 6.52.
        $this->assertMoney($response, 'data.tax_breakdown.0.rate', 20.00);
        $this->assertMoney($response, 'data.tax_breakdown.0.net', 180.00);
        $this->assertMoney($response, 'data.tax_breakdown.0.discount', 23.48);
        $this->assertMoney($response, 'data.tax_breakdown.0.base', 156.52);
        $this->assertMoney($response, 'data.tax_breakdown.0.tax', 31.30);
        $this->assertMoney($response, 'data.tax_breakdown.1.rate', 10.00);
        $this->assertMoney($response, 'data.tax_breakdown.1.discount', 6.52);
        $this->assertMoney($response, 'data.tax_breakdown.1.tax', 4.35);
    }

    /**
     * Aynı kırılım, detay ucundaki `tax_breakdown` ile BİREBİR aynı yapıda
     * olmalıdır — tek doğruluk kaynağının API yüzeyindeki karşılığı.
     */
    public function test_calculate_breakdown_matches_the_show_endpoint(): void
    {
        $actor = $this->fullActor();
        $payload = ['items' => $this->scenarioItems(), 'discount_type' => 'amount', 'discount_value' => 30.00];

        $preview = $this->actingAs($actor)->postJson('/api/quotes/calculate', $payload)->json('data.tax_breakdown');

        $quoteId = $this->actingAs($actor)->postJson('/api/quotes', $payload + ['title' => 'Kırılım'])->json('data.id');
        $stored = $this->actingAs($actor)->getJson("/api/quotes/{$quoteId}")->json('data.tax_breakdown');

        $this->assertSame($preview, $stored);
    }

    /**
     * HİÇBİR ŞEY KAYDEDİLMEZ — ne teklif, ne kalem, ne denetim izi.
     */
    public function test_calculate_persists_nothing(): void
    {
        $actor = $this->fullActor();

        $activityBefore = DB::table('activity_log')->count();

        $this->actingAs($actor)->postJson('/api/quotes/calculate', [
            'items' => $this->scenarioItems(),
            'discount_type' => 'amount',
            'discount_value' => 30.00,
        ])->assertStatus(200);

        $this->assertSame($activityBefore, DB::table('activity_log')->count());
        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('quote_items', 0);
    }

    /**
     * Boş `items` GEÇERLİDİR: form ilk açıldığında ve son satır silindiğinde
     * istemci bu ucu çağırır; 422 döndürmek meşru bir duruma kırmızı hata
     * bastırırdı (controller dokümanı).
     */
    public function test_calculate_accepts_an_empty_item_list(): void
    {
        $actor = $this->fullActor();

        foreach ([['items' => []], []] as $payload) {
            $response = $this->actingAs($actor)->postJson('/api/quotes/calculate', $payload);

            $response->assertStatus(200);
            $this->assertMoney($response, 'data.subtotal', 0.00);
            $this->assertMoney($response, 'data.discount_amount', 0.00);
            $this->assertMoney($response, 'data.tax_amount', 0.00);
            $this->assertMoney($response, 'data.total', 0.00);
            $response->assertJsonPath('data.tax_breakdown', []);
            $response->assertJsonPath('data.items', []);
        }
    }

    /**
     * Boş kalem listesinde indirim ara toplamı (0) aşamaz — kural hesap
     * sınıfından gelir, uçta tekrarlanmaz.
     */
    public function test_calculate_rejects_a_discount_on_an_empty_item_list(): void
    {
        $this->actingAs($this->fullActor())
            ->postJson('/api/quotes/calculate', ['items' => [], 'discount_value' => 1.00])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_DISCOUNT_EXCEEDS_SUBTOTAL');
    }

    public function test_calculate_rejects_a_discount_greater_than_the_subtotal(): void
    {
        $this->actingAs($this->fullActor())
            ->postJson('/api/quotes/calculate', [
                'items' => [['quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
                'discount_type' => 'amount',
                'discount_value' => 100.01,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'QUOTE_DISCOUNT_EXCEEDS_SUBTOTAL');
    }

    public function test_calculate_rejects_invalid_item_values(): void
    {
        $actor = $this->fullActor();

        $cases = [
            ['quantity' => -1, 'unit_price' => 100.00, 'tax_rate' => 20],
            ['quantity' => 1, 'unit_price' => -100.00, 'tax_rate' => 20],
            ['quantity' => 1, 'unit_price' => 100.00, 'discount_percent' => 101, 'tax_rate' => 20],
            ['quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 100.01],
        ];

        foreach ($cases as $item) {
            $this->actingAs($actor)
                ->postJson('/api/quotes/calculate', ['items' => [$item]])
                ->assertStatus(422)
                ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        }
    }

    public function test_calculate_rejects_an_unknown_discount_type(): void
    {
        $this->actingAs($this->fullActor())
            ->postJson('/api/quotes/calculate', [
                'items' => [['quantity' => 1, 'unit_price' => 100.00, 'tax_rate' => 20]],
                'discount_type' => 'kdv_dahil',
                'discount_value' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['discount_type']]]);
    }

    /**
     * Kalem sayısı sınırı: sınırsız girdi, bcmath ile keyfi hassasiyette
     * çarpım yapan bir döngüyü besleyen ucuz bir CPU yük vektörüdür.
     */
    public function test_calculate_enforces_the_item_limit(): void
    {
        $actor = $this->fullActor();
        $item = ['quantity' => 1, 'unit_price' => 1.00, 'tax_rate' => 20];

        $this->actingAs($actor)
            ->postJson('/api/quotes/calculate', ['items' => array_fill(0, CalculateQuoteRequest::MAX_ITEMS, $item)])
            ->assertStatus(200);

        $this->actingAs($actor)
            ->postJson('/api/quotes/calculate', ['items' => array_fill(0, CalculateQuoteRequest::MAX_ITEMS + 1, $item)])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['items']]]);
    }

    /**
     * Ad ve `product_id` hesabın girdisi DEĞİLDİR ama yanıtta geri
     * yankılanır, böylece istemci satırları formdaki satırlarla
     * eşleştirebilir. Kaydetme ucundan farklı olarak ad ZORUNLU değildir:
     * kullanıcı adı yazmadan önce de canlı toplam görebilmelidir.
     */
    public function test_calculate_echoes_pass_through_fields_without_requiring_a_name(): void
    {
        $response = $this->actingAs($this->fullActor())->postJson('/api/quotes/calculate', [
            'items' => [
                ['product_id' => 4242, 'quantity' => 2, 'unit_price' => 100.00, 'tax_rate' => 20],
            ],
        ]);

        $response->assertStatus(200);
        // `exists` kuralı YOK — hiçbir şey kaydedilmediği için referans
        // bütünlüğünün koruyacağı bir şey yoktur.
        $response->assertJsonPath('data.items.0.product_id', 4242);
        $this->assertMoney($response, 'data.items.0.line_total', 200.00);
    }

    // -------------------------------------------------------------------
    // PDF
    // -------------------------------------------------------------------

    public function test_pdf_endpoint_returns_a_pdf_stream(): void
    {
        $actor = $this->fullActor();

        $created = $this->actingAs($actor)->postJson('/api/quotes', [
            'title' => 'PDF teklifi',
            'items' => $this->scenarioItems(),
        ])->json('data');

        $response = $this->actingAs($actor)->get("/api/quotes/{$created['id']}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'teklif-'.$created['quote_number'].'.pdf',
            (string) $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_user_without_quotes_view_permission_cannot_download_pdf(): void
    {
        $quote = $this->quoteWithItems();

        $this->actingAs(User::factory()->create())
            ->get("/api/quotes/{$quote->id}/pdf")
            ->assertStatus(403);
    }
}
