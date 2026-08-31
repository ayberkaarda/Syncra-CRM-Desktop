<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desktop sync — `sync_version` delta cursor column (SYNCDESKTOP §4.2 (a)).
 *
 * Every table the desktop client mirrors gets the SAME single column, because
 * the pull query is deliberately uniform:
 *
 *     WHERE sync_version > :cursor ORDER BY sync_version ASC LIMIT :limit
 *
 * A keyset scan over one indexed column is stable under concurrent writes; an
 * `updated_at` cursor is not (clock skew, second-granularity ties) and a
 * composite cursor would have to be carried through the wire format, the
 * server query and the client's `cursors` table. Protocol K-C froze the single
 * scalar - which in turn makes per-row UNIQUE versions mandatory (§2.5).
 *
 * DELIBERATELY ABSENT: `taggables`, `quote_items`, `custom_field_values`.
 * Protocol §1.4/§1.5 embeds all three in their owner row's payload
 * (`tags: [ids]`, `quotes.items`, `custom_fields: {}`), so they are not pull
 * tables and own no version. Their owner is bumped instead
 * (App\Services\Sync\TagSyncService, App\Sync\SyncVersionBumper).
 *
 * NOT NULL DEFAULT 0 rather than nullable: 0 means "never versioned", which is
 * strictly below every real cursor, so a row that somehow escaped the observer
 * is invisible to the delta instead of poisoning it with NULL comparisons. The
 * backfill migration (2026_09_01_100008) then guarantees no scope row stays 0.
 */
return new class extends Migration
{
    /**
     * Single source of truth for this migration. Mirrored by
     * App\Sync\SyncableRegistry::syncVersionTables(), and the two are locked
     * together by tests/Feature/Sync/SyncSchemaTest.php.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        // RW
        'companies', 'contacts', 'leads', 'deals', 'tasks', 'activities',
        'tickets', 'quotes', 'conversations', 'messages', 'conversation_user',
        'notifications', 'tags',
        // RO mirrors
        'pipeline_stages', 'custom_fields', 'products', 'price_lists',
        'price_list_items', 'exchange_rates', 'saved_views', 'settings', 'users',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->unsignedBigInteger('sync_version')->default(0);
                $blueprint->index('sync_version', 'idx_'.$table.'_sync_version');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex('idx_'.$table.'_sync_version');
                $blueprint->dropColumn('sync_version');
            });
        }
    }
};
