<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SYNCDESKTOP §4.6/5 + protocol §3.7 — channel authorisation with a bearer
 * token.
 *
 * The desktop client's Echo instance cannot use the SPA's authorizer: it has
 * no CSRF cookie and no session. It needs a `/broadcasting/auth` that accepts
 * `Authorization: Bearer`, which is why routes/api.php registers a SECOND
 * route at `api/broadcasting/auth` instead of calling `withBroadcasting()`
 * again - that helper hard-codes the URI, and a duplicate registration would
 * SILENTLY never run (D9).
 */
class SyncBroadcastingAuthTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    private const SOCKET = '123.456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        /*
         * phpunit.xml pins BROADCAST_CONNECTION=null, and NullBroadcaster::auth()
         * is an EMPTY method: it returns null, the controller answers 200, and
         * routes/channels.php is never consulted. Every negative assertion below
         * would then pass against a broadcaster that authorises everything - the
         * worst kind of green.
         *
         * The `reverb` (Pusher-protocol) driver runs the real
         * Broadcaster::verifyUserCanAccessChannel. No server is needed: signing
         * an auth response is a local HMAC over the socket id.
         *
         * Setup copied from BroadcastingTest - the purge + re-require is
         * mandatory, because the callbacks registered at boot landed on the
         * NullBroadcaster and a fresh PusherBroadcaster would start with an
         * EMPTY channel list, answering 403 for the wrong reason.
         */
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'host' => '127.0.0.1',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);

        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    public function test_a_device_token_can_authorize_its_own_private_channel(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => 'private-user.'.$user->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_device_token_cannot_authorize_someone_elses_channel(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $other = User::factory()->create();

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => 'private-user.'.$other->id,
            ])
            ->assertStatus(403);
    }

    public function test_an_anonymous_request_is_401(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => self::SOCKET,
            'channel_name' => 'private-user.1',
        ])->assertStatus(401);
    }

    public function test_a_deactivated_user_cannot_open_new_subscriptions(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        // Straight to the column: going through the admin endpoint would also
        // delete the token, and what is under test here is the `active`
        // middleware, not the revocation.
        $user->forceFill(['is_active' => false])->saveQuietly();

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => 'private-user.'.$user->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_DEACTIVATED');
    }

    /**
     * Protocol §3.7 / D9: the SPA's own route must still be there. A second
     * `withBroadcasting()` call would have produced a duplicate `/broadcasting/
     * auth`, and RouteCollection returns the FIRST match - the new one would
     * have been dead code that nobody could see was dead.
     */
    public function test_the_spa_broadcasting_route_is_untouched(): void
    {
        $uris = collect(Route::getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_contains($uri, 'broadcasting/auth'))
            ->values()
            ->all();

        sort($uris);

        $this->assertSame(['api/broadcasting/auth', 'broadcasting/auth'], $uris);
    }
}
