<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Forced password change (`must_change_password`) - docs/AUTH-FLOWS.md §6.
 *
 * Runs against the dedicated `syncra_crm_test` database (phpunit.xml).
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Gecici!Sifre2026x';

    private const NEW = 'Kalici#Sifrem2026!';

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

        // Sanctum only makes a request stateful when Origin/Referer matches
        // config('sanctum.stateful') - without it there is no session at all.
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    private function makeUser(array $attributes = [], ?string $role = null): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make(self::CURRENT),
            'is_active' => true,
            'must_change_password' => true,
        ], $attributes));

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], $overrides);
    }

    /**
     * Simulate the next request arriving in a fresh PHP process so the guard
     * rebuilds its user from the replayed session cookie instead of reusing
     * the instance it cached during the previous request.
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

    /**
     * Collect the cookies a response set, merged onto an existing jar.
     *
     * @param  array<string, string>  $jar
     * @return array<string, string>
     */
    private function captureCookies(TestResponse $response, array $jar = []): array
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '') {
                continue;
            }

            $jar[$cookie->getName()] = $cookie->getValue();
        }

        return $jar;
    }

    /**
     * Swap the active cookie jar - this is what lets one test drive two
     * independent browser sessions for the same user.
     *
     * @param  array<string, string>  $jar
     */
    private function useCookies(array $jar): void
    {
        // Values are already encrypted by EncryptCookies on the way out, so
        // they have to be replayed verbatim rather than re-encrypted.
        $this->unencryptedCookies = [];
        $this->withUnencryptedCookies($jar);

        $this->startNewRequestCycle();
    }

    /**
     * @return array<string, string> the session jar for the new login
     */
    private function loginSession(User $user, string $password = self::CURRENT): array
    {
        $this->useCookies([]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $response->assertOk();

        return $this->captureCookies($response);
    }

    private function currentSessionId(): string
    {
        return $this->app['session']->driver()->getId();
    }

    // ---------------------------------------------------------------- A1/A2

    public function test_flagged_user_is_blocked_from_a_protected_endpoint_without_losing_the_session(): void
    {
        $user = $this->makeUser([], 'Admin');

        $this->actingAs($user)
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'PASSWORD_CHANGE_REQUIRED')
            ->assertJsonPath('errors.message', 'Devam etmeden önce geçici şifrenizi değiştirmeniz gerekiyor.');

        // The session must survive: the user still has to be able to change it.
        $this->actingAs($user)->getJson('/api/me')->assertOk();
    }

    public function test_flagged_user_is_blocked_from_every_protected_endpoint(): void
    {
        $user = $this->makeUser([], 'Super Admin');

        // Super Admin holds every ability through Gate::before, which proves the
        // enforcement is a middleware and not a permission check.
        foreach ([
            ['getJson', '/api/users'],
            ['getJson', '/api/roles'],
            ['postJson', '/api/users'],
            ['getJson', '/api/users/'.$user->id],
        ] as [$method, $uri]) {
            $this->actingAs($user)
                ->{$method}($uri)
                ->assertStatus(403)
                ->assertJsonPath('errors.code', 'PASSWORD_CHANGE_REQUIRED');
        }
    }

    public function test_whitelisted_endpoints_stay_reachable_for_a_flagged_user(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        // Reaches validation rather than the middleware: 422, not 403.
        $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload(['password' => 'kisa']))
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertNoContent();
    }

    // ------------------------------------------------------------- A3/A4/A12

    public function test_login_response_carries_the_flag(): void
    {
        $flagged = $this->makeUser();
        $clean = $this->makeUser(['must_change_password' => false]);

        $this->postJson('/api/login', ['email' => $flagged->email, 'password' => self::CURRENT])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->useCookies([]);

        $this->postJson('/api/login', ['email' => $clean->email, 'password' => self::CURRENT])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_password_change_clears_the_flag_and_lifts_the_block(): void
    {
        $user = $this->makeUser([], 'Admin');

        $originalHash = $user->password;
        $originalRememberToken = $user->getRememberToken();

        $session = $this->loginSession($user);

        // Blocked before the change.
        $this->useCookies($session);
        $this->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'PASSWORD_CHANGE_REQUIRED');

        $sessionIdBefore = $this->currentSessionId();

        $this->useCookies($session);
        $response = $this->postJson('/api/password/change', $this->payload());

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.must_change_password', false);

        // Session fixation protection: a new id was issued.
        $this->assertNotSame($sessionIdBefore, $this->currentSessionId());

        $session = $this->captureCookies($response, $session);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotSame($originalHash, $fresh->password, 'Şifre hash değişmedi.');
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->assertNotSame(
            $originalRememberToken,
            $fresh->getRememberToken(),
            'Remember token rotate edilmedi.'
        );

        // A4: the same session may now reach protected endpoints.
        $this->useCookies($session);
        $this->getJson('/api/users')->assertOk();
    }

    public function test_the_session_that_changed_the_password_survives(): void
    {
        $user = $this->makeUser();

        $session = $this->loginSession($user);

        $this->useCookies($session);
        $response = $this->postJson('/api/password/change', $this->payload());
        $response->assertOk();

        $session = $this->captureCookies($response, $session);

        $this->useCookies($session);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);
    }

    // ----------------------------------------------------------- A5/A6/A7/A8

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload(['current_password' => 'Yanlis!Sifre2026x']))
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.fields.current_password.0', 'Şifre hatalı.');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_new_password_cannot_equal_the_current_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload([
                'password' => self::CURRENT,
                'password_confirmation' => self::CURRENT,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.fields.password.0', 'şifre alanı ve mevcut şifre farklı olmalıdır.');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('weakPasswords')]
    public function test_weak_or_unconfirmed_new_password_is_rejected(array $overrides): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload($overrides));

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');

        $this->assertNotEmpty($response->json('errors.fields.password'));
        $this->assertTrue($user->fresh()->must_change_password);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function weakPasswords(): array
    {
        return [
            'eleven characters' => [['password' => 'Kisa#Sif12x', 'password_confirmation' => 'Kisa#Sif12x']],
            'no symbol' => [['password' => 'SifreOlmayan2026', 'password_confirmation' => 'SifreOlmayan2026']],
            'no number' => [['password' => 'SifreOlmayanAbc#', 'password_confirmation' => 'SifreOlmayanAbc#']],
            'no uppercase' => [['password' => 'sifreolmayan2026#', 'password_confirmation' => 'sifreolmayan2026#']],
            'confirmation mismatch' => [['password_confirmation' => 'Baska#Sifre2026!']],
        ];
    }

    public function test_password_change_is_rate_limited(): void
    {
        $user = $this->makeUser();

        // throttle:6,1 - six attempts are allowed, the seventh is refused.
        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($user)
                ->postJson('/api/password/change', $this->payload(['current_password' => 'Yanlis!Sifre'.$i]))
                ->assertStatus(422);
        }

        $response = $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload());

        $response->assertStatus(429)
            ->assertJsonPath('errors.code', 'TOO_MANY_ATTEMPTS');

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertTrue($user->fresh()->must_change_password, 'Throttle edilen istek şifreyi değiştirmiş.');
    }

    // ------------------------------------------------------------------- A9

    public function test_changing_the_password_kills_the_users_other_sessions(): void
    {
        // This test pins a guarantee that comes from CONFIGURATION, not from
        // application code: config('sanctum.middleware.authenticate_session').
        // Sanctum's AuthenticateSession compares the session's
        // `password_hash_web` with the user's current hash on every stateful
        // request. Delete that config line and other sessions silently keep
        // working - this test is the alarm for that regression.
        $user = $this->makeUser(['must_change_password' => false]);

        $sessionA = $this->loginSession($user);
        $sessionB = $this->loginSession($user);

        // Each session needs one authenticated request after login: during the
        // login request itself AuthenticateSession returns early (there is no
        // user yet when it runs) and so never stamps the hash.
        $this->useCookies($sessionA);
        $sessionA = $this->captureCookies($this->getJson('/api/me')->assertOk(), $sessionA);

        $this->useCookies($sessionB);
        $sessionB = $this->captureCookies($this->getJson('/api/me')->assertOk(), $sessionB);

        // Session A changes the password.
        $this->useCookies($sessionA);
        $response = $this->postJson('/api/password/change', $this->payload());
        $response->assertOk();
        $sessionA = $this->captureCookies($response, $sessionA);

        // A survives...
        $this->useCookies($sessionA);
        $this->getJson('/api/me')->assertOk();

        // ...B is gone on its very next request.
        $this->useCookies($sessionB);
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('errors.code', 'UNAUTHENTICATED');
    }

    public function test_admin_reset_password_kills_the_target_users_open_session(): void
    {
        $admin = $this->makeUser(['must_change_password' => false], 'Admin');
        $target = $this->makeUser(['must_change_password' => false]);

        $targetSession = $this->loginSession($target);

        $this->useCookies($targetSession);
        $targetSession = $this->captureCookies($this->getJson('/api/me')->assertOk(), $targetSession);

        // The administrator resets the target's password from their own session.
        $this->useCookies([]);
        $this->actingAs($admin)
            ->postJson("/api/users/{$target->id}/reset-password", ['password' => self::NEW])
            ->assertNoContent();

        $this->useCookies($targetSession);
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('errors.code', 'UNAUTHENTICATED');
    }

    // --------------------------------------------------------------- A10/A11

    public function test_unflagged_user_is_untouched_by_the_middleware(): void
    {
        $user = $this->makeUser(['must_change_password' => false], 'Admin');

        $this->actingAs($user)->getJson('/api/users')->assertOk();
        $this->actingAs($user)->getJson('/api/roles')->assertOk();
        $this->actingAs($user)->getJson('/api/me')->assertOk();
    }

    public function test_unflagged_user_can_change_their_password_voluntarily(): void
    {
        $user = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_deactivated_and_flagged_user_gets_user_deactivated_not_password_change_required(): void
    {
        // Middleware order is auth:sanctum -> active -> password.changed.
        $user = $this->makeUser(['is_active' => false], 'Admin');

        $this->actingAs($user)
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_DEACTIVATED');

        $this->useCookies([]);

        // Even the exempt change-password endpoint is closed to a dead account.
        $this->actingAs($user)
            ->postJson('/api/password/change', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_DEACTIVATED');
    }
}
