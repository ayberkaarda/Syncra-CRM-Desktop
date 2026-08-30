<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * =============================================================================
 * FAZ 14 / İZ F / ATTIO C1 — GLOBAL ARAMA YETKİLENDİRME MATRİSİ
 * =============================================================================
 * `docs/PHASE-AUDIT.md` §5.4: "C1 global arama: tek uç 7 modülü birleştirdiği
 * için tek bir hata TÜM veriyi sızdırır. Sonuçlar modül bazlı `.view` izniyle
 * filtrelenmeli ... izinsiz modül sonuç kümesinde HİÇ görünmemeli (sayı/varlık
 * sızıntısı dahil)."
 *
 * Bu dosya `GlobalSearchService::search()`'ün o kararını (izinsiz modülün
 * ANAHTARININ yanıtta hiç oluşmaması — boş dizi bile değil) rol bazında
 * kilitler. Kurulum deseni `OwnershipIsolationTest`/`ExportThrottleTest`'ten
 * alındı (`actorWithRole()`, `RolePermissionSeeder`) — ikinci bir kurulum
 * yöntemi İCAT EDİLMEDİ.
 */
class SearchAuthorizationTest extends TestCase
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
     * Terimle eşleşen bir kayıt her modülde oluşturur — böylece bir anahtarın
     * yokluğu "zaten eşleşen kayıt yoktu" ile karıştırılamaz; her modülde
     * GERÇEKTEN eşleşen bir satır var, buna rağmen izinsiz modülün anahtarı
     * yanıtta olmamalı.
     */
    private function seedMatchingRecordInEveryModule(): void
    {
        Deal::factory()->create(['title' => 'Qzxseek Fırsatı']);
        Lead::factory()->create(['first_name' => 'Qzxseek']);
        Contact::factory()->create(['first_name' => 'Qzxseek']);
        Company::factory()->create(['name' => 'Qzxseek A.Ş.']);
        Quote::factory()->create(['title' => 'Qzxseek Teklifi']);
        Ticket::factory()->create(['subject' => 'Qzxseek Sorunu']);
        User::factory()->create(['name' => 'Qzxseek Kullanıcı']);
    }

    // =========================================================================
    // Kimlik doğrulama
    // =========================================================================

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/search?q=Qzxseek')->assertStatus(401);
    }

    // =========================================================================
    // Satış Temsilcisi — tickets.view YOK, users.view YOK
    // =========================================================================

    public function test_sales_rep_never_sees_tickets_or_users_keys(): void
    {
        $this->seedMatchingRecordInEveryModule();
        $actor = $this->actorWithRole('Satış Temsilcisi');

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);

        $data = $response->json('data');

        // İzinli modüller: gerçekten çalışmalı (bu regresyon bir "her şeyi
        // gizle" kaçamağıyla yanlışlıkla yeşile dönmesin diye).
        $this->assertArrayHasKey('deals', $data);
        $this->assertArrayHasKey('leads', $data);
        $this->assertArrayHasKey('contacts', $data);
        $this->assertArrayHasKey('companies', $data);
        $this->assertArrayHasKey('quotes', $data);
        $this->assertNotEmpty($data['deals']);

        // İzinsiz modüller: ANAHTAR bile yok (boş dizi değil — bkz. sınıf dokümanı).
        $this->assertArrayNotHasKey('tickets', $data);
        $this->assertArrayNotHasKey('users', $data);

        // Ham yanıt gövdesinde de "tickets"/"users" kelimesi hiç geçmemeli —
        // anahtar adının kendisi bile bir modülün varlığını ele verebilir.
        $raw = $response->getContent();
        $this->assertStringNotContainsString('"tickets"', $raw);
        $this->assertStringNotContainsString('"users"', $raw);
    }

    // =========================================================================
    // Destek Temsilcisi — deals.view YOK, quotes.view YOK, leads.view YOK
    // =========================================================================

    public function test_support_rep_never_sees_deals_quotes_or_leads_keys(): void
    {
        $this->seedMatchingRecordInEveryModule();
        $actor = $this->actorWithRole('Destek Temsilcisi');

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertArrayHasKey('tickets', $data);
        $this->assertArrayHasKey('contacts', $data);
        $this->assertArrayHasKey('companies', $data);
        $this->assertNotEmpty($data['tickets']);

        $this->assertArrayNotHasKey('deals', $data);
        $this->assertArrayNotHasKey('quotes', $data);
        $this->assertArrayNotHasKey('leads', $data);
        $this->assertArrayNotHasKey('users', $data);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('"deals"', $raw);
        $this->assertStringNotContainsString('"quotes"', $raw);
        $this->assertStringNotContainsString('"leads"', $raw);
    }

    // =========================================================================
    // İzleyici — tüm `.view` izinleri var (yazma yok); 7 modülün 7'si de görünür
    // =========================================================================

    public function test_viewer_sees_all_seven_module_keys(): void
    {
        $this->seedMatchingRecordInEveryModule();
        $actor = $this->actorWithRole('İzleyici');

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);

        $data = $response->json('data');

        foreach (['deals', 'leads', 'contacts', 'companies', 'quotes', 'tickets', 'users'] as $module) {
            $this->assertArrayHasKey($module, $data, "İzleyici '{$module}' anahtarını görmeli.");
            $this->assertNotEmpty($data[$module], "İzleyici '{$module}' modülünde eşleşen kaydı görmeli.");
        }
    }

    // =========================================================================
    // Sayı sızıntısı yok — izinsiz modül için `meta`/`count` alanında da
    // hiçbir iz bulunmamalı (yalnızca anahtar değil, toplam sayısı da yok).
    // =========================================================================

    public function test_response_carries_no_count_for_unauthorized_modules(): void
    {
        $this->seedMatchingRecordInEveryModule();
        $actor = $this->actorWithRole('Destek Temsilcisi');

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);

        $raw = $response->getContent();
        // "deals_count", "leads_count" gibi bir sayaç alanı da olmamalı.
        $this->assertStringNotContainsString('deals_count', $raw);
        $this->assertStringNotContainsString('leads_count', $raw);
        $this->assertStringNotContainsString('quotes_count', $raw);
    }

    // =========================================================================
    // Throttle — `throttle:60,1,search`
    // =========================================================================

    public function test_search_allows_sixty_requests_per_minute_then_throttles(): void
    {
        $actor = $this->actorWithRole('Admin');

        for ($i = 1; $i <= 60; $i++) {
            $this->actingAs($actor)
                ->getJson('/api/search?q=ab')
                ->assertStatus(200);
        }

        $this->actingAs($actor)
            ->getJson('/api/search?q=ab')
            ->assertStatus(429);
    }
}
