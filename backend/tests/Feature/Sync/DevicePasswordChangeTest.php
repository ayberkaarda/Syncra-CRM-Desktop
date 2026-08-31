<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Services\Auth\DeviceTokenService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Protocol §7.3/8 + §3.6 — what a password change does to device tokens.
 *
 * ==========================================================================
 * THE TRAP THIS FILE EXISTS FOR
 * ==========================================================================
 * The intuitive implementation is "delete every token except my own":
 *
 *     $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
 *
 * From the SPA that deletes NOTHING. A cookie session's currentAccessToken()
 * is a TransientToken, which has no `id`, so the expression collapses to
 * `where('id', '!=', null)` - and SQL matches no row against NULL. The gate
 * would silently do nothing forever, with no error anywhere.
 *
 * Both directions are asserted, because a test of only the desktop case would
 * pass against exactly that broken implementation.
 */
class DevicePasswordChangeTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    private const PASSWORD = 'Correct!Horse2026';

    private const NEW_PASSWORD = 'Another!Horse2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * The SPA half needs a REAL session, which Sanctum only creates when
     * Origin matches config('sanctum.stateful') and the test client actually
     * sends cookies. Copied from Tests\Feature\Auth\ChangePasswordTest -
     * without it the request is stateless, the TransientToken this test is
     * about never exists, and the assertion would prove nothing.
     */
    private function asSpa(): void
    {
        $this->withCredentials();
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    public function test_changing_the_password_from_the_spa_drops_every_device_token(): void
    {
        $this->asSpa();

        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->assignRole('Admin');

        $user->createToken('LAPTOP', [DeviceTokenService::ABILITY]);
        $user->createToken('DESKTOP', [DeviceTokenService::ABILITY]);

        $this->assertSame(2, $user->tokens()->count());

        $this->actingAs($user)
            ->postJson('/api/password/change', [
                'current_password' => self::PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'SPA\'dan yapılan şifre değişimi cihaz belirteçlerini SİLMEDİ — TransientToken tuzağı.'
        );
    }

    public function test_changing_the_password_from_a_device_keeps_that_device_and_drops_the_others(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->assignRole('Admin');

        $mine = $user->createToken('THIS-PC', [DeviceTokenService::ABILITY]);
        $other = $user->createToken('OTHER-PC', [DeviceTokenService::ABILITY]);

        $this->withToken($mine->plainTextToken)
            ->postJson('/api/password/change', [
                'current_password' => self::PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $remaining = $user->fresh()->tokens()->pluck('id')->all();

        $this->assertSame([$mine->accessToken->getKey()], $remaining);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->getKey()]);

        // And the surviving token still works - the user is not signed out of
        // the machine they are sitting at.
        $this->withToken($mine->plainTextToken)->getJson('/api/sync/manifest')->assertOk();
    }
}
