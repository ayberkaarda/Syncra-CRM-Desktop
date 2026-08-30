<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sanctum SPA (cookie session) authentication.
 *
 * These run against the dedicated `syncra_crm_test` database configured in
 * phpunit.xml - never the development database.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct!Horse2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // getJson()/postJson() only send cookies when credentials are enabled -
        // exactly the axios `withCredentials: true` the SPA uses. Without this
        // the session cookie never leaves the test client and every request
        // silently starts a brand new session.
        $this->withCredentials();

        // Sanctum only turns a request stateful when its Origin/Referer matches
        // config('sanctum.stateful'); without it there is no session at all.
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    private function makeUser(array $attributes = [], ?string $role = null): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ], $attributes));

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /**
     * Carry the session + XSRF cookies of a response into the next request.
     * The test client does not keep a cookie jar on its own.
     */
    private function keepSessionFrom(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '') {
                continue;
            }

            // The values are already encrypted by EncryptCookies on the way
            // out, so they must be replayed verbatim.
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        $this->startNewRequestCycle();
    }

    /**
     * Simulate the next request arriving in a brand new PHP process: drop the
     * resolved auth manager so the guard has to rebuild its user from the
     * replayed session cookie instead of returning the instance it cached
     * during the previous request.
     */
    private function startNewRequestCycle(): void
    {
        Auth::clearResolvedInstances();
        $this->app->forgetInstance('auth');
        $this->app->forgetInstance('auth.driver');

        // The session Store is a singleton and Store::loadSession() merges the
        // handler's data ON TOP of whatever attributes it already holds
        // (array_replace). Without this flush the previous request's session
        // attributes - `password_hash_web` above all - would bleed into the
        // next one, which never happens in production where every request
        // builds a fresh Store. Only the in-memory attributes are dropped; the
        // array session handler keeps the persisted data.
        if ($this->app->resolved('session')) {
            $this->app['session']->driver()->flush();
        }
    }

    private function login(User $user, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/login', array_merge([
            'email' => $user->email,
            'password' => self::PASSWORD,
        ], $overrides));
    }

    public function test_csrf_cookie_endpoint_is_available(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $this->assertNotNull($response->headers->getCookies()[0] ?? null);
    }

    public function test_user_can_login_with_valid_credentials_and_reach_me(): void
    {
        $user = $this->makeUser(['name' => 'Ayse Yilmaz'], 'Satış Temsilcisi');

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.role.name', 'Satış Temsilcisi')
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'email', 'department', 'is_active',
                    'must_change_password', 'last_login_at', 'created_at',
                    'role', 'permissions',
                ],
            ]);

        $this->assertNotNull($user->fresh()->last_login_at, 'last_login_at güncellenmedi.');
        $this->assertAuthenticatedAs($user->fresh());

        $this->keepSessionFrom($response);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_with_wrong_password_returns_422_without_leaking_which_field_is_wrong(): void
    {
        $user = $this->makeUser();

        $response = $this->login($user, ['password' => 'definitely-not-it']);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.fields.email.0', 'E-posta veya şifre hatalı.');

        // The generic message must not hint at which half was wrong, and the
        // response must not carry a `password` field error either.
        $response->assertJsonMissingPath('errors.fields.password');
        $this->assertGuest();
    }

    public function test_unknown_email_returns_the_same_response_as_a_wrong_password(): void
    {
        $user = $this->makeUser();

        $wrongPassword = $this->login($user, ['password' => 'definitely-not-it']);
        $unknownEmail = $this->login($user, [
            'email' => 'nobody-here@syncra.local',
            'password' => 'definitely-not-it',
        ]);

        $this->assertSame($wrongPassword->getStatusCode(), $unknownEmail->getStatusCode());
        $this->assertSame(
            $wrongPassword->json('errors'),
            $unknownEmail->json('errors'),
            'Bilinmeyen e-posta ile yanlış şifre yanıtları farklı - kullanıcı numaralandırma riski.'
        );
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $user = $this->makeUser(['is_active' => false]);

        $this->login($user)
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_DEACTIVATED');

        $this->assertGuest();
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('errors.code', 'UNAUTHENTICATED');
    }

    public function test_sixth_login_attempt_is_rate_limited(): void
    {
        $user = $this->makeUser();

        for ($i = 1; $i <= AuthService::MAX_LOGIN_ATTEMPTS; $i++) {
            $this->login($user, ['password' => 'wrong-'.$i])->assertStatus(422);
        }

        $response = $this->login($user, ['password' => 'wrong-6']);

        $response->assertStatus(429)
            ->assertJsonPath('errors.code', 'TOO_MANY_ATTEMPTS');

        $this->assertNotNull($response->headers->get('Retry-After'), 'Retry-After başlığı yok.');
    }

    public function test_lockout_window_grows_after_a_lockout(): void
    {
        $user = $this->makeUser();
        $key = AuthService::throttleKey($user->email, '127.0.0.1');

        $this->assertSame(AuthService::BASE_LOCKOUT_MINUTES, AuthService::lockoutMinutes($key));

        for ($i = 1; $i <= AuthService::MAX_LOGIN_ATTEMPTS; $i++) {
            $this->login($user, ['password' => 'wrong-'.$i])->assertStatus(422);
        }

        $this->assertGreaterThan(
            AuthService::BASE_LOCKOUT_MINUTES,
            AuthService::lockoutMinutes($key),
            'Ardışık kilitlenmede bekleme süresi artmadı.'
        );
    }

    public function test_successful_login_clears_the_rate_limiter(): void
    {
        $user = $this->makeUser();
        $key = AuthService::throttleKey($user->email, '127.0.0.1');

        $this->login($user, ['password' => 'wrong'])->assertStatus(422);
        $this->login($user)->assertOk();

        $this->assertSame(AuthService::BASE_LOCKOUT_MINUTES, AuthService::lockoutMinutes($key));

        // The next window starts from a clean slate.
        $this->login($user)->assertOk();
    }

    public function test_me_returns_401_after_logout(): void
    {
        $user = $this->makeUser();

        $loginResponse = $this->login($user);
        $loginResponse->assertOk();
        $this->keepSessionFrom($loginResponse);

        $logoutResponse = $this->postJson('/api/logout');
        $logoutResponse->assertNoContent();
        $this->keepSessionFrom($logoutResponse);

        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('errors.code', 'UNAUTHENTICATED');
    }

    public function test_user_deactivated_mid_session_is_rejected_on_the_next_request(): void
    {
        $user = $this->makeUser();

        $loginResponse = $this->login($user);
        $loginResponse->assertOk();
        $this->keepSessionFrom($loginResponse);

        $this->getJson('/api/me')->assertOk();

        // Administrator flips the flag while the session is still open.
        $user->forceFill(['is_active' => false])->save();

        // In production every request is a fresh process; force the guard to
        // re-read the user instead of reusing the one it cached above.
        $this->startNewRequestCycle();

        $this->getJson('/api/me')
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_DEACTIVATED');
    }

    public function test_super_admin_receives_every_ability_through_gate_before(): void
    {
        $superAdmin = $this->makeUser([], 'Super Admin');

        $this->assertTrue($superAdmin->roles()->exists());
        // The role itself carries zero permission rows on purpose.
        $this->assertSame(0, $superAdmin->roles->first()->permissions()->count());

        $this->assertTrue($superAdmin->can('users.delete'));
        $this->assertTrue($superAdmin->can('settings.manage'));
        $this->assertTrue($superAdmin->can('logs.export'));
    }

    public function test_gate_before_does_not_grant_abilities_to_other_roles(): void
    {
        // Guards against returning `false` instead of `null` from Gate::before,
        // which would short-circuit every other check in the application.
        $rep = $this->makeUser([], 'Satış Temsilcisi');

        $this->assertFalse($rep->can('users.delete'));
        $this->assertTrue($rep->can('leads.create'));
    }

    public function test_user_resource_expands_every_permission_for_a_super_admin(): void
    {
        $superAdmin = $this->makeUser([], 'Super Admin');

        $permissionCount = Permission::where('guard_name', 'web')->count();
        $this->assertSame(63, $permissionCount, 'İzin sözlüğü 63 kayıt olmalı.');

        $response = $this->login($superAdmin);

        $permissions = $response->assertOk()->json('data.permissions');

        $this->assertCount($permissionCount, $permissions);
        $this->assertContains('users.delete', $permissions);
        $this->assertContains('settings.manage', $permissions);

        $this->assertSame($permissions, UserResource::effectivePermissions($superAdmin));
    }

    public function test_user_resource_returns_only_the_role_permissions_for_a_normal_user(): void
    {
        $viewer = $this->makeUser([], 'İzleyici');

        $permissions = $this->login($viewer)->assertOk()->json('data.permissions');

        $this->assertContains('users.view', $permissions);
        $this->assertNotContains('users.delete', $permissions);
        $this->assertNotContains('settings.manage', $permissions);
    }

    public function test_forgot_password_always_returns_202(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Talebiniz alındı. Sistem yöneticisi sizinle iletişime geçecek.');

        $this->postJson('/api/password/forgot', ['email' => 'ghost@syncra.local'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Talebiniz alındı. Sistem yöneticisi sizinle iletişime geçecek.');
    }

    public function test_unknown_api_route_returns_the_not_found_envelope(): void
    {
        $this->getJson('/api/definitely-not-a-route')
            ->assertStatus(404)
            ->assertJsonPath('errors.code', 'NOT_FOUND');
    }
}
