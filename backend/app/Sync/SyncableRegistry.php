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
     * Tombstone tables whose `sync_deletions` rows carry an `owner_key` and
     * are filtered by it on pull (RISK-2 O3 / TM-F2).
     *
     * TODAY THIS IS EXACTLY ONE TABLE, and the other three are absent on
     * purpose - each for a different reason, none of them "not worth it":
     *
     *   - `notifications` — the row IS owned. `SyncScope::applyRowScope()`
     *     restricts the ROWS to `notifiable_type = <caller morph> AND
     *     notifiable_id = <caller id>`, and until now the tombstones ignored
     *     that entirely, so every device learned every user's deleted
     *     notification uuids. `owner_key` records that same pair
     *     (`notifiable_type:notifiable_id`) at delete time, and
     *     `SyncPullService::deletionsFor()` requires it to match. IN.
     *
     *   - `tags` — org-wide vocabulary with `permission => null` in self::RW:
     *     every authenticated user pulls every tag row. A tombstone can only
     *     ever reveal something the row itself already revealed to the same
     *     caller, so there is nothing to scope. OUT.
     *
     *   - `price_list_items` — gated at the TABLE level by `products.view`
     *     (self::RO). A caller without that permission never receives the
     *     `price_list_items` key at all, deletions included; a caller with it
     *     already pulls every row of the table. There is no per-row owner
     *     below that gate to filter on. OUT.
     *
     *   - `conversation_user` — already scoped, and its owner is a PAIR, not
     *     a user. The `row_key` is the logical key `conversation_id:user_id`,
     *     and `deletionsFor()` uses BOTH halves: `%:<my id>` catches "I was
     *     removed from a conversation" (after which no membership row exists
     *     to look me up by) and `<conversation I am in>:%` catches "somebody
     *     else left a conversation I am still in" - which a single-user
     *     `owner_key` could not express without writing one tombstone per
     *     remaining member. Adding the column here would duplicate half the
     *     key and break the second case. OUT.
     *
     * The tombstone is also written by a trigger for `conversation_user`
     * (`...100009_create_conversation_user_sync_triggers`), which cannot call
     * PHP - another reason the scoping for that table has to live in the key
     * rather than in a column an observer would have to fill.
     *
     * @return array<int, string>
     */
    public static function ownerScopedTombstoneTables(): array
    {
        return ['notifications'];
    }

    /**
     * Tables whose ROWS must never be cut by the bootstrap `window_days`
     * filter (SYNCDESKTOP §4.4 contract gap, FIX-BE U10 + RISK-2 K2).
     *
     * THE RULE: reference/lookup data is exempt; time-ordered event data is
     * not. `exchange_rates` is the ONE reference table that stays windowed,
     * and it is not an exception to the rule - §4.1 writes its own tighter
     * bound into the table list ("exchange_rates (son 7 gün)"), already
     * enforced as a ROW SCOPE in `SyncScope::applyRowScope()`. An FX rate is
     * genuinely a dated series: yesterday's row is not a lookup entry that
     * happens to be old, it is a different fact.
     *
     * WHY (RISK-2 K2): the window is a VOLUME tool for time-ordered records -
     * "do not download five years of deals onto a laptop". Applied to a lookup
     * table it is a category error: `updated_at` on a product, a pipeline
     * stage or a user says when somebody last EDITED the definition, not
     * whether it is still in use. FIX-BE measured the damage on a 30-day
     * window: `products` shipped 0 of 20 rows and `users` 7 of 10, so a
     * bootstrapped client rendered every quote line with a blank product and
     * every assignment as "Atanan: —" while the owner ids were sitting right
     * there in the rows it did receive.
     *
     * This is NOT a contract change. §4.1 already lists these tables on their
     * own row - `pipeline_stages, custom_fields, products, price_lists,
     * price_list_items, exchange_rates (son 7 gün), saved_views,
     * settings(public)`, plus `users` and `tags` on rows of their own - SEPARATE
     * from the time-ordered entity group (`companies ... quotes, quote_items`),
     * and it attached a window note to exactly one of them. The code was the
     * thing that had drifted.
     *
     * `users` deliberately gets the exemption rather than a U9-style
     * relational closure: the table is a bounded org roster (10 rows on the
     * dev database, hundreds at enterprise scale), the exemption already
     * returns 10/10, and a closure machine for a table that small is waste.
     *
     * VOLUME: the only enterprise-scale growth candidate here is
     * `price_list_items` (lists x products). Its answer is not the window -
     * a price the client cannot see is a wrong quote - but the 5 MB
     * `has_more` pagination that §4.4 already mandates and
     * `SyncPullService::pull()` already applies to every table alike. Locked
     * by `SyncPullTest::test_a_read_only_table_pages_through_has_more_...`.
     *
     * Derived from self::RO rather than hard-listed so a new reference table
     * is exempt by construction and cannot silently reintroduce U10.
     *
     * `SyncPullService::rowsFor()` is the only caller.
     *
     * @return array<int, string>
     */
    public static function windowExemptTables(): array
    {
        return array_merge(
            // §4.1 groups `tags, taggables, custom_field_values` on their own
            // row: a tag is a label from the org's fixed vocabulary, not a
            // transactional record. Whether it was last touched yesterday or a
            // year ago says nothing about whether it is still in use -
            // `taggables` (embedded as `tags: [ids]` on every owner row) is
            // the only thing that answers that, and it is never windowed. A
            // stale-looking `tags` row still referenced by an in-window record
            // is exactly the U10 bug: the mirror table came back empty while
            // owner rows kept pointing at ids nothing had shipped.
            ['tags'],
            array_values(array_diff(array_keys(self::RO), ['exchange_rates'])),
        );
    }

    /**
     * RW tables with a nullable FK into `companies`, keyed by their column
     * name (SYNCDESKTOP §4.4 contract gap, FIX-BE U9).
     *
     * Used ONLY to build the depth-1 relational closure in
     * `SyncPullService::applyCompanyClosure()`: on a bootstrap pull, a
     * `contacts`/`deals`/`tickets`/`quotes` row that falls inside the window
     * can point at a `companies` row that does not (it was created long ago
     * and nobody has touched it since) — the window filter has no concept of
     * "still referenced", so that company would simply never ship and the
     * client renders the relation as empty. `leads.converted_company_id` is
     * the same shape post-conversion. Closure is depth 1 only: a closure-added
     * company's OWN foreign keys (there are none today) are never chased.
     *
     * @return array<string, string>
     */
    public static function companyReferenceColumns(): array
    {
        return [
            'contacts' => 'company_id',
            'deals' => 'company_id',
            'tickets' => 'company_id',
            'quotes' => 'company_id',
            'leads' => 'converted_company_id',
        ];
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
