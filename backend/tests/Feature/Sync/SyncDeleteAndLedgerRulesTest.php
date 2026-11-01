<?php

namespace Tests\Feature\Sync;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Senkron push'un `delete` kararları ve idempotency defterinin kapsamı.
 */
class SyncDeleteAndLedgerRulesTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mutations
     */
    private function push(string $token, array $mutations): TestResponse
    {
        // The resolved guard caches its user between requests of one test.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => $mutations,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mutation(array $overrides): array
    {
        return array_merge([
            'seq' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function notificationFor(User $user): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\Dummy',
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => '{"m":"x"}', 'created_at' => now(), 'updated_at' => now(), 'sync_version' => 1,
        ]);

        return $id;
    }

    /**
     * Grup sahibinin konuşma silmesi Policy'den geçiyor, sonra eşleşmeyen dala
     * düşüp yakalanmayan bir istisnayla tüm push'u 500'e çeviriyordu. Artık
     * yalnız o mutasyon reddedilir ve aynı paketteki sonraki iş uygulanır.
     */
    public function test_group_owner_conversation_delete_is_rejected_without_breaking_the_batch(): void
    {
        [$owner, $token] = $this->deviceUser('Admin');
        $other = User::factory()->create();
        $conversation = Conversation::factory()->group()->createdBy($owner)->withMembers([$owner, $other])->create();

        $response = $this->push($token, [
            $this->mutation(['seq' => 1, 'op' => 'delete', 'entity' => 'conversation', 'server_id' => $conversation->id]),
            $this->mutation(['seq' => 2, 'op' => 'create', 'entity' => 'company', 'client_id' => (string) Str::uuid(),
                'payload' => ['name' => 'Sonraki iş']]),
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_MUTATION')
            ->assertJsonPath('results.1.status', 'applied');

        $this->assertNotNull(Conversation::query()->find($conversation->id));
        $this->assertTrue(Company::query()->where('name', 'Sonraki iş')->exists());
    }

    public function test_owner_may_delete_their_own_notification_offline(): void
    {
        [$owner, $token] = $this->deviceUser('Satış Temsilcisi');
        $id = $this->notificationFor($owner);

        $this->push($token, [$this->mutation(['op' => 'delete', 'entity' => 'notification', 'client_id' => $id])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    /**
     * Başkasının bildirimi: sahiplik kuralı, süper yönetici dahil herkese
     * uygulanır (web ucuyla aynı).
     */
    public function test_foreign_notification_delete_is_rejected_even_for_super_admin(): void
    {
        $victim = User::factory()->create();

        foreach (['Admin', 'Super Admin'] as $role) {
            [, $token] = $this->deviceUser($role);
            $id = $this->notificationFor($victim);

            $this->push($token, [$this->mutation(['op' => 'delete', 'entity' => 'notification', 'client_id' => $id])])
                ->assertOk()
                ->assertJsonPath('results.0.status', 'rejected');

            $this->assertDatabaseHas('notifications', ['id' => $id]);
        }
    }

    /**
     * İki kullanıcı aynı idempotency_key'i kullanırsa ikincinin defter satırı
     * da yazılmalı; biri diğerinin sonucunu görmemeli.
     */
    public function test_a_reused_idempotency_key_is_scoped_to_its_user(): void
    {
        [$alice, $aliceToken] = $this->deviceUser('Admin');
        [$bob, $bobToken] = $this->deviceUser('Admin');
        $key = (string) Str::uuid();

        $aliceResult = $this->push($aliceToken, [$this->mutation(['idempotency_key' => $key, 'op' => 'create',
            'entity' => 'company', 'client_id' => (string) Str::uuid(), 'payload' => ['name' => 'Alice Ltd']])])
            ->assertJsonPath('results.0.status', 'applied')->json('results.0');

        $bobMutation = $this->mutation(['idempotency_key' => $key, 'op' => 'create',
            'entity' => 'company', 'client_id' => (string) Str::uuid(), 'payload' => ['name' => 'Bob Ltd']]);

        $bobResult = $this->push($bobToken, [$bobMutation])
            ->assertJsonPath('results.0.status', 'applied')->json('results.0');

        $this->assertNotSame($aliceResult['server_id'], $bobResult['server_id']);
        $this->assertDatabaseHas('sync_idempotency', ['idempotency_key' => $key, 'user_id' => $alice->id]);
        $this->assertDatabaseHas('sync_idempotency', ['idempotency_key' => $key, 'user_id' => $bob->id]);

        // Bob'un yeniden denemesi KENDİ defter satırından cevaplanır.
        $replay = $this->push($bobToken, [$bobMutation])->json('results.0');
        $this->assertSame($bobResult['server_id'], $replay['server_id']);
        $this->assertSame(1, Company::query()->where('name', 'Bob Ltd')->count());
    }
}
