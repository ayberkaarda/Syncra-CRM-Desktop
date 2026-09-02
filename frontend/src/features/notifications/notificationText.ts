// Client-side `notifications.data` -> {title, body} resolution.
//
// ## Why this exists, and why it is NOT a second copy of anything
//
// The web app never resolves `title_key`/`body_key` on the client: `NotificationResource`
// (`backend/app/Http/Resources/NotificationResource.php`) and `CrmNotification::toBroadcast()`
// (`backend/app/Notifications/CrmNotification.php`) both call `App\Notifications\Support\
// NotificationText::resolve()` server-side, in PHP, against `backend/lang/<locale>/
// notifications.php`, before the row ever reaches `frontend/src/features/notifications/**`. That
// was confirmed by reading every file in this feature folder: none of them references
// `title_key`/`body_key`/`params` — `NotificationList`/`NotificationsPage` just print
// `notification.title`/`.body` as plain strings the server already rendered.
//
// The desktop app has no such round trip. It reads `notifications.data` straight out of its
// local SQLite mirror (protocol §6.1 P12 — the row IS the API resource, unmapped), which still
// carries the raw key + params for any row written after Phase 14 / Track D converted a
// notification type to key mode (`docs/PHASE-INTL.md` §1.4). Something has to run the PHP-side
// resolution's client equivalent, and this is that "one place" (defter O61 / B3) — not a fork of
// web logic (there is no client-side fork to make), but the client-side counterpart the web path
// never needed. If the web ever grows a client-rendered notification surface, it consumes this
// same function instead of writing a second one.
//
// ## Content gap (deliberate, not an oversight)
//
// `NotificationText::resolveParams()`'s param contract is mirrored below (a `_at`-suffixed
// param is an ISO-8601 date, formatted at read time in the reader's language; everything else is
// printed as-is). The SENTENCES themselves are a different matter: they live in
// `backend/lang/<locale>/notifications.php` and nowhere in `frontend/src/i18n/**` yet — verified
// by grepping every locale file in that tree for `deal_assigned`/`chat_mention`/etc. and finding
// nothing. This module is explicitly barred from writing into `frontend/src/i18n/**` in this
// task, and docs/ENGINEERING-RULES.md §6's hard-code ban means inventing a parallel hard-coded 4-language
// sentence table in this file instead would just move today's bug (dead, drifting text) rather
// than fix it. So `resolveNotificationText()` looks the key up against whatever the app's real
// i18next catalogue has RIGHT NOW (`i18n.exists()`, never a throwing `t()` on an unchecked key —
// see `desktop/src/ui/errors.ts` for the same non-throwing-lookup discipline against the same
// dev-mode-throwing `missingKeyHandler`), and if the catalogue has nothing for it yet, falls back
// to a generic, already-translated label rather than the raw key. The follow-up this leaves
// behind: add `notifications:<type>.<title|body>[_variant]` keys (i18next `{{param}}`
// interpolation, not Laravel `:param`) to the four `frontend/src/i18n/locales/*/notifications.json`
// files, transcribed from `backend/lang/*/notifications.php` — a task for whichever lane owns
// `frontend/src/i18n/**`.
import i18n from '../../i18n'
import { formatDateTime } from '../../lib/datetime'

/** The subset of a `notifications.data` row this module reads. Every field is `unknown` because
 * it comes straight off a JSON column (server) or a JSON-typed mirror column (desktop) with no
 * runtime validation done yet. */
export type NotificationTextSource = {
  title?: unknown
  body?: unknown
  title_key?: unknown
  body_key?: unknown
  params?: unknown
}

export type ResolvedNotificationText = {
  title: string
  body: string
}

/** Generic, already-translated stand-in for an unresolved title — never the raw key. `desktop`
 * namespace is loaded for every locale (it is one of the four `desktop.json` files, not
 * desktop-app-exclusive content), so this resolves the same way regardless of which app calls in. */
const FALLBACK_TITLE_KEY = 'desktop:entities.notification'

/**
 * Mirrors `NotificationText::resolve()` (backend/app/Notifications/Support/NotificationText.php).
 *
 * PRIORITY, reversed from the bug this fixes: `title_key`/`body_key` + `params` resolve FIRST.
 * `title`/`body` are the fallback, for the two cases key-mode cannot cover — a row written before
 * Phase 14 / Track D converted its type to key mode, and (defensively) a row whose key does not
 * resolve in the current catalogue yet (see module docblock).
 */
export function resolveNotificationText(data: NotificationTextSource): ResolvedNotificationText {
  const titleKey = nonEmptyString(data.title_key)

  if (titleKey === null) {
    // Legacy / not-yet-converted row: `data.title`/`data.body` ARE the sentence, stored as-is.
    return {
      title: nonEmptyString(data.title) ?? '',
      body: nonEmptyString(data.body) ?? '',
    }
  }

  const bodyKey = nonEmptyString(data.body_key)
  const params = resolveParams(data.params)

  return {
    title: translate(titleKey, params) ?? nonEmptyString(data.title) ?? fallbackTitle(),
    body: (bodyKey !== null ? translate(bodyKey, params) : null) ?? nonEmptyString(data.body) ?? '',
  }
}

/**
 * `key` in Laravel dot-path form (`notifications.deal_assigned.title`, the exact string
 * `CrmNotification::toArray()` writes). i18next's OWN key format needs a `namespace:key`
 * separator to land in the right catalogue file instead of `common`'s, so the leading segment
 * (always `notifications` today, per every `titleKey:`/`bodyKey:` call site in
 * `backend/app/Notifications/*.php`) becomes the namespace and the i18next default `.`
 * `keySeparator` does the rest. Returns `null` — not the raw key — when nothing in the currently
 * loaded catalogue answers for it, so the caller's fallback chain runs instead of `i18next`'s own
 * (which, in dev/test, would throw via `missingKeyHandler`; `frontend/src/i18n/index.ts` §1.7).
 */
function translate(key: string, params: Record<string, string>): string | null {
  // The common (today: only) case is handled with the namespace prefix written directly as a
  // `notifications:${...}` TEMPLATE LITERAL, not built into a variable first (that was the
  // `i18nKey` line this replaces). Reason: `frontend/scripts/check-i18n-dead-keys.mjs`'s static
  // scanner only resolves `t(...)` calls whose argument is a quote/backtick literal — a plain
  // identifier argument (`i18n.t(i18nKey, params)`) is invisible to it. Writing the prefix
  // literally gives the scanner a `notifications:` head, which its `literalHead.endsWith(':')`
  // branch (`check-i18n-dead-keys.mjs:207-211`) recognises as "this whole namespace is
  // referenced". That single missing literal is why all 36 `notifications:*.title`/`.body`
  // catalogue keys reported as dead-code false positives (defter O31 / O73) despite this
  // function being their one real, if dynamically-keyed, consumer.
  //
  // A key that does NOT start with the literal `notifications.` namespace (none exist today —
  // every call site above is grep-verified; kept only so a future call site cannot silently
  // mis-resolve) falls through to the general lookup, byte-for-byte the same construction this
  // branch replaces for the common case.
  if (key.startsWith('notifications.')) {
    const rest = key.slice('notifications.'.length)
    if (!i18n.exists(`notifications:${rest}`)) return null
    return String(i18n.t(`notifications:${rest}`, params))
  }

  const namespaceSeparator = key.indexOf('.')
  const i18nKey = namespaceSeparator === -1 ? key : `${key.slice(0, namespaceSeparator)}:${key.slice(namespaceSeparator + 1)}`

  if (!i18n.exists(i18nKey)) return null
  return String(i18n.t(i18nKey, params))
}

function fallbackTitle(): string {
  return i18n.exists(FALLBACK_TITLE_KEY) ? String(i18n.t(FALLBACK_TITLE_KEY)) : ''
}

/**
 * Mirrors `NotificationText::resolveParams()`. A `_at`-suffixed param is an ISO-8601 instant,
 * reformatted HERE (read time, reader's language) rather than trusting whatever the sender's
 * language happened to format it in — the exact freeze `NotificationText`'s docblock explains.
 * `formatDate`/`Time` (`frontend/src/lib/datetime.ts`) is the single date-formatting source this
 * app already has; this reuses it instead of a third `Intl.DateTimeFormat` call site.
 */
function resolveParams(raw: unknown): Record<string, string> {
  if (raw === null || typeof raw !== 'object') return {}

  const result: Record<string, string> = {}
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    if (key.endsWith('_at') && typeof value === 'string' && value !== '') {
      result[key] = formatDateTime(value)
      continue
    }
    result[key] = typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? String(value) : ''
  }
  return result
}

function nonEmptyString(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}
