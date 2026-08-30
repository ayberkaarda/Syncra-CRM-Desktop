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
 * =============================================================================
 * FAZ 13 — "OKUMA DÜZ, YAZMA SAHİPLE SINIRLI" (Model C) MATRİS TESTİ
 * =============================================================================
 * `App\Policies\Concerns\ChecksRecordOwnership` şu kuralı uygular:
 * `update`/`move`/`convert`/`complete` (ve `tickets.status`'un geçtiği
 * `update`) için modül izni TEK BAŞINA yetmez — aktör kaydın SAHİBİ olmalı,
 * kayıt SAHİPSİZ olmalı ya da aktör modülün `*.assign` iznini taşımalıdır.
 * `view`/`viewAny` BU KURALDAN MUAFTIR (bilinçli karar — bkz. aşağıdaki
 * `test_cross_owner_read_remains_flat_across_all_hardened_modules`).
 *
 * Bu dosya o kuralı DealPolicy/LeadPolicy/TaskPolicy/TicketPolicy/
 * ActivityPolicy için matris hâlinde kilitler. `AuthzIdorTest` ve
 * `MassAssignmentTest`'teki ilgili 5 test (Faz 13'te güncellendi) bu
 * matrisin YALNIZCA birer örneğini taşıyordu; burada matris TAMAMLANIYOR:
 * sahipsiz kayıt (havuz), yönetici (`*.assign`) kontrastı, destek kuyruğu
 * regresyonu ve kapsam dışı (Contact/Company/Quote) doğrulaması.
 *
 * Genel kurulum deseni `AuthzIdorTest`'ten alındı (`actorWithRole()`,
 * `openStage()`) — kendi kurulum yöntemi İCAT EDİLMEDİ.
 */
class OwnershipIsolationTest extends TestCase
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
     * Rolsüz, YALNIZCA verilen izinlere sahip bir aktör. Tickets modülünde
     * `tickets.update` taşıyıp `tickets.assign` TAŞIMAYAN gerçek bir rol
     * YOKTUR (RolePermissionSeeder'da `Destek Temsilcisi`'nin TAMAMI
     * `tickets.assign` taşır — bkz. TicketPolicy::update() dokümanı ve
     * aşağıdaki destek kuyruğu regresyon testi). Çekirdek kuralı (sahiplik
     * kontrolünün GERÇEKTEN çalıştığını) rol kompozisyonundan bağımsız
     * doğrulamak için burada elle kurgulanmış, dar izinli bir aktör kullanılır.
     *
     * @param  array<int, string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    // =====================================================================
    // MATRİS — çapraz sahip YAZMA: sahiplik yok, sahipsiz değil, *.assign yok -> 403
    // =====================================================================

    public function test_deals_cross_owner_update_and_move_are_forbidden_without_ownership_or_deals_assign(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // deals.update/move VAR, deals.assign YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $stage = $this->openStage();
        $deal = Deal::factory()->create(['owner_id' => $repB->id, 'pipeline_stage_id' => $stage->id]);

        $this->actingAs($repA)->patchJson("/api/deals/{$deal->id}", ['title' => 'X'])
            ->assertStatus(403);

        $toStage = $this->openStage();
        $this->actingAs($repA)->patchJson("/api/deals/{$deal->id}/move", [
            'to_stage_id' => $toStage->id,
            'version' => $deal->version,
        ])->assertStatus(403);

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'title' => $deal->title, 'pipeline_stage_id' => $stage->id]);
    }

    public function test_leads_cross_owner_update_and_convert_are_forbidden_without_ownership_or_leads_assign(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // leads.update/convert VAR, leads.assign YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $lead = Lead::factory()->create(['owner_id' => $repB->id, 'status' => 'contacted']);

        $this->actingAs($repA)->patchJson("/api/leads/{$lead->id}", ['first_name' => 'X'])
            ->assertStatus(403);

        $this->actingAs($repA)->postJson("/api/leads/{$lead->id}/convert", [])
            ->assertStatus(403);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => $lead->first_name, 'status' => 'contacted']);
    }

    public function test_tasks_cross_assignee_update_and_complete_are_forbidden_without_ownership_or_tasks_assign(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // tasks.update VAR, tasks.assign YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $task = Task::factory()->create(['assigned_to' => $repB->id, 'status' => 'pending']);

        $this->actingAs($repA)->patchJson("/api/tasks/{$task->id}", ['title' => 'X'])
            ->assertStatus(403);

        $this->actingAs($repA)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true])
            ->assertStatus(403);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title, 'status' => 'pending']);
    }

    /**
     * `tickets.assign`'i OLMAYAN dar izinli bir aktörle kurgulandı (bkz.
     * actorWithPermissions() dokümanı) — gerçek `Destek Temsilcisi` rolü
     * `tickets.assign`'i her zaman taşıdığı için matrisi izole test etmenin
     * tek yolu budur. Gerçekçi (rol bazlı) senaryo destek kuyruğu regresyon
     * testinde ayrıca kapsanıyor.
     */
    public function test_tickets_cross_assignee_update_and_status_change_are_forbidden_when_actor_lacks_tickets_assign(): void
    {
        $repA = $this->actorWithPermissions(['tickets.view', 'tickets.update']); // tickets.assign YOK
        $repB = User::factory()->create();
        $ticket = Ticket::factory()->create(['assigned_to' => $repB->id, 'status' => 'open']);

        $this->actingAs($repA)->patchJson("/api/tickets/{$ticket->id}", ['subject' => 'X'])
            ->assertStatus(403);

        $this->actingAs($repA)->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertStatus(403);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'subject' => $ticket->subject, 'status' => 'open']);
    }

    public function test_activities_cross_owner_update_is_forbidden_without_ownership_or_activities_delete(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi'); // activities.update VAR, activities.delete YOK
        $repB = $this->actorWithRole('Satış Temsilcisi');
        $activity = Activity::factory()->create([
            'user_id' => $repB->id,
            'activityable_type' => Lead::class,
            'activityable_id' => Lead::factory()->create()->id,
        ]);

        $this->actingAs($repA)->patchJson("/api/activities/{$activity->id}", ['subject' => 'X'])
            ->assertStatus(403);

        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'subject' => $activity->subject]);
    }

    // =====================================================================
    // HAVUZ DAVRANIŞI — sahipsiz kayıt (owner_id/assigned_to NULL) herkese açık
    // =====================================================================

    /**
     * Yalnızca `update` ucu test edildi: `move`/`convert`/`complete`/
     * `tickets.status` da BİREBİR AYNI `ownsOrManages()` çağrısını kullanıyor
     * (yukarıdaki matris testlerinde zaten görüldü) — sahipsiz kayıt kolu
     * `$ownerId === null` erken dönüşü olduğundan eylem türünden BAĞIMSIZDIR,
     * her eylemi ayrı ayrı tekrar etmek aynı kod yolunu yeniden test eder.
     */
    public function test_unowned_records_are_writable_by_any_permitted_actor_across_deals_leads_tasks_and_tickets(): void
    {
        $rep = $this->actorWithRole('Satış Temsilcisi'); // hiçbir *.assign taşımıyor
        $ticketRep = $this->actorWithPermissions(['tickets.view', 'tickets.update']); // tickets.assign YOK

        $deal = Deal::factory()->create(['owner_id' => null, 'pipeline_stage_id' => $this->openStage()->id]);
        $lead = Lead::factory()->create(['owner_id' => null, 'status' => 'new']);
        $task = Task::factory()->create(['assigned_to' => null, 'status' => 'pending']);
        $ticket = Ticket::factory()->create(['assigned_to' => null, 'status' => 'open']);

        $this->actingAs($rep)->patchJson("/api/deals/{$deal->id}", ['title' => 'Havuzdan alındı'])
            ->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/leads/{$lead->id}", ['first_name' => 'Havuzdan'])
            ->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/tasks/{$task->id}", ['title' => 'Havuzdan alındı'])
            ->assertStatus(200);
        $this->actingAs($ticketRep)->patchJson("/api/tickets/{$ticket->id}", ['subject' => 'Havuzdan alındı'])
            ->assertStatus(200);

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'title' => 'Havuzdan alındı']);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Havuzdan']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Havuzdan alındı']);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'subject' => 'Havuzdan alındı']);
    }

    /**
     * KONTRAST: `Activity` bu havuz muafiyetinin DIŞINDA — `user_id === null`
     * "öksüz kayıt" sayılır (yazarı silinmiş), sahipsiz DEĞİL, ve yalnızca
     * `activities.delete` taşıyan biri dokunabilir (bkz. ActivityPolicy::
     * update() dokümanı, `ChecksRecordOwnership::ownsOrManages()` hiç
     * ÇAĞRILMIYOR bu dalda). Bu bilinçli asimetriyi burada AÇIKÇA kilitliyoruz
     * ki "her modülde null = havuz" varsayımı yanlışlıkla genellenmesin.
     */
    public function test_activities_with_a_null_user_id_are_orphaned_not_pooled_unlike_other_modules(): void
    {
        $rep = $this->actorWithRole('Satış Temsilcisi'); // activities.update VAR, activities.delete YOK
        $activity = Activity::factory()->create([
            'user_id' => null,
            'activityable_type' => Lead::class,
            'activityable_id' => Lead::factory()->create()->id,
        ]);

        $this->actingAs($rep)->patchJson("/api/activities/{$activity->id}", ['subject' => 'X'])
            ->assertStatus(403);

        $manager = $this->actorWithRole('Satış Müdürü'); // activities.delete
        $this->actingAs($manager)->patchJson("/api/activities/{$activity->id}", ['subject' => 'Yönetici düzeltti'])
            ->assertStatus(200);
    }

    // =====================================================================
    // YÖNETİCİ KONTRASTI — *.assign taşıyan aktör çapraz sahip yazabilir
    // =====================================================================

    public function test_managers_and_admins_can_write_cross_owner_records_via_the_assign_permission(): void
    {
        $owner = User::factory()->create();

        $salesManager = $this->actorWithRole('Satış Müdürü'); // deals/leads/tasks.assign + activities.delete
        $admin = $this->actorWithRole('Admin'); // tickets.assign (Satış Müdürü'nde tickets izni YOK)

        $stage = $this->openStage();
        $deal = Deal::factory()->create(['owner_id' => $owner->id, 'pipeline_stage_id' => $stage->id]);
        $toStage = $this->openStage();
        $lead = Lead::factory()->create(['owner_id' => $owner->id, 'status' => 'contacted']);
        $task = Task::factory()->create(['assigned_to' => $owner->id, 'status' => 'pending']);
        $ticket = Ticket::factory()->create(['assigned_to' => $owner->id, 'status' => 'open']);
        $activity = Activity::factory()->create([
            'user_id' => $owner->id,
            'activityable_type' => Lead::class,
            'activityable_id' => Lead::factory()->create()->id,
        ]);

        $this->actingAs($salesManager)->patchJson("/api/deals/{$deal->id}", ['title' => 'Müdür değiştirdi'])
            ->assertStatus(200);
        $this->actingAs($salesManager)->patchJson("/api/deals/{$deal->id}/move", [
            'to_stage_id' => $toStage->id,
            'version' => $deal->fresh()->version,
        ])->assertStatus(200);
        $this->actingAs($salesManager)->patchJson("/api/leads/{$lead->id}", ['first_name' => 'Müdür'])
            ->assertStatus(200);
        $this->actingAs($salesManager)->patchJson("/api/tasks/{$task->id}", ['title' => 'Müdür değiştirdi'])
            ->assertStatus(200);
        $this->actingAs($salesManager)->patchJson("/api/tasks/{$task->id}/complete", ['completed' => true])
            ->assertStatus(200);
        $this->actingAs($salesManager)->patchJson("/api/activities/{$activity->id}", ['subject' => 'Müdür değiştirdi'])
            ->assertStatus(200);

        $this->actingAs($admin)->patchJson("/api/tickets/{$ticket->id}", ['subject' => 'Admin değiştirdi'])
            ->assertStatus(200);
        $this->actingAs($admin)->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertStatus(200);

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'title' => 'Müdür değiştirdi', 'pipeline_stage_id' => $toStage->id]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Müdür']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Müdür değiştirdi', 'status' => 'completed']);
        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'subject' => 'Müdür değiştirdi']);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'subject' => 'Admin değiştirdi', 'status' => 'in_progress']);
    }

    // =====================================================================
    // OKUMA — bilinçli olarak DÜZ kaldı (Faz 13 kapsamı yalnızca yazma)
    // =====================================================================

    /**
     * GEREKÇE: ürün gereksinimi paylaşılan realtime bir Kanban panosu istiyor
     * ve kayda bağlı sohbet "kaydı görebilen herkes açar" diyor; `İzleyici`
     * rolü TÜM `.view` izinlerine sahip salt-okuma bir roldür. Bu yüzden
     * `view`/`viewAny` Faz 13 sertleştirmesinin KAPSAMI DIŞINDA bilinçli
     * olarak bırakıldı — bu test o kararı AÇIKÇA kilitler: sahiplik/atama
     * izni OLMASA BİLE çapraz sahip GET her zaman 200 dönmeli.
     */
    public function test_cross_owner_read_remains_flat_across_all_hardened_modules(): void
    {
        $repA = $this->actorWithRole('Satış Temsilcisi');
        $ticketRepA = $this->actorWithPermissions(['tickets.view']);
        $repB = User::factory()->create();

        $deal = Deal::factory()->create(['owner_id' => $repB->id, 'pipeline_stage_id' => $this->openStage()->id]);
        $lead = Lead::factory()->create(['owner_id' => $repB->id, 'status' => 'new']);
        $task = Task::factory()->create(['assigned_to' => $repB->id]);
        $ticket = Ticket::factory()->create(['assigned_to' => $repB->id, 'status' => 'open']);
        $activity = Activity::factory()->create([
            'user_id' => $repB->id,
            'activityable_type' => Lead::class,
            'activityable_id' => Lead::factory()->create()->id,
        ]);

        $this->actingAs($repA)->getJson("/api/deals/{$deal->id}")->assertStatus(200);
        $this->actingAs($repA)->getJson("/api/leads/{$lead->id}")->assertStatus(200);
        $this->actingAs($repA)->getJson("/api/tasks/{$task->id}")->assertStatus(200);
        $this->actingAs($ticketRepA)->getJson("/api/tickets/{$ticket->id}")->assertStatus(200);
        $this->actingAs($repA)->getJson("/api/activities/{$activity->id}")->assertStatus(200);
    }

    // =====================================================================
    // DESTEK KUYRUĞU REGRESYONU — en önemli aşırı-düzeltme koruması
    // =====================================================================

    /**
     * =========================================================================
     * AŞIRI-DÜZELTMEYE KARŞI EN ÖNEMLİ TEST
     * =========================================================================
     * `Destek Temsilcisi` rolünün TAMAMI `tickets.assign` iznini taşır (bkz.
     * RolePermissionSeeder) — yani PAYLAŞILAN destek kuyruğunda bugün bir
     * talebe dokunabilen herkes YARIN DA dokunabilmelidir; hardening'in
     * "sahiplik veya *.assign" kuralı burada aslında NO-OP'tur çünkü her iki
     * temsilci de zaten `*.assign` taşıyor. Eğer bu test 403 dönerse,
     * hardening YANLIŞLIKLA paylaşılan kuyruğu bozmuş demektir — TicketPolicy::
     * update() dokümanının bizzat uyardığı senaryo budur.
     */
    public function test_two_different_support_reps_can_still_update_each_others_tickets_because_the_role_carries_tickets_assign(): void
    {
        $repA = $this->actorWithRole('Destek Temsilcisi');
        $repB = $this->actorWithRole('Destek Temsilcisi');
        $this->assertTrue($repA->hasPermissionTo('tickets.assign'));

        $ticket = Ticket::factory()->create(['assigned_to' => $repB->id, 'status' => 'open']);

        $this->actingAs($repA)->patchJson("/api/tickets/{$ticket->id}", ['subject' => 'A tarafından güncellendi'])
            ->assertStatus(200);
        $this->actingAs($repA)->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'in_progress'])
            ->assertStatus(200);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'subject' => 'A tarafından güncellendi',
            'status' => 'in_progress',
        ]);
    }

    // =====================================================================
    // KAPSAM DIŞI DOĞRULAMASI — Contact/Company/Quote Faz 13'e DAHİL DEĞİL
    // =====================================================================

    /**
     * Contact/Company BİLİNÇLİ olarak dışarıda: paylaşılan adres defteri /
     * master data (bkz. ContactPolicy/CompanyPolicy docblock'ları),
     * `*.assign` izni bile YOK bu modüllerde. Çapraz sahip yazma hâlâ 200
     * dönmeli — hardening bu iki modüle SIZMAMALI.
     */
    public function test_contact_and_company_cross_owner_writes_remain_flat_by_design(): void
    {
        $rep = $this->actorWithRole('Satış Müdürü'); // contacts.update/companies.update
        $otherOwner = User::factory()->create();

        $contact = Contact::factory()->create(['owner_id' => $otherOwner->id]);
        $company = Company::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($rep)->patchJson("/api/contacts/{$contact->id}", ['first_name' => 'Değiştirildi'])
            ->assertStatus(200);
        $this->actingAs($rep)->patchJson("/api/companies/{$company->id}", ['name' => 'Değiştirildi A.Ş.'])
            ->assertStatus(200);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'first_name' => 'Değiştirildi']);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Değiştirildi A.Ş.']);
    }

    /**
     * Quote BİLİNÇLİ olarak dışarıda: `quotes.update` bugünkü izin
     * matrisinde YALNIZCA Satış Müdürü/Admin/Super Admin'de — Satış
     * Temsilcisi teklif oluşturur ama güncelleyemez, yani bu uç zaten
     * yönetici düzeyinde ve `created_by` bazlı bir sahiplik kontrolü no-op
     * olurdu (bkz. QuotePolicy::update() docblock'u). Bu test hem "yaratıcı
     * olmayan ama izinli aktör hâlâ yazabiliyor" (flat) hem de "izinsiz
     * aktör (yaratıcı olsa bile) yazamıyor" (permission-gated, ownership
     * DEĞİL) yönlerini birlikte kilitler.
     */
    public function test_quote_update_permission_remains_manager_and_admin_only_regardless_of_creator(): void
    {
        $creatorRep = $this->actorWithRole('Satış Temsilcisi'); // quotes.create VAR, quotes.update YOK
        $quote = Quote::factory()->create(['created_by' => $creatorRep->id, 'status' => 'draft']);

        // Yaratıcının KENDİSİ bile quotes.update taşımadığı için 403.
        $this->actingAs($creatorRep)->patchJson("/api/quotes/{$quote->id}", ['title' => 'X'])
            ->assertStatus(403);

        // quotes.update taşıyan bir Müdür, YARATICI OLMASA BİLE güncelleyebiliyor (flat).
        $manager = $this->actorWithRole('Satış Müdürü'); // quotes.update
        $this->actingAs($manager)->patchJson("/api/quotes/{$quote->id}", ['title' => 'Müdür güncelledi'])
            ->assertStatus(200);

        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'title' => 'Müdür güncelledi']);
    }

    // =====================================================================
    // OLUŞTURMA ANINDA SAHİP ZORLAMASI (F8'in ikinci yarısı)
    // =====================================================================

    /**
     * `*.assign` izni OLMAYAN bir aktör, `POST /api/deals` gövdesinde
     * başkasının `owner_id`'sini gönderse bile kaydın GERÇEK sahibi
     * KENDİSİ olur — `ForcesRecordOwnerOnCreate::forceOwnerUnlessAssigner()`
     * alanı sessizce isteği yapanın kimliğine sabitler (istek REDDEDİLMEZ,
     * gerekçe: alanı hata yapmak meşru "kendi adına kayıt açma" akışını
     * kırardı — bkz. trait docblock'u).
     */
    public function test_create_time_owner_is_forced_to_the_actor_when_the_actor_lacks_the_assign_permission(): void
    {
        $rep = $this->actorWithRole('Satış Temsilcisi'); // deals.assign YOK
        $someoneElse = User::factory()->create();
        // DealService::create() pipeline_stage_id verilmezse ilk açık aşamayı
        // arar; boş şemada aşama yoksa 500 RuntimeException fırlatır — bu
        // testin konusuyla İLGİSİZ bir hata olmasın diye önce bir tane açılır.
        $this->openStage();

        $response = $this->actingAs($rep)->postJson('/api/deals', [
            'title' => 'Kendi Adıma Açtığım Fırsat',
            'owner_id' => $someoneElse->id, // görmezden gelinmeli
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.owner.id', $rep->id);

        $this->assertDatabaseHas('deals', [
            'title' => 'Kendi Adıma Açtığım Fırsat',
            'owner_id' => $rep->id,
        ]);
        $this->assertDatabaseMissing('deals', [
            'title' => 'Kendi Adıma Açtığım Fırsat',
            'owner_id' => $someoneElse->id,
        ]);
    }
}
