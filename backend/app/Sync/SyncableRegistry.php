<?php

namespace App\Sync;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\ExchangeRate;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SavedView;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The ONE place that says what the desktop client mirrors (SYNCDESKTOP §4.1,
 * protocol §1).
 *
 * Manifest, pull, push, the observer wiring and the schema tests all read this
 * class, so "is table X in scope" has exactly one answer. A table that is not
 * listed here is not synced - `activity_log`, `page_visit_logs`,
 * `session_logs`, `sessions`, `personal_access_tokens`,
 * `password_reset_tokens`, `email_templates`, `automation_rules`,
 * `attachments`, `jobs*`, `cache*` (protocol §1.3) - and there is no second
 * list anywhere that could drift from this one.
 *
 * THREE tables are deliberately absent even though their data IS synced:
 * `taggables`, `quote_items` and `custom_field_values` travel inside their
 * owner's row (`tags`, `items`, `custom_fields`) instead of as tables of their
 * own (protocol §1.4/§1.5). See `embeds` below.
 */
final class SyncableRegistry
{
    /**
     * Read-write tables: the client may push mutations for these.
     *
     * Keys are TABLE names. `entity` is the singular name the push wire format
     * uses (`"entity": "deal"`); a null entity means the table is pulled but
     * carries no push surface of its own.
     *
     * `permission` is the module `.view` permission that gates the table. When
     * the caller lacks it the table is missing from the manifest and from
     * every pull response - the KEY itself, not an empty array. Same principle
     * as GlobalSearchService: `deals: []` still tells the caller a `deals`
     * module exists and that they cannot see it.
     *
     * `null` permission means reference data every authenticated user needs to
     * render any record at all (tags, custom field definitions, pipeline
     * columns, FX rates) or data that is already row-scoped to the caller
     * (`saved_views`, `settings` public subset, the `users` projection). This
     * mirrors what the web API already exposes to the same user; the sync
     * layer does not invent a stricter or looser rule than the surface it
     * shadows.
     *
     * @var array<string, array<string, mixed>>
     */
    private const RW = [
        'companies' => [
            'entity' => 'company', 'model' => Company::class, 'permission' => 'companies.view',
            'soft_deletes' => true, 'embeds' => ['tags', 'custom_fields'],
        ],
        'contacts' => [
            'entity' => 'contact', 'model' => Contact::class, 'permission' => 'contacts.view',
            'soft_deletes' => true, 'embeds' => ['tags', 'custom_fields'],
        ],
        'leads' => [
            'entity' => 'lead', 'model' => Lead::class, 'permission' => 'leads.view',
            'soft_deletes' => true, 'embeds' => ['tags', 'custom_fields'],
        ],
        'deals' => [
            'entity' => 'deal', 'model' => Deal::class, 'permission' => 'deals.view',
            'soft_deletes' => true, 'embeds' => ['tags', 'custom_fields'],
        ],
        'tasks' => [
            'entity' => 'task', 'model' => Task::class, 'permission' => 'tasks.view',
            'soft_deletes' => true, 'embeds' => [],
        ],
        'activities' => [
            'entity' => 'activity', 'model' => Activity::class, 'permission' => 'activities.view',
            'soft_deletes' => true, 'embeds' => [],
        ],
        'tickets' => [
            'entity' => 'ticket', 'model' => Ticket::class, 'permission' => 'tickets.view',
            'soft_deletes' => true, 'embeds' => ['tags', 'custom_fields'],
        ],
        'quotes' => [
            'entity' => 'quote', 'model' => Quote::class, 'permission' => 'quotes.view',
            'soft_deletes' => true, 'embeds' => ['items'],
        ],
        'conversations' => [
            'entity' => 'conversation', 'model' => Conversation::class, 'permission' => 'chat.use',
            'soft_deletes' => true, 'embeds' => [],
        ],
        'messages' => [
            'entity' => 'message', 'model' => Message::class, 'permission' => 'chat.use',
            'soft_deletes' => true, 'embeds' => [],
        ],
        'conversation_user' => [
            // No model - a pure pivot, versioned by database trigger
            // (protocol §2.2). Pushed only as `conversation.read` /
            // `conversation.delivered` actions against the `conversation`
            // entity, never as a row of its own.
            'entity' => null, 'model' => null, 'permission' => 'chat.use',
            'soft_deletes' => false, 'embeds' => [],
        ],
        'notifications' => [
            'entity' => 'notification', 'model' => DatabaseNotification::class,
            'permission' => 'notifications.view', 'soft_deletes' => false, 'embeds' => [],
        ],
        'tags' => [
            'entity' => null, 'model' => Tag::class, 'permission' => null,
            'soft_deletes' => false, 'embeds' => [],
        ],
    ];

    /**
     * Read-only mirrors: pulled, never pushed.
     *
     * @var array<string, array<string, mixed>>
     */
    private const RO = [
        'pipeline_stages' => ['model' => PipelineStage::class, 'permission' => null, 'soft_deletes' => false],
        'custom_fields' => ['model' => CustomField::class, 'permission' => null, 'soft_deletes' => false],
        'products' => ['model' => Product::class, 'permission' => 'products.view', 'soft_deletes' => true],
        'price_lists' => ['model' => PriceList::class, 'permission' => 'products.view', 'soft_deletes' => true],
        'price_list_items' => ['model' => PriceListItem::class, 'permission' => 'products.view', 'soft_deletes' => false],
        'exchange_rates' => ['model' => ExchangeRate::class, 'permission' => null, 'soft_deletes' => false],
        'saved_views' => ['model' => SavedView::class, 'permission' => null, 'soft_deletes' => false],
        'settings' => ['model' => Setting::class, 'permission' => null, 'soft_deletes' => false],
        'users' => ['model' => User::class, 'permission' => null, 'soft_deletes' => true],
    ];

    /**
     * `users` is the only table with a column projection, and it is a
     * WHITELIST: SYNCDESKTOP §4.1 says "no other column", and `users` holds
     * `password`, `remember_token`, `must_change_password` and `last_login_at`.
     * A blacklist would leak the next column somebody adds.
     *
     * `avatar_url` from the specification does not exist on this schema (there
     * is no avatar column at all); it is omitted rather than faked.
     *
     * @var array<int, string>
     */
    public const USER_PROJECTION = ['id', 'name', 'email', 'is_active', 'department', 'sync_version'];

    /**
     * Tables that carry a `sync_version` column. Locked against the migration
     * by tests/Feature/Sync/SyncSchemaTest.php.
     *
     * @return array<int, string>
     */
    public static function syncVersionTables(): array
    {
        return array_merge(array_keys(self::RW), array_keys(self::RO));
    }

    /**
     * Tables that carry a `client_id` column (protocol: notifications excluded,
     * its UUID primary key already IS the client id).
     *
     * @return array<int, string>
     */
    public static function clientIdTables(): array
    {
        return array_values(array_filter(
            array_keys(self::RW),
            fn (string $table): bool => $table !== 'notifications'
        ));
    }

    /**
     * Tables that write hard-delete tombstones into `sync_deletions`
     * (protocol §2.7 + P19). `conversation_user` is here even though its writer
     * is a trigger rather than SyncDeletionObserver - the list describes the
     * WIRE contract, not the mechanism.
     *
     * The membership test is simply "does this table hard delete": everything
     * else in scope either soft deletes - the row itself returns through the
     * delta carrying `deleted_at` plus a fresh version, which is strictly more
     * information than a tombstone - or is embedded in an owner payload
     * (`taggables`, `quote_items`, `custom_field_values`, §1.4/§1.5), where a
     * shrinking array IS the deletion signal.
     *
     * `price_list_items` (P19) is the RO side of that same test, and it was
     * missed when §2.7 was written: it carries no SoftDeletes and
     * `DELETE /api/price-lists/{list}/products/{product}` really removes the
     * row, so without a tombstone a client's mirror could only ever GROW and a
     * withdrawn price would be shown forever.
     *
     * @return array<int, string>
     */
    public static function tombstoneTables(): array
    {
        return ['tags', 'notifications', 'conversation_user', 'price_list_items'];
    }

    /**
     * Every pull table with its mode/permission metadata, in manifest order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tables(): array
    {
        $tables = [];

        foreach (self::RW as $table => $definition) {
            $tables[$table] = $definition + ['mode' => 'rw'];
        }

        foreach (self::RO as $table => $definition) {
            $tables[$table] = $definition + ['mode' => 'ro', 'entity' => null, 'embeds' => []];
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function table(string $table): ?array
    {
        return self::tables()[$table] ?? null;
    }

    /**
     * entity name (`deal`) -> table name (`deals`).
     */
    public static function tableForEntity(string $entity): ?string
    {
        foreach (self::RW as $table => $definition) {
            if ($definition['entity'] === $entity) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function entity(string $entity): ?array
    {
        $table = self::tableForEntity($entity);

        return $table === null ? null : self::tables()[$table];
    }

    /**
     * Entity names the push endpoint accepts.
     *
     * @return array<int, string>
     */
    public static function pushEntities(): array
    {
        return array_values(array_filter(array_column(self::RW, 'entity')));
    }
}
