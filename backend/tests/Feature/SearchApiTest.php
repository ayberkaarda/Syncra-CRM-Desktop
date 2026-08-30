<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SearchAuthorizationTest;
use Tests\TestCase;

/**
 * `GET /api/search` — işlevsel sözleşme testleri (eşleşme doğruluğu, joker
 * kaçışı, N+1, sayfalama YOKLUĞU, sözleşme şekli). Yetkilendirme/anahtar-
 * sızıntısı matrisi ayrı dosyada: {@see SearchAuthorizationTest}.
 */
class SearchApiTest extends TestCase
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
    // Kimlik doğrulama
    // -------------------------------------------------------------------

    public function test_guest_cannot_search(): void
    {
        $this->getJson('/api/search?q=deneme')->assertStatus(401);
    }

    // -------------------------------------------------------------------
    // Doğrulama (min uzunluk, zorunluluk)
    // -------------------------------------------------------------------

    public function test_missing_query_is_rejected(): void
    {
        $actor = $this->actorWithRole('Admin');

        $this->actingAs($actor)->getJson('/api/search')->assertStatus(422);
    }

    public function test_single_character_query_is_rejected(): void
    {
        $actor = $this->actorWithRole('Admin');

        $this->actingAs($actor)->getJson('/api/search?q=a')->assertStatus(422);
    }

    public function test_two_character_query_is_accepted(): void
    {
        $actor = $this->actorWithRole('Admin');

        $this->actingAs($actor)->getJson('/api/search?q=ab')->assertStatus(200);
    }

    // -------------------------------------------------------------------
    // Eşleşme doğruluğu — 7 modülden birer kayıt, ortak terimle ara
    // -------------------------------------------------------------------

    public function test_common_term_matches_a_record_in_every_module(): void
    {
        $actor = $this->actorWithRole('Admin'); // Admin 7 modülün de .view iznini taşır.

        $deal = Deal::factory()->create(['title' => 'Qzxseek Fırsatı']);
        $lead = Lead::factory()->create(['first_name' => 'Qzxseek', 'last_name' => 'Yılmaz']);
        $contact = Contact::factory()->create(['first_name' => 'Qzxseek', 'last_name' => 'Demir']);
        $company = Company::factory()->create(['name' => 'Qzxseek Teknoloji A.Ş.']);
        $quote = Quote::factory()->create(['title' => 'Qzxseek Teklifi']);
        $ticket = Ticket::factory()->create(['subject' => 'Qzxseek Sorunu']);
        $user = User::factory()->create(['name' => 'Qzxseek Kullanıcı']);

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);

        $response->assertJsonPath('data.deals.0.id', $deal->id);
        $response->assertJsonPath('data.deals.0.type', 'deal');
        $response->assertJsonPath('data.deals.0.link', "/deals/{$deal->id}");

        $response->assertJsonPath('data.leads.0.id', $lead->id);
        $response->assertJsonPath('data.contacts.0.id', $contact->id);
        $response->assertJsonPath('data.companies.0.id', $company->id);
        $response->assertJsonPath('data.quotes.0.id', $quote->id);
        $response->assertJsonPath('data.quotes.0.subtitle', $quote->quote_number);
        $response->assertJsonPath('data.tickets.0.id', $ticket->id);
        $response->assertJsonPath('data.users.0.id', $user->id);
        $response->assertJsonPath('data.users.0.link', '/users');

        // Sözleşme şekli: her sonuç en az type/id/title/subtitle/link taşır.
        foreach (['deals', 'leads', 'contacts', 'companies', 'quotes', 'tickets', 'users'] as $module) {
            $item = $response->json("data.{$module}.0");
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayHasKey('subtitle', $item);
            $this->assertArrayHasKey('link', $item);
        }
    }

    // -------------------------------------------------------------------
    // Joker karakter kaçışı — `%` tüm kayıtları döndürmemeli
    // -------------------------------------------------------------------

    public function test_percent_wildcard_in_query_is_escaped_and_does_not_match_everything(): void
    {
        $actor = $this->actorWithRole('Admin');

        Company::factory()->count(5)->create();

        // "%" kaçırılmazsa `LIKE '%%%'` her satırla eşleşir (her string en
        // az 0 karakter içerir). Kaçırılırsa literal bir "%" karakteri
        // aranır — hiçbir factory-üretimi firma adı bunu içermez.
        $response = $this->actingAs($actor)->getJson('/api/search?q=%25%25');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.companies'));
    }

    public function test_underscore_wildcard_in_query_is_escaped(): void
    {
        $actor = $this->actorWithRole('Admin');

        // Kaçış YOKSA `_` "herhangi bir tek karakter" anlamına gelir ve
        // "Qzxseek" "Q_xseek" deseniyle eşleşir. Kaçırılırsa yalnızca
        // literal "_" karakteri içeren adlar eşleşmeli.
        Company::factory()->create(['name' => 'Qzxseek Ltd.']);

        $response = $this->actingAs($actor)->getJson('/api/search?q=Q_xseek');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.companies'));
    }

    // -------------------------------------------------------------------
    // Sayfalama YOK — bu bir komut paleti ucu; `meta.pagination` beklenmez.
    // -------------------------------------------------------------------

    public function test_response_has_no_pagination_metadata(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->getJson('/api/search?q=deneme');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('meta.pagination');
    }

    // -------------------------------------------------------------------
    // Modül başına sonuç sınırı (PER_MODULE_LIMIT = 5)
    // -------------------------------------------------------------------

    public function test_module_results_are_capped_at_five(): void
    {
        $actor = $this->actorWithRole('Admin');

        Company::factory()->count(8)->create(['name' => 'Qzxseek Şirketi']);

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data.companies'));
    }

    // -------------------------------------------------------------------
    // N+1 kontrolü — modül sayısı sabit, sorgu sayısı kayıt sayısıyla BÜYÜMEMELİ
    // -------------------------------------------------------------------

    public function test_search_does_not_n_plus_one_across_modules(): void
    {
        $actor = $this->actorWithRole('Admin');

        // Her modülden birkaç eşleşen kayıt — ilişkili (company) alanlar
        // dahil (Deal/Contact `with(['company'])` kullanıyor).
        $company = Company::factory()->create(['name' => 'Qzxseek Holding']);
        Deal::factory()->count(3)->create(['title' => 'Qzxseek Fırsatı', 'company_id' => $company->id]);
        Lead::factory()->count(3)->create(['first_name' => 'Qzxseek']);
        Contact::factory()->count(3)->create(['first_name' => 'Qzxseek', 'company_id' => $company->id]);
        Quote::factory()->count(3)->create(['title' => 'Qzxseek Teklifi']);
        Ticket::factory()->count(3)->create(['subject' => 'Qzxseek Sorunu']);
        User::factory()->count(3)->create(['name' => 'Qzxseek Kullanıcı']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($actor)->getJson('/api/search?q=Qzxseek');

        $response->assertStatus(200);

        // 7 modül x (1 ana sorgu + en fazla 1 eager-load sorgusu) + Gate/
        // auth ile ilgili birkaç ek sorgu (izin/rol yükleme). N+1 olsaydı
        // (her SONUÇ satırı için ayrı bir company sorgusu) eşleşen kayıt
        // sayısı arttıkça bu sayı da artardı; burada SABİT bir tavana
        // yakın kalması beklenir.
        $this->assertLessThan(
            40,
            count($queries),
            'Beklenenden fazla sorgu çalıştı ('.count($queries).') - N+1 şüphesi:'."\n".implode("\n", $queries)
        );
    }
}
