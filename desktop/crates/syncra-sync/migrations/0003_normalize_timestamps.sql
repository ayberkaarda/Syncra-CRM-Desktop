-- Migration 0003 — one timestamp dialect in the mirror (defter O83).
--
-- THE BUG
-- -------
-- Every `*_at` column in this schema is TEXT, and two writers filled it with two different
-- shapes:
--
--   server row   `2026-09-01 23:59:00`        -- MySQL DATETIME text, SPACE at offset 10
--   offline row  `2026-09-01T08:00:00.000Z`   -- outbox::now_iso(), RFC 3339, `T` at offset 10
--
-- The server half is not a server bug: `SyncPullService::fetchRows()` reads the mirror tables
-- with `DB::table()`, i.e. without Eloquent and therefore without a datetime cast, so the raw
-- column text goes straight onto the wire.
--
-- Every comparison the read layer makes on these columns is a *string* comparison
-- (`db/query.rs`: `ORDER BY {sort_col}`, the `messages` keyset `created_at < (SELECT ...)`,
-- the `from`/`to` range filters, and `due_at < strftime('%Y-%m-%dT%H:%M:%SZ','now')`).
-- `'T'` is 0x54 and `' '` is 0x20, so on any given date EVERY offline row sorted above EVERY
-- server row no matter what the clock said, and every server task due later *today* was
-- reported "overdue" because its space-form stamp is below the `T`-form "now" for the whole
-- day.
--
-- THE FIX, AND WHY IT IS SPLIT IN TWO
-- ----------------------------------
-- `db/mod.rs::json_to_sql_for_column()` normalises server timestamps at the two write
-- boundaries (`db/upsert.rs::upsert_row` for the pull path, `sync/mod.rs::apply_server_row`
-- for conflict resolution and replayed `pending_shadows`), so nothing new can enter in the
-- old dialect. That covers future writes only: a mirror on disk today is already full of
-- space-form values, and nothing re-pulls a row whose `sync_version` has not moved. This
-- migration converts what is already there.
--
-- WHAT IS CONVERTED, AND WHAT IS DELIBERATELY NOT
-- ----------------------------------------------
-- 86 columns across the 22 mirror tables — every `*_at` column in `0001_init.sql` except:
--
--   * `local_updated_at` (22 columns). Written only by `sync/local.rs` from
--     `outbox::now_iso()`; it has never held a server value, so there is nothing to convert.
--   * `outbox.occurred_at`, `conflicts.created_at`, `cached_files.fetched_at`. Engine-local
--     bookkeeping tables, never fed from a pull row.
--   * every date-only column — `expected_close_date`, `valid_until`, `rate_date`,
--     `exchange_rate_date`. These are MySQL `date()` columns whose `2026-09-05` values are
--     already correct; inventing a midnight for them would be a new bug, not a fix. None of
--     them ends in `_at`, which is exactly why the runtime helper keys off that suffix.
--
-- The guard is `LIKE '____-__-__ __:__:__'`. LIKE has to match the WHOLE value, and the
-- pattern is 19 single-character wildcards plus four literals, so it matches only a value
-- that is exactly 19 characters long and carries a literal SPACE at offset 10:
--
--   '2026-09-01 23:59:00'       -> matches, rewritten to '2026-09-01T23:59:00.000Z'
--   '2026-09-01T23:59:00.000Z'  -> no match (24 chars, and `T` is not the literal space)
--   '2026-09-01T23:59:00Z'      -> no match (20 chars)
--   '2026-09-05'                -> no match (10 chars) — date-only values are untouched
--   NULL                        -> no match — NULL stays NULL
--
-- The rewrite is `substr(x,1,10) || 'T' || substr(x,12,8) || '.000Z'` rather than
-- `replace(x,' ','T')`: `_` in a LIKE pattern matches any character, a space included, so
-- `replace` would in principle rewrite a second stray space somewhere in the value. Slicing at
-- fixed offsets can only ever produce the intended shape.
--
-- The `.000` matters. `now_iso()` writes milliseconds, so a bare `...:00Z` and a local
-- `...:00.000Z` are not string-equal at the same instant (`'Z'` is 0x5A, `'.'` is 0x2E) and the
-- two dialects would simply split again one character further along. `.000Z` is the shape both
-- sides now agree on.
--
-- Values with a fractional part in the space dialect (`2026-09-01 23:59:00.123`) are NOT
-- matched here: MySQL `DATETIME` produces none today, and a migration that half-understands a
-- value it has never seen is worse than one that leaves it alone. `normalize_timestamp()`
-- handles that shape at runtime should a `DATETIME(6)` column ever appear.
--
-- Six of these tables carry `AFTER UPDATE` FTS triggers, so a converted row is re-indexed as
-- a side effect. That is harmless — the triggers rebuild the row's `fts_records` entry from
-- the same text as before — and it is safe here because `db/mod.rs::open()` calls
-- `register_functions()` (which defines `syncra_fold`) before `migrate()` runs.
--
-- The whole file runs inside the single transaction `db/mod.rs::migrate_with()` opens, so it
-- either lands completely or not at all.


-- companies (3)
UPDATE companies SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE companies SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE companies SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- contacts (3)
UPDATE contacts SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE contacts SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE contacts SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- leads (4)
UPDATE leads SET converted_at = substr(converted_at, 1, 10) || 'T' || substr(converted_at, 12, 8) || '.000Z'
 WHERE converted_at LIKE '____-__-__ __:__:__';
UPDATE leads SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE leads SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE leads SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- deals (4)
UPDATE deals SET closed_at = substr(closed_at, 1, 10) || 'T' || substr(closed_at, 12, 8) || '.000Z'
 WHERE closed_at LIKE '____-__-__ __:__:__';
UPDATE deals SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE deals SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE deals SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- tasks (6)
UPDATE tasks SET due_at = substr(due_at, 1, 10) || 'T' || substr(due_at, 12, 8) || '.000Z'
 WHERE due_at LIKE '____-__-__ __:__:__';
UPDATE tasks SET reminder_at = substr(reminder_at, 1, 10) || 'T' || substr(reminder_at, 12, 8) || '.000Z'
 WHERE reminder_at LIKE '____-__-__ __:__:__';
UPDATE tasks SET completed_at = substr(completed_at, 1, 10) || 'T' || substr(completed_at, 12, 8) || '.000Z'
 WHERE completed_at LIKE '____-__-__ __:__:__';
UPDATE tasks SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE tasks SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE tasks SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- activities (4)
UPDATE activities SET occurred_at = substr(occurred_at, 1, 10) || 'T' || substr(occurred_at, 12, 8) || '.000Z'
 WHERE occurred_at LIKE '____-__-__ __:__:__';
UPDATE activities SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE activities SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE activities SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- tickets (10)
UPDATE tickets SET sla_due_at = substr(sla_due_at, 1, 10) || 'T' || substr(sla_due_at, 12, 8) || '.000Z'
 WHERE sla_due_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET first_response_at = substr(first_response_at, 1, 10) || 'T' || substr(first_response_at, 12, 8) || '.000Z'
 WHERE first_response_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET resolved_at = substr(resolved_at, 1, 10) || 'T' || substr(resolved_at, 12, 8) || '.000Z'
 WHERE resolved_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET closed_at = substr(closed_at, 1, 10) || 'T' || substr(closed_at, 12, 8) || '.000Z'
 WHERE closed_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET sla_paused_at = substr(sla_paused_at, 1, 10) || 'T' || substr(sla_paused_at, 12, 8) || '.000Z'
 WHERE sla_paused_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET sla_warning_notified_at = substr(sla_warning_notified_at, 1, 10) || 'T' || substr(sla_warning_notified_at, 12, 8) || '.000Z'
 WHERE sla_warning_notified_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET sla_breach_notified_at = substr(sla_breach_notified_at, 1, 10) || 'T' || substr(sla_breach_notified_at, 12, 8) || '.000Z'
 WHERE sla_breach_notified_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE tickets SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- quotes (6)
UPDATE quotes SET sent_at = substr(sent_at, 1, 10) || 'T' || substr(sent_at, 12, 8) || '.000Z'
 WHERE sent_at LIKE '____-__-__ __:__:__';
UPDATE quotes SET accepted_at = substr(accepted_at, 1, 10) || 'T' || substr(accepted_at, 12, 8) || '.000Z'
 WHERE accepted_at LIKE '____-__-__ __:__:__';
UPDATE quotes SET rejected_at = substr(rejected_at, 1, 10) || 'T' || substr(rejected_at, 12, 8) || '.000Z'
 WHERE rejected_at LIKE '____-__-__ __:__:__';
UPDATE quotes SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE quotes SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE quotes SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- conversations (4)
UPDATE conversations SET last_message_at = substr(last_message_at, 1, 10) || 'T' || substr(last_message_at, 12, 8) || '.000Z'
 WHERE last_message_at LIKE '____-__-__ __:__:__';
UPDATE conversations SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE conversations SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE conversations SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- messages (4)
UPDATE messages SET edited_at = substr(edited_at, 1, 10) || 'T' || substr(edited_at, 12, 8) || '.000Z'
 WHERE edited_at LIKE '____-__-__ __:__:__';
UPDATE messages SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE messages SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE messages SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- conversation_user (4)
UPDATE conversation_user SET joined_at = substr(joined_at, 1, 10) || 'T' || substr(joined_at, 12, 8) || '.000Z'
 WHERE joined_at LIKE '____-__-__ __:__:__';
UPDATE conversation_user SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE conversation_user SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE conversation_user SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- notifications (4)
UPDATE notifications SET read_at = substr(read_at, 1, 10) || 'T' || substr(read_at, 12, 8) || '.000Z'
 WHERE read_at LIKE '____-__-__ __:__:__';
UPDATE notifications SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE notifications SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE notifications SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- tags (3)
UPDATE tags SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE tags SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE tags SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- pipeline_stages (3)
UPDATE pipeline_stages SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE pipeline_stages SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE pipeline_stages SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- custom_fields (3)
UPDATE custom_fields SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE custom_fields SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE custom_fields SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- products (3)
UPDATE products SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE products SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE products SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- price_lists (3)
UPDATE price_lists SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE price_lists SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE price_lists SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- price_list_items (3)
UPDATE price_list_items SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE price_list_items SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE price_list_items SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- exchange_rates (3)
UPDATE exchange_rates SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE exchange_rates SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE exchange_rates SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- saved_views (3)
UPDATE saved_views SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE saved_views SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE saved_views SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- settings (3)
UPDATE settings SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE settings SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE settings SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';

-- users (3)
UPDATE users SET created_at = substr(created_at, 1, 10) || 'T' || substr(created_at, 12, 8) || '.000Z'
 WHERE created_at LIKE '____-__-__ __:__:__';
UPDATE users SET updated_at = substr(updated_at, 1, 10) || 'T' || substr(updated_at, 12, 8) || '.000Z'
 WHERE updated_at LIKE '____-__-__ __:__:__';
UPDATE users SET deleted_at = substr(deleted_at, 1, 10) || 'T' || substr(deleted_at, 12, 8) || '.000Z'
 WHERE deleted_at LIKE '____-__-__ __:__:__';
