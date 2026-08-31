<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Notifications\Support\NotificationText;
use App\Sync\SyncableRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `POST /api/sync/pull` — the delta reader (SYNCDESKTOP §4.4, protocol §4.2).
 *
 * ------------------------------------------------------------------------
 * THE QUERY IS THE CONTRACT
 * ------------------------------------------------------------------------
 *     WHERE sync_version > :cursor ORDER BY sync_version ASC LIMIT :limit
 *
 * One indexed column, keyset paging, no OFFSET. Its correctness rests on one
 * precondition, which the whole versioning design exists to guarantee
 * (protocol §2.5/K-C): `sync_version` is UNIQUE PER ROW inside a table. If two
 * rows ever shared a version and LIMIT fell between them, the second would
 * never be returned again - a silently missing record, forever.
 *
 * Queries go through the query builder rather than Eloquent on purpose:
 *   - soft-deleted rows MUST come back (they are the tombstone), and every
 *     model here carries a global scope that hides them;
 *   - `conversation_user` has no model at all;
 *   - the `users` projection must be enforced in SQL, not trimmed afterwards.
 *
 * ------------------------------------------------------------------------
 * BOOTSTRAP WINDOW
 * ------------------------------------------------------------------------
 * `cursor = 0` means the client has nothing, so `window_days` bounds what it
 * downloads (SYNCDESKTOP K12/K8: a disk ceiling, widened later through
 * "Download archive"). On a DELTA pull the filter is not applied - an old
 * record that was edited today is a legitimate change and must not be dropped
 * for being old.
 */
class SyncPullService
{
    public const PROTOCOL_VERSION = 1;

    public const DEFAULT_LIMIT = 500;

    public const MAX_LIMIT = 1000;

    /**
     * SYNCDESKTOP §4.4: a response larger than this is cut and reported with
     * `has_more`, so a client on a slow link never has to swallow an
     * unbounded body before it can make progress.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly SyncScope $scope) {}

    /**
     * @param  array<string, int>  $cursors
     * @return array<string, mixed>
     */
    public function pull(User $user, array $cursors, int $limit, ?int $windowDays): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $tables = [];
        $bytes = 0;

        foreach ($this->scope->tablesFor($user) as $table => $definition) {
            $cursor = (int) ($cursors[$table] ?? 0);

            // Budget exhausted: every table not yet visited simply stays out of
            // this response. Its cursor is untouched, so the next pull picks it
            // up exactly where it was left.
            if ($bytes >= self::MAX_BYTES) {
                break;
            }

            $rows = $this->rowsFor($user, $table, $definition, $cursor, $limit, $windowDays);
            $deletions = $this->deletionsFor($user, $table, $cursor, $limit);

            $fullPage = count($rows) >= $limit || count($deletions) >= $limit;

            [$rows, $rowBytes, $truncated] = $this->trimToBudget($rows, self::MAX_BYTES - $bytes);

            $nextCursor = $cursor;

            foreach ($rows as $row) {
                $nextCursor = max($nextCursor, (int) $row['sync_version']);
            }

            // Deletions are counted only when the row set survived intact. A
            // cursor may never move past a row that was cut, and rows and
            // tombstones share ONE scalar cursor.
            if (! $truncated) {
                foreach ($deletions as $deletion) {
                    $nextCursor = max($nextCursor, (int) $deletion['sync_version']);
                }
            } else {
                $deletions = [];
            }

            $tables[$table] = [
                'rows' => $rows,
                'deletions' => $deletions,
                'next_cursor' => $nextCursor,
                // `has_more` is a promise about THIS table: a full page, or a
                // page cut by the byte budget, means there is more behind it.
                // The client keeps pulling until it is false.
                'has_more' => $fullPage || $truncated,
            ];

            $bytes += $rowBytes;
        }

        return [
            'server_time' => now()->toIso8601String(),
            'tables' => $tables,
        ];
    }

    /**
     * Cut a page at the response byte ceiling (SYNCDESKTOP §4.4).
     *
     * ONE row is always kept, even when it alone blows the budget. Without
     * that floor a single oversized record - a deal with a very long
     * description - would return an empty page forever with `has_more: true`,
     * and the client would loop on a cursor that can never advance. Sending it
     * over budget once is the only outcome that makes progress.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: bool} kept rows, bytes, whether anything was cut
     */
    private function trimToBudget(array $rows, int $budget): array
    {
        $kept = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $size = strlen((string) json_encode($row));

            if ($kept !== [] && $bytes + $size > $budget) {
                return [$kept, $bytes, true];
            }

            $kept[] = $row;
            $bytes += $size;
        }

        return [$kept, $bytes, false];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(User $user, string $table, array $definition, int $cursor, int $limit, ?int $windowDays): array
    {
        $query = DB::table($table)->where('sync_version', '>', $cursor);

        $this->scope->applyRowScope($query, $table, $user);

        if ($cursor === 0 && $windowDays !== null && $this->hasUpdatedAt($table)) {
            $query->where('updated_at', '>=', now()->subDays($windowDays));
        }

        $projection = $this->scope->projectionFor($table);

        $rows = $query
            ->orderBy('sync_version')
            ->limit($limit)
            ->get($projection ?? ['*'])
            ->map(fn ($row): array => (array) $row)
            ->all();

        if ($rows === []) {
            return [];
        }

        if ($table === 'notifications') {
            $rows = $this->renderNotificationText($rows, $user);
        }

        return $this->attachEmbeds($table, $definition, $rows);
    }

    /**
     * DESKTOP-ARCHITECTURE.md EK 3, "BACKEND'E DEVREDİLEN İKİ GERÇEK BOŞLUK" #1: KEY-mode rows
     * (`data.title_key`/`data.body_key`/`data.params`) carry no sentence at all, and the desktop
     * client has no PHP translation catalogue to turn a key into one - only
     * `NotificationResource::toArray()` (web) does, via `NotificationText::resolve()`. Without
     * this step a newly-created key-mode notification would show its raw key on desktop.
     *
     * Reuses `NotificationText::resolve()` - the SAME render path `NotificationResource` and
     * `CrmNotification::toBroadcast()` already call - rather than reimplementing rendering here
     * (K7). Rendered `title`/`body` are written INTO `data` alongside the untouched
     * `title_key`/`body_key`/`params`, mirroring `toBroadcast()`'s `array_merge(...)` shape; a
     * plain-text (pre-Phase-14) row already carries `data.title`/`data.body` and the merge is a
     * no-op for it. The desktop mapper (`mapNotification()`) already reads `data.title` before
     * falling back to `data.title_key`, so this alone closes the gap with no client-side change.
     *
     * Locale: `SyncScope::applyRowScope()` restricts the `notifications` query to
     * `notifiable_id = $user->getKey()` (see `SyncScope`), so every row reaching this method
     * belongs to the very `$user` who is pulling - there is no second recipient to look up.
     * `$user->locale` is therefore both correct and free of an N+1.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function renderNotificationText(array $rows, User $user): array
    {
        foreach ($rows as $index => $row) {
            $data = json_decode((string) ($row['data'] ?? '{}'), true);
            $data = is_array($data) ? $data : [];

            $data = array_merge($data, NotificationText::resolve($data, $user->locale));

            $rows[$index]['data'] = json_encode($data);
        }

        return $rows;
    }

    /**
     * Embedded children (protocol §1.4/§1.5): `taggables`, `custom_field_values`
     * and `quote_items` are not tables of their own on either side of the wire.
     * They are loaded ONE query per kind for the whole page - never per row.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachEmbeds(string $table, array $definition, array $rows): array
    {
        $embeds = $definition['embeds'] ?? [];

        if ($embeds === []) {
            return $rows;
        }

        $ids = array_column($rows, 'id');
        /** @var class-string<Model>|null $model */
        $model = $definition['model'];
        $morph = $model === null ? null : (new $model)->getMorphClass();

        $tags = [];
        $customFields = [];
        $items = [];

        if (in_array('tags', $embeds, true) && $morph !== null) {
            foreach (DB::table('taggables')
                ->where('taggable_type', $morph)
                ->whereIn('taggable_id', $ids)
                ->get(['taggable_id', 'tag_id']) as $row) {
                $tags[(int) $row->taggable_id][] = (int) $row->tag_id;
            }
        }

        if (in_array('custom_fields', $embeds, true) && $morph !== null) {
            foreach (DB::table('custom_field_values as cfv')
                ->join('custom_fields as cf', 'cf.id', '=', 'cfv.custom_field_id')
                ->where('cfv.customizable_type', $morph)
                ->whereIn('cfv.customizable_id', $ids)
                ->get(['cfv.customizable_id', 'cf.key', 'cfv.value']) as $row) {
                $customFields[(int) $row->customizable_id][(string) $row->key] = $row->value;
            }
        }

        if (in_array('items', $embeds, true)) {
            foreach (DB::table('quote_items')
                ->whereIn('quote_id', $ids)
                ->orderBy('quote_id')
                ->orderBy('position')
                ->get() as $row) {
                $items[(int) $row->quote_id][] = (array) $row;
            }
        }

        foreach ($rows as $index => $row) {
            $id = (int) $row['id'];

            if (in_array('tags', $embeds, true)) {
                $rows[$index]['tags'] = $tags[$id] ?? [];
            }

            if (in_array('custom_fields', $embeds, true)) {
                $rows[$index]['custom_fields'] = $customFields[$id] ?? [];
            }

            if (in_array('items', $embeds, true)) {
                $rows[$index]['items'] = $items[$id] ?? [];
            }
        }

        return $rows;
    }

    /**
     * Hard-delete tombstones (protocol §2.7). Only three tables ever produce
     * them; a soft delete needs none, because the row itself comes back with
     * `deleted_at` set.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deletionsFor(User $user, string $table, int $cursor, int $limit): array
    {
        if (! in_array($table, SyncableRegistry::tombstoneTables(), true)) {
            return [];
        }

        $query = DB::table('sync_deletions')
            ->where('table_name', $table)
            ->where('sync_version', '>', $cursor);

        if ($table === 'conversation_user') {
            /*
             * The tombstone's row_key is the LOGICAL key `conversation_id:user_id`
             * (the surrogate id is useless to a client: leaving and rejoining
             * mints a new one). Scoping has to cover BOTH shapes of removal:
             *   - somebody else left a conversation I am still in;
             *   - I was removed, so I am no longer a member and the membership
             *     query below would not find me - hence the second clause,
             *     which matches my own user id at the end of the key.
             */
            $conversationIds = DB::table('conversation_user')
                ->where('user_id', $user->getKey())
                ->pluck('conversation_id')
                ->all();

            $query->where(function ($q) use ($conversationIds, $user): void {
                $q->where('row_key', 'like', '%:'.$user->getKey());

                foreach ($conversationIds as $conversationId) {
                    $q->orWhere('row_key', 'like', $conversationId.':%');
                }
            });
        }

        return $query
            ->orderBy('sync_version')
            ->limit($limit)
            ->get(['row_key', 'sync_version'])
            ->map(fn ($row): array => [
                'row_key' => (string) $row->row_key,
                'sync_version' => (int) $row->sync_version,
            ])
            ->all();
    }

    private function hasUpdatedAt(string $table): bool
    {
        // `sync_deletions` aside, every scope table carries timestamps today.
        // Asked once per table per request through the schema cache rather than
        // hard-coded, so adding a timestamp-less table cannot produce a
        // "column not found" at pull time.
        static $cache = [];

        return $cache[$table] ??= Schema::hasColumn($table, 'updated_at');
    }
}
