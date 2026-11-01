<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleGrantCeilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor(array $permissions = [], ?string $role = null): User
    {
        $actor = User::factory()->create();
        if ($role !== null) {
            $actor->assignRole($role);
        }
        $actor->givePermissionTo($permissions);

        return $actor;
    }

    public function test_k15_role_existing_only_in_another_guard_returns_validation_errors_without_writes(): void
    {
        $actor = $this->actor([], 'Admin');
        $target = $this->actor([], 'İzleyici');
        $originalName = $target->name;
        Role::create(['name' => 'Other guard only', 'guard_name' => 'sanctum']);
        $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Invalid guard', 'email' => 'invalid-guard@example.com',
            'password' => 'Str0ng!Passw0rd#26', 'role' => 'Other guard only',
        ])->assertUnprocessable()->assertJsonValidationErrors('role', 'errors.fields');
        $this->assertDatabaseMissing('users', ['email' => 'invalid-guard@example.com']);

        $this->patchJson("/api/users/{$target->id}", [
            'name' => 'Must not persist', 'role' => 'Other guard only',
        ])->assertUnprocessable()->assertJsonValidationErrors('role', 'errors.fields');
        $this->assertSame($originalName, $target->fresh()->name);
        $this->assertTrue($target->fresh()->hasRole('İzleyici'));
    }

    public function test_admin_cannot_start_the_matrix_to_super_admin_escalation_chain(): void
    {
        $actor = $this->actor([], 'Admin');
        $role = Role::findByName('Admin', 'web');
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $this->actingAs($actor)->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'permissions' => array_merge($before, ['users.manage_roles']),
        ])->assertStatus(422)
            ->assertJsonPath('code', 'PERMISSION_GRANT_FORBIDDEN')
            ->assertJsonPath('errors.code', 'PERMISSION_GRANT_FORBIDDEN')
            ->assertJsonStructure(['errors' => ['message', 'fields' => ['permissions']]]);

        $this->assertSame($before, $role->fresh()->permissions->pluck('name')->sort()->values()->all());
        $this->actingAs($actor->fresh())->patchJson("/api/users/{$actor->id}", [
            'role' => 'Super Admin',
        ])->assertForbidden();
        $this->assertFalse($actor->fresh()->hasRole('Super Admin'));
    }

    public function test_matrix_cannot_grant_missing_permissions_to_another_role_even_with_manage_roles(): void
    {
        $actor = $this->actor(['settings.manage', 'users.manage_roles']);
        $role = Role::findByName('İzleyici', 'web');
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $this->actingAs($actor)->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'permissions' => ['users.manage_roles', 'users.delete'],
        ])->assertStatus(422)->assertJsonPath('errors.code', 'PERMISSION_GRANT_FORBIDDEN');
        $this->assertSame($before, $role->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_legitimate_matrix_edits_accept_inherited_and_direct_permissions_and_removals(): void
    {
        $owned = Role::create(['name' => 'Matris Yöneticisi', 'guard_name' => 'web']);
        $owned->syncPermissions(['settings.manage', 'tickets.view']);
        $actor = $this->actor(['dashboard.view'], $owned->name);
        $role = Role::findByName('Destek Temsilcisi', 'web');
        $this->actingAs($actor)->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'permissions' => ['tickets.view', 'dashboard.view'],
        ])->assertOk();
        $this->assertSame(['dashboard.view', 'tickets.view'], $role->fresh()->permissions->pluck('name')->sort()->values()->all());
        $this->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => []])->assertOk();
        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_manage_roles_does_not_bypass_the_role_ceiling_on_update_or_create(): void
    {
        $actor = $this->actor(['users.manage_roles', 'users.update', 'users.create']);
        $target = $this->actor([], 'İzleyici');
        foreach (['Super Admin', 'Admin'] as $role) {
            $this->actingAs($actor)->patchJson("/api/users/{$target->id}", [
                'role' => $role, 'name' => 'Rejected edit',
            ])->assertForbidden();
            $this->assertNotSame('Rejected edit', $target->fresh()->name);
            $this->assertTrue($target->fresh()->hasRole('İzleyici'));

            $email = $role === 'Admin' ? 'ceiling-admin@example.com' : 'ceiling-super@example.com';
            $this->postJson('/api/users', [
                'name' => 'Rejected user', 'email' => $email,
                'password' => 'Str0ng!Passw0rd#26', 'role' => $role,
            ])->assertForbidden();
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_legitimate_role_update_and_create_work_within_the_ceiling(): void
    {
        $role = Role::create(['name' => 'Sınırlı Rol', 'guard_name' => 'web']);
        $role->syncPermissions(['tickets.view', 'dashboard.view']);
        $owned = Role::create(['name' => 'Kullanıcı Yöneticisi', 'guard_name' => 'web']);
        $owned->syncPermissions(['users.manage_roles', 'users.update', 'users.create', 'tickets.view']);
        $actor = $this->actor(['dashboard.view'], $owned->name);
        $target = User::factory()->create();
        $this->actingAs($actor)->patchJson("/api/users/{$target->id}", [
            'role' => $role->name, 'name' => 'Allowed edit',
        ])->assertOk();
        $this->assertTrue($target->fresh()->hasRole($role->name));
        $this->assertSame('Allowed edit', $target->fresh()->name);

        $this->postJson('/api/users', [
            'name' => 'Allowed user', 'email' => 'ceiling-allowed@example.com',
            'password' => 'Str0ng!Passw0rd#26', 'role' => $role->name,
        ])->assertCreated();
        $this->assertTrue(User::where('email', 'ceiling-allowed@example.com')->firstOrFail()->hasRole($role->name));
    }

    public function test_super_admin_gate_before_still_allows_matrix_grants_and_role_assignments(): void
    {
        $actor = $this->actor([], 'Super Admin');
        $this->assertCount(0, $actor->getAllPermissions());
        $this->assertTrue($actor->can('users.manage_roles'));
        $role = Role::findByName('İzleyici', 'web');
        $this->actingAs($actor)->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'permissions' => ['users.delete', 'users.manage_roles'],
        ])->assertOk();
        $this->assertTrue($role->fresh()->hasPermissionTo('users.delete'));
        $target = User::factory()->create();
        $this->patchJson("/api/users/{$target->id}", ['role' => 'Super Admin'])->assertOk();
        $this->assertTrue($target->fresh()->hasRole('Super Admin'));
        $this->postJson('/api/users', [
            'name' => 'New administrator', 'email' => 'new-super@example.com',
            'password' => 'Str0ng!Passw0rd#26', 'role' => 'Super Admin',
        ])->assertCreated();
        $this->assertTrue(User::where('email', 'new-super@example.com')->firstOrFail()->hasRole('Super Admin'));
        $superRole = Role::findByName('Super Admin', 'web');
        $this->patchJson("/api/settings/roles/{$superRole->id}/permissions", ['permissions' => []])
            ->assertStatus(422)->assertJsonPath('errors.code', 'ROLE_NOT_EDITABLE');
    }

    public function test_last_active_super_admin_cannot_be_demoted_even_by_their_own_gate_before(): void
    {
        $actor = $this->actor([], 'Super Admin');
        $inactive = $this->actor([], 'Super Admin');
        $inactive->update(['is_active' => false]);
        $this->actingAs($actor)->patchJson("/api/users/{$actor->id}", [
            'role' => 'Admin', 'name' => 'Rejected demotion',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'LAST_SUPER_ADMIN')
            ->assertJsonPath('errors.code', 'LAST_SUPER_ADMIN')
            ->assertJsonStructure(['errors' => ['message', 'fields' => ['role']]]);
        $this->assertTrue($actor->fresh()->hasRole('Super Admin'));
        $this->assertNotSame('Rejected demotion', $actor->fresh()->name);
    }

    public function test_demotion_is_allowed_when_another_active_super_admin_remains(): void
    {
        $actor = $this->actor([], 'Super Admin');
        $other = $this->actor([], 'Super Admin');
        $this->actingAs($actor)->patchJson("/api/users/{$actor->id}", ['role' => 'Admin'])->assertOk();
        $this->assertTrue($actor->fresh()->hasRole('Admin'));
        $this->assertTrue($other->fresh()->hasRole('Super Admin'));
    }

    public function test_k1_role_spelling_variants_use_the_resolved_role_on_both_routes(): void
    {
        $creator = $this->actor([], 'Admin');
        $editor = $this->actor(['users.manage_roles', 'users.update']);
        $target = $this->actor([], 'İzleyici');
        foreach (['Super Admin', 'super admin', 'SUPER ADMIN', 'Süper Admin', "Super\u{00A0}Admin", "Super\u{200B} Admin"] as $index => $name) {
            // Engines with stricter collations may reject an unknown spelling at validation.
            $resolved = Role::where('guard_name', 'web')->where('name', $name)->first();
            $status = $resolved === null ? 422 : 403;
            if ($resolved !== null) {
                $this->assertSame('Super Admin', $resolved->name);
            }
            $email = "variant-{$index}@example.com";
            $this->actingAs($creator)->postJson('/api/users', [
                'name' => 'Rejected', 'email' => $email,
                'password' => 'Str0ng!Passw0rd#26', 'role' => $name,
            ])->assertStatus($status);
            $this->assertDatabaseMissing('users', ['email' => $email]);
            $this->actingAs($editor)->patchJson("/api/users/{$target->id}", [
                'role' => $name, 'name' => 'Rejected',
            ])->assertStatus($status);
            $this->assertTrue($target->fresh()->hasRole('İzleyici'));
            $this->assertNotSame('Rejected', $target->fresh()->name);
        }
    }

    public function test_k1_last_super_admin_can_resubmit_a_collation_equivalent_role(): void
    {
        $actor = $this->actor([], 'Super Admin');
        $name = Role::where('name', 'super admin')->exists() ? 'super admin' : 'Super Admin';
        $this->actingAs($actor)->patchJson("/api/users/{$actor->id}", [
            'name' => 'Renamed administrator', 'role' => $name,
        ])->assertOk();
        $this->assertTrue($actor->fresh()->hasRole('Super Admin'));
        $this->postJson('/api/users', [
            'name' => 'Canonical assignment', 'email' => 'canonical@example.com',
            'password' => 'Str0ng!Passw0rd#26', 'role' => $name,
        ])->assertCreated();
        $this->assertTrue(User::where('email', 'canonical@example.com')->firstOrFail()->hasRole('Super Admin'));
    }

    public function test_k3_only_new_matrix_permissions_are_subject_to_the_ceiling(): void
    {
        $actor = $this->actor(['settings.manage', 'deals.view']);
        $role = Role::create(['name' => 'Existing foreign permissions', 'guard_name' => 'web']);
        $role->syncPermissions(['logs.export', 'dashboard.view', 'tickets.view']);
        foreach ([
            ['logs.export', 'dashboard.view'], // Removal while keeping foreign permissions.
            ['logs.export', 'dashboard.view'], // No change.
            ['logs.export', 'dashboard.view', 'deals.view'], // Held addition.
        ] as $permissions) {
            $this->actingAs($actor)->patchJson("/api/settings/roles/{$role->id}/permissions", [
                'permissions' => $permissions,
            ])->assertOk();
            $expected = $permissions;
            sort($expected);
            $this->assertSame($expected, $role->fresh()->permissions->pluck('name')->sort()->values()->all());
        }
        $before = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'permissions' => [...$before, 'users.delete'],
        ])->assertStatus(422)->assertJsonPath('errors.code', 'PERMISSION_GRANT_FORBIDDEN');
        $this->assertSame($before, $role->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_k4_unchanged_higher_role_is_not_a_grant_during_profile_edit(): void
    {
        $actor = $this->actor(['users.manage_roles', 'users.update']);
        $target = $this->actor([], 'Admin');
        $this->actingAs($actor)->patchJson("/api/users/{$target->id}", [
            'name' => 'Profile edit', 'role' => 'Admin',
        ])->assertOk();
        $this->assertSame('Profile edit', $target->fresh()->name);
        $this->assertTrue($target->fresh()->hasRole('Admin'));
        $other = $this->actor([], 'İzleyici');
        $this->patchJson("/api/users/{$other->id}", ['role' => 'Admin'])->assertForbidden();
        $this->assertTrue($other->fresh()->hasRole('İzleyici'));
    }
}
