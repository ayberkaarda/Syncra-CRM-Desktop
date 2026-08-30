<?php

namespace Tests\Feature;

use App\Listeners\LogFailedLogin;
use App\Listeners\LogLockout;
use App\Listeners\LogSuccessfulLogin;
use App\Listeners\LogSuccessfulLogout;
use App\Models\SessionLog;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\UserAgentParser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Faz 5 / B - session_logs (login/logout/failed_login/locked_out) audit
 * trail. Mirrors the request-plumbing helpers in tests/Feature/AuthTest.php
 * (Sanctum SPA cookie session, withCredentials, csrf-stateful Origin).
 */
class SessionLogTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct!Horse2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withCredentials();

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
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
     * The test client does not keep a cookie jar on its own. Mirrors
     * AuthTest::keepSessionFrom()/startNewRequestCycle().
     */
    private function keepSessionFrom(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '') {
                continue;
            }

            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        Auth::clearResolvedInstances();
        $this->app->forgetInstance('auth');
        $this->app->forgetInstance('auth.driver');

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

    public function test_successful_login_writes_a_login_row(): void
    {
        $user = $this->makeUser();

        $response = $this->login($user);
        $response->assertOk();

        $this->assertSame(1, SessionLog::query()->where('event', 'login')->count());

        $row = SessionLog::query()->where('event', 'login')->first();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($user->email, $row->email);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('Chrome 120', $row->browser);
        $this->assertSame('Windows 10/11', $row->platform);
        $this->assertSame('desktop', $row->device);
        $this->assertNotNull($row->logged_in_at);
        $this->assertNotNull($row->session_id);
        $this->assertNull($row->logged_out_at);
    }

    public function test_logout_updates_the_same_row_instead_of_inserting_a_new_one(): void
    {
        $user = $this->makeUser();

        $loginResponse = $this->login($user);
        $loginResponse->assertOk();
        $this->keepSessionFrom($loginResponse);

        $this->assertSame(1, SessionLog::query()->count());
        $loginRow = SessionLog::query()->where('event', 'login')->firstOrFail();

        $logoutResponse = $this->postJson('/api/logout');
        $logoutResponse->assertNoContent();

        // Still exactly one row - the login row was updated, not duplicated.
        $this->assertSame(1, SessionLog::query()->count());

        $loginRow->refresh();
        $this->assertNotNull($loginRow->logged_out_at, 'logged_out_at doldurulmadı.');
        $this->assertNotNull($loginRow->duration_seconds, 'duration_seconds doldurulmadı.');
        $this->assertSame('login', $loginRow->event, 'event alanı hâlâ login olmalı - satır güncellendi, yeni satır açılmadı.');
    }

    public function test_wrong_password_writes_a_failed_login_row_pointing_at_the_real_user(): void
    {
        $user = $this->makeUser();

        $this->login($user, ['password' => 'definitely-not-it'])->assertStatus(422);

        $row = SessionLog::query()->where('event', 'failed_login')->firstOrFail();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($user->email, $row->email);
        $this->assertNotNull($row->ip_address);
    }

    public function test_unknown_email_writes_a_failed_login_row_with_null_user_id(): void
    {
        $this->login($this->makeUser(), [
            'email' => 'nobody-here@syncra.local',
            'password' => 'irrelevant',
        ])->assertStatus(422);

        $row = SessionLog::query()->where('event', 'failed_login')->firstOrFail();

        $this->assertNull($row->user_id);
        $this->assertSame('nobody-here@syncra.local', $row->email);
    }

    public function test_sixth_failed_attempt_writes_exactly_one_locked_out_row(): void
    {
        $user = $this->makeUser();

        for ($i = 1; $i <= AuthService::MAX_LOGIN_ATTEMPTS; $i++) {
            $this->login($user, ['password' => 'wrong-'.$i])->assertStatus(422);
        }

        // Next attempt is rejected by throttle:login BEFORE reaching AuthService.
        $this->login($user, ['password' => 'wrong-6'])->assertStatus(429);

        $this->assertSame(
            1,
            SessionLog::query()->where('event', 'locked_out')->count(),
            'locked_out satırı tam olarak bir kez yazılmalı.'
        );

        $lockRow = SessionLog::query()->where('event', 'locked_out')->firstOrFail();
        $this->assertSame($user->email, $lockRow->email);

        // Failed attempts before the lock: MAX_LOGIN_ATTEMPTS rows.
        $this->assertSame(
            AuthService::MAX_LOGIN_ATTEMPTS,
            SessionLog::query()->where('event', 'failed_login')->count()
        );
    }

    public function test_deactivated_user_login_attempt_is_logged_and_fails(): void
    {
        $user = $this->makeUser(['is_active' => false]);

        $this->login($user)->assertStatus(403);

        $this->assertGuest();

        $row = SessionLog::query()->where('event', 'failed_login')->firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($user->email, $row->email);
    }

    public function test_a_session_log_write_failure_does_not_break_login(): void
    {
        $this->app->bind(LogSuccessfulLogin::class, fn () => new class(app(UserAgentParser::class)) extends LogSuccessfulLogin
        {
            protected function persist(array $attributes): void
            {
                throw new \RuntimeException('DB kilitli (simülasyon).');
            }
        });

        $user = $this->makeUser();

        $response = $this->login($user);

        $response->assertOk();
        $this->assertAuthenticatedAs($user->fresh());

        // The write failed, so no row exists - but the login itself succeeded.
        $this->assertSame(0, SessionLog::query()->where('event', 'login')->count());
    }

    public function test_a_session_log_write_failure_does_not_break_logout(): void
    {
        $this->app->bind(LogSuccessfulLogout::class, fn () => new class(app(UserAgentParser::class)) extends LogSuccessfulLogout
        {
            protected function persist(array $attributes): void
            {
                throw new \RuntimeException('DB kilitli (simülasyon).');
            }

            protected function update(SessionLog $log, array $attributes): void
            {
                throw new \RuntimeException('DB kilitli (simülasyon).');
            }
        });

        $user = $this->makeUser();
        $loginResponse = $this->login($user);
        $loginResponse->assertOk();
        $this->keepSessionFrom($loginResponse);

        $logoutResponse = $this->postJson('/api/logout');
        $logoutResponse->assertNoContent();
        $this->keepSessionFrom($logoutResponse);

        // Same check AuthTest::test_me_returns_401_after_logout() uses - a
        // fresh request confirms the session really was invalidated, despite
        // the session-log write throwing.
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('errors.code', 'UNAUTHENTICATED');
    }

    public function test_a_session_log_write_failure_does_not_break_failed_login(): void
    {
        $this->app->bind(LogFailedLogin::class, fn () => new class(app(UserAgentParser::class)) extends LogFailedLogin
        {
            protected function persist(array $attributes): void
            {
                throw new \RuntimeException('DB kilitli (simülasyon).');
            }
        });

        $user = $this->makeUser();

        $this->login($user, ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('errors.fields.email.0', 'E-posta veya şifre hatalı.');

        $this->assertGuest();
    }

    public function test_a_session_log_write_failure_does_not_break_lockout(): void
    {
        $this->app->bind(LogLockout::class, fn () => new class(app(UserAgentParser::class)) extends LogLockout
        {
            protected function persist(array $attributes): void
            {
                throw new \RuntimeException('DB kilitli (simülasyon).');
            }
        });

        $user = $this->makeUser();

        for ($i = 1; $i <= AuthService::MAX_LOGIN_ATTEMPTS; $i++) {
            $this->login($user, ['password' => 'wrong-'.$i])->assertStatus(422);
        }

        $this->login($user, ['password' => 'wrong-6'])
            ->assertStatus(429)
            ->assertJsonPath('errors.code', 'TOO_MANY_ATTEMPTS');
    }
}
