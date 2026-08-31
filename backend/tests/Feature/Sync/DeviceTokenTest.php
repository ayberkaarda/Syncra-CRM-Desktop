<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\DeviceTokenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * SYNCDESKTOP §4.6/1 + §9 — device token issue, lockout, revocation and the
 * ability gate.
 *
 * The gate assertions are the reason this file exists: protocol §3.3/K-A
 * proved that `ability:desktop` alone cannot refuse a cookie session, so both
 * halves are locked here - the ability check AND the `device.token` check that
 * makes it meaningful.
 */
class DeviceTokenTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    private const PASSWORD = 'Correct!Horse2026';

    private const FINGERPRINT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Drop whatever `actingAs()` left on the web guard.
     *
     * Sanctum tries `config('sanctum.guard')` - the session guard - BEFORE it
     * looks at the bearer token, so a test that used actingAs() earlier would
     * keep authenticating as that user and would pass for the wrong reason.
     */
    private function forgetAuthenticatedSession(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function actor(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make(self::PASSWORD),
        ], $attributes));

        $user->assignRole('Admin');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'device@example.test',
            'password' => self::PASSWORD,
            'device_name' => 'AYBERK-PC',
            'device_fingerprint' => self::FINGERPRINT,
            'platform' => 'windows',
            'app_version' => '1.0.0',
        ], $overrides);
    }

    public function test_device_login_issues_a_desktop_token_and_writes_a_desktop_session_log(): void
    {
        $user = $this->actor(['email' => 'device@example.test']);

        $response = $this->postJson('/api/auth/device', $this->payload());

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_id', 'user', 'must_change_password', 'abilities'])
            ->assertJsonPath('abilities', [DeviceTokenService::ABILITY])
            ->assertJsonPath('user.id', $user->id);

        $token = PersonalAccessToken::query()->firstOrFail();

        $this->assertSame([DeviceTokenService::ABILITY], $token->abilities);
        $this->assertSame(self::FINGERPRINT, $token->device_fingerprint);
        $this->assertSame('windows', $token->device_platform);
        // config/sanctum.php `expiration => null` must stay null: a desktop
        // credential that silently expires would log the user out of an
        // offline application with no way to explain why.
        $this->assertNull($token->expires_at);

        $this->assertDatabaseHas('session_logs', [
            'user_id' => $user->id,
            'event' => 'login',
            'channel' => 'desktop',
        ]);
    }

    public function test_a_second_login_from_the_same_fingerprint_replaces_the_previous_token(): void
    {
        $this->actor(['email' => 'device@example.test']);

        $first = $this->postJson('/api/auth/device', $this->payload())->json('token_id');
        $second = $this->postJson('/api/auth/device', $this->payload())->json('token_id');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, PersonalAccessToken::query()->count(), 'One token per device (SYNCDESKTOP §4.3).');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first]);
    }

    public function test_a_different_fingerprint_keeps_both_tokens(): void
    {
        $this->actor(['email' => 'device@example.test']);

        $this->postJson('/api/auth/device', $this->payload());
        $this->postJson('/api/auth/device', $this->payload([
            'device_fingerprint' => str_repeat('b', 64),
            'device_name' => 'LINUX-BOX',
            'platform' => 'linux',
        ]));

        $this->assertSame(2, PersonalAccessToken::query()->count());
    }

    public function test_wrong_password_is_401_invalid_credentials(): void
    {
        $this->actor(['email' => 'device@example.test']);

        $this->postJson('/api/auth/device', $this->payload(['password' => 'nope']))
            ->assertStatus(401)
            ->assertJsonPath('errors.code', 'INVALID_CREDENTIALS');
    }

    public function test_inactive_user_is_403_user_inactive(): void
    {
        $this->actor(['email' => 'device@example.test', 'is_active' => false]);

        $this->postJson('/api/auth/device', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_INACTIVE');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    /**
     * SYNCDESKTOP §4.3: the lockout is the SAME keyed lockout POST /api/login
     * uses. Proved by exhausting the counter through /api/login and then
     * observing the device endpoint refuse - if the two had separate counters
     * (which a second named limiter would give them, see DeviceTokenService)
     * this would still return 401.
     */
    public function test_lockout_is_shared_with_the_web_login_and_answers_423(): void
    {
        $this->actor(['email' => 'device@example.test']);

        for ($i = 0; $i < AuthService::MAX_LOGIN_ATTEMPTS; $i++) {
            $this->postJson('/api/login', ['email' => 'device@example.test', 'password' => 'wrong']);
        }

        $response = $this->postJson('/api/auth/device', $this->payload());

        $response->assertStatus(423)
            ->assertJsonPath('errors.code', 'LOCKED_OUT');

        $this->assertIsInt($response->json('errors.retry_after'));
        $this->assertGreaterThan(0, $response->json('errors.retry_after'));
    }

    /**
     * The escalation counter is shared too: after one lockout the NEXT window
     * doubles (1 -> 2 minutes), which AuthService::lockoutMinutes() reads from
     * a cache key that carries no limiter name.
     */
    public function test_device_failures_escalate_the_shared_lockout_window(): void
    {
        $this->actor(['email' => 'device@example.test']);

        $throttleKey = AuthService::throttleKey('device@example.test', '127.0.0.1');

        $this->assertSame(AuthService::BASE_LOCKOUT_MINUTES, AuthService::lockoutMinutes($throttleKey));

        for ($i = 0; $i < AuthService::MAX_LOGIN_ATTEMPTS; $i++) {
            $this->postJson('/api/auth/device', $this->payload(['password' => 'wrong']));
        }

        $this->assertSame(
            AuthService::BASE_LOCKOUT_MINUTES * 2,
            AuthService::lockoutMinutes($throttleKey),
            'Cihaz girişi başarısızlıkları paylaşılan kilitlenme sayacını ilerletmiyor.'
        );

        RateLimiter::clear(AuthService::limiterKey($throttleKey));
    }

    public function test_deactivating_a_user_revokes_their_device_tokens_immediately(): void
    {
        [$user, $token] = $this->deviceUser();

        $this->withToken($token)->getJson('/api/sync/manifest')->assertOk();

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)->patchJson("/api/users/{$user->id}/active", ['is_active' => false])->assertOk();

        $this->assertSame(0, $user->tokens()->count());

        // `actingAs()` leaves the admin resolved on the web guard, and Sanctum
        // tries that guard BEFORE the bearer token - so without this the next
        // request would authenticate as the admin and prove nothing about the
        // revoked token.
        $this->forgetAuthenticatedSession();

        $this->withToken($token)->getJson('/api/sync/manifest')->assertStatus(401);
    }

    public function test_admin_password_reset_revokes_every_device_token(): void
    {
        [$user, $token] = $this->deviceUser();

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->postJson("/api/users/{$user->id}/reset-password", ['password' => 'Another!Horse2026'])
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());

        $this->forgetAuthenticatedSession();

        $this->withToken($token)->getJson('/api/sync/manifest')->assertStatus(401);
    }

    /**
     * SYNCDESKTOP §9/1, in the form protocol D2 corrected it to: a client
     * without a DEVICE token is refused, whatever its abilities claim.
     */
    public function test_a_token_without_the_desktop_ability_is_refused(): void
    {
        [, $token] = $this->nonDeviceTokenUser();

        $this->withToken($token)->getJson('/api/sync/manifest')->assertStatus(403);
    }

    /**
     * Protocol §7.3/1 — the ONE test where `actingAs()` is mandatory.
     *
     * It reproduces the weakness K-A documents: TransientToken::can() returns
     * true unconditionally, so a cookie session passes `ability:desktop`. If
     * `device.token` were ever removed from the route this test would go GREEN
     * on a 200 and the leak would be invisible; asserting 403 pins the
     * middleware that actually closes it.
     */
    public function test_a_cookie_session_cannot_reach_the_sync_endpoints(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $this->actingAs($user)
            ->getJson('/api/sync/manifest')
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'ABILITY_REQUIRED');
    }

    public function test_a_user_sees_and_revokes_only_their_own_devices(): void
    {
        [$user, $token] = $this->deviceUser();
        [$other] = $this->deviceUser();

        $otherTokenId = $other->tokens()->value('id');

        $response = $this->withToken($token)->getJson('/api/me/devices');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.name', 'TEST-PC');

        // Somebody else's token id is a 404, not a 403 - the same
        // existence-hiding rule the notifications endpoints use.
        $this->withToken($token)->deleteJson("/api/me/devices/{$otherTokenId}")->assertStatus(404);
        $this->assertSame(1, $other->tokens()->count());

        $ownTokenId = $user->tokens()->value('id');
        $this->withToken($token)->deleteJson("/api/me/devices/{$ownTokenId}")->assertNoContent();
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }
}
