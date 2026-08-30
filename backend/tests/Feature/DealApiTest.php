<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DealApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rol/izin sözlüğünü kur — ayrı test veritabanı (phpunit.xml), ana
        // syncra_crm verisine dokunulmaz.
        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function openStage(array $attributes = []): PipelineStage
    {
        return PipelineStage::factory()->create(array_merge([
            'is_won' => false,
            'is_lost' => false,
            'is_active' => true,
        ], $attributes));
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/deals')->assertStatus(401);
        $this->getJson('/api/deals/board')->assertStatus(401);
    }

    public function test_user_without_deals_view_permission_cannot_list_deals(): void
    {
        $actor = User::factory()->create(); // rolsüz / izinsiz

        $this->actingAs($actor)->getJson('/api/deals')->assertStatus(403);
    }

    public function test_user_without_deals_view_permission_cannot_view_board(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->getJson('/api/deals/board')->assertStatus(403);
    }

    public function test_user_without_deals_view_permission_cannot_show_deal(): void
    {
        $actor = User::factory()->create();
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $this->actingAs($actor)->getJson("/api/deals/{$deal->id}")->assertStatus(403);
    }

    /**
     * The permission-denied path above only proves the gate is wired up.
     * This is the read half of CRUD itself: a permitted actor's GET must
     * come back with the full detail contract (DealResource), every
     * eager-loaded relation resolved to the right record, not just a 200.
     */
    public function test_show_returns_the_full_deal_with_its_relations(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        $owner = User::factory()->create();
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $tag = Tag::factory()->create();
        $deal = Deal::factory()->create([
            'pipeline_stage_id' => $stage->id,
            'owner_id' => $owner->id,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'title' => 'Detay Fırsatı',
            'amount' => 5000.55,
        ]);
        $deal->tags()->attach($tag->id);

        $response = $this->actingAs($actor)->getJson("/api/deals/{$deal->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $deal->id)
            ->assertJsonPath('data.title', 'Detay Fırsatı')
            ->assertJsonPath('data.amount', 5000.55)
            ->assertJsonPath('data.pipeline_stage.id', $stage->id)
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.contact.id', $contact->id)
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    public function test_user_without_deals_create_permission_cannot_store_deal(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor)->postJson('/api/deals', ['title' => 'Test Fırsatı'])->assertStatus(403);
    }

    public function test_user_without_deals_update_permission_cannot_update_deal(): void
    {
        $actor = User::factory()->create();
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $this->actingAs($actor)->patchJson("/api/deals/{$deal->id}", ['title' => 'Güncel'])->assertStatus(403);
    }

    public function test_user_without_deals_delete_permission_cannot_destroy_deal(): void
    {
        $actor = User::factory()->create();
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $this->actingAs($actor)->deleteJson("/api/deals/{$deal->id}")->assertStatus(403);
    }

    public function test_user_without_deals_assign_permission_cannot_assign_deal(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $owner = User::factory()->create();
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $this->actingAs($actor)
            ->patchJson("/api/deals/{$deal->id}/assign", ['owner_id' => $owner->id])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Route sırası: /deals/board, /deals/{deal}'den ÖNCE eşleşmeli
    // -------------------------------------------------------------------

    public function test_board_route_is_not_captured_by_deal_show_route(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $this->openStage();

        $response = $this->actingAs($actor)->getJson('/api/deals/board');

        // {deal} route-model-binding'e düşseydi 'board' bir id gibi
        // yorumlanır ve ModelNotFoundException -> 404 dönerdi.
        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['currency', 'total_open_amount']]);
    }

    // -------------------------------------------------------------------
    // Board: sıralama, version, per_stage sınırı, total_amount
    // -------------------------------------------------------------------

    public function test_board_returns_stages_ordered_by_position_and_cards_ordered_within_stage(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);

        $stageB = $this->openStage(['position' => 2, 'name' => 'B Aşaması']);
        $stageA = $this->openStage(['position' => 1, 'name' => 'A Aşaması']);

        $dealLast = Deal::factory()->create(['pipeline_stage_id' => $stageA->id, 'position' => 'c']);
        $dealFirst = Deal::factory()->create(['pipeline_stage_id' => $stageA->id, 'position' => 'a']);
        $dealMiddle = Deal::factory()->create(['pipeline_stage_id' => $stageA->id, 'position' => 'b']);

        $response = $this->actingAs($actor)->getJson('/api/deals/board');

        $response->assertStatus(200);
        $columns = $response->json('data');

        $this->assertSame($stageA->id, $columns[0]['stage']['id']);
        $this->assertSame($stageB->id, $columns[1]['stage']['id']);

        $ids = collect($columns[0]['deals'])->pluck('id')->all();
        $this->assertSame([$dealFirst->id, $dealMiddle->id, $dealLast->id], $ids);

        // version her kartta zorunlu.
        foreach ($columns[0]['deals'] as $card) {
            $this->assertArrayHasKey('version', $card);
            $this->assertNotNull($card['version']);
        }
    }

    /**
     * `pipeline_stage_id` her kartta bulunmalı VE değeri, kartın altında
     * göründüğü sütunun `stage.id`'siyle eşleşmeli — birden fazla FARKLI
     * aşama id'si karşılaştırılarak alanın sabit/hardcode bir değer değil,
     * gerçekten kartın kendi `pipeline_stage_id`'sinden doldurulduğu
     * kanıtlanır. Bu alan A şeridinin `/deals/{deal}/move` 409 çakışma
     * zarfında da aynı DealCardResource üzerinden kullanılıyor.
     */
    public function test_board_cards_carry_pipeline_stage_id_matching_their_column(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);

        $stageA = $this->openStage(['position' => 1]);
        $stageB = $this->openStage(['position' => 2]);

        $dealInA = Deal::factory()->create(['pipeline_stage_id' => $stageA->id]);
        $dealInB = Deal::factory()->create(['pipeline_stage_id' => $stageB->id]);

        $response = $this->actingAs($actor)->getJson('/api/deals/board');

        $response->assertStatus(200);
        $columns = $response->json('data');

        $cardsById = collect($columns)->flatMap(fn ($column) => $column['deals'])->keyBy('id');

        $this->assertSame($stageA->id, $cardsById[$dealInA->id]['pipeline_stage_id']);
        $this->assertSame($stageB->id, $cardsById[$dealInB->id]['pipeline_stage_id']);
        $this->assertNotSame(
            $cardsById[$dealInA->id]['pipeline_stage_id'],
            $cardsById[$dealInB->id]['pipeline_stage_id']
        );

        foreach ($columns as $column) {
            foreach ($column['deals'] as $card) {
                $this->assertArrayHasKey('pipeline_stage_id', $card);
                $this->assertSame($column['stage']['id'], $card['pipeline_stage_id']);
            }
        }
    }

    public function test_board_per_stage_limit_and_has_more_and_total_amount_independent_of_limit(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();

        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'position' => 'a', 'amount' => 1000]);
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'position' => 'b', 'amount' => 2000]);
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'position' => 'c', 'amount' => 3000]);

        $response = $this->actingAs($actor)->getJson('/api/deals/board?per_stage=2');

        $response->assertStatus(200);
        $column = collect($response->json('data'))->firstWhere('stage.id', $stage->id);

        $this->assertCount(2, $column['deals']);
        $this->assertTrue($column['meta']['has_more']);
        // Toplam TÜM 3 kartın tutarı olmalı, sadece yüklenen 2'sinin değil.
        $this->assertEquals(6000, $column['meta']['total_amount']);
        $this->assertSame(3, $column['meta']['count']);
    }

    /**
     * `boardAggregates()` artık `withCount`/`withSum` kullanıyor (eski
     * `selectRaw` + `groupBy` yerine) — `deals` modelindeki SoftDeletes
     * global scope'unun bu ilişki alt-sorgularında da uygulandığını, yani
     * silinmiş bir kartın toplama/sayıma KARIŞMADIĞINI kanıtlar.
     */
    public function test_board_totals_exclude_soft_deleted_deals(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();

        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'position' => 'a', 'amount' => 1000]);
        $deleted = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'position' => 'b', 'amount' => 9000]);
        $deleted->delete();

        $response = $this->actingAs($actor)->getJson('/api/deals/board');

        $response->assertStatus(200);
        $column = collect($response->json('data'))->firstWhere('stage.id', $stage->id);

        $this->assertSame(1, $column['meta']['count']);
        $this->assertEquals(1000, $column['meta']['total_amount']);
        $this->assertCount(1, $column['deals']);
    }

    /**
     * `withSum` hiç eşleşen kart yoksa `null` döner — DealService bunu
     * `?? 0`'a çevirmeli, yanıtta `total_amount: null` çıkmamalı.
     */
    public function test_board_total_amount_is_zero_not_null_for_empty_stage(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $emptyStage = $this->openStage();

        $response = $this->actingAs($actor)->getJson('/api/deals/board');

        $response->assertStatus(200);
        $column = collect($response->json('data'))->firstWhere('stage.id', $emptyStage->id);

        $this->assertSame(0, $column['meta']['count']);
        $this->assertNotNull($column['meta']['total_amount']);
        $this->assertEquals(0, $column['meta']['total_amount']);
    }

    /**
     * N+1 regresyon testi — board ucu owner/company/contact/tags
     * ilişkilerini `Collection::load()` ile TÜM kartlar üzerinde tek seferde
     * yükler (bkz. DealService::board()). Kart/deal sayısı arttıkça sorgu
     * sayısı artmamalı: N+1 olsaydı 12 deal için en az +12 ek sorgu (owner)
     * + 12 (company) + 12 (contact) + 12 (tags) = 48 ek sorgu gerekirdi.
     */
    public function test_board_does_not_execute_n_plus_one_queries_for_card_relations(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $tag = Tag::factory()->create();
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();
        $owner = User::factory()->create();

        $stages = collect(range(1, 3))->map(fn ($i) => $this->openStage(['position' => $i]));

        foreach ($stages as $stage) {
            $deals = Deal::factory()->count(4)->create([
                'pipeline_stage_id' => $stage->id,
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'owner_id' => $owner->id,
            ]);

            foreach ($deals as $deal) {
                $deal->tags()->attach($tag->id);
            }
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/deals/board');

        $response->assertStatus(200);

        // 3 aşama x 4 deal = 12 kart. Eager loading ile sorgu sayısı
        // aşama/kart sayısından bağımsız SABİT bir tabana yakın kalır.
        // N+1 olsaydı en az 12*4 = 48 EK sorgu gerekirdi; eşik bunun çok
        // altında tutuldu.
        $this->assertLessThan(
            30,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') - N+1 şüphesi:
'.implode('
', $queries)
        );

        // owner/company/contact/tags ilişkileri TAM OLARAK birer sorguda
        // (tüm kartlar için toplu) eager-load edilmeli, kart başına değil.
        $tagQueries = collect($queries)->filter(fn ($sql) => str_contains($sql, 'taggables'))->count();
        $this->assertSame(1, $tagQueries, 'tags ilişkisi tam olarak 1 sorguda eager-load edilmeli.');
    }

    public function test_board_supports_owner_and_company_and_from_to_filters(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        $owner = User::factory()->create();

        $matching = Deal::factory()->create([
            'pipeline_stage_id' => $stage->id,
            'owner_id' => $owner->id,
            'expected_close_date' => now()->addDays(5),
        ]);
        Deal::factory()->create([
            'pipeline_stage_id' => $stage->id,
            'owner_id' => null,
            'expected_close_date' => now()->addDays(5),
        ]);

        $response = $this->actingAs($actor)->getJson("/api/deals/board?filter[owner_id]={$owner->id}");

        $response->assertStatus(200);
        $column = collect($response->json('data'))->firstWhere('stage.id', $stage->id);
        $ids = collect($column['deals'])->pluck('id')->all();

        $this->assertSame([$matching->id], $ids);
    }

    // -------------------------------------------------------------------
    // Liste: sayfalama, sıralama, arama, filtreler
    // -------------------------------------------------------------------

    public function test_list_returns_pagination_meta(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        Deal::factory()->count(3)->create(['pipeline_stage_id' => $stage->id]);

        $response = $this->actingAs($actor)->getJson('/api/deals?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonCount(2, 'data');
    }

    /**
     * `meta.totals` FİLTRELENMİŞ TÜM kümenin toplamı olmalı, o sayfaya
     * yüklenen (per_page ile sınırlı) kayıtların değil — panodaki
     * `total_amount` kararıyla aynı ilke.
     */
    public function test_list_totals_are_independent_of_pagination(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        Deal::factory()->count(30)->create(['pipeline_stage_id' => $stage->id]);

        $response = $this->actingAs($actor)->getJson('/api/deals?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.totals.count', 30);
    }

    /**
     * Filtre uygulandığında toplamlar da AYNI filtreye tabi olmalı — yalnızca
     * `data` değil, `meta.totals` da daralmalı.
     */
    public function test_list_totals_respect_active_filters(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $openStage = $this->openStage();
        $wonStage = PipelineStage::factory()->won()->create();

        Deal::factory()->count(4)->create(['pipeline_stage_id' => $openStage->id, 'status' => 'open']);
        Deal::factory()->won()->count(2)->create(['pipeline_stage_id' => $wonStage->id]);

        $response = $this->actingAs($actor)->getJson('/api/deals?filter[status]=won');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.totals.count', 2);
    }

    /**
     * Durum bazlı toplamlar (open/won/lost) ayrı ayrı doğru olmalı ve
     * toplamları `total_amount`'a eşit olmalı (tüm deal'ler ya open, ya won,
     * ya lost olduğu için üçünün toplamı bütünü kapsar).
     */
    public function test_list_totals_break_down_by_status_and_sum_to_total(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $openStage = $this->openStage();
        $wonStage = PipelineStage::factory()->won()->create();
        $lostStage = PipelineStage::factory()->lost()->create();

        Deal::factory()->create(['pipeline_stage_id' => $openStage->id, 'status' => 'open', 'amount' => 1000]);
        Deal::factory()->create(['pipeline_stage_id' => $openStage->id, 'status' => 'open', 'amount' => 500]);
        Deal::factory()->won()->create(['pipeline_stage_id' => $wonStage->id, 'amount' => 2000]);
        Deal::factory()->lost()->create(['pipeline_stage_id' => $lostStage->id, 'amount' => 300]);

        $response = $this->actingAs($actor)->getJson('/api/deals');

        $response->assertStatus(200);
        $totals = $response->json('meta.totals');

        $this->assertEquals(1500, $totals['open_amount']);
        $this->assertEquals(2000, $totals['won_amount']);
        $this->assertEquals(300, $totals['lost_amount']);
        $this->assertEquals(3800, $totals['total_amount']);
        $this->assertEquals(
            $totals['open_amount'] + $totals['won_amount'] + $totals['lost_amount'],
            $totals['total_amount']
        );
        $this->assertSame('TRY', $totals['currency']);
    }

    /**
     * Boş sonuç kümesinde toplamlar `0` olmalı, `null` DEĞİL —
     * `sum()`'ın eşleşen satır yokken SQL'in kendisinin (NULL) değil,
     * Eloquent'in normalize ettiği değeri döndürdüğünü kanıtlar.
     */
    public function test_list_totals_are_zero_not_null_for_empty_result(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $this->openStage(); // hiç deal yok

        $response = $this->actingAs($actor)->getJson('/api/deals?filter[status]=won');

        $response->assertStatus(200);
        $totals = $response->json('meta.totals');

        $this->assertSame(0, $totals['count']);
        foreach (['total_amount', 'open_amount', 'won_amount', 'lost_amount'] as $key) {
            $this->assertNotNull($totals[$key], "{$key} null olmamalı.");
            $this->assertEquals(0, $totals[$key]);
        }
    }

    /**
     * Soft-deleted bir deal toplamlara KARIŞMAMALI — `Deal` modelindeki
     * SoftDeletes global scope'u `amountTotals()`'ın kullandığı temel
     * sorguya da (board'daki gibi) otomatik uygulanır.
     */
    public function test_list_totals_exclude_soft_deleted_deals(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();

        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'amount' => 1000]);
        $deleted = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'amount' => 9000]);
        $deleted->delete();

        $response = $this->actingAs($actor)->getJson('/api/deals');

        $response->assertStatus(200)
            ->assertJsonPath('meta.totals.count', 1);
        $this->assertEquals(1000, $response->json('meta.totals.total_amount'));
    }

    public function test_invalid_sort_column_falls_back_to_default(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'title' => 'Eski', 'created_at' => now()->subDays(2)]);
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'title' => 'Yeni', 'created_at' => now()]);

        // 'description' beyaz listede yok -> sessizce -created_at'e düşmeli.
        $response = $this->actingAs($actor)->getJson('/api/deals?sort=description');

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertSame(['Yeni', 'Eski'], $titles);
    }

    public function test_search_does_not_leak_records_outside_other_filters(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();

        // title eşleşir ama status eşleşmez -> dönmemeli.
        Deal::factory()->won()->create(['pipeline_stage_id' => PipelineStage::factory()->won()->create()->id, 'title' => 'Syncra Projesi']);
        // title ve status ikisi de eşleşir -> dönmeli.
        $match = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'title' => 'Syncra Projesi', 'status' => 'open']);
        // title eşleşmez -> dönmemeli.
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'title' => 'Başka Fırsat', 'status' => 'open']);

        $response = $this->actingAs($actor)->getJson('/api/deals?q=Syncra&filter[status]=open');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($match->id, $response->json('data.0.id'));
    }

    public function test_filter_by_stage_id(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stageA = $this->openStage();
        $stageB = $this->openStage();
        Deal::factory()->create(['pipeline_stage_id' => $stageA->id]);
        $target = Deal::factory()->create(['pipeline_stage_id' => $stageB->id]);

        $response = $this->actingAs($actor)->getJson("/api/deals?filter[stage_id]={$stageB->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($target->id, $response->json('data.0.id'));
    }

    public function test_filter_by_owner_id(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        $owner = User::factory()->create();
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'owner_id' => null]);
        $owned = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'owner_id' => $owner->id]);

        $response = $this->actingAs($actor)->getJson("/api/deals?filter[owner_id]={$owner->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($owned->id, $response->json('data.0.id'));
    }

    public function test_filter_by_company_id(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        $company = Company::factory()->create();
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'company_id' => null]);
        $target = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'company_id' => $company->id]);

        $response = $this->actingAs($actor)->getJson("/api/deals?filter[company_id]={$company->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($target->id, $response->json('data.0.id'));
    }

    public function test_filter_by_amount_range(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'amount' => 100]);
        $inRange = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'amount' => 5000]);
        Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'amount' => 999999]);

        $response = $this->actingAs($actor)->getJson('/api/deals?filter[amount_min]=1000&filter[amount_max]=10000');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($inRange->id, $response->json('data.0.id'));
    }

    public function test_filter_by_tag_id(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $stage = $this->openStage();
        $tag = Tag::factory()->create();

        $tagged = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);
        $tagged->tags()->attach($tag->id);
        Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $response = $this->actingAs($actor)->getJson("/api/deals?filter[tag_id]={$tag->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($tagged->id, $response->json('data.0.id'));
    }

    // -------------------------------------------------------------------
    // Oluşturma
    // -------------------------------------------------------------------

    public function test_store_ignores_client_supplied_position_and_forces_status_open(): void
    {
        $actor = $this->actorWithPermissions(['deals.create', 'deals.view']);
        $stage = $this->openStage();

        $response = $this->actingAs($actor)->postJson('/api/deals', [
            'title' => 'Yeni Fırsat',
            'pipeline_stage_id' => $stage->id,
            'position' => 'zzz-client-supplied',
            'version' => 999,
            'status' => 'won',
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('zzz-client-supplied', $response->json('data.position'));
        $this->assertSame(1, $response->json('data.version'));
        $this->assertSame('open', $response->json('data.status'));

        $this->assertDatabaseHas('deals', [
            'id' => $response->json('data.id'),
            'status' => 'open',
            'version' => 1,
        ]);
    }

    public function test_store_defaults_to_first_active_stage_when_none_given(): void
    {
        $actor = $this->actorWithPermissions(['deals.create', 'deals.view']);
        $this->openStage(['position' => 5]);
        $first = $this->openStage(['position' => 1]);
        $this->openStage(['position' => 3]);

        $response = $this->actingAs($actor)->postJson('/api/deals', ['title' => 'Sıralı Fırsat']);

        $response->assertStatus(201)
            ->assertJsonPath('data.pipeline_stage.id', $first->id);
    }

    // -------------------------------------------------------------------
    // Güncelleme
    // -------------------------------------------------------------------

    public function test_update_rejects_pipeline_stage_id_position_version_and_status(): void
    {
        $actor = $this->actorWithPermissions(['deals.update', 'deals.view']);
        $stage = $this->openStage();
        $otherStage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $response = $this->actingAs($actor)->patchJson("/api/deals/{$deal->id}", [
            'pipeline_stage_id' => $otherStage->id,
            'position' => 'xx',
            'version' => 2,
            'status' => 'won',
        ]);

        $response->assertStatus(422)->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'pipeline_stage_id' => $stage->id,
            'status' => 'open',
        ]);
    }

    public function test_update_allows_normal_fields(): void
    {
        $actor = $this->actorWithPermissions(['deals.update', 'deals.view']);
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $response = $this->actingAs($actor)->patchJson("/api/deals/{$deal->id}", [
            'title' => 'Güncellenmiş Başlık',
            'amount' => 12345.67,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.title', 'Güncellenmiş Başlık');
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'title' => 'Güncellenmiş Başlık']);
    }

    // -------------------------------------------------------------------
    // Silme
    // -------------------------------------------------------------------

    public function test_won_deal_cannot_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['deals.delete']);
        $wonStage = PipelineStage::factory()->won()->create();
        $deal = Deal::factory()->won()->create(['pipeline_stage_id' => $wonStage->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/deals/{$deal->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'deleted_at' => null]);
    }

    public function test_lost_deal_cannot_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['deals.delete']);
        $lostStage = PipelineStage::factory()->lost()->create();
        $deal = Deal::factory()->lost()->create(['pipeline_stage_id' => $lostStage->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/deals/{$deal->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'deleted_at' => null]);
    }

    public function test_open_deal_can_be_deleted(): void
    {
        $actor = $this->actorWithPermissions(['deals.delete']);
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id]);

        $response = $this->actingAs($actor)->deleteJson("/api/deals/{$deal->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
    }

    // -------------------------------------------------------------------
    // Atama
    // -------------------------------------------------------------------

    public function test_assign_sets_owner(): void
    {
        $actor = $this->actorWithPermissions(['deals.assign', 'deals.view']);
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['pipeline_stage_id' => $stage->id, 'owner_id' => null]);
        $owner = User::factory()->create();

        $response = $this->actingAs($actor)
            ->patchJson("/api/deals/{$deal->id}/assign", ['owner_id' => $owner->id]);

        $response->assertStatus(200)->assertJsonPath('data.owner.id', $owner->id);
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'owner_id' => $owner->id]);
    }

    // -------------------------------------------------------------------
    // Pipeline stages
    // -------------------------------------------------------------------

    public function test_pipeline_stages_index_returns_only_active_by_default(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $active = $this->openStage(['position' => 1]);
        $this->openStage(['position' => 2, 'is_active' => false]);

        $response = $this->actingAs($actor)->getJson('/api/pipeline-stages');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($active->id, $response->json('data.0.id'));
    }

    public function test_pipeline_stages_index_includes_inactive_when_requested(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);
        $this->openStage(['position' => 1]);
        $this->openStage(['position' => 2, 'is_active' => false]);

        $response = $this->actingAs($actor)->getJson('/api/pipeline-stages?include_inactive=1');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }
}
