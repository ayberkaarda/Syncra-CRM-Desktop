<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Settings\RoleMatrixService;
use App\Services\Users\UserService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who changed whose access, and when. Role assignment, the permission matrix,
 * an administrator's password reset and activation changes all happen outside
 * the model attributes the audit trail records on its own (pivot tables, and
 * columns it must never log), so each leaves an explicit audit row - with the
 * actor, the subject and what moved, and never any password material.
 */
class AdminAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);
    }

    private function member(string $role = 'Satış Temsilcisi'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function lastAudit(string $description): ?Activity
    {
        return Activity::query()->where('description', $description)->latest('id')->first();
    }

    public function test_a_role_change_is_audited(): void
    {
        $user = $this->member();

        $this->patchJson("/api/users/{$user->id}", ['role' => 'Admin'])->assertOk();

        $row = $this->lastAudit('users.role_changed');
        $this->assertNotNull($row, 'no audit row for the role change');
        $this->assertSame($this->admin->id, (int) $row->causer_id);
        $this->assertSame($user->id, (int) $row->subject_id);
        $this->assertSame(['Satış Temsilcisi'], $row->properties['from']);
        $this->assertSame(['Admin'], $row->properties['to']);
    }

    public function test_an_unchanged_role_writes_no_role_row(): void
    {
        $user = $this->member();

        $this->patchJson("/api/users/{$user->id}", ['role' => 'Satış Temsilcisi'])->assertOk();

        $this->assertNull($this->lastAudit('users.role_changed'));
    }

    public function test_the_role_given_to_a_new_user_is_audited(): void
    {
        // The service, not POST /api/users: the request's password rule asks
        // an online breach list, which a test must not depend on.
        $id = app(UserService::class)->create([
            'name' => 'Yeni Kişi',
            'email' => 'yeni@example.test',
            'password' => 'Xy9!kLmn0pQrS#t',
            'role' => 'İzleyici',
        ])->id;

        $row = $this->lastAudit('users.role_changed');
        $this->assertNotNull($row, 'no audit row for the role given at creation');
        $this->assertSame($id, (int) $row->subject_id);
        $this->assertSame([], $row->properties['from']);
        $this->assertSame(['İzleyici'], $row->properties['to']);
        $this->assertStringNotContainsString('Xy9!kLmn0pQrS#t', (string) $row->getRawOriginal('properties'));
    }

    public function test_a_permission_matrix_change_is_audited(): void
    {
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();
        $before = $role->permissions->pluck('name')->all();
        $after = array_values(array_diff($before, [$before[0]]));
        $after[] = 'leads.create';

        $this->patchJson("/api/settings/roles/{$role->id}/permissions", ['permissions' => $after])->assertOk();

        $row = $this->lastAudit('roles.permissions_changed');
        $this->assertNotNull($row, 'no audit row for the matrix change');
        $this->assertSame($this->admin->id, (int) $row->causer_id);
        $this->assertSame($role->id, (int) $row->subject_id);
        $this->assertSame(['leads.create'], $row->properties['granted']);
        $this->assertSame([$before[0]], $row->properties['revoked']);
    }

    /**
     * A request that reads the permission cache while the matrix save is still
     * open fills it from the committed (old) matrix. Clearing only inside the
     * transaction would leave a revoked permission alive for the cache TTL.
     */
    public function test_the_permission_cache_is_cleared_after_the_matrix_save_commits(): void
    {
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();
        $after = array_values(array_slice($role->permissions->pluck('name')->all(), 1));
        $registrar = app(PermissionRegistrar::class);

        DB::transaction(function () use ($role, $after, $registrar): void {
            app(RoleMatrixService::class)->syncPermissions($role, $after, $this->admin);

            // The other request, before this one commits.
            $registrar->getCacheRepository()->put($registrar->cacheKey, 'stale');
        });

        $this->assertFalse($registrar->getCacheRepository()->has($registrar->cacheKey));
    }

    public function test_an_unchanged_permission_matrix_writes_no_row(): void
    {
        $role = Role::query()->where('name', 'İzleyici')->firstOrFail();

        $this->patchJson("/api/settings/roles/{$role->id}/permissions", [
            'permissions' => $role->permissions->pluck('name')->all(),
        ])->assertOk();

        $this->assertNull($this->lastAudit('roles.permissions_changed'));
    }

    public function test_an_admin_password_reset_is_audited_without_the_password(): void
    {
        $user = $this->member();
        $user->createToken('desktop', ['desktop']);
        $password = 'Xy9!kLmn0pQrS#t';

        app(UserService::class)->resetPassword($user, $password);

        $row = $this->lastAudit('users.password_reset');
        $this->assertNotNull($row, 'no audit row for the password reset');
        $this->assertSame($this->admin->id, (int) $row->causer_id);
        $this->assertSame($user->id, (int) $row->subject_id);
        $this->assertSame(1, $row->properties['tokens_revoked']);

        $everything = Activity::query()->get()->map(fn (Activity $a) => $a->getRawOriginal('properties'))->implode("\n");
        $this->assertStringNotContainsString($password, $everything);
        $this->assertStringNotContainsString('$2y$', $everything);
        $this->assertStringNotContainsString((string) $user->fresh()->remember_token, $everything);
    }

    public function test_deactivation_and_reactivation_are_audited(): void
    {
        $user = $this->member();

        $this->patchJson("/api/users/{$user->id}/active", ['is_active' => false])->assertOk();
        $off = Activity::query()->where('subject_id', $user->id)->where('subject_type', $user->getMorphClass())
            ->latest('id')->first();

        $this->patchJson("/api/users/{$user->id}/active", ['is_active' => true])->assertOk();
        $on = Activity::query()->where('subject_id', $user->id)->where('subject_type', $user->getMorphClass())
            ->latest('id')->first();

        $this->assertNotNull($off);
        $this->assertFalse((bool) $off->properties['attributes']['is_active']);
        $this->assertTrue((bool) $off->properties['old']['is_active']);
        $this->assertSame($this->admin->id, (int) $off->causer_id);
        $this->assertNotSame($off->id, $on->id);
        $this->assertTrue((bool) $on->properties['attributes']['is_active']);
        $this->assertStringNotContainsString('remember_token', (string) $off->getRawOriginal('properties'));
    }
}
