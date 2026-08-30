<?php

namespace Tests\Feature;

use App\Events\UserDeactivated;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rol/izin sözlüğünü kur (63 izin, 6 rol) — proje kuralı: ayrı test veritabanı,
        // ana syncra_crm verisine dokunulmaz (RefreshDatabase + phpunit.xml testing DB).
        $this->seed(RolePermissionSeeder::class);
    }

    protected function actorWithRole(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if (! empty($extraPermissions)) {
            $user->givePermissionTo($extraPermissions);
        }

        return $user;
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_user_without_users_view_permission_cannot_list_users(): void
    {
        $actor = User::factory()->create(); // rolsüz / izinsiz

        $response = $this->actingAs($actor)->getJson('/api/users');

        $response->assertStatus(403);
    }

    public function test_izleyici_role_cannot_create_user(): void
    {
        $actor = $this->actorWithRole('İzleyici');

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Yeni Kullanıcı',
            'email' => 'yeni.kullanici@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_user_with_must_change_password_true(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Yeni Kullanıcı',
            'email' => 'yeni.kullanici@example.com',
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
            'department' => 'Satış',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.must_change_password', true);

        $this->assertDatabaseHas('users', [
            'email' => 'yeni.kullanici@example.com',
            'must_change_password' => true,
        ]);
    }

    public function test_weak_password_is_rejected(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'Zayıf Şifreli',
            'email' => 'zayif.sifreli@example.com',
            'password' => '12345678',
            'role' => 'Satış Temsilcisi',
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $actor = $this->actorWithRole('Admin');
        $existing = User::factory()->create(['email' => 'mevcut@example.com']);

        $response = $this->actingAs($actor)->postJson('/api/users', [
            'name' => 'İkinci Kullanıcı',
            'email' => $existing->email,
            'password' => 'Str0ng!Passw0rd#26',
            'role' => 'Satış Temsilcisi',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_cannot_deactivate_own_account(): void
    {
        $actor = $this->actorWithRole('Admin');

        $response = $this->actingAs($actor)->patchJson("/api/users/{$actor->id}/active", [
            'is_active' => false,
        ]);

        $response->assertStatus(403);
    }

    public function test_last_active_super_admin_cannot_be_deactivated(): void
    {
        $superAdmin = $this->superAdmin();

        $actor = $this->actorWithRole('Admin', [
            'users.toggle_active',
            'users.manage_roles',
        ]);

        $response = $this->actingAs($actor)->patchJson("/api/users/{$superAdmin->id}/active", [
            'is_active' => false,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'is_active' => true]);
    }

    public function test_last_active_super_admin_cannot_be_deleted(): void
    {
        $superAdmin = $this->superAdmin();

        $actor = $this->actorWithRole('Admin', [
            'users.delete',
            'users.manage_roles',
        ]);

        $response = $this->actingAs($actor)->deleteJson("/api/users/{$superAdmin->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'deleted_at' => null]);
    }

    public function test_user_without_manage_roles_permission_cannot_change_role(): void
    {
        $actor = $this->actorWithRole('Admin'); // Admin rolünde users.manage_roles YOK
        $target = $this->actorWithRole('Satış Temsilcisi');

        $response = $this->actingAs($actor)->patchJson("/api/users/{$target->id}", [
            'role' => 'Destek Temsilcisi',
        ]);

        $response->assertStatus(403);
        $this->assertTrue($target->fresh()->hasRole('Satış Temsilcisi'));
    }

    public function test_deactivating_a_user_dispatches_user_deactivated_event(): void
    {
        Event::fake([UserDeactivated::class]);

        $actor = $this->actorWithRole('Admin');
        $target = $this->actorWithRole('Satış Temsilcisi');

        $response = $this->actingAs($actor)->patchJson("/api/users/{$target->id}/active", [
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        Event::assertDispatched(UserDeactivated::class);
    }

    public function test_index_supports_pagination_sorting_search_and_filtering(): void
    {
        $actor = $this->actorWithRole('Admin');

        User::factory()->create(['name' => 'Ahmet Yılmaz', 'email' => 'ahmet@example.com', 'is_active' => true]);
        User::factory()->create(['name' => 'Mehmet Demir', 'email' => 'mehmet@example.com', 'is_active' => false]);
        User::factory()->create(['name' => 'Ahmet Kaya', 'email' => 'ahmetkaya@example.com', 'is_active' => true]);

        // Pagination
        $response = $this->actingAs($actor)->getJson('/api/users?per_page=2&page=1');
        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.per_page', 2);
        $response->assertJsonPath('meta.pagination.current_page', 1);
        $this->assertCount(2, $response->json('data'));
        $this->assertGreaterThanOrEqual(4, $response->json('meta.pagination.total'));

        // Search (q)
        $response = $this->actingAs($actor)->getJson('/api/users?q=Ahmet&per_page=100');
        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->every(fn ($name) => str_contains($name, 'Ahmet')));

        // Filter (is_active)
        $response = $this->actingAs($actor)->getJson('/api/users?filter[is_active]=0&per_page=100');
        $response->assertStatus(200);
        $activeFlags = collect($response->json('data'))->pluck('is_active');
        $this->assertTrue($activeFlags->every(fn ($isActive) => $isActive === false));

        // Sorting (name asc)
        $response = $this->actingAs($actor)->getJson('/api/users?sort=name&per_page=100');
        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->values()->all();
        $sorted = $names;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names);
    }

    public function test_unknown_sort_column_falls_back_to_default_without_error(): void
    {
        $actor = $this->actorWithRole('Admin');

        User::factory()->count(2)->create();

        $response = $this->actingAs($actor)->getJson('/api/users?sort=not_a_real_column');

        $response->assertStatus(200);
    }
}
