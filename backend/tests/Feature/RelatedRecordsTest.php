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
use Tests\TestCase;

/**
 * Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli
 * (docs/PHASE-INTL.md §3, docs/PHASE-AUDIT.md §5.1 C3 satırı).
 *
 * Bu dosya YALNIZCA bu fazda genişletilen `show()` uçlarının yeni `related`
 * bloğunu kilitler: (1) izin filtresi — modül izni olmayan kullanıcı ilgili
 * anahtarı YANITTA HİÇ görmez (boş dizi bile değil); (2) N+1 yok — kayıt
 * sayısı arttıkça sorgu sayısı SABİT kalır.
 */
class RelatedRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // -------------------------------------------------------------------
    // Yetki filtresi — C3'ün bağlayıcı güvenlik kısıtı (§5.1 C1 ile aynı sınıf)
    // -------------------------------------------------------------------

    public function test_company_show_hides_related_groups_without_module_permission(): void
    {
        // `quotes.view` BİLEREK yok — firmayı görebilen ama teklif izni
        // olmayan kullanıcı o firmanın tekliflerini HİÇ görmemeli.
        $actor = $this->actorWithPermissions(['companies.view', 'deals.view', 'tickets.view']);

        $company = Company::factory()->create();
        Deal::factory()->create(['company_id' => $company->id]);
        Ticket::factory()->create(['company_id' => $company->id]);
        Quote::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($actor)->getJson("/api/companies/{$company->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.deals.total', 1);
        $response->assertJsonPath('data.related.tickets.total', 1);
        // Anahtar TAMAMEN yok — null/boş dizi değil.
        $response->assertJsonMissingPath('data.related.quotes');
    }

    public function test_company_show_includes_quotes_group_with_permission(): void
    {
        $actor = $this->actorWithPermissions(['companies.view', 'quotes.view']);

        $company = Company::factory()->create();
        $quote = Quote::factory()->create(['company_id' => $company->id, 'title' => 'Bulut Teklifi']);

        $response = $this->actingAs($actor)->getJson("/api/companies/{$company->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.quotes.total', 1);
        $response->assertJsonPath('data.related.quotes.items.0.id', $quote->id);
        // `deals`/`tickets` izni yok — hiç eklenmemiş olmalı.
        $response->assertJsonMissingPath('data.related.deals');
        $response->assertJsonMissingPath('data.related.tickets');
    }

    public function test_contact_show_hides_related_groups_without_module_permission(): void
    {
        // `quotes.view` BİLEREK yok — kişiyi görebilen ama teklif izni olmayan
        // kullanıcı o kişinin tekliflerini HİÇ görmemeli (Faz 14 kapanışı).
        $actor = $this->actorWithPermissions(['contacts.view']);

        $contact = Contact::factory()->create();
        Deal::factory()->create(['contact_id' => $contact->id]);
        Ticket::factory()->create(['contact_id' => $contact->id]);
        Quote::factory()->create(['contact_id' => $contact->id]);

        $response = $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}")
            ->assertStatus(200);

        $response->assertJsonMissingPath('data.related.deals');
        $response->assertJsonMissingPath('data.related.tickets');
        // Anahtar TAMAMEN yok — null/boş dizi değil.
        $response->assertJsonMissingPath('data.related.quotes');
    }

    public function test_contact_show_includes_deals_and_tickets_with_permission(): void
    {
        $actor = $this->actorWithPermissions(['contacts.view', 'deals.view', 'tickets.view']);

        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create(['contact_id' => $contact->id]);
        $ticket = Ticket::factory()->create(['contact_id' => $contact->id]);

        $response = $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.deals.total', 1);
        $response->assertJsonPath('data.related.deals.items.0.id', $deal->id);
        $response->assertJsonPath('data.related.tickets.total', 1);
        $response->assertJsonPath('data.related.tickets.items.0.id', $ticket->id);
        // `quotes.view` yok — hiç eklenmemiş olmalı.
        $response->assertJsonMissingPath('data.related.quotes');
    }

    public function test_contact_show_includes_quotes_group_with_permission(): void
    {
        $actor = $this->actorWithPermissions(['contacts.view', 'quotes.view']);

        $contact = Contact::factory()->create();
        $quote = Quote::factory()->create(['contact_id' => $contact->id, 'title' => 'Bulut Teklifi']);

        $response = $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.quotes.total', 1);
        $response->assertJsonPath('data.related.quotes.items.0.id', $quote->id);
        // `deals`/`tickets` izni yok — hiç eklenmemiş olmalı.
        $response->assertJsonMissingPath('data.related.deals');
        $response->assertJsonMissingPath('data.related.tickets');
    }

    public function test_deal_show_hides_quotes_group_without_permission(): void
    {
        $actor = $this->actorWithPermissions(['deals.view']);

        $deal = Deal::factory()->create();
        Quote::factory()->create(['deal_id' => $deal->id]);

        $response = $this->actingAs($actor)->getJson("/api/deals/{$deal->id}")
            ->assertStatus(200);

        $response->assertJsonMissingPath('data.related.quotes');
    }

    public function test_deal_show_includes_quotes_group_with_permission(): void
    {
        $actor = $this->actorWithPermissions(['deals.view', 'quotes.view']);

        $deal = Deal::factory()->create();
        $quote = Quote::factory()->create(['deal_id' => $deal->id]);

        $response = $this->actingAs($actor)->getJson("/api/deals/{$deal->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.quotes.total', 1);
        $response->assertJsonPath('data.related.quotes.items.0.id', $quote->id);
    }

    public function test_lead_show_includes_converted_records_with_permission(): void
    {
        $actor = $this->actorWithPermissions(['leads.view', 'contacts.view', 'companies.view', 'deals.view']);

        $contact = Contact::factory()->create();
        $company = Company::factory()->create();
        $deal = Deal::factory()->create();

        $lead = Lead::factory()->create([
            'status' => 'converted',
            'converted_contact_id' => $contact->id,
            'converted_company_id' => $company->id,
            'converted_deal_id' => $deal->id,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/leads/{$lead->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.converted_contact.items.0.id', $contact->id);
        $response->assertJsonPath('data.related.converted_company.items.0.id', $company->id);
        $response->assertJsonPath('data.related.converted_deal.items.0.id', $deal->id);
    }

    public function test_lead_show_hides_converted_company_without_permission(): void
    {
        $actor = $this->actorWithPermissions(['leads.view', 'contacts.view']);

        $contact = Contact::factory()->create();
        $company = Company::factory()->create();

        $lead = Lead::factory()->create([
            'status' => 'converted',
            'converted_contact_id' => $contact->id,
            'converted_company_id' => $company->id,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/leads/{$lead->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.converted_contact.items.0.id', $contact->id);
        $response->assertJsonMissingPath('data.related.converted_company');
    }

    public function test_quote_show_includes_related_company_deal_contact_with_permission(): void
    {
        $actor = $this->actorWithPermissions(['quotes.view', 'companies.view', 'deals.view', 'contacts.view']);

        $company = Company::factory()->create();
        $deal = Deal::factory()->create();
        $contact = Contact::factory()->create();

        $quote = Quote::factory()->create([
            'company_id' => $company->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/quotes/{$quote->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.company.total', 1);
        $response->assertJsonPath('data.related.company.items.0.id', $company->id);
        $response->assertJsonPath('data.related.deal.total', 1);
        $response->assertJsonPath('data.related.deal.items.0.id', $deal->id);
        $response->assertJsonPath('data.related.contact.total', 1);
        $response->assertJsonPath('data.related.contact.items.0.id', $contact->id);
    }

    public function test_quote_show_hides_related_groups_without_module_permission(): void
    {
        // `deals.view`/`contacts.view` BİLEREK yok — bu teklifi görebilen ama
        // fırsat/kişi izni olmayan kullanıcı o yönleri HİÇ görmemeli. `companies.view`
        // VAR — o grup görünmeli, izinsiz olanlar tamamen yok olmalı.
        $actor = $this->actorWithPermissions(['quotes.view', 'companies.view']);

        $company = Company::factory()->create();
        $deal = Deal::factory()->create();
        $contact = Contact::factory()->create();

        $quote = Quote::factory()->create([
            'company_id' => $company->id,
            'deal_id' => $deal->id,
            'contact_id' => $contact->id,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/quotes/{$quote->id}")
            ->assertStatus(200);

        $response->assertJsonPath('data.related.company.total', 1);
        // Anahtar TAMAMEN yok — null/boş dizi değil.
        $response->assertJsonMissingPath('data.related.deal');
        $response->assertJsonMissingPath('data.related.contact');
    }

    public function test_quote_show_omits_related_group_when_link_is_null(): void
    {
        // Teklifin firma/fırsat/kişi bağlantısı yoksa (hepsi null) ilgili modülün
        // izni olsa bile `related` altında o anahtar hiç yer almamalı — LeadController
        // ile aynı FK-null kontrolü (bkz. QuoteController::loadRelatedRecords()).
        $actor = $this->actorWithPermissions(['quotes.view', 'companies.view', 'deals.view', 'contacts.view']);

        $quote = Quote::factory()->create([
            'company_id' => null,
            'deal_id' => null,
            'contact_id' => null,
        ]);

        $response = $this->actingAs($actor)->getJson("/api/quotes/{$quote->id}")
            ->assertStatus(200);

        $response->assertJsonMissingPath('data.related.company');
        $response->assertJsonMissingPath('data.related.deal');
        $response->assertJsonMissingPath('data.related.contact');
    }

    // -------------------------------------------------------------------
    // N+1 yok — sorgu sayısı KAYIT SAYISIYLA değil, GRUP SAYISIYLA orantılı
    // -------------------------------------------------------------------

    public function test_company_show_query_count_does_not_grow_with_related_record_count(): void
    {
        $actor = $this->actorWithPermissions(['companies.view', 'deals.view', 'quotes.view', 'tickets.view']);
        $company = Company::factory()->create();

        // Az sayıda ilişkili kayıt.
        Deal::factory()->count(2)->create(['company_id' => $company->id]);
        Quote::factory()->count(2)->create(['company_id' => $company->id]);
        Ticket::factory()->count(2)->create(['company_id' => $company->id]);

        DB::enableQueryLog();
        $this->actingAs($actor)->getJson("/api/companies/{$company->id}")->assertStatus(200);
        $fewQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Şimdi çok daha fazla ilişkili kayıt (limit 5'in çok üstünde). Fabrika
        // kayıtlarının KENDİ sorguları (insert + pipeline stage lookup vb.)
        // ölçüme karışmasın diye log burada KAPALI.
        Deal::factory()->count(20)->create(['company_id' => $company->id]);
        Quote::factory()->count(20)->create(['company_id' => $company->id]);
        Ticket::factory()->count(20)->create(['company_id' => $company->id]);

        DB::enableQueryLog();
        $this->actingAs($actor)->getJson("/api/companies/{$company->id}")->assertStatus(200);
        $manyQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Kayıt sayısı 3'ten 63'e çıktı ama sorgu sayısı SABİT (±birkaç sorgu
        // toleransı — izin önbelleği/lazy singleton gibi çalıştırmalar arası
        // gürültü için) kalmalı: grup başına count() + limitli get() = 2 sorgu
        // × 3 grup, kayıt sayısından BAĞIMSIZ. Gerçek bir N+1 olsaydı fark
        // onlarca/yüzlerce sorgu olurdu (ilk ölçümde gözlemlendiği gibi).
        $this->assertLessThanOrEqual(
            $fewQueryCount + 3,
            $manyQueryCount,
            "Sorgu sayısı ilişkili kayıt sayısıyla birlikte arttı: {$fewQueryCount} -> {$manyQueryCount} (N+1 şüphesi)."
        );
    }

    public function test_contact_show_query_count_does_not_grow_with_related_record_count(): void
    {
        $actor = $this->actorWithPermissions(['contacts.view', 'deals.view', 'quotes.view', 'tickets.view']);
        $contact = Contact::factory()->create();

        // Az sayıda ilişkili kayıt.
        Deal::factory()->count(2)->create(['contact_id' => $contact->id]);
        Quote::factory()->count(2)->create(['contact_id' => $contact->id]);
        Ticket::factory()->count(2)->create(['contact_id' => $contact->id]);

        DB::enableQueryLog();
        $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}")->assertStatus(200);
        $fewQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        // Şimdi çok daha fazla ilişkili kayıt (limit 5'in çok üstünde). Fabrika
        // kayıtlarının KENDİ sorguları ölçüme karışmasın diye log burada KAPALI.
        Deal::factory()->count(20)->create(['contact_id' => $contact->id]);
        Quote::factory()->count(20)->create(['contact_id' => $contact->id]);
        Ticket::factory()->count(20)->create(['contact_id' => $contact->id]);

        DB::enableQueryLog();
        $this->actingAs($actor)->getJson("/api/contacts/{$contact->id}")->assertStatus(200);
        $manyQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $fewQueryCount + 3,
            $manyQueryCount,
            "Sorgu sayısı ilişkili kayıt sayısıyla birlikte arttı: {$fewQueryCount} -> {$manyQueryCount} (N+1 şüphesi)."
        );
    }
}
