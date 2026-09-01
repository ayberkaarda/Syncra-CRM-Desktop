// The mirror's `created_at`/`due_at`/`sla_due_at` timestamp shape, and the one function that
// reads it correctly.
//
// ## Why this lives in `frontend/src/lib` and not under `desktop/src`
//
// It started in `desktop/src/bridge/notifications.ts` (one caller), moved to
// `desktop/src/platform/data/timestamps.ts` (three callers), and landed here once it turned out
// the callers that matter most are in the SHARED app, not in the desktop shell: the notification
// list renders its timestamps through `features/notifications/components/notificationMeta.ts::
// formatRelativeTime`, and notification bodies print their `*_at` parameters through
// `lib/datetime.ts` (see `features/notifications/notificationText.ts::resolveParams`). Neither
// can import from `desktop/src`: the `@` alias points ONE WAY (`vite.desktop.config.ts` maps
// `@` -> `frontend/src`, so the desktop shell reaches the shared app and never the reverse), and
// `frontend/src`'s own imports are all relative by construction. `frontend/src/lib` is the only
// directory both sides can reach, so the implementation lives here and
// `desktop/src/platform/data/timestamps.ts` is now a one-line re-export.
//
// ## The two shapes a mirror timestamp column holds
//
// * `2026-09-01 07:58:01` — a row that arrived from the server. `SyncPullService::fetchRows()`
//   reads every mirrored table through the raw query builder (`DB::table($table)->get(...)`),
//   never through Eloquent, so a MySQL `DATETIME` reaches the wire in MySQL's own text form: a
//   SPACE separator and no zone at all. `json_to_sql()` stores that verbatim in the `TEXT`
//   column (`migrations/0001_init.sql`), and `row_to_json()` hands it back untouched.
// * `2026-09-01T07:58:01.123Z` — a row created locally, stamped by `outbox::now_iso()`
//   (`Utc::now().to_rfc3339_opts(SecondsFormat::Millis, true)`), already unambiguous.
//
// **The instant behind the first form is UTC** — `backend/config/app.php` pins `APP_TIMEZONE`
// to `UTC`, so the column, the wire and the mirror are all UTC — but nothing in the STRING says
// so, and ECMA-262 requires a date-time form carrying no offset to be read as LOCAL time.
// `Date.parse`/`new Date(...)` therefore shift every pulled row by the host's UTC offset: on
// UTC+3 three hours into the past (a notification whose `created_at` looks three hours older
// than it is; a task or ticket whose `is_overdue` flips early), on a negative offset the same
// row lands in the future.
//
// So the zone is supplied explicitly: the space form is normalised to `T` and given the `Z` the
// server meant. Anything already carrying `Z` or a numeric offset is passed through unchanged.
//
// ## The web bundle is unaffected — by construction, not by luck
//
// `lib/datetime.ts` and `notificationMeta.ts` ship in the WEB build too, where timestamps do not
// come from the mirror but from `NotificationResource` and its siblings, which serialise through
// `toIso8601String()` (`backend/app/Http/Resources/NotificationResource.php`) — i.e. always with
// an explicit `+00:00` offset. An offset-carrying value takes the pass-through branch below and
// reaches `Date.parse` byte-identical to what `new Date(value)` was given before, so the web
// rendering cannot change. `mirrorTime.test.ts` pins that as an assertion rather than leaving it
// as an argument.
//
// ## Date-only columns are a different shape, deliberately untouched
//
// `expected_close_date`, `valid_until` and the like are `date()` migration columns
// (`"2026-09-05"`, no time part at all) — ECMA-262 already reads a date-only string as UTC
// midnight, so they must NOT match the naive-timestamp pattern below. The regex requires a
// `[ T]HH:MM:SS` tail precisely so a bare date falls through to the `Date.parse` branch
// unchanged.
const NAIVE_TIMESTAMP = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2}(?:\.\d+)?)$/

/**
 * A mirror timestamp column in milliseconds since the epoch, read as UTC.
 *
 * Anything unrecognised (including a date-only value already handled correctly by
 * `Date.parse`, and genuine garbage) is passed straight to `Date.parse`; garbage then parses to
 * `NaN`, which every caller here treats as "not usable" rather than as an error.
 */
export function parseMirrorTimestamp(value: string): number {
  const naive = NAIVE_TIMESTAMP.exec(value.trim())
  return naive ? Date.parse(`${naive[1]}T${naive[2]}Z`) : Date.parse(value)
}
