<?php

namespace Tests\Feature\Security;

use App\Models\Conversation;
use App\Models\Deal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zorunlu parola değişikliği bekleyen kullanıcı REST tarafında yalnızca
 * logout/me/password-change uçlarına erişir. Kanal yetkilendirmesi de aynı
 * çizgiyi izlemeli: veri taşıyan her kanal reddedilir; kişisel
 * `private-user.{id}` kanalı da reddedilir, çünkü bildirim, görev hatırlatma
 * (başlık + bağlı kayıt etiketi) ve sohbet sayaçlarını taşır. Her iki
 * giriş noktası (`/broadcasting/auth` ve `/api/broadcasting/auth`) aynı
 * callback'leri paylaştığı için ikisi de denenir.
 *
 * `reverb` sürücüsü zorlanır: `null` sürücüsü callback'leri hiç çalıştırmaz
 * (bkz. ChannelAuthorizationTest).
 */
class ForcedPasswordChannelTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '246810.1213141';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
            ],
        ]);

        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    private function authorize(User $user, string $uri, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson($uri, ['socket_id' => self::SOCKET, 'channel_name' => $channel]);
    }

    private function forcedAdmin(): User
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('Admin');
        $user->givePermissionTo('logs.view');

        return $user;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function routes(): array
    {
        return [
            'cookie' => ['/broadcasting/auth'],
            'bearer' => ['/api/broadcasting/auth'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function dataChannels(User $user): array
    {
        $deal = Deal::factory()->create();
        $conversation = Conversation::factory()->create();
        $conversation->users()->attach($user->id);

        return [
            'presence-online',
            "presence-record.deal.{$deal->id}",
            "private-conversation.{$conversation->id}",
            'private-logs',
            'private-dashboard',
            'private-deals',
            'private-tickets',
        ];
    }

    #[DataProvider('routes')]
    public function test_forced_change_user_gets_no_channel_not_even_the_personal_one(string $uri): void
    {
        $user = $this->forcedAdmin();

        $this->authorize($user, $uri, 'private-user.'.$user->id)->assertForbidden();

        foreach ($this->dataChannels($user) as $channel) {
            $this->authorize($user, $uri, $channel)->assertForbidden();
        }
    }

    #[DataProvider('routes')]
    public function test_same_user_after_password_change_gets_every_channel(string $uri): void
    {
        $user = $this->forcedAdmin();
        $user->forceFill(['must_change_password' => false])->save();

        $this->authorize($user, $uri, 'private-user.'.$user->id)->assertOk();

        foreach ($this->dataChannels($user) as $channel) {
            $this->authorize($user, $uri, $channel)->assertOk();
        }
    }
}
