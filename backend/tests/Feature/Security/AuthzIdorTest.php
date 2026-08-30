<?php

namespace Tests\Feature\Security;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 13 / İz A — A2.1, A2.2, A2.3, A2.4, A2.7, A2.8 güvenlik regresyon testleri.
 *
 * =============================================================================
 * A2.1/A2.2 — ÇAPRAZ SAHİP OKUMA/GÜNCELLEME/SİLME: GERÇEK POLICY DAVRANIŞI
 * =============================================================================
 * PHASE-AUDIT §2/A2.1-A2.2 "Policy sahiplik/görünürlük kuralı; yetkisizse
 * 403/404" diyor ve varsayımı KENDİ KODUMUZDAN doğrulamamızı istiyor —
 * "kendi beklentini koda dayatma". `app/Policies/{Deal,Lead,Contact,Company,
 * Quote,Task,Ticket}Policy.php` TEK TEK OKUNDU: HİÇBİRİ `owner_id`/
 * `assigned_to`/`created_by` karşılaştırması YAPMIYOR — `view`/`update`/
 * `delete` yalnızca `$user->can('<modül>.<eylem>')` izin kontrolüdür (silinen
 * kayıtlar için ek durum kısıtı olsa da, KİMİN sahip olduğuyla ilgisi yok).
 * TEK İSTİSNA `ActivityPolicy::delete()` — o da AYRICA test edilip
 * KONTRAST olarak gösteriliyor.
 *
 * SONUÇ: bu sistemde yetkilendirme modeli SATIR (kayıt) bazlı değil, MODÜL
 * (izin) bazlı düz bir RBAC'tır — "Satış Temsilcisi yalnızca KENDİ
 * lead/deal/quote/task'ını görür" ifadesi (PHASE-AUDIT §3 rol tablosu) bir
 * UI/varsayılan filtre beklentisidir, POLICY SEVİYESİNDE ZORUNLU BİR SINIR
 * DEĞİLDİR: aynı izne sahip herhangi bir kullanıcı, başka birinin kaydını
 * görebilir/güncelleyebilir/silebilir. Bu testler bu GERÇEK davranışı
 * kilitler (kod değiştirilmedi) ve raporda teknik lidere AÇIKÇA bildirildi —
 * eğer amaç satır bazlı izolasyonsa bu bir sertleştirme kalemi olabilir.
 *
 * =============================================================================
 * FAZ 13 GÜNCELLEMESİ — "OKUMA DÜZ, YAZMA SAHİPLE SINIRLI" (Model C)
 * =============================================================================
 * Yukarıdaki A2.1/A2.2 sonucu ("MODÜL bazlı düz RBAC, satır bazlı sınır YOK")
 * Faz 13'te KISMEN geçersiz kılındı: `App\Policies\Concerns\
 * ChecksRecordOwnership` artık `update`/`move`/`convert`/`complete` (ve
 * `tickets.status`'un geçtiği `update`) için modül izninin YETMEDİĞİNİ,
 * ayrıca kullanıcının kaydın SAHİBİ olması, kayıt SAHİPSİZ olması ya da
 * kullanıcının modülün `*.assign` iznini taşıması GEREKTİĞİNİ şart koşuyor.
 * `view`/`viewAny` bu kuraldan MUAF — okuma BİLİNÇLİ olarak düz kaldı
 * (paylaşılan Kanban/kayıt-sohbeti gereksinimi, İzleyici'nin salt-okuma rolü
 * olması). Silme KAPSAM DIŞI bırakıldı (zaten yalnızca Müdür/Admin'de ve bu
 * roller `*.assign` da taşıyor — kontrol no-op olurdu).
 *
 * Aşağıdaki ÜÇ test bu yüzden GÜNCELLENDİ (eski hâlleri "yazma da izinden
 * başka bir şey sormaz" iddiasını kilitliyordu, ki artık YANLIŞ):
 * `deals_cross_owner_read_stays_flat_but_write_now_requires_ownership_or_assign`,
 * `leads_cross_owner_read_stays_flat_but_write_now_requires_ownership_or_assign`,
 * `activities_update_now_requires_ownership_or_the_delete_permission_matching_delete`.
 * `contacts`/`companies`/`quotes` testleri DEĞİŞMEDİ (bu üç modül Faz 13
 * kapsamı DIŞINDA bırakıldı — bkz. ContactPolicy/CompanyPolicy/QuotePolicy
 * docblock'ları). `tasks`/`tickets` cross-owner testleri de DEĞİŞMEDİ: ikisi
 * de aktör olarak `tasks.assign`/`tickets.assign` TAŞIYAN bir rol kullanıyordu
 * (Satış Müdürü / Admin), yani yeni kural altında da hâlâ true — tesadüfen
 * zaten "yönetici" senaryosunu test ediyorlardı. Tam matris (sahiplik
 * olmadan/sahipsiz kayıtla/yönetici izniyle 8 uç) ayrı dosyada:
 * `tests/Feature/Security/OwnershipIsolationTest.php`.
 *
 * =============================================================================
 * A2.3 — YATAY ATAMA MANİPÜLASYONU
 * =============================================================================
 * `/assign` uçları AYRI bir izne bağlı (`deals.assign`, `leads.assign`,
 * `tasks.assign`, `tickets.assign`) ve o izne sahip olmayan bir aktör bu
 * uçlardan 403 alır — bu KİLİTLENİYOR. (NOT: `MassAssignmentTest::
 * test_FINDING_deals_update_permission_alone_can_reassign_owner_bypassing_deals_assign`
 * bu kapının GENEL update ucundan baypas edilebildiğini AYRI BİR BULGU olarak
 * kilitliyor — o dosyanın sorumluluğu, burada tekrarlanmıyor.)
 *
 * =============================================================================
 * A2.4 — İZLEYİCİ: HİÇBİR YAZMA YETKİSİ YOK
 * =============================================================================
 * İzleyici rolü yalnızca `.view` izinlerini taşır (RolePermissionSeeder).
 * Aşağıdaki sweep, 8 modülün ana yazma uçlarının TAMAMINDA 403 döndüğünü tek
 * bir testte kilitler — gövdeler bilerek GEÇERLİ (yalnızca yetki nedeniyle
 * 403 alınsın, FormRequest 422'si karışmasın).
 *
 * =============================================================================
 * A2.7 — Log/Rapor/Dashboard izin sınırı
 * =============================================================================
 * `logs.view`, `reports.view`, `dashboard.view` yokken ilgili uçlar 403.
 *
 * =============================================================================
 * A2.8 — UserPolicy: kendini kilitleme / son Super Admin
 * =============================================================================
 * `UserPolicy::delete()`/`toggleActive()` HTTP seviyesinde kilitleniyor.
 */
class AuthzIdorTest extends TestCase
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

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    // =====================================================================
    // A2.1 / A2.2 — çapraz sahip erişim: GERÇEK (izin bazlı, sahiplik-körü) davranış
    // =====================================================================

    /**
     * ESKİ DAVRANIŞ (bu test eskiden neyi kilitliyordu): `DealPolicy::update()`
     * yalnızca `deals.update` izni soruyordu — A, B'nin sahip olduğu fırsatı
     * sahiplikten TAMAMEN bağımsız güncelleyebiliyordu. Test adı da
     * ("...are_governed_by_permission_not_ownership") bunu iddia ediyordu.
     *
     * NEDEN DEĞİŞTİ (Faz 13): `App\Policies\Concerns\ChecksRecordOwnership`
     * eklendi — `update`/`move` artık modül izni YETMİYOR, ayrıca aktör
     * SAHİP olmalı, kayıt SAHİPSİZ olmalı ya da aktör `deals.assign`
     * taşımalı. OKUMA (`view`) BİLİNÇLİ olarak DEĞİŞMEDİ — paylaşılan Kanban
     * gereksinimi ve İzleyici'nin salt-okuma rolü olması gerekçesiyle (bkz.
     * ChecksRecordOwnership docblock'u) — bu test onu da AÇIKÇA kilitliyor.
     */
    public function test_deals_cross_owner_read_stays_flat_but_write_now_requires_ownership_or_assign(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // deals.view/update/create/move, ama deals.assign YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $deal = Deal::factory()->create(['owner_id' => $repB->id, 'pipeline_stage_id' => $this->openStage()->id]);

        // OKUMA hâlâ düz: bu BİLİNÇLİ bir karar (paylaşılan pipeline/Kanban),
        // Faz 13 sertleştirmesi yalnızca YAZMAYI kapsıyor.
        $this->actingAs($repA)->getJson("/api/deals/{$deal->id}")->assertStatus(200);

        // YAZMA artık 403: A ne sahip ne de deals.assign taşıyor.
        $this->actingAs($repA)->patchJson("/api/deals/{$deal->id}", ['title' => 'A tarafından değiştirildi'])
            ->assertStatus(403);
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'title' => $deal->title]);

        // Silme Faz 13 kapsamı DIŞINDA (DealPolicy::delete() dokümanı):
        // deals.delete zaten yalnızca Müdür/Admin/Super Admin'de ve bu roller
        // deals.assign de taşıyor, bu yüzden hâlâ sahiplikten bağımsız silinebiliyor.
        $manager = $this->actorWithRole('Satış Müdürü');
        $this->actingAs($manager)->deleteJson("/api/deals/{$deal->id}")->assertStatus(204);
        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
    }

    /**
     * ESKİ DAVRANIŞ / NEDEN DEĞİŞTİ: bkz. yukarıdaki deals testinin docblock'u
     * — LeadPolicy::update() için BİREBİR aynı hikâye, `leads.assign` sinyaliyle.
     */
    public function test_leads_cross_owner_read_stays_flat_but_write_now_requires_ownership_or_assign(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // leads.assign YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $lead = Lead::factory()->create(['owner_id' => $repB->id, 'status' => 'new']);

        // OKUMA hâlâ düz (bilinçli karar).
        $this->actingAs($repA)->getJson("/api/leads/{$lead->id}")->assertStatus(200);

        // YAZMA artık 403.
        $this->actingAs($repA)->patchJson("/api/leads/{$lead->id}", ['first_name' => 'DeğiştirildiA'])
            ->assertStatus(403);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => $lead->first_name]);

        // Delete kapsam dışı: leads.delete yalnızca Müdür/Admin/Super Admin'de,
        // bu roller leads.assign de taşıyor.
        $manager = $this->actorWithRole('Admin'); // leads.delete + leads.assign
        $this->actingAs($manager)->deleteJson("/api/leads/{$lead->id}")->assertStatus(204);
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
    }

    public function test_contacts_cross_owner_view_update_and_delete_are_governed_by_permission_not_ownership(): void
    {
        $rep = $this->actorWithRole('Satış Müdürü'); // contacts.view/update/delete
        $otherOwner = User::factory()->create();
        $contact = Contact::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($rep)->getJson("/api/contacts/{$contact->id}")->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/contacts/{$contact->id}", ['first_name' => 'Değiştirildi'])
            ->assertStatus(200);
        $this->actingAs($rep)->deleteJson("/api/contacts/{$contact->id}")->assertStatus(204);
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    public function test_companies_cross_owner_view_update_and_delete_are_governed_by_permission_not_ownership(): void
    {
        $rep = $this->actorWithRole('Satış Müdürü'); // companies.view/update/delete
        $otherOwner = User::factory()->create();
        $company = Company::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($rep)->getJson("/api/companies/{$company->id}")->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/companies/{$company->id}", ['name' => 'Değiştirildi A.Ş.'])
            ->assertStatus(200);
        $this->actingAs($rep)->deleteJson("/api/companies/{$company->id}")->assertStatus(204);
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_quotes_cross_creator_view_update_and_delete_are_governed_by_permission_not_ownership(): void
    {
        $rep = $this->actorWithRole('Satış Müdürü'); // quotes.view/update/delete
        $otherCreator = User::factory()->create();
        $quote = Quote::factory()->create(['created_by' => $otherCreator->id, 'status' => 'draft']);

        $this->actingAs($rep)->getJson("/api/quotes/{$quote->id}")->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/quotes/{$quote->id}", ['title' => 'Değiştirilen Teklif'])
            ->assertStatus(200);
        $this->actingAs($rep)->deleteJson("/api/quotes/{$quote->id}")->assertStatus(204);
        $this->assertSoftDeleted('quotes', ['id' => $quote->id]);
    }

    public function test_tasks_cross_assignee_view_update_and_delete_are_governed_by_permission_not_ownership(): void
    {
        $rep = $this->actorWithRole('Satış Müdürü'); // tasks.view/update/delete
        $otherAssignee = User::factory()->create();
        $task = Task::factory()->create(['assigned_to' => $otherAssignee->id, 'created_by' => $otherAssignee->id]);

        $this->actingAs($rep)->getJson("/api/tasks/{$task->id}")->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/tasks/{$task->id}", ['title' => 'Değiştirilen Görev'])
            ->assertStatus(200);
        $this->actingAs($rep)->deleteJson("/api/tasks/{$task->id}")->assertStatus(204);
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_tickets_cross_assignee_view_update_and_delete_are_governed_by_permission_not_ownership(): void
    {
        $rep = $this->actorWithRole('Admin'); // tickets.view/update/delete (Destek Temsilcisi'nde delete yok)
        $otherAssignee = User::factory()->create();
        $ticket = Ticket::factory()->create(['assigned_to' => $otherAssignee->id, 'created_by' => $otherAssignee->id, 'status' => 'open']);

        $this->actingAs($rep)->getJson("/api/tickets/{$ticket->id}")->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/tickets/{$ticket->id}", ['subject' => 'Değiştirilen Talep'])
            ->assertStatus(200);
        $this->actingAs($rep)->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(204);
        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    /**
     * ESKİ DAVRANIŞ (bu test eskiden neyi kilitliyordu): `ActivityPolicy::
     * update()` sahiplik SORMUYORDU — yalnızca `activities.update` izniyle
     * A, B'nin yazdığı aktiviteyi güncelleyebiliyordu. `delete()` ise TEK
     * İSTİSNA olarak zaten yazan kişiyi (`user_id`) ya da `activities.delete`
     * iznini şart koşuyordu — test bu KONTRASTI (update sahiplik-körü, delete
     * değil) kilitliyordu.
     *
     * NEDEN DEĞİŞTİ (Faz 13): `ActivityPolicy::update()` artık
     * `ChecksRecordOwnership::ownsOrManages()` kullanıyor — `activities.
     * assign` DİYE BİR İZİN OLMADIĞI için yönetici sinyali olarak
     * `activities.delete` geçiliyor (o izni taşıyan roller zaten Müdür/Admin).
     * Sonuç: KONTRAST ORTADAN KALKTI — update artık delete ile BİREBİR AYNI
     * sahiplik/izin mantığını paylaşıyor (bkz. ActivityPolicy::update()
     * docblock'u: "ikincisi daha sinsi, silinen not kaybolur ama değiştirilen
     * not yanlış içeriğiyle doğru sanılır").
     */
    public function test_activities_update_now_requires_ownership_or_the_delete_permission_matching_delete(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // activities.view/create/update, activities.delete YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $activity = Activity::factory()->create([
            'user_id' => $repB->id,
            'activityable_type' => Lead::class,
            'activityable_id' => Lead::factory()->create()->id,
        ]);

        // OKUMA hâlâ düz (Faz 13 kapsamı yalnızca yazma).
        $this->actingAs($repA)->getJson("/api/activities/{$activity->id}")->assertStatus(200);

        // update: A ne yazan kişi NE DE activities.delete taşıyor -> artık 403
        // (eskiden 200'dü).
        $this->actingAs($repA)->patchJson("/api/activities/{$activity->id}", ['subject' => 'A tarafından değiştirildi'])
            ->assertStatus(403);
        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'subject' => $activity->subject]);

        // delete: AYNI şekilde 403 — davranış burada değişmedi.
        $this->actingAs($repA)->deleteJson("/api/activities/{$activity->id}")->assertStatus(403);
        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'deleted_at' => null]);

        // Yazan kişi (B) kendi kaydını hem güncelleyebilir hem silebilir —
        // izinsiz de olsa creator kuralı (ownsOrManages: ownerId === user->id).
        $this->actingAs($repB)->patchJson("/api/activities/{$activity->id}", ['subject' => 'B tarafından değiştirildi'])
            ->assertStatus(200);
        $this->actingAs($repB)->deleteJson("/api/activities/{$activity->id}")->assertStatus(204);

        // KONTRAST (pozitif): activities.delete taşıyan bir yönetici (Satış
        // Müdürü), yazan kişi olmasa bile başkasının aktivitesini güncelleyebilir
        // — ownsOrManages'in "yönetici" ayağı.
        $activity2 = Activity::factory()->create([
            'user_id' => $repB->id,
            'activityable_type' => Lead::class,
            'activityable_id' => Lead::factory()->create()->id,
        ]);
        $manager = $this->actorWithRole('Satış Müdürü'); // activities.delete
        $this->actingAs($manager)->patchJson("/api/activities/{$activity2->id}", ['subject' => 'Yönetici tarafından değiştirildi'])
            ->assertStatus(200);
    }

    // =====================================================================
    // A2.3 — yatay atama manipülasyonu: /assign uçları izinsiz erişimi reddeder
    // =====================================================================

    public function test_assign_endpoints_reject_an_actor_without_the_dedicated_assign_permission(): void
    {
        $noPerm = User::factory()->create(); // rolsüz/izinsiz
        $targetOwner = User::factory()->create();

        $deal = Deal::factory()->create(['pipeline_stage_id' => $this->openStage()->id]);
        $this->actingAs($noPerm)->patchJson("/api/deals/{$deal->id}/assign", ['owner_id' => $targetOwner->id])
            ->assertStatus(403);

        $lead = Lead::factory()->create();
        $this->actingAs($noPerm)->patchJson("/api/leads/{$lead->id}/assign", ['owner_id' => $targetOwner->id])
            ->assertStatus(403);

        $task = Task::factory()->create();
        $this->actingAs($noPerm)->patchJson("/api/tasks/{$task->id}/assign", ['assigned_to' => $targetOwner->id])
            ->assertStatus(403);

        $ticket = Ticket::factory()->create();
        $this->actingAs($noPerm)->patchJson("/api/tickets/{$ticket->id}/assign", ['assigned_to' => $targetOwner->id])
            ->assertStatus(403);
    }

    public function test_assign_endpoint_succeeds_and_is_not_restricted_to_a_specific_target_owner_once_permitted(): void
    {
        // Pozitif kontrast: izni OLAN bir aktör, hedefin kim olduğuna
        // bakılmaksızın atayabiliyor (Policy hedef kullanıcıyı kısıtlamıyor,
        // yalnızca aktörün `deals.assign` iznini soruyor).
        $manager = $this->actorWithRole('Satış Müdürü');
        $deal = Deal::factory()->create(['pipeline_stage_id' => $this->openStage()->id]);
        $newOwner = User::factory()->create();

        $this->actingAs($manager)->patchJson("/api/deals/{$deal->id}/assign", ['owner_id' => $newOwner->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'owner_id' => $newOwner->id]);
    }

    // =====================================================================
    // A2.4 — İzleyici: hiçbir yazma yetkisi yok (tüm ana POST/PATCH/DELETE)
    // =====================================================================

    public function test_izleyici_role_is_forbidden_from_every_main_write_endpoint_across_all_modules(): void
    {
        $izleyici = $this->actorWithRole('İzleyici');

        $owner = User::factory()->create();
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $this->openStage()->id]);
        $lead = Lead::factory()->create(['owner_id' => $owner->id, 'status' => 'new']);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);
        $company = Company::factory()->create(['owner_id' => $owner->id]);
        $quote = Quote::factory()->create(['created_by' => $owner->id, 'status' => 'draft']);
        $task = Task::factory()->create(['assigned_to' => $owner->id]);
        $ticket = Ticket::factory()->create(['assigned_to' => $owner->id, 'status' => 'open']);
        $activity = Activity::factory()->create([
            'user_id' => $owner->id,
            'activityable_type' => Lead::class,
            'activityable_id' => $lead->id,
        ]);

        $targetUser = User::factory()->create();

        // [method, uri, body] — her biri GEÇERLİ bir gövde taşır ki 403,
        // FormRequest'in 422'siyle KARIŞMASIN (yalnızca yetki nedeniyle reddedilsin).
        $attempts = [
            ['post', '/api/leads', ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'izle1@example.com', 'source' => 'website']],
            ['patch', "/api/leads/{$lead->id}", ['first_name' => 'X']],
            ['delete', "/api/leads/{$lead->id}", []],
            ['post', "/api/leads/{$lead->id}/convert", []],
            ['patch', "/api/leads/{$lead->id}/assign", ['owner_id' => $targetUser->id]],

            ['post', '/api/contacts', ['first_name' => 'X', 'last_name' => 'Y']],
            ['patch', "/api/contacts/{$contact->id}", ['first_name' => 'X']],
            ['delete', "/api/contacts/{$contact->id}", []],

            ['post', '/api/companies', ['name' => 'X A.Ş.']],
            ['patch', "/api/companies/{$company->id}", ['name' => 'X A.Ş.']],
            ['delete', "/api/companies/{$company->id}", []],

            ['post', '/api/deals', ['title' => 'X Fırsatı']],
            ['patch', "/api/deals/{$deal->id}", ['title' => 'X']],
            ['delete', "/api/deals/{$deal->id}", []],
            ['patch', "/api/deals/{$deal->id}/move", ['to_stage_id' => $this->openStage()->id, 'version' => $deal->version]],
            ['patch', "/api/deals/{$deal->id}/assign", ['owner_id' => $targetUser->id]],

            ['post', '/api/tasks', ['title' => 'X Görevi']],
            ['patch', "/api/tasks/{$task->id}", ['title' => 'X']],
            ['delete', "/api/tasks/{$task->id}", []],
            ['patch', "/api/tasks/{$task->id}/complete", ['completed' => true]],
            ['patch', "/api/tasks/{$task->id}/assign", ['assigned_to' => $targetUser->id]],

            ['post', '/api/tickets', ['subject' => 'X Talebi', 'description' => 'Açıklama']],
            ['patch', "/api/tickets/{$ticket->id}", ['subject' => 'X']],
            ['delete', "/api/tickets/{$ticket->id}", []],
            ['patch', "/api/tickets/{$ticket->id}/status", ['status' => 'in_progress']],
            ['patch', "/api/tickets/{$ticket->id}/assign", ['assigned_to' => $targetUser->id]],

            ['post', '/api/activities', ['type' => 'note', 'subject' => 'X', 'occurred_at' => now()->toIso8601String()]],
            ['patch', "/api/activities/{$activity->id}", ['subject' => 'X']],
            ['delete', "/api/activities/{$activity->id}", []],

            ['post', '/api/quotes', ['title' => 'X Teklifi']],
            ['patch', "/api/quotes/{$quote->id}", ['title' => 'X']],
            ['delete', "/api/quotes/{$quote->id}", []],
            ['post', "/api/quotes/{$quote->id}/send", []],
            ['patch', "/api/quotes/{$quote->id}/status", ['status' => 'rejected']],
            ['post', "/api/quotes/{$quote->id}/revise", []],
        ];

        foreach ($attempts as [$method, $uri, $body]) {
            $response = $this->actingAs($izleyici)->json(strtoupper($method), $uri, $body);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                sprintf('İzleyici %s %s ucundan 403 ALMALIYDI, %d aldı. Gövde: %s', strtoupper($method), $uri, $response->getStatusCode(), json_encode($body))
            );
        }
    }

    // =====================================================================
    // A2.7 — log/rapor/dashboard izin sınırı
    // =====================================================================

    public function test_reports_logs_and_dashboard_endpoints_require_their_respective_view_permission(): void
    {
        $noPerm = User::factory()->create(); // rolsüz/izinsiz

        $this->actingAs($noPerm)->getJson('/api/reports/sales-performance')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/reports/user-performance')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/reports/source-analysis')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/reports/conversion')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/reports/export')->assertStatus(403);

        $this->actingAs($noPerm)->getJson('/api/logs/sessions')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/logs/page-visits')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/logs/activities')->assertStatus(403);
        // `type` zorunlu bir alan (ExportLogRequest) — FormRequest doğrulaması
        // Gate kontrolünden ÖNCE çalışır, bu yüzden geçerli bir değer
        // GEÇİLMELİ; aksi halde 403 değil 422 alınır ve izin kontrolü hiç
        // test edilmemiş olur.
        $this->actingAs($noPerm)->getJson('/api/logs/export?type=sessions')->assertStatus(403);

        $this->actingAs($noPerm)->getJson('/api/dashboard/kpis')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/dashboard/funnel')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/dashboard/revenue-trend')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/dashboard/recent-activities')->assertStatus(403);
        $this->actingAs($noPerm)->getJson('/api/dashboard/task-summary')->assertStatus(403);
    }

    public function test_reports_export_requires_reports_export_specifically_not_just_reports_view(): void
    {
        // reports.view sahibi ama reports.export SAHİP OLMAYAN bir aktör
        // (Destek Temsilcisi'nin AKSİNE burada elle kurgulanıyor: yalnızca
        // reports.view veriliyor) export ucundan 403 almalı — iki izin AYRI.
        $actor = User::factory()->create();
        $actor->givePermissionTo('reports.view');

        $this->actingAs($actor)->getJson('/api/reports/sales-performance')->assertStatus(200);
        $this->actingAs($actor)->getJson('/api/reports/export')->assertStatus(403);
    }

    // =====================================================================
    // A2.8 — UserPolicy: kendini kilitleme / son Super Admin
    // =====================================================================

    public function test_user_cannot_deactivate_their_own_account_via_the_api(): void
    {
        $actor = $this->actorWithRole('Admin'); // users.toggle_active

        $response = $this->actingAs($actor)->patchJson("/api/users/{$actor->id}/active", ['is_active' => false]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'is_active' => true]);
    }

    public function test_user_cannot_delete_their_own_account_via_the_api(): void
    {
        $actor = $this->actorWithRole('Admin'); // users.delete
        $actor->givePermissionTo('users.delete');

        $response = $this->actingAs($actor)->deleteJson("/api/users/{$actor->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $actor->id, 'deleted_at' => null]);
    }

    /**
     * Aktör bilerek Super Admin DEĞİL (Admin + users.toggle_active +
     * users.manage_roles): aktör Super Admin olsaydı `Gate::before` (A2.5)
     * Policy'yi hiç ÇAĞIRMADAN `true` dönerdi ve bu test aslında
     * `UserPolicy::toggleActive()`'in son-aktif-Super-Admin kuralını değil,
     * Gate::before kısayolunu ölçerdi. Kilit gerçekten Policy'de mi diye
     * bakmak için aktörün NORMAL Gate akışından geçmesi gerekir.
     */
    public function test_the_last_active_super_admin_cannot_be_deactivated_by_a_non_super_admin_actor(): void
    {
        $lastSuperAdmin = $this->actorWithRole('Super Admin');

        $admin = $this->actorWithRole('Admin');
        $admin->givePermissionTo(['users.manage_roles', 'users.toggle_active']);

        $this->assertSame(1, User::role('Super Admin')->where('is_active', true)->count());

        $response = $this->actingAs($admin)->patchJson(
            "/api/users/{$lastSuperAdmin->id}/active",
            ['is_active' => false],
        );

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $lastSuperAdmin->id, 'is_active' => true]);
    }

    public function test_the_last_active_super_admin_cannot_be_deleted(): void
    {
        $lastSuperAdmin = $this->actorWithRole('Super Admin');

        $admin = $this->actorWithRole('Admin');
        $admin->givePermissionTo(['users.manage_roles', 'users.delete']);

        $this->assertSame(1, User::role('Super Admin')->where('is_active', true)->count());

        $response = $this->actingAs($admin)->deleteJson("/api/users/{$lastSuperAdmin->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $lastSuperAdmin->id, 'deleted_at' => null]);
    }
}
