<?php

namespace Tests\Feature\Sync;

use App\Services\Sync\SyncPullService;
use App\Services\Sync\SyncPushService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYNCDESKTOP §4.6/2 — manifest shape and the permission surface.
 *
 * The load-bearing assertion is the NEGATIVE one: a module the caller cannot
 * see must be missing by KEY. An empty entry would still disclose that the
 * module exists - the same leak GlobalSearchService closes and
 * SearchAuthorizationTest locks.
 */
class SyncManifestTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manifest_carries_the_protocol_version_and_policy_limits(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $response = $this->withToken($token)->getJson('/api/sync/manifest');

        $response->assertOk()
            ->assertJsonPath('protocol_version', SyncPullService::PROTOCOL_VERSION)
            ->assertJsonPath('policy.push_batch_max', SyncPushService::MAX_BATCH)
            ->assertJsonPath('policy.push_bytes_max', SyncPushService::MAX_BYTES)
            ->assertJsonPath('policy.pull_limit_max', SyncPullService::MAX_LIMIT)
            ->assertJsonStructure(['server_time', 'tables', 'permissions', 'user']);

        $this->assertSame('rw', $response->json('tables.deals.mode'));
        $this->assertSame('ro', $response->json('tables.products.mode'));
    }

    public function test_taggables_quote_items_and_custom_field_values_are_not_tables(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $tables = $this->withToken($token)->getJson('/api/sync/manifest')->json('tables');

        // Protocol §1.4/§1.5: all three ride inside their owner's payload.
        // Listing them would tell the client to build mirror tables and
        // cursors it must never have.
        $this->assertArrayNotHasKey('taggables', $tables);
        $this->assertArrayNotHasKey('quote_items', $tables);
        $this->assertArrayNotHasKey('custom_field_values', $tables);
    }

    public function test_a_module_without_view_permission_has_no_key_at_all(): void
    {
        // Destek Temsilcisi holds tickets/contacts/companies/tasks and chat,
        // but neither deals nor quotes nor leads nor products.
        [, $token] = $this->deviceUser('Destek Temsilcisi');

        $tables = $this->withToken($token)->getJson('/api/sync/manifest')->json('tables');

        $this->assertArrayHasKey('tickets', $tables);
        $this->assertArrayHasKey('conversations', $tables, 'chat.use taşıyan rol sohbeti görmeli.');

        foreach (['deals', 'quotes', 'leads', 'products', 'price_lists', 'price_list_items'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $tables,
                "İzinsiz modül `{$forbidden}` manifest'te ANAHTAR olarak bile görünmemeli (varlık sızıntısı)."
            );
        }
    }
}
