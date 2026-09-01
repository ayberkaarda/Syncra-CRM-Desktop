<?php

namespace App\Services\Sync;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Support\NotificationText;
use App\Services\Tickets\SlaService;
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
 *
 * WIRE SEMANTICS OF `window_days` (RISK-2 #3). §4.4 says "delta'da filtre
 * yok", but the request rule said `min:1` unconditionally, so a client that
 * sent the field alongside a non-zero cursor got a 422 for a value the server
 * was going to ignore anyway. The field is therefore defined as a BOOTSTRAP
 * HINT, never an error:
 *
 *   - accepted values are `null`, `0`, and `1..365`;
 *   - `null` and `0` are THE SAME REQUEST - "no window", no filter, no U9
 *     closure. `0` reads as "zero days of history", which as a filter would
 *     mean an empty bootstrap; nobody can want that, so it degrades to the
 *     no-filter branch rather than 422-ing or returning nothing;
 *   - `> 365` still 422s. That cap is a real budget contract (K8/K12), not a
 *     validation accident, and silently clamping it would hide a client bug;
 *   - the filter is applied PER TABLE and only where that table's cursor is
 *     0. A delta pull that carries `window_days: 30` is therefore already
 *     behaviourally identical to one that omits it - the fix is purely that
 *     it is no longer rejected.
 *
 * Locked by SyncPullTest::test_window_days_is_accepted_and_ignored_on_a_delta_pull
 * and ..._rejects_a_window_wider_than_the_retention_ceiling.
 *
 * ------------------------------------------------------------------------
 * TWO GAPS §4.4 NEVER CLOSED (FIX-BE U9/U10)
 * ------------------------------------------------------------------------
 * §4.4 defines the window purely as a per-table time filter; it says nothing
 * about referential integrity between tables, or about reference/lookup data
 * that is not a time-ordered stream at all. Both gaps produced the same
 * symptom - a mirror table the client needed came back short or empty:
 *
 *   - U10 `tags`: §4.1 groups `tags, taggables, custom_field_values` as their
 *     own row, separate from the time-ordered entity list. A tag is a fixed
 *     vocabulary label, not a transactional record, so it is now exempt from
 *     the window entirely (`SyncableRegistry::windowExemptTables()`) rather
 *     than filtered by an `updated_at` that has nothing to do with whether it
 *     is still in use.
 *
 *   - U9 `companies`: a `contacts`/`deals`/`tickets`/`quotes` row inside the
 *     window can point at a `companies` row that is not - the company was
 *     created long ago and nobody has edited it since. The window filter has
 *     no concept of "still referenced", so that company never shipped and the
 *     relation rendered empty on the client. `applyCompanyClosure()` fixes
 *     this with a DEPTH-1 relational closure, run once after the main loop:
 *     it reads the company FK column
 *     (`SyncableRegistry::companyReferenceColumns()`) off whatever rows are
 *     ACTUALLY in this response for those five tables, and fetches any
 *     `companies` row they point at that the window left out. Depth is
 *     capped at 1 - a closure-added company's own foreign keys (there are
 *     none today) are never chased - so growth is bounded by the rows already
 *     in this same response, not by the whole database. Closure rows are
 *     added outside the byte budget (`trimToBudget` is not applied to them):
 *     the alternative - a company silently dropped by the budget - re-orphans
 *     the exact relation this fix exists to close, and the client's cursor
 *     for `companies` would have already advanced past that company's low
 *     `sync_version` by the time it fails to appear, since a bootstrap
 *     closure runs only once (gated on `cursor === 0`). This is bounded by
 *     what THIS response already carries for the five referencing tables (at
 *     most `5 * limit` distinct ids, almost always far fewer since many rows
 *     share a company) rather than unbounded, but a pull that also happens to
 *     hit the byte ceiling on those referencing tables before every one of
 *     them is visited can still miss a closure candidate that table would
 *     have contributed - see FIX-BE report for why this residual case was
 *     accepted rather than solved by reordering table processing.
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

        // `window_days` is a BOOTSTRAP hint, never an error (RISK-2 #3). `0`
        // is the wire spelling of "no window" and collapses to null here, so
        // the two values take the identical code path; see the class docblock.
        $windowDays = $windowDays === 0 ? null : $windowDays;

        $tables = [];
        $bytes = 0;
        $scopeTables = $this->scope->tablesFor($user);

        foreach ($scopeTables as $table => $definition) {
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

        // U9 closure only ever applies to a BOOTSTRAP window pull: on a delta
        // pull the window filter never ran, so there is nothing to close over
        // (see class docblock, "TWO GAPS §4.4 NEVER CLOSED").
        if ($windowDays !== null && (int) ($cursors['companies'] ?? 0) === 0 && array_key_exists('companies', $tables)) {
            $bytes = $this->applyCompanyClosure($user, $tables, $bytes, $scopeTables['companies']);
        }

        return [
            'server_time' => now()->toIso8601String(),
            'tables' => $tables,
        ];
    }

    /**
     * Depth-1 relational closure for `companies` (FIX-BE U9, see class
     * docblock). Runs once, after every table in the response has its final,
     * budget-trimmed row set - so the FK ids it reads off
     * `contacts`/`deals`/`tickets`/`quotes`/`leads` are exactly what this
     * response is actually sending the client, not what a wider query could
     * have returned.
     *
     * @param  array<string, array<string, mixed>>  $tables
     * @param  array<string, mixed>  $companiesDefinition
     */
    private function applyCompanyClosure(User $user, array &$tables, int $bytes, array $companiesDefinition): int
    {
        $alreadyPresent = array_map(
            static fn (array $row): int => (int) $row['id'],
            $tables['companies']['rows']
        );

        $referencedIds = [];

        foreach (SyncableRegistry::companyReferenceColumns() as $table => $column) {
            if (! isset($tables[$table])) {
                continue;
            }

            foreach ($tables[$table]['rows'] as $row) {
                $value = $row[$column] ?? null;

                if ($value !== null) {
                    $referencedIds[(int) $value] = true;
                }
            }
        }

        $missingIds = array_values(array_diff(array_keys($referencedIds), $alreadyPresent));

        if ($missingIds === []) {
            return $bytes;
        }

        $query = DB::table('companies')->whereIn('id', $missingIds);
        $this->scope->applyRowScope($query, 'companies', $user);

        $extraRows = $query
            ->orderBy('sync_version')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        if ($extraRows === []) {
            return $bytes;
        }

        $extraRows = $this->attachEmbeds('companies', $companiesDefinition, $extraRows);

        foreach ($extraRows as $row) {
            $bytes += strlen((string) json_encode($row));
            $tables['companies']['rows'][] = $row;
            $tables['companies']['next_cursor'] = max($tables['companies']['next_cursor'], (int) $row['sync_version']);
        }

        return $bytes;
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

        if (
            $cursor === 0
            && $windowDays !== null
            && $this->hasUpdatedAt($table)
            && ! in_array($table, SyncableRegistry::windowExemptTables(), true)
        ) {
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

        if ($table === 'tickets') {
            $rows = $this->attachTicketSla($rows);
        }

        if ($table === 'messages') {
            $rows = $this->attachMessageAttachments($rows);
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
     * DESKTOP-ARCHITECTURE.md EK 4, KARAR A26: the desktop client gets the
     * SLA countdown as four SERVER-COMPUTED fields on the `tickets` pull row
     * - `sla_remaining_seconds`, `sla_total_seconds`, `sla_target_hours`,
     * `sla_breached` - never the raw formula. This mirrors exactly what
     * `TicketResource::toArray()` already sends the web client (K7: the two
     * response shapes must never diverge), by calling the SAME
     * `SlaService` methods rather than re-deriving the arithmetic here.
     *
     * `sla_due_at`, `sla_paused_at`, `sla_paused_seconds`, `resolved_at`,
     * `priority` and `status` are plain columns already present in `$row`
     * (the pull query is `SELECT *`), so no migration and no extra query are
     * needed - only a hydration step, because `SlaService` expects Carbon
     * instances and this row is still raw strings from the query builder.
     *
     * `Ticket::newFromBuilder()` is used instead of `new Ticket($row)` /
     * `Ticket::make($row)` for two reasons: it marks the model `exists =
     * true` (this is a real persisted row, not a draft - `SlaService` must
     * never accidentally be handed something that looks unsaved), and it
     * goes through `setRawAttributes()`, which bypasses mass-assignment
     * guarding entirely - irrelevant for reads, but the correct primitive
     * for "this is exactly what the database returned". Carbon casting for
     * `sla_due_at` / `sla_paused_at` / `resolved_at` still applies on
     * ATTRIBUTE ACCESS (via `casts()` and, for `created_at`, Eloquent's
     * automatic timestamp casting) because that happens in the model's
     * attribute accessor, independent of how the raw attributes were set -
     * verified in SyncPullTicketSlaTest by asserting the four computed
     * fields equal what `TicketResource` produces for the identical ticket.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachTicketSla(array $rows): array
    {
        $sla = app(SlaService::class);
        $prototype = new Ticket;

        foreach ($rows as $index => $row) {
            $ticket = $prototype->newFromBuilder($row);

            $rows[$index]['sla_remaining_seconds'] = $sla->remainingSeconds($ticket);
            $rows[$index]['sla_total_seconds'] = $sla->totalSeconds($ticket);
            $rows[$index]['sla_target_hours'] = $sla->targetHoursForTicket($ticket);
            $rows[$index]['sla_breached'] = $sla->isBreached($ticket);
        }

        return $rows;
    }

    /**
     * KARAR A29 (defter O90): `attachments` never joins the sync scope - file
     * BYTES are out of protocol §1.3, and mirroring the whole table for four
     * fields would be the tail wagging the dog. Instead the `messages` pull
     * row carries the attachment's METADATA as four flattened fields, sourced
     * from the exact same shape `AttachmentResource::toArray()` already
     * builds for the web client (K7: ONE definition of `is_image`, never
     * re-derived here from a MIME prefix). `attachment_is_image` is computed
     * by calling `Attachment::isInlineEligibleImage()` - the same allowlist
     * check the web resource and `AttachmentController::show()`'s `?inline=1`
     * gate use (`config('chat.attachments.inline_mime_types')`: exactly the
     * four raster types `image/jpeg|png|gif|webp`). This is deliberately
     * NARROWER than a `str_starts_with($mime, 'image/')` prefix check would
     * be - `image/svg+xml` matches that prefix but is excluded from the
     * allowlist on purpose (inline SVG rendering is a known XSS vector), so a
     * prefix-based re-derivation here would silently mark SVGs as
     * image-eligible against the source definition's intent. This mirrors
     * `attachTicketSla()`'s pattern one method up:
     * a server-computed/looked-up projection glued onto the raw `SELECT *`
     * row rather than expressed as a join, because the row set here is
     * whatever `rowsFor()` already paged and trimmed - a join would have to
     * happen before paging to be correct, and would fetch attachment bytes
     * this endpoint has no business returning.
     *
     * ONE query for the whole page (`whereIn`), matching `attachEmbeds()`'s
     * discipline - never one query per row.
     *
     * A soft-deleted message (`deleted_at` set) gets none of the four fields,
     * mirroring `MessageResource::toArray()`'s mask: on the web surface a
     * deleted message's `attachment` is unconditionally null, so a pull row
     * that kept the name/mime/size for a "deleted" message would disagree
     * with the surface it is supposed to mirror.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachMessageAttachments(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['deleted_at'] !== null || $row['attachment_id'] === null) {
                continue;
            }

            $ids[(int) $row['attachment_id']] = true;
        }

        if ($ids === []) {
            return $rows;
        }

        // Eloquent (not DB::table()) so `isInlineEligibleImage()` - the K7
        // source-of-truth definition - can be called on each row below,
        // instead of re-deriving it here from the raw mime string.
        $attachments = Attachment::query()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'original_name', 'mime_type', 'size'])
            ->keyBy('id');

        foreach ($rows as $index => $row) {
            if ($row['deleted_at'] !== null || $row['attachment_id'] === null) {
                continue;
            }

            $attachment = $attachments->get((int) $row['attachment_id']);

            if ($attachment === null) {
                continue;
            }

            $rows[$index]['attachment_name'] = $attachment->original_name;
            $rows[$index]['attachment_mime'] = $attachment->mime_type;
            $rows[$index]['attachment_size'] = (int) $attachment->size;
            $rows[$index]['attachment_is_image'] = $attachment->isInlineEligibleImage();
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
     * Hard-delete tombstones (protocol §2.7). Only four tables ever produce
     * them; a soft delete needs none, because the row itself comes back with
     * `deleted_at` set.
     *
     * ------------------------------------------------------------------------
     * OWNER SCOPE (RISK-2 O3 / TM-F2)
     * ------------------------------------------------------------------------
     * A tombstone outlives its row, so the owner cannot be resolved by
     * looking the row up - it is gone. Until `owner_key` existed this method
     * had a scope for exactly ONE table (`conversation_user`, whose row_key
     * embeds the pair) and none for `notifications`, so every device received
     * every user's deleted notification uuids. Existence only, no content and
     * no attribution - but existence of another user's notification is still
     * that user's data, and a live desktop client makes it demonstrable.
     *
     * `owner_key` is required to MATCH, never merely "not somebody else's":
     * a NULL on an owner-scoped table (a tombstone written before the
     * migration, or by a path that could not resolve an owner) is delivered to
     * nobody rather than to everybody. That fails closed - the client keeps
     * one stale mirror row until its next re-bootstrap, instead of the fix
     * exempting exactly the rows it exists for.
     *
     * `tags` and `price_list_items` are deliberately unscoped: the first is
     * org-wide vocabulary every authenticated caller pulls in full, the second
     * is gated wholesale by `products.view` at the TABLE level, so neither can
     * leak through a tombstone anything its rows do not already show the same
     * caller. See `SyncableRegistry::ownerScopedTombstoneTables()`.
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

        if (in_array($table, SyncableRegistry::ownerScopedTombstoneTables(), true)) {
            // The same pair SyncScope::applyRowScope() filters the ROWS on,
            // written by SyncDeletionObserver at delete time.
            $query->where('owner_key', $user->getMorphClass().':'.$user->getKey());
        }

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
