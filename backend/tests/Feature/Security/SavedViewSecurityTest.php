<?php

namespace Tests\Feature\Security;

use App\Models\Deal;
use App\Models\SavedView;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * =============================================================================
 * FAZ 14 / İZ F / ATTIO C2 — KAYITLI GÖRÜNÜMLER GÜVENLİK MATRİSİ
 * =============================================================================
 * `docs/PHASE-AUDIT.md` §5.4 (BAĞLAYICI): "paylaşılan bir görünüm AÇAN kullanıcının
 * yetkisiyle çalışmalı, OLUŞTURANIN yetkisiyle DEĞİL — aksi hâlde 'confused deputy' ile
 * yetki yükseltme olur. `query_json` sunucuda yeniden doğrulanmalı, ham filtre olarak
 * sorguya gömülmemeli."
 *
 * Kurulum deseni `SearchAuthorizationTest`/`AutomationRulePermissionTest`'ten alındı
 * (`actorWithRole()`, `RolePermissionSeeder`) — ikinci bir kurulum yöntemi İCAT EDİLMEDİ.
 * Bu dosya görev tanımının ZORUNLU kıldığı 4 senaryoyu birebir kapsar:
 *   (a) confused-deputy: geniş kapsamlı A, dar kapsamlı B açtığında B YALNIZ kendi
 *       yetkisiyle görebildiğini görür/görmez.
 *   (b) `query_json`'a bilinmeyen/izinsiz alan veya sıralama sütunu enjekte edilince
 *       reddediliyor.
 *   (c) başkasının görünümünü düzenleme/silme 403.
 *   (d) paylaşılmamış görünüm başkasına HİÇ görünmüyor.
 */
class SavedViewSecurityTest extends TestCase
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

    // -------------------------------------------------------------------
    // (a) Confused deputy — AÇANIN yetkisi, OLUŞTURANIN yetkisi DEĞİL
    // -------------------------------------------------------------------

    /**
     * A ("Satış Müdürü", `deals.view` TAŞIR) geniş kapsamlı, paylaşılan bir `deals`
     * görünümü kaydeder. B ("Destek Temsilcisi", `deals.view` TAŞIMAZ — dar kapsamlı)
     * bu görünümü açmaya çalışır. B'nin OLUŞTURAN A'nın yetkisini ÖDÜNÇ ALMADIĞI iki
     * ayrı katmanda kanıtlanır:
     *   1) B, görünümün metadata'sını (`GET /api/saved-views?module=deals`) bile GÖREMEZ
     *      — modülün varlığını/filtrelerini bilemez.
     *   2) B, A'nın görünümünden aldığı filtreleri GERÇEK `GET /api/deals` ucuna
     *      elle taşısa bile (saldırganın en kötümser senaryosu) YİNE 403 alır — çünkü
     *      veri her zaman AÇANIN (B'nin) kendi Policy kontrolünden geçen normal liste
     *      ucundan çekilir, SavedView bu akışı ASLA bypass etmez.
     */
    public function test_confused_deputy_narrow_scoped_opener_gets_nothing_from_a_broad_shared_view(): void
    {
        $creator = $this->actorWithRole('Satış Müdürü');
        $opener = $this->actorWithRole('Destek Temsilcisi');

        Deal::factory()->count(3)->create();

        $sharedView = SavedView::factory()->forModule('deals')->shared()->create([
            'user_id' => $creator->id,
            'name' => 'Tüm açık fırsatlar',
            'query_json' => ['filter' => ['status' => 'open']],
        ]);

        // 1) Metadata bile görünmüyor.
        $this->actingAs($opener)->getJson('/api/saved-views?module=deals')->assertStatus(403);

        // 2) Filtreleri gerçek uca elle taşısa bile veri gelmiyor.
        $this->actingAs($opener)
            ->getJson('/api/deals?'.http_build_query(['filter' => $sharedView->query_json['filter']]))
            ->assertStatus(403);
    }

    /**
     * Aynı paylaşılan görünüm, MODÜLÜ görebilen bir kullanıcı ("Satış Temsilcisi",
     * `deals.view` TAŞIR) tarafından açıldığında normal şekilde çalışır — ve bunu
     * OLUŞTURAN A'nın oturumuna/kimliğine hiç dokunmadan, yalnızca B'nin kendi
     * oturumuyla yapar (A bu istekte hiç `actingAs` edilmedi).
     */
    public function test_shared_view_works_for_an_opener_who_has_their_own_permission(): void
    {
        $creator = $this->actorWithRole('Satış Müdürü');
        $opener = $this->actorWithRole('Satış Temsilcisi');

        Deal::factory()->count(2)->create();

        $sharedView = SavedView::factory()->forModule('deals')->shared()->create([
            'user_id' => $creator->id,
            'name' => 'Tüm açık fırsatlar',
            'query_json' => ['filter' => ['status' => 'open']],
        ]);

        $listResponse = $this->actingAs($opener)->getJson('/api/saved-views?module=deals')->assertOk();
        $this->assertTrue(collect($listResponse->json('data'))->pluck('id')->contains($sharedView->id));

        $this->actingAs($opener)
            ->getJson('/api/deals?'.http_build_query(['filter' => $sharedView->query_json['filter']]))
            ->assertOk();
    }

    // -------------------------------------------------------------------
    // (b) query_json enjeksiyonu reddedilir
    // -------------------------------------------------------------------

    public function test_unknown_filter_field_injection_is_rejected_not_silently_dropped(): void
    {
        $user = $this->actorWithRole('Admin');

        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'users',
            'name' => 'Enjeksiyon denemesi',
            'query_json' => ['filter' => ['is_active' => true, 'password' => 'anything']],
        ])->assertStatus(422);

        $this->assertArrayHasKey('query_json', $response->json('errors.fields') ?? []);
        $this->assertDatabaseMissing('saved_views', ['name' => 'Enjeksiyon denemesi']);
    }

    public function test_disallowed_sort_column_injection_is_rejected(): void
    {
        $user = $this->actorWithRole('Admin');

        // `email` `users` modülünde GEÇERLİ bir sıralama sütunu, ama `deals` modülünde
        // DEĞİL — modül başına beyaz liste kontrolünü kanıtlar (genel bir sütun-var-mı
        // kontrolü değil).
        $response = $this->actingAs($user)->postJson('/api/saved-views', [
            'module' => 'deals',
            'name' => 'Sıralama enjeksiyonu',
            'query_json' => ['sort' => 'email'],
        ])->assertStatus(422);

        $this->assertArrayHasKey('query_json', $response->json('errors.fields') ?? []);
    }

    // -------------------------------------------------------------------
    // (c) Başkasının görünümünü düzenleme/silme 403
    // -------------------------------------------------------------------

    public function test_editing_another_users_shared_view_is_forbidden(): void
    {
        $owner = $this->actorWithRole('Admin');
        $attacker = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)
            ->patchJson("/api/saved-views/{$view->id}", ['is_shared' => false])
            ->assertStatus(403);

        $this->assertDatabaseHas('saved_views', ['id' => $view->id, 'is_shared' => true]);
    }

    public function test_deleting_another_users_shared_view_is_forbidden(): void
    {
        $owner = $this->actorWithRole('Admin');
        $attacker = $this->actorWithRole('Admin');
        $view = SavedView::factory()->forModule('deals')->shared()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)->deleteJson("/api/saved-views/{$view->id}")->assertStatus(403);

        $this->assertDatabaseHas('saved_views', ['id' => $view->id]);
    }

    // -------------------------------------------------------------------
    // (d) Paylaşılmamış görünüm başkasına HİÇ görünmüyor
    // -------------------------------------------------------------------

    public function test_unshared_view_is_completely_invisible_to_another_user_with_the_same_permission(): void
    {
        $owner = $this->actorWithRole('Admin');
        $otherUser = $this->actorWithRole('Admin');
        $privateView = SavedView::factory()->forModule('deals')->create(['user_id' => $owner->id, 'name' => 'Gizli görünümüm']);

        $response = $this->actingAs($otherUser)->getJson('/api/saved-views?module=deals')->assertOk();

        $this->assertFalse(collect($response->json('data'))->pluck('id')->contains($privateView->id));
    }
}
