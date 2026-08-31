<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SYNCDESKTOP §4.6/6 — `logs:prune` covers the two new sync tables.
 *
 * Both grow without bound if nothing prunes them, and both are only useful to
 * a client whose cursor is older than the row: a device that has been offline
 * longer than the tombstone window re-bootstraps instead, and a push retry
 * happens within minutes rather than weeks.
 */
class SyncRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function tombstone(string $table, string $key, int $daysAgo): void
    {
        DB::table('sync_deletions')->insert([
            'table_name' => $table,
            'row_key' => $key,
            'sync_version' => 1,
            'deleted_at' => now()->subDays($daysAgo),
        ]);
    }

    private function ledgerEntry(int $userId, int $daysAgo): string
    {
        $key = (string) Str::uuid();

        DB::table('sync_idempotency')->insert([
            'idempotency_key' => $key,
            'user_id' => $userId,
            'result_json' => '{"status":"applied"}',
            'created_at' => now()->subDays($daysAgo),
        ]);

        return $key;
    }

    public function test_prune_removes_expired_sync_rows_and_keeps_fresh_ones(): void
    {
        $user = User::factory()->create();

        $this->tombstone('tags', 'old', 120);
        $this->tombstone('tags', 'fresh', 10);

        $expiredKey = $this->ledgerEntry($user->id, 30);
        $freshKey = $this->ledgerEntry($user->id, 1);

        $this->artisan('logs:prune', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('sync_deletions', ['row_key' => 'old']);
        $this->assertDatabaseHas('sync_deletions', ['row_key' => 'fresh']);

        $this->assertDatabaseMissing('sync_idempotency', ['idempotency_key' => $expiredKey]);
        $this->assertDatabaseHas('sync_idempotency', ['idempotency_key' => $freshKey]);
    }

    public function test_the_new_tables_can_be_pruned_in_isolation(): void
    {
        $user = User::factory()->create();

        $this->tombstone('tags', 'old', 120);
        $expiredKey = $this->ledgerEntry($user->id, 30);

        $this->artisan('logs:prune', ['--table' => 'sync_idempotency', '--force' => true])->assertSuccessful();

        // --table is a scalpel: only the named table is touched.
        $this->assertDatabaseMissing('sync_idempotency', ['idempotency_key' => $expiredKey]);
        $this->assertDatabaseHas('sync_deletions', ['row_key' => 'old']);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $user = User::factory()->create();

        $this->tombstone('tags', 'old', 120);
        $this->ledgerEntry($user->id, 30);

        $this->artisan('logs:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('sync_deletions', 1);
        $this->assertDatabaseCount('sync_idempotency', 1);
    }
}
