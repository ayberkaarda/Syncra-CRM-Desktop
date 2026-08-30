<?php

namespace Tests\Feature;

use App\Broadcasting\ChannelRegistry;
use App\Broadcasting\OnlineUserRegistry;
use App\Events\UserDeactivated;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Tests\TestCase;

/**
 * Channel authorization - the security boundary of the realtime layer.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SUITE FORCES THE `reverb` CONNECTION
 * ---------------------------------------------------------------------------
 * phpunit.xml sets BROADCAST_CONNECTION=null so ordinary tests never emit a
 * frame. NullBroadcaster::auth() is an empty method: it returns null, the
 * controller answers 200, and the channel callbacks are never consulted at all.
 * Left alone, every assertion below would pass against a broadcaster that
 * authorizes literally everything - the worst kind of green test.
 *
 * setUp() therefore swaps in the `reverb` (Pusher-protocol) broadcaster with
 * throwaway credentials. That path runs the real
 * Broadcaster::verifyUserCanAccessChannel and therefore the real
 * routes/channels.php. It needs no running server: signing an auth response is
 * a local HMAC over the socket id, and nothing here opens a socket or calls the
 * Reverb HTTP API.
 */
class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '123456.7891011';

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
                'options' => [
                    'host' => '127.0.0.1',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);

        // BroadcastManager::__call forwards to the CURRENT default driver, so
        // the callbacks registered while booting landed on the NullBroadcaster
        // and the fresh PusherBroadcaster would start with an empty channel
        // list - every authorization would answer 403 and the negative
        // assertions below would pass for the wrong reason. Purge the memoized
        // driver, then replay the dictionary onto the new one.
        //
        // Re-requiring routes/channels.php is safe precisely because it
        // declares no file-scope const or function (see ChannelRegistry).
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function authorize(?User $user, string $channel, array $extra = []): TestResponse
    {
        $payload = ['socket_id' => self::SOCKET, 'channel_name' => $channel] + $extra;

        $request = $user === null ? $this : $this->actingAs($user);

        return $request->postJson('/broadcasting/auth', $payload);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /* ----------------------------------------------------------------------
     * The endpoint itself
     * ------------------------------------------------------------------- */

    public function test_unauthenticated_request_cannot_authorize_a_channel(): void
    {
        $this->authorize(null, 'private-user.1')->assertStatus(401);
    }

    public function test_deactivated_user_cannot_authorize_their_own_channel(): void
    {
        // `active` fires before the channel callback is ever reached: an
        // account switched off mid-session opens no new subscriptions.
        $user = User::factory()->create(['is_active' => false]);

        $this->authorize($user, 'private-user.'.$user->id)
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'USER_DEACTIVATED');
    }

    public function test_channel_auth_is_reachable_during_a_forced_password_change(): void
    {
        // Deliberate: `password.changed` is NOT on this route. A user under a
        // forced password change still needs a live socket - that is exactly
        // the session in which UserDeactivated has to reach them.
        $user = User::factory()->create(['must_change_password' => true]);

        $this->authorize($user, 'private-user.'.$user->id)->assertOk();
    }

    /* ----------------------------------------------------------------------
     * private-user.{id}
     * ------------------------------------------------------------------- */

    public function test_user_can_authorize_their_own_private_channel(): void
    {
        $user = User::factory()->create();

        $this->authorize($user, 'private-user.'.$user->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->authorize($user, 'private-user.'.$victim->id)->assertStatus(403);
    }

    public function test_super_admin_cannot_authorize_another_users_private_channel(): void
    {
        // No admin override on purpose: this channel carries the user's own
        // notifications, and "may manage users" is not "may read their mail".
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $victim = User::factory()->create();

        $this->authorize($admin, 'private-user.'.$victim->id)->assertStatus(403);
    }

    public function test_user_deactivated_event_targets_the_channel_this_file_authorizes(): void
    {
        // Contract check between the Phase 2 event and the Phase 4 channel
        // dictionary: if either side is renamed this fails loudly instead of
        // silently dropping session-revoke notifications.
        $user = User::factory()->create();

        $channels = (new UserDeactivated($user->id))->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-user.'.$user->id, (string) $channels[0]);

        $this->authorize($user, 'private-user.'.$user->id)->assertOk();
    }

    /* ----------------------------------------------------------------------
     * presence-online
     * ------------------------------------------------------------------- */

    public function test_active_user_can_join_the_online_presence_channel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Satış Temsilcisi');

        $response = $this->authorize($user, 'presence-online')->assertOk();

        $channelData = json_decode((string) $response->json('channel_data'), true);

        $this->assertSame((string) $user->id, (string) $channelData['user_id']);
        $this->assertSame(
            ['id', 'name', 'email', 'role', 'department'],
            array_keys($channelData['user_info']),
        );
        $this->assertSame('Satış Temsilcisi', $channelData['user_info']['role']);
    }

    public function test_presence_roster_never_leaks_account_state(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $payload = ChannelRegistry::payload($user);

        $this->assertArrayNotHasKey('must_change_password', $payload);
        $this->assertArrayNotHasKey('is_active', $payload);
        $this->assertArrayNotHasKey('password', $payload);
    }

    public function test_deactivated_user_cannot_join_the_online_presence_channel(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->authorize($user, 'presence-online')->assertStatus(403);
    }

    /* ----------------------------------------------------------------------
     * presence-record.{type}.{id}
     * ------------------------------------------------------------------- */

    public function test_user_with_module_permission_can_watch_an_existing_record(): void
    {
        $deal = Deal::factory()->create();
        $user = $this->userWithPermissions('deals.view');

        $this->authorize($user, 'presence-record.deal.'.$deal->id)
            ->assertOk()
            ->assertJsonStructure(['auth', 'channel_data']);
    }

    public function test_user_without_module_permission_cannot_watch_a_record(): void
    {
        $deal = Deal::factory()->create();
        $user = $this->userWithPermissions('tickets.view');

        $this->authorize($user, 'presence-record.deal.'.$deal->id)->assertStatus(403);
    }

    public function test_watching_a_nonexistent_record_is_refused(): void
    {
        // IDOR: authorizing an id that does not exist would turn this endpoint
        // into an oracle for "which deal ids are real".
        $user = $this->userWithPermissions('deals.view');

        $this->authorize($user, 'presence-record.deal.999999')->assertStatus(403);
    }

    public function test_soft_deleted_records_cannot_be_watched(): void
    {
        $deal = Deal::factory()->create();
        $deal->delete();

        $user = $this->userWithPermissions('deals.view');

        $this->authorize($user, 'presence-record.deal.'.$deal->id)->assertStatus(403);
    }

    public function test_deactivated_user_cannot_watch_a_record(): void
    {
        $deal = Deal::factory()->create();

        $user = User::factory()->create(['is_active' => false]);
        $user->givePermissionTo('deals.view');

        $this->authorize($user, 'presence-record.deal.'.$deal->id)->assertStatus(403);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbiddenRecordTypes(): array
    {
        return [
            'model outside the whitelist' => ['user'],
            'fully qualified class name' => ['App\Models\User'],
            'escaped class name' => ['App\\\\Models\\\\User'],
            'framework internal' => ['Illuminate\Support\Facades\DB'],
            'case variation' => ['Deal'],
            'plural form' => ['deals'],
            'traversal attempt' => ['../deal'],
            'numeric type' => ['0'],
        ];
    }

    /**
     * A caller-supplied `{type}` must never be resolved into a class name.
     * Only the literal keys of ChannelRegistry::RECORDS are channels.
     */
    #[DataProvider('forbiddenRecordTypes')]
    public function test_record_channel_rejects_types_outside_the_whitelist(string $type): void
    {
        // Deliberately over-permissioned: the refusal must come from the
        // whitelist, not from a missing permission.
        $user = $this->userWithPermissions(
            'deals.view', 'tickets.view', 'contacts.view',
            'companies.view', 'leads.view', 'users.view',
        );

        $this->authorize($user, 'presence-record.'.$type.'.1')->assertStatus(403);
    }

    public function test_the_record_whitelist_is_exactly_the_documented_five_types(): void
    {
        // Guards the map itself: a typo'd class or a permission missing from
        // the seeded dictionary would otherwise only surface at runtime.
        $this->assertSame(
            ['deal', 'ticket', 'contact', 'company', 'lead'],
            array_keys(ChannelRegistry::RECORDS),
        );

        foreach (ChannelRegistry::RECORDS as $type => $entry) {
            $this->assertTrue(class_exists($entry['model']), "Model missing for record type [{$type}].");
            $this->assertDatabaseHas('permissions', ['name' => $entry['permission']]);
        }
    }

    public function test_each_record_type_authorizes_against_its_own_module_permission(): void
    {
        $records = [
            'deal' => Deal::factory()->create(),
            'ticket' => Ticket::factory()->create(),
            'contact' => Contact::factory()->create(),
            'company' => Company::factory()->create(),
            'lead' => Lead::factory()->create(),
        ];

        foreach ($records as $type => $record) {
            $permission = ChannelRegistry::RECORDS[$type]['permission'];
            $channel = 'presence-record.'.$type.'.'.$record->id;

            $this->authorize($this->userWithPermissions($permission), $channel)
                ->assertOk();

            // Same record, but a permission belonging to a different module.
            $this->authorize($this->userWithPermissions('settings.manage'), $channel)
                ->assertStatus(403);
        }
    }

    /* ----------------------------------------------------------------------
     * private-conversation.{id}
     * ------------------------------------------------------------------- */

    public function test_conversation_participant_can_authorize_the_channel(): void
    {
        $user = $this->userWithPermissions('chat.use');
        $conversation = Conversation::factory()->create(['created_by' => $user->id]);
        $conversation->users()->attach($user->id);

        $this->authorize($user, 'private-conversation.'.$conversation->id)->assertOk();
    }

    public function test_non_participant_cannot_authorize_a_conversation_channel(): void
    {
        $member = $this->userWithPermissions('chat.use');
        $outsider = $this->userWithPermissions('chat.use');

        $conversation = Conversation::factory()->create(['created_by' => $member->id]);
        $conversation->users()->attach($member->id);

        $this->authorize($outsider, 'private-conversation.'.$conversation->id)->assertStatus(403);
    }

    public function test_participant_without_chat_permission_cannot_authorize_a_conversation(): void
    {
        $user = $this->userWithPermissions();
        $conversation = Conversation::factory()->create(['created_by' => $user->id]);
        $conversation->users()->attach($user->id);

        $this->authorize($user, 'private-conversation.'.$conversation->id)->assertStatus(403);
    }

    /* ----------------------------------------------------------------------
     * private-logs / private-dashboard
     * ------------------------------------------------------------------- */

    public function test_logs_channel_requires_the_logs_view_permission(): void
    {
        $this->authorize($this->userWithPermissions('logs.view'), 'private-logs')->assertOk();
        $this->authorize($this->userWithPermissions(), 'private-logs')->assertStatus(403);
    }

    public function test_dashboard_channel_requires_the_dashboard_view_permission(): void
    {
        $this->authorize($this->userWithPermissions('dashboard.view'), 'private-dashboard')->assertOk();
        $this->authorize($this->userWithPermissions(), 'private-dashboard')->assertStatus(403);
    }

    /* ----------------------------------------------------------------------
     * GET /api/presence/online
     * ------------------------------------------------------------------- */

    public function test_online_roster_requires_authentication(): void
    {
        $this->getJson('/api/presence/online')->assertStatus(401);
    }

    public function test_online_roster_is_behind_the_forced_password_change(): void
    {
        // The route lives inside the `password.changed` group - a user who has
        // not yet replaced their initial password must not read colleague data.
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_online_roster_falls_back_to_the_cached_snapshot_when_reverb_is_down(): void
    {
        // No Reverb server in the test process, so the registry takes its
        // degraded path: serve the last known roster and say so. It must never
        // 500, and never fall back to a database "is_online" flag.
        $user = User::factory()->create();
        $online = User::factory()->create(['name' => 'Cached Colleague']);

        cache()->put(OnlineUserRegistry::SNAPSHOT_KEY, [$online->id], 300);

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertOk()
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('meta.source', 'cache')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.name', 'Cached Colleague')
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'department']]]);
    }

    public function test_online_roster_omits_deactivated_accounts(): void
    {
        $user = User::factory()->create();
        $ghost = User::factory()->create(['is_active' => false]);

        cache()->put(OnlineUserRegistry::SNAPSHOT_KEY, [$ghost->id], 300);

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertOk()
            ->assertJsonPath('meta.count', 0);
    }

    public function test_online_roster_is_empty_without_a_snapshot(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.count', 0);
    }

    /**
     * Replace the HTTP client the reverb broadcaster talks to, so the registry
     * can be driven through its three outcomes without a running server.
     */
    private function fakePusher(): MockInterface
    {
        /** @var PusherBroadcaster $broadcaster */
        $broadcaster = Broadcast::connection('reverb');

        $pusher = Mockery::mock(Pusher::class);
        $broadcaster->setPusher($pusher);

        return $pusher;
    }

    public function test_online_roster_reads_live_membership_from_reverb(): void
    {
        $user = User::factory()->create();
        $connected = User::factory()->create(['name' => 'Live Colleague']);

        $this->fakePusher()
            ->shouldReceive('get')
            ->with('/channels/presence-online/users')
            ->andReturn((object) ['users' => [(object) ['id' => (string) $connected->id]]]);

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertOk()
            ->assertJsonPath('meta.source', 'reverb')
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.name', 'Live Colleague');
    }

    public function test_an_empty_presence_channel_is_not_reported_as_stale(): void
    {
        // Reverb errors on the members endpoint when the channel is unoccupied
        // rather than returning an empty list. Treating that as a failure would
        // pin the roster to the last snapshot for as long as nobody is online -
        // an empty office would permanently show whoever left last.
        $user = User::factory()->create();
        $ghost = User::factory()->create(['name' => 'Went Home']);

        cache()->put(OnlineUserRegistry::SNAPSHOT_KEY, [$ghost->id], 300);

        $pusher = $this->fakePusher();
        $pusher->shouldReceive('get')
            ->with('/channels/presence-online/users')
            ->andThrow(new ApiErrorException('{}'));
        $pusher->shouldReceive('get')
            ->with('/channels/presence-online')
            ->andReturn((object) ['occupied' => false]);

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertOk()
            ->assertJsonPath('meta.source', 'reverb')
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.count', 0);
    }

    public function test_a_genuine_api_failure_still_falls_back_to_the_snapshot(): void
    {
        // Same first exception as the test above, but the confirming call fails
        // too - that is a real outage, not an empty channel.
        $user = User::factory()->create();
        $known = User::factory()->create(['name' => 'Last Known']);

        cache()->put(OnlineUserRegistry::SNAPSHOT_KEY, [$known->id], 300);

        $pusher = $this->fakePusher();
        $pusher->shouldReceive('get')
            ->with('/channels/presence-online/users')
            ->andThrow(new ApiErrorException('Authentication signature invalid.'));
        $pusher->shouldReceive('get')
            ->with('/channels/presence-online')
            ->andThrow(new ApiErrorException('Authentication signature invalid.'));

        $this->actingAs($user)->getJson('/api/presence/online')
            ->assertOk()
            ->assertJsonPath('meta.source', 'cache')
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('data.0.name', 'Last Known');
    }
}
