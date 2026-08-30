<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Faz 13 / İz A — A2.5 (Super Admin muafiyetinin kapsamı) + A2.6 (yetki
 * yükseltme) güvenlik regresyon testleri.
 *
 * =============================================================================
 * A2.5 — Gate::before KISA DEVRE YAPMAMALI
 * =============================================================================
 * `AppServiceProvider::registerSuperAdminGate()` (satır ~145-152):
 *
 *   Gate::before(fn ($user, $ability) => $user->hasRole('Super Admin') ? true : null);
 *
 * KRİTİK ayrım `false` DEĞİL `null` dönmesidir: Laravel `Gate::before`
 * callback'leri sırayla çalıştırır ve İLK `null`-DIŞI sonucu nihai karar
 * sayar. `false` dönseydi Super Admin OLMAYAN HERKES için HER yetenek
 * reddedilir, tüm Policy'ler ve izinler baypas edilirdi (fail-closed görünüp
 * aslında fail-broken olan bir hata). Bu dosya HTTP seviyesinde iki ucu
 * kilitliyor: (1) hiçbir açık izni olmayan bir Super Admin, `settings.manage`
 * arkasındaki bir uca erişebiliyor; (2) hiçbir rolü/izni olmayan sıradan bir
 * kullanıcı AYNI uca erişemiyor VE elindeki gerçek bir izinle ilgisiz bir uca
 * erişmesi normal Policy akışından geçiyor (kısa devre YOK).
 * (AuthTest.php'deki `test_super_admin_receives_every_ability_through_gate_before`
 * ve `test_gate_before_does_not_grant_abilities_to_other_roles` bunu birim
 * seviyesinde zaten doğruluyor; burada HTTP/route seviyesinde tekrar kilitleniyor.)
 *
 * =============================================================================
 * A2.6 — YETKİ YÜKSELTME (settings.manage sahibi kendine/rolüne izin ekler)
 * =============================================================================
 * `RoleMatrixService::syncPermissions()` OKUNDU (`app/Services/Settings/
 * RoleMatrixService.php`). Gerçek davranış — VARSAYIM DEĞİL:
 *   - Super Admin rolü asla düzenlenemez (`ROLE_NOT_EDITABLE`, 422).
 *   - Var olmayan bir izin adı gönderilirse TÜM istek reddedilir
 *     (`UNKNOWN_PERMISSION`, 422) ve HİÇBİR ŞEY yazılmaz.
 *   - `assertActorKeepsSettingsAccess()`: bir aktör KENDİ rolünden
 *     `settings.manage`'i kaldıran bir sync gönderirse VE başka hiçbir yol
 *     (başka bir rol / doğrudan izin) o erişimi korumuyorsa reddedilir
 *     (`CANNOT_REVOKE_OWN_SETTINGS_ACCESS`, 422).
 *   - AMA: `settings.manage` sahibi bir aktörün KENDİ rolüne DAHA ÖNCE
 *     sahip olmadığı BAŞKA bir izni EKLEMESİNİ engelleyen bir kural YOK.
 *     Bu "yukarı yükseltme" senaryosu koddan OKUNARAK doğrulandı ve
 *     bilinçli bir tasarım kararı gibi görünüyor (bkz. PHASE-AUDIT §2/A2.6
 *     "Recon: SAFE — ama test yaz"): `settings.manage` zaten sistemin en
 *     yüksek idari iznidir (rol/izin matrisinin tamamını değiştirebilir),
 *     dolayısıyla kendi rolüne ek izin verebilmek bu iznin ZATEN kapsadığı
 *     bir yetkidir, ondan bağımsız yeni bir açık değildir. Bu test GERÇEK
 *     davranışı kilitler; test'in adı bunu AÇIKÇA "izin verilir" olarak
 *     işaretler ki ileride biri bunu kısıtlamaya karar verirse test bilinçli
 *     güncellenir (sessizce kırılmaz).
 */
class PrivilegeEscalationTest extends TestCase
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

    // -------------------------------------------------------------------
    // A2.5
    // -------------------------------------------------------------------

    public function test_super_admin_with_zero_explicit_permissions_reaches_settings_manage_protected_endpoint(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        // Rolün kendisi hiçbir izin taşımıyor (RolePermissionSeeder) — erişim
        // SADECE Gate::before'dan geliyor olmalı.
        $this->assertSame(0, $superAdmin->roles->first()->permissions()->count());

        $response = $this->actingAs($superAdmin)->getJson('/api/settings/permission-matrix');

        $response->assertStatus(200);
    }

    public function test_permissionless_user_is_forbidden_from_the_same_endpoint(): void
    {
        // Rolsüz/izinsiz — Gate::before hiçbir şey döndürmez (null), normal
        // Gate akışı devreye girer ve settings.manage yokluğu 403 üretir.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/settings/permission-matrix');

        $response->assertStatus(403);
    }

    public function test_gate_before_does_not_leak_super_admin_access_through_an_unrelated_endpoint_for_normal_roles(): void
    {
        // Satış Temsilcisi gerçek (ilgisiz) bir izne sahip: leads.create.
        // Bunun çalışması, Gate::before'un normal roller için akışı
        // KESMEDİĞİNİ (short-circuit yapmadığını) doğrular — aksi halde
        // `false` dönüşü her şeyi (hatta sahip olunan izinleri de) keserdi.
        $rep = $this->actorWithRole('Satış Temsilcisi');

        $this->actingAs($rep)->postJson('/api/leads', [
            'first_name' => 'Ayşe',
            'last_name' => 'Yılmaz',
            'email' => 'ayse.yilmaz@example.com',
            'source' => 'website',
        ])->assertStatus(201);

        // Ama settings.manage gerektiren uca ERİŞEMEMELİ — Gate::before
        // "null" döndüğü için normal Policy/izin reddi devreye girer.
        $this->actingAs($rep)->getJson('/api/settings/permission-matrix')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // A2.6
    // -------------------------------------------------------------------

    public function test_settings_manage_holder_can_add_a_previously_unheld_permission_to_their_own_role(): void
    {
        // GERÇEK DAVRANIŞ (RoleMatrixService::syncPermissions() okunarak
        // doğrulandı): kendi rolüne yeni bir izin EKLEMEK
        // assertActorKeepsSettingsAccess() tarafından ENGELLENMEZ — o kural
        // yalnızca settings.manage'in KALDIRILMASINI engeller. Bu, bilinçli
        // bir tasarım: settings.manage zaten matrisin tamamını değiştirme
        // yetkisidir.
        $actor = $this->actorWithRole('Admin');
        $this->assertFalse($actor->hasPermissionTo('users.manage_roles'));

        $adminRole = Role::where('name', 'Admin')->first();
        $currentPermissions = $adminRole->permissions->pluck('name')->all();
        $this->assertNotContains('users.manage_roles', $currentPermissions);

        $response = $this->actingAs($actor)->patchJson(
            "/api/settings/roles/{$adminRole->id}/permissions",
            ['permissions' => array_values(array_unique([...$currentPermissions, 'users.manage_roles']))],
        );

        $response->assertStatus(200);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($adminRole->fresh()->permissions->contains('name', 'users.manage_roles'));
    }

    public function test_settings_manage_holder_cannot_strip_settings_manage_from_their_own_sole_role(): void
    {
        // Aynı kuralın DİĞER yönü: kendi TEK rolünden settings.manage'i
        // kaldırmaya çalışmak reddedilmeli (CANNOT_REVOKE_OWN_SETTINGS_ACCESS).
        $actor = $this->actorWithRole('Admin');

        $adminRole = Role::where('name', 'Admin')->first();
        $permissionsWithoutSettingsManage = $adminRole->permissions
            ->pluck('name')
            ->reject(fn (string $name): bool => $name === 'settings.manage')
            ->values()
            ->all();

        $response = $this->actingAs($actor)->patchJson(
            "/api/settings/roles/{$adminRole->id}/permissions",
            ['permissions' => $permissionsWithoutSettingsManage],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'CANNOT_REVOKE_OWN_SETTINGS_ACCESS');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($adminRole->fresh()->permissions->contains('name', 'settings.manage'));
    }

    public function test_syncing_a_made_up_permission_name_is_rejected_and_nothing_is_written(): void
    {
        // "Uydurma izin adı gönderince ne olur" — sözlükte olmayan bir izin
        // adı TÜM isteği reddetmeli, matriste sessizce görmezden gelinmemeli.
        $actor = $this->actorWithRole('Admin', ['settings.manage']);

        $targetRole = Role::where('name', 'Satış Temsilcisi')->first();
        $before = $targetRole->permissions->pluck('name')->sort()->values()->all();

        $response = $this->actingAs($actor)->patchJson(
            "/api/settings/roles/{$targetRole->id}/permissions",
            ['permissions' => ['leads.view', 'bu-izin-hic-var-olmadi.super-admin-yap']],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'UNKNOWN_PERMISSION');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $after = $targetRole->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame($before, $after, 'Bilinmeyen izin reddedilirken kayıtlı diğer izinler de değişmemeli (kısmi yazım yok).');
    }

    public function test_super_admin_role_permissions_can_never_be_edited_even_by_a_settings_manage_holder(): void
    {
        $actor = $this->actorWithRole('Admin', ['settings.manage']);
        $superAdminRole = Role::where('name', 'Super Admin')->first();

        $response = $this->actingAs($actor)->patchJson(
            "/api/settings/roles/{$superAdminRole->id}/permissions",
            ['permissions' => ['users.delete', 'settings.manage']],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'ROLE_NOT_EDITABLE');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(0, $superAdminRole->fresh()->permissions()->count());
    }
}
