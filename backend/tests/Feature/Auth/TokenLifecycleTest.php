<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Belirteç yaşam döngüsünün iki ucu:
 *  - bearer `POST /api/logout` o belirteci gerçekten iptal eder;
 *  - zorunlu parola değişimi bekleyen kullanıcıya verilen cihaz belirteci
 *    kısa ömürlüdür, yalnızca beyaz listedeki uçlara ulaşır ve kalıcı hâle
 *    ancak parola değiştirildikten sonra gelir.
 */
class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct!Horse2026';

    private const NEW_PASSWORD = 'Another!Horse2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Sanctum önce oturum korumasına bakar; önceki bir istekte kalan kimlik
     * bearer denemesini yanlış sebepten geçirmesin.
     */
    private function forgetGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function user(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['password' => Hash::make(self::PASSWORD)], $attributes));
        $user->assignRole('Admin');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function devicePayload(string $email): array
    {
        return [
            'email' => $email,
            'password' => self::PASSWORD,
            'device_name' => 'TEST-PC',
            'device_fingerprint' => str_repeat('c', 64),
            'platform' => 'windows',
            'app_version' => '1.0.0',
        ];
    }

    // ------------------------------------------------------------ logout

    public function test_bearer_logout_revokes_that_token_only(): void
    {
        $user = $this->user();
        $mine = $user->createToken('THIS-PC', [DeviceTokenService::ABILITY]);
        $other = $user->createToken('OTHER-PC', [DeviceTokenService::ABILITY]);

        $this->withToken($mine->plainTextToken)->postJson('/api/logout')->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mine->accessToken->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->getKey()]);

        $this->forgetGuards();
        $this->withToken($mine->plainTextToken)->getJson('/api/me')->assertUnauthorized();

        $this->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/me')->assertOk();
    }

    public function test_cookie_logout_keeps_device_tokens(): void
    {
        $user = $this->user();
        $device = $user->createToken('THIS-PC', [DeviceTokenService::ABILITY]);

        $this->actingAs($user)->postJson('/api/logout')->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $device->accessToken->getKey()]);
    }

    public function test_desktop_device_revoke_still_works(): void
    {
        $user = $this->user();
        $device = $user->createToken('THIS-PC', [DeviceTokenService::ABILITY]);

        $this->withToken($device->plainTextToken)
            ->deleteJson('/api/me/devices/'.$device->accessToken->getKey())
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $device->accessToken->getKey()]);
    }

    // ------------------------------------------- forced change + device token

    public function test_forced_change_user_gets_a_short_lived_token(): void
    {
        $this->user(['email' => 'forced@example.test', 'must_change_password' => true]);

        $this->postJson('/api/auth/device', $this->devicePayload('forced@example.test'))
            ->assertOk()
            ->assertJsonPath('must_change_password', true);

        $token = PersonalAccessToken::query()->sole();

        $this->assertNotNull($token->expires_at, 'Zorunlu değişim bekleyen kullanıcının belirteci süresiz olmamalı.');
        $this->assertTrue($token->expires_at->lte(now()->addMinutes(DeviceTokenService::FORCED_CHANGE_TOKEN_MINUTES)));
    }

    public function test_forced_change_token_reaches_only_the_whitelist_and_dies_unless_the_password_changes(): void
    {
        $this->user(['email' => 'forced@example.test', 'must_change_password' => true]);

        $plain = $this->postJson('/api/auth/device', $this->devicePayload('forced@example.test'))->json('token');

        $this->forgetGuards();
        $this->withToken($plain)->getJson('/api/me')->assertOk();

        $this->forgetGuards();
        $this->withToken($plain)->getJson('/api/sync/manifest')->assertForbidden();

        $this->travel(DeviceTokenService::FORCED_CHANGE_TOKEN_MINUTES + 1)->minutes();

        $this->forgetGuards();
        $this->withToken($plain)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_changing_the_password_from_the_device_makes_its_token_lasting(): void
    {
        $user = $this->user(['email' => 'forced@example.test', 'must_change_password' => true]);

        $plain = $this->postJson('/api/auth/device', $this->devicePayload('forced@example.test'))->json('token');

        $this->forgetGuards();
        $this->withToken($plain)->postJson('/api/password/change', [
            'current_password' => self::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertNull(PersonalAccessToken::query()->sole()->expires_at);
        $this->assertFalse((bool) $user->fresh()->must_change_password);

        $this->travel(DeviceTokenService::FORCED_CHANGE_TOKEN_MINUTES + 1)->minutes();

        $this->forgetGuards();
        $this->withToken($plain)->getJson('/api/sync/manifest')->assertOk();
    }

    public function test_regular_user_token_has_no_expiry(): void
    {
        $this->user(['email' => 'normal@example.test']);

        $this->postJson('/api/auth/device', $this->devicePayload('normal@example.test'))->assertOk();

        $this->assertNull(PersonalAccessToken::query()->sole()->expires_at);
    }
}
