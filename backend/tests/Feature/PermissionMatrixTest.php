<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faz 10 — rol/izin matrisi (`/api/settings/permission-matrix`,
 * `PATCH /api/settings/roles/{role}/permissions`).
 *
 * İki koruma bu dosyanın asıl konusudur:
 *   1. `Super Admin` DÜZENLENEMEZ — yetkisi izin tablosundan değil
 *      `Gate::before`'dan gelir; oraya izin yazmak yanıltıcı ve tehlikelidir.
 *   2. Kullanıcı KENDİ Ayarlar erişimini kapatamaz — UserPolicy'deki "kimse
 *      kendi hesabını pasifleştiremez" korumasının aynısı.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actorWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * Doğrudan izinli DEĞİL, ROL üzerinden yetkili bir yönetici — matrisi
     * düzenleyen gerçek kullanıcı böyledir.
     */
    private function adminRoleUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    private function manager(): User
    {
        return $this->actorWithPermissions(['settings.manage']);
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / yetkilendirme
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/settings/permission-matrix')->assertStatus(401);
    }

    public function test_reading_the_matrix_requires_settings_manage(): void
    {
        // `roles.view` + `users.manage_roles` GET /api/roles için yeterlidir
        // ama matris bir yönetim ekranıdır ve tüm yetki haritasını gösterir.
        $actor = $this->actorWithPermissions(['roles.view', 'users.manage_roles']);

        $this->actingAs($actor)->getJson('/api/settings/permission-matrix')->assertStatus(403);
        $this->actingAs($actor)->getJson('/api/roles')->assertStatus(200);
    }

    public function test_editing_a_role_requires_settings_manage(): void
    {
        $actor = $this->actorWithPermissions(['users.manage_roles']);
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();

        $this->actingAs($actor)
            ->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Matrisin şekli
    // -------------------------------------------------------------------

    public function test_the_matrix_returns_every_role_and_permission_grouped_by_module(): void
    {
        $response = $this->actingAs($this->manager())->getJson('/api/settings/permission-matrix');

        $response->assertStatus(200);

        $roleNames = collect($response->json('data.roles'))->pluck('name')->all();
        $this->assertContains('Super Admin', $roleNames);
        $this->assertContains('Admin', $roleNames);
        $this->assertContains('İzleyici', $roleNames);

        $permissionNames = collect($response->json('data.permissions'))->pluck('name')->all();
        $this->assertContains('settings.manage', $permissionNames);
        $this->assertContains('deals.move', $permissionNames);

        // `modul.eylem` ayrıştırması SUNUCUDA yapılır; istemci izin adını
        // parçalamak zorunda kalmaz.
        $dealsMove = collect($response->json('data.permissions'))->firstWhere('name', 'deals.move');
        $this->assertSame('deals', $dealsMove['module']);
        $this->assertSame('move', $dealsMove['action']);

        $modules = collect($response->json('data.modules'))->pluck('key')->all();
        $this->assertContains('deals', $modules);
        $this->assertContains('settings', $modules);
    }

    public function test_super_admin_is_reported_as_not_editable_with_zero_permissions(): void
    {
        $response = $this->actingAs($this->manager())->getJson('/api/settings/permission-matrix');

        $superAdmin = collect($response->json('data.roles'))->firstWhere('name', 'Super Admin');

        $this->assertTrue($superAdmin['is_super_admin']);
        $this->assertFalse($superAdmin['is_editable']);
        // Sıfır izin satırı KASITLIDIR (Gate::before).
        $this->assertSame([], $superAdmin['permissions']);

        $admin = collect($response->json('data.roles'))->firstWhere('name', 'Admin');
        $this->assertTrue($admin['is_editable']);
        $this->assertContains('settings.manage', $admin['permissions']);
    }

    public function test_the_matrix_reports_how_many_users_hold_each_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Destek Temsilcisi');

        $response = $this->actingAs($this->manager())->getJson('/api/settings/permission-matrix');

        $support = collect($response->json('data.roles'))->firstWhere('name', 'Destek Temsilcisi');
        $this->assertSame(1, $support['users_count']);
    }

    // -------------------------------------------------------------------
    // Düzenleme
    // -------------------------------------------------------------------

    public function test_the_permission_list_is_a_full_sync_not_a_delta(): void
    {
        $role = Role::query()->where('name', 'Destek Temsilcisi')->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('tickets.create'));

        $response = $this->actingAs($this->manager())->patchJson(
            "/api/settings/roles/{$role->id}/permissions",
            ['permissions' => ['tickets.view', 'dashboard.view']],
        );

        $response->assertStatus(200);

        $role->refresh()->load('permissions');
        $names = $role->permissions->pluck('name')->all();

        sort($names);
        $this->assertSame(['dashboard.view', 'tickets.view'], $names);
    }

    public function test_a_role_can_be_emptied(): void
    {
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertStatus(200)
            ->assertJsonPath('data.permissions', []);
    }

    public function test_a_missing_permissions_key_is_rejected(): void
    {
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/roles/{$role->id}/permissions", [])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    public function test_an_unknown_permission_name_is_rejected_and_nothing_is_written(): void
    {
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();
        $before = $role->permissions->pluck('name')->sort()->values()->all();

        $this->actingAs($this->manager())->patchJson(
            "/api/settings/roles/{$role->id}/permissions",
            ['permissions' => ['leads.view', 'leads.teleport']],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNKNOWN_PERMISSION')
            ->assertJsonPath('unknown_permissions.0', 'leads.teleport');

        $after = $role->refresh()->load('permissions')->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame($before, $after);
    }

    // -------------------------------------------------------------------
    // Super Admin dokunulmaz
    // -------------------------------------------------------------------

    public function test_super_admin_permissions_cannot_be_edited(): void
    {
        $role = Role::query()->where('name', 'Super Admin')->firstOrFail();

        $this->actingAs($this->manager())->patchJson(
            "/api/settings/roles/{$role->id}/permissions",
            ['permissions' => ['leads.view']],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'ROLE_NOT_EDITABLE');

        $this->assertSame(0, $role->refresh()->permissions()->count());
    }

    public function test_super_admin_permissions_cannot_be_cleared_either(): void
    {
        $role = Role::query()->where('name', 'Super Admin')->firstOrFail();

        $this->actingAs($this->manager())
            ->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ROLE_NOT_EDITABLE');
    }

    // -------------------------------------------------------------------
    // Kendi erişimini kapatamazsın
    // -------------------------------------------------------------------

    public function test_a_user_cannot_strip_settings_manage_from_their_own_role(): void
    {
        $actor = $this->adminRoleUser();
        $role = Role::query()->where('name', 'Admin')->firstOrFail();

        $this->actingAs($actor)->patchJson(
            "/api/settings/roles/{$role->id}/permissions",
            ['permissions' => ['dashboard.view']],
        )
            ->assertStatus(422)
            ->assertJsonPath('code', 'CANNOT_REVOKE_OWN_SETTINGS_ACCESS');

        $this->assertTrue($role->refresh()->hasPermissionTo('settings.manage'));
    }

    public function test_the_same_role_can_be_edited_as_long_as_settings_manage_survives(): void
    {
        $actor = $this->adminRoleUser();
        $role = Role::query()->where('name', 'Admin')->firstOrFail();

        $this->actingAs($actor)->patchJson(
            "/api/settings/roles/{$role->id}/permissions",
            ['permissions' => ['settings.manage', 'dashboard.view']],
        )->assertStatus(200);

        $this->assertTrue($role->refresh()->hasPermissionTo('settings.manage'));
    }

    public function test_another_role_can_be_stripped_freely(): void
    {
        $actor = $this->adminRoleUser();
        $role = Role::query()->where('name', 'Satış Temsilcisi')->firstOrFail();

        $this->actingAs($actor)
            ->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])
            ->assertStatus(200);
    }

    public function test_a_super_admin_is_never_locked_out_by_this_rule(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Super Admin');
        $actor->assignRole('Admin');

        $role = Role::query()->where('name', 'Admin')->firstOrFail();

        // Super Admin'in yetkisi Gate::before'dan gelir; matris değişikliği
        // onu dışarıda bırakamaz, dolayısıyla kilit devreye girmez.
        $this->actingAs($actor)
            ->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => ['dashboard.view']])
            ->assertStatus(200);
    }
}
