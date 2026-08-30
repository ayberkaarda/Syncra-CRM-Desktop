<?php

namespace Tests\Feature\Security;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 13 / İz A — A4 (Mass Assignment) güvenlik regresyon testleri.
 *
 * PHASE-AUDIT §2/A4 recon bulgusu: hassas alanlar `$fillable`'da olsa da
 * `UpdateXRequest`'lerde `missing` kuralıyla istemciden gelirse 422; controller
 * yalnız `$request->validated()` geçirir. Bu dosya o iddiayı A4.1-A4.3 için
 * BİREBİR kilitler.
 *
 * A4.4-A4.6 için kod OKUNARAK doğrulama yapılmış ve o zaman GERÇEK davranışın
 * beklentiden SAPTIĞI (PHASE-AUDIT'in "SAFE" dediği iki nokta aslında güvenlik
 * açığıydı) iki test "BULGU" olarak işaretlenmişti.
 *
 * =============================================================================
 * FAZ 13 GÜNCELLEMESİ — HER İKİ BULGU DA KAPATILDI (teknik lidere raporlanan
 * bulgular üzerine sertleştirme UYGULANDI, bu şeridin işi eski davranışı
 * kilitleyen testleri YENİ gerçeğe taşımak)
 * =============================================================================
 * - **F7** (eski A4.4 bulgusu): `UserService::create()` artık bir "rol tavanı"
 *   uyguluyor — `users.manage_roles` taşımayan aktör `Super Admin` rolünü HİÇ
 *   atayamaz, başka bir rol atayabilmesi için o rolün izin kümesi kendi izin
 *   kümesinin ALT KÜMESİ olmalı; aksi hâlde `AuthorizationException` -> 403.
 *   Eski test `test_finding_admin_without_manage_roles_permission_can_mint_a_new_super_admin_via_store`
 *   (201 + gerçek Super Admin bekliyordu) artık YANLIŞ; yerini REGRESYON kilidi
 *   `test_admin_without_manage_roles_cannot_mint_a_super_admin` aldı (403
 *   bekliyor) — ayrıca Admin'in MEŞRU rolleri hâlâ atayabildiğini de doğruluyor
 *   (aşırı-düzeltmeye karşı koruma).
 * - **F8** (eski A4.6 bulgusu): `owner_id`/`assigned_to` artık
 *   `Update{Deal,Lead,Task,Ticket}Request`'lerde `['missing']` — gövdede
 *   bulunmaları (değeri ne olursa olsun) 422 üretir. Eski test
 *   `test_finding_deals_update_permission_alone_can_reassign_owner_bypassing_deals_assign`
 *   (200 + owner_id değişti bekliyordu) artık YANLIŞ; yerini REGRESYON kilidi
 *   `test_deals_update_permission_alone_can_no_longer_reassign_owner_bypassing_deals_assign`
 *   aldı (422 bekliyor) — ayrıca dedike `/assign` ucunun DOĞRU izinle (`deals.
 *   assign`) hâlâ çalıştığını da doğruluyor.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actorWithRole(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($extraPermissions !== []) {
            $user->givePermissionTo($extraPermissions);
        }

        return $user;
    }

    private function openStage(): PipelineStage
    {
        return PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
    }

    // -------------------------------------------------------------------
    // A4.1 — Deal: pipeline_stage_id, position, version, status
    // -------------------------------------------------------------------

    public function test_deal_update_endpoint_rejects_pipeline_stage_position_version_and_status(): void
    {
        $actor = $this->actorWithRole('Admin');
        $deal = Deal::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/deals/{$deal->id}", [
            'title' => 'Güncellenmiş Başlık',
            'pipeline_stage_id' => PipelineStage::factory()->create()->id,
            'position' => 'a0001',
            'version' => 99,
            'status' => 'won',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertSame(
            ['pipeline_stage_id', 'position', 'status', 'version'],
            $this->sortedFieldKeys($response),
        );

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'title' => $deal->title,
            'status' => $deal->status,
            'version' => $deal->version,
        ]);
    }

    // -------------------------------------------------------------------
    // A4.2 — Ticket: status + 8 sla_* alanı
    // -------------------------------------------------------------------

    public function test_ticket_update_endpoint_rejects_status_and_all_sla_fields(): void
    {
        $actor = $this->actorWithRole('Destek Temsilcisi');
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/tickets/{$ticket->id}", [
            'subject' => 'Güncellenmiş Konu',
            'status' => 'resolved',
            'ticket_number' => 'TKT-999999',
            'sla_due_at' => now()->addDay()->toIso8601String(),
            'sla_paused_at' => now()->toIso8601String(),
            'sla_paused_seconds' => 0,
            'sla_warning_notified_at' => now()->toIso8601String(),
            'sla_breach_notified_at' => now()->toIso8601String(),
            'first_response_at' => now()->toIso8601String(),
            'resolved_at' => now()->toIso8601String(),
            'closed_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertSame(
            [
                'closed_at', 'first_response_at', 'resolved_at', 'sla_breach_notified_at',
                'sla_due_at', 'sla_paused_at', 'sla_paused_seconds', 'sla_warning_notified_at',
                'status', 'ticket_number',
            ],
            $this->sortedFieldKeys($response),
        );

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
        ]);
    }

    // -------------------------------------------------------------------
    // A4.3 — Quote: status, tutarlar, parent_quote_id, revision
    // -------------------------------------------------------------------

    public function test_quote_update_endpoint_rejects_status_totals_parent_quote_id_and_revision(): void
    {
        $actor = $this->actorWithRole('Satış Müdürü');
        $quote = Quote::factory()->create();

        $response = $this->actingAs($actor)->patchJson("/api/quotes/{$quote->id}", [
            'title' => 'Güncellenmiş Teklif',
            'status' => 'accepted',
            'quote_number' => 'QTE-999999',
            'subtotal' => 1,
            'discount_amount' => 1,
            'tax_amount' => 1,
            'total' => 1,
            'sent_at' => now()->toIso8601String(),
            'accepted_at' => now()->toIso8601String(),
            'rejected_at' => now()->toIso8601String(),
            'created_by' => 999999,
            'parent_quote_id' => 1,
            'revision' => 99,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertSame(
            [
                'accepted_at', 'created_by', 'discount_amount', 'parent_quote_id', 'quote_number',
                'rejected_at', 'revision', 'sent_at', 'status', 'subtotal', 'tax_amount', 'total',
            ],
            $this->sortedFieldKeys($response),
        );

        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'title' => $quote->title,
            'status' => $quote->status,
            'total' => $quote->total,
        ]);
    }

    // -------------------------------------------------------------------
    // A4.5 — User: is_active, must_change_password $fillable'da ama
    // Store/UpdateUserRequest kurallarında YOK (örtük koruma)
    // -------------------------------------------------------------------

    public function test_creating_a_user_with_is_active_false_in_the_body_is_silently_ignored(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Test Kullanıcı',
            'email' => 'is-active-probe@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
            // StoreUserRequest'te tanımlı DEĞİL: validated() bunu asla
            // içermez, dolayısıyla UserService::create() hiç görmez.
            'is_active' => false,
        ]);

        $response->assertStatus(201);

        // Yeni kullanıcı varsayılan (is_active=true) olarak yaratılır — gövdedeki
        // `false` sessizce yok sayıldı, DB'ye yazılmadı.
        $this->assertDatabaseHas('users', [
            'email' => 'is-active-probe@example.com',
            'is_active' => true,
        ]);
    }

    public function test_creating_a_user_with_must_change_password_false_in_the_body_is_silently_ignored(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Test Kullanıcı 2',
            'email' => 'must-change-probe@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
            // Aynı şekilde StoreUserRequest'te YOK; UserService::create()
            // must_change_password'u KOŞULSUZ true'ya zorluyor (satır 38).
            'must_change_password' => false,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'must-change-probe@example.com',
            'must_change_password' => true,
        ]);
    }

    public function test_updating_a_user_with_is_active_and_must_change_password_in_the_body_does_not_change_them(): void
    {
        $actor = $this->actorWithRole('Admin');
        $target = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $target->assignRole('Satış Temsilcisi');

        $response = $this->actingAs($actor)->patchJson("/api/users/{$target->id}", [
            'department' => 'Yeni Departman',
            // UpdateUserRequest'te de YOK — `validated()` bu iki anahtarı
            // ASLA içermeyeceği için UserService::update() hiç görmez.
            'is_active' => false,
            'must_change_password' => true,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'department' => 'Yeni Departman',
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    // -------------------------------------------------------------------
    // A4.4 — REGRESYON KİLİDİ (Faz 13 / F7): `role` alanı POST /api/users
    // üzerinden artık `users.manage_roles` OLMADAN Super Admin ÜRETEMİYOR.
    // -------------------------------------------------------------------

    /**
     * =========================================================================
     * ESKİ DAVRANIŞ (bu test eskiden neyi kilitliyordu, adı
     * `test_finding_admin_without_manage_roles_permission_can_mint_a_new_super_admin_via_store`
     * idi): `UserController::store()` -> `UserService::create()` rol atarken
     * `users.manage_roles` HİÇ SORMUYORDU — yalnızca `users.create` (Admin
     * rolünde var) yeterliydi ve `role` adı doğrudan `syncRoles()`'a
     * gidiyordu. Test bu GERÇEK (savunmasız) davranışı BULGU olarak
     * kilitliyordu: Admin, `POST /api/users` gövdesine `"role": "Super Admin"`
     * koyarak 201 + gerçek bir Super Admin hesabı üretebiliyordu — `manageRoles`
     * kontrolünün UPDATE yolunda var olup CREATE yolunda YOK olması dikey bir
     * yetki yükseltmesiydi.
     *
     * NEDEN DEĞİŞTİ (Faz 13 / F7): teknik lidere raporlanan bu bulgu üzerine
     * `UserService::assertActorMayGrantRole()` eklendi — `users.manage_roles`
     * taşımayan aktör ARTIK `Super Admin` rolünü hiçbir şekilde atayamaz
     * (`AuthorizationException` -> 403). Bu test artık YENİ (kapatılmış)
     * davranışı REGRESYON KİLİDİ olarak tutuyor.
     */
    public function test_admin_without_manage_roles_cannot_mint_a_super_admin(): void
    {
        $actor = $this->actorWithRole('Admin');
        $this->assertFalse(
            $actor->hasPermissionTo('users.manage_roles'),
            'Bu testin önkoşulu: Admin rolü users.manage_roles TAŞIMAMALI (RolePermissionSeeder).'
        );

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Baştan Yaratılan Super Admin',
            'email' => 'yeni-super-admin@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Super Admin',
        ]);

        // YENİ davranış: rol tavanı Super Admin'i her hâlükârda yasaklıyor.
        $response->assertStatus(403);

        $newUser = User::where('email', 'yeni-super-admin@example.com')->first();
        $this->assertNull($newUser, 'Reddedilen istek geride hiçbir kullanıcı kaydı BIRAKMAMALI (transaction geri alındı).');
    }

    /**
     * AŞIRI-DÜZELTMEYE KARŞI KORUMA: rol tavanı kuralı Admin'in `users.create`
     * yeteneğini TAMAMEN işlevsiz bırakmamalı. `Satış Temsilcisi` rolünün TÜM
     * izinleri (leads/contacts/companies/deals/tasks/activities/quotes/
     * products alt kümesi) Admin'in izin kümesinin ALT KÜMESİDİR — bu yüzden
     * `users.manage_roles` OLMASA BİLE Admin bu rolü meşru şekilde atayabilmeli.
     */
    public function test_admin_without_manage_roles_can_still_assign_a_role_that_is_a_subset_of_its_own_permissions(): void
    {
        $actor = $this->actorWithRole('Admin');
        $this->assertFalse($actor->hasPermissionTo('users.manage_roles'));

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Yeni Satış Temsilcisi',
            'email' => 'yeni-temsilci@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.role.name', 'Satış Temsilcisi');

        $newUser = User::where('email', 'yeni-temsilci@example.com')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->hasRole('Satış Temsilcisi'));
    }

    // -------------------------------------------------------------------
    // A4.6 — REGRESYON KİLİDİ (Faz 13 / F8): owner_id artık genel
    // `PATCH .../{id}` ucundan DEĞİŞTİRİLEMİYOR (422); yalnızca dedike
    // `/assign` ucu (doğru izinle) çalışıyor.
    // -------------------------------------------------------------------

    /**
     * =========================================================================
     * ESKİ DAVRANIŞ (bu test eskiden neyi kilitliyordu, adı
     * `test_finding_deals_update_permission_alone_can_reassign_owner_bypassing_deals_assign`
     * idi): `UpdateDealRequest` `owner_id`'yi `['sometimes','nullable',
     * 'integer','exists:users,id']` olarak KABUL EDİYORDU ve `DealPolicy::
     * update()` yalnız `deals.update` soruyordu, `deals.assign` DEĞİL. Test
     * bu GERÇEK (savunmasız) davranışı BULGU olarak kilitliyordu: yalnızca
     * `deals.update` taşıyan (`deals.assign` OLMAYAN) bir Satış Temsilcisi,
     * `PATCH /api/deals/{id}` ile deal'ı başka birine devredebiliyordu —
     * dedike `/assign` ucunun izin kapısı genel update ucundan tamamen
     * BAYPAS EDİLEBİLİYORDU.
     *
     * NEDEN DEĞİŞTİ (Faz 13 / F8): teknik lidere raporlanan bu bulgu üzerine
     * `UpdateDealRequest`'te `owner_id` artık `['missing']` — gövdede
     * bulunması (değeri ne olursa olsun) 422 `VALIDATION_ERROR` üretir.
     * Devretme YALNIZCA `PATCH /api/deals/{deal}/assign` (`deals.assign`
     * izniyle korunan) üzerinden yapılabilir. Bu test artık YENİ (kapatılmış)
     * davranışı REGRESYON KİLİDİ olarak tutuyor; ayrıca dedike `/assign`
     * ucunun DOĞRU izinle (deals.assign) hâlâ çalıştığını da doğruluyor
     * (aşırı-düzeltmeye karşı koruma — kapı tamamen kapanmamış, yalnızca
     * tek bir meşru kapıya indirgenmiş olmalı).
     */
    public function test_deals_update_permission_alone_can_no_longer_reassign_owner_bypassing_deals_assign(): void
    {
        $rep = $this->actorWithRole('Satış Temsilcisi');
        $this->assertFalse($rep->hasPermissionTo('deals.assign'));
        $this->assertTrue($rep->hasPermissionTo('deals.update'));

        $originalOwner = $rep; // deal'ın SAHİBİ rep'in kendisi olsun ki 403 update-izni
        // yüzünden DEĞİL, spesifik owner_id alanı yüzünden gelsin (sahiplik
        // engeli bu testin konusu DEĞİL — bkz. OwnershipIsolationTest).
        $newOwner = User::factory()->create();
        $deal = Deal::factory()->create([
            'owner_id' => $originalOwner->id,
            'pipeline_stage_id' => $this->openStage()->id,
        ]);

        // Dedike atama ucu izin yokken doğru şekilde reddediyor.
        $this->actingAs($rep)->patchJson("/api/deals/{$deal->id}/assign", [
            'owner_id' => $newOwner->id,
        ])->assertStatus(403);

        // Genel update ucu artık owner_id'yi kabul ETMİYOR: 422 (baypas kapandı).
        $response = $this->actingAs($rep)->patchJson("/api/deals/{$deal->id}", [
            'owner_id' => $newOwner->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'VALIDATION_ERROR');
        $this->assertSame(['owner_id'], $this->sortedFieldKeys($response));

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'owner_id' => $originalOwner->id,
        ]);

        // Pozitif kontrast: DOĞRU izne (deals.assign) sahip bir yönetici,
        // dedike `/assign` ucundan aynı devri sorunsuz yapabiliyor — kapı
        // tamamen kapanmadı, tek meşru yola indirgendi.
        $manager = $this->actorWithRole('Satış Müdürü'); // deals.assign
        $this->actingAs($manager)->patchJson("/api/deals/{$deal->id}/assign", [
            'owner_id' => $newOwner->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'owner_id' => $newOwner->id,
        ]);
    }

    /**
     * Bu API `{"errors":{"fields": {...}}}` özel zarfını kullanıyor
     * (bootstrap/app.php) — Laravel'in varsayılan `assertJsonValidationErrors()`
     * helper'ı `errors.<field>` bekler, bu şemayla uyuşmaz. `errors.fields`
     * altındaki anahtarları sıralı döndürür.
     *
     * @return array<int, string>
     */
    private function sortedFieldKeys($response): array
    {
        $fields = (array) ($response->json('errors.fields') ?? []);
        $keys = array_keys($fields);
        sort($keys);

        return $keys;
    }
}
