// `mapNotification` x real notification catalog — the missing link (defter O68).
//
// Notification text resolution is tested in two places today, and BOTH are insufficient on
// their own:
//
//   * `mappers.test.ts`'s `mapNotification` suite (defter O61 / B3) proves the RESOLUTION
//     MECHANISM (key lookup, `{{param}}` interpolation, `_at` reformatting, fallback chain) is
//     correct, but does so against a small FAKE `notifications` bundle injected with
//     `i18n.addResourceBundle` — a stand-in the docblock there is explicit about, precisely
//     because the real 4-language sentence catalogue did not exist yet when that suite was
//     written.
//   * `npm run i18n:notifications` (`frontend/scripts/check-notification-catalogue.mjs`) proves
//     the real catalogue (`frontend/src/i18n/locales/*/notifications.json`) stays in PARITY with
//     the backend PHP catalogue (`backend/lang/*/notifications.php`) — same keys, same param
//     names — but never calls `mapNotification`, or any frontend code, at all.
//
// Neither end asks "does `mapNotification`, pointed at the REAL catalogue, actually produce a
// real sentence?" — the parity script would happily stay green if `translate()` silently
// stopped resolving every key (e.g. a namespace/keySeparator regression), because it never
// invokes i18next. This file is that missing link: it loads the real four-language
// `notifications.json` catalogue into the real `i18next` singleton the app ships with, and
// round-trips `mapNotification` for EVERY `type.field` pair the catalogue declares.
//
// The loop walks the catalogue itself (not a hand-picked sample) so a 13th notification type —
// or a new `body_*`/`title_*` variant on an existing one — is covered the moment its JSON key
// lands, with no edit needed here. The one thing that DOES need a hand-added entry per key is
// `PARAM_FIXTURES`: interpolation only proves itself with parameters shaped like what the
// backend actually sends, so each fixture is transcribed from the `params:` array at the
// `Notifications::make()` call site that produces it (`backend/app/Notifications/*.php`,
// file:line cited per group below) — never invented. A key with no fixture falls back to `{}`,
// which is a deliberate trap, not a shortcut: assertion (b) below (no leftover `{{...}}`) then
// fails loudly on that specific key, which is exactly the signal that a newly added key needs a
// real fixture added here.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import { beforeAll, describe, it } from 'vitest'

import i18n from '@/i18n'

import { mapNotification } from './mappers'
import type { LocalRow } from './engine'

const LOCALES = ['tr', 'en', 'de', 'fr'] as const

/** Reads the real `frontend/src/i18n/locales/<locale>/notifications.json` from disk — the
 * catalogue the app actually ships, not a copy. Plain `fs.readFileSync` + `JSON.parse` rather
 * than a static `import ... from '....json'`: neither `desktop/tsconfig.json` nor the
 * `frontend/tsconfig.app.json` it extends sets `resolveJsonModule`, and this task's file
 * ownership does not include either config. `wire-fixtures.test.ts` establishes the same
 * `fileURLToPath(new URL('../../../../...', import.meta.url))` pattern for reaching outside
 * `desktop/src` from this directory. */
function loadCatalog(locale: string): Record<string, unknown> {
  const path = fileURLToPath(
    new URL(`../../../../frontend/src/i18n/locales/${locale}/notifications.json`, import.meta.url)
  )
  return JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown>
}

/** Top-level JSON keys that are UI chrome (breadcrumb/page/bell/list/relativeTime/toast), not
 * backend-sourced notification types — mirrors `CHROME_KEYS` in
 * `frontend/scripts/check-notification-catalogue.mjs` so this file walks exactly the same
 * "message type" surface that script already cross-checks against the backend catalogue. */
const CHROME_KEYS = new Set(['breadcrumb', 'page', 'bell', 'list', 'relativeTime', 'toast'])

/** `{deal_assigned: ['title', 'body'], ...}` — every notification type and its field variants,
 * read from the `tr` catalogue (the reference locale; `npm run i18n:check` already guarantees
 * the other three carry the same key set). This IS the loop the module docblock promises: add a
 * type or a `body_*`/`title_*` variant to `notifications.json` and it appears here without
 * touching this file. */
function catalogMessageTypes(catalog: Record<string, unknown>): Record<string, string[]> {
  const out: Record<string, string[]> = {}
  for (const [type, value] of Object.entries(catalog)) {
    if (CHROME_KEYS.has(type)) continue
    if (value === null || typeof value !== 'object' || Array.isArray(value)) continue
    out[type] = Object.keys(value as Record<string, unknown>)
  }
  return out
}

/**
 * Realistic `params` per `type.field`, transcribed from the `Notifications::make()` call site
 * that actually builds them — never invented. `_at`-suffixed params are ISO-8601 instants
 * (`resolveNotificationText`'s reformat contract, `notificationText.ts`); everything else is a
 * plain string, matching the wire shape every `make()` below produces.
 *
 * A key intentionally absent here (none, as of the 12 types this catalogue declares — every one
 * below is transcribed) falls back to `{}` at lookup time; see the module docblock for why.
 */
const PARAM_FIXTURES: Record<string, Record<string, string>> = {
  // backend/app/Notifications/DealAssignedNotification.php:342-354
  'deal_assigned.title': {},
  'deal_assigned.body': { subject: 'Acme A.Ş.', amount: '₺12.500,00' },
  // backend/app/Notifications/DealLostNotification.php:390-395
  'deal_lost.title': {},
  'deal_lost.body': { subject: 'Acme A.Ş.', amount: '₺12.500,00' },
  // backend/app/Notifications/DealWonNotification.php:481-486
  'deal_won.title': {},
  'deal_won.body': { subject: 'Acme A.Ş.', amount: '₺12.500,00' },
  // backend/app/Notifications/DealStageChangedNotification.php:440-445
  'deal_stage_changed.title': {},
  'deal_stage_changed.body': { deal_title: 'Yeni web sitesi', stage: 'Görüşme' },
  // backend/app/Notifications/TaskAssignedNotification.php:636-656 — `due_at` is `_at`-suffixed
  'task_assigned.title': {},
  'task_assigned.body': { title: 'Sözleşmeyi gönder' },
  'task_assigned.body_with_due': { title: 'Sözleşmeyi gönder', due_at: '2026-09-05T14:30:00Z' },
  // backend/app/Notifications/ChatMentionNotification.php:56-78 — `actor`/`conversation` come
  // from the same shared `params` array as the body excerpt (`array_filter` merges them), so
  // the title variants below carry only what their own titleKey interpolates.
  'chat_mention.title': { actor: 'Elif Yılmaz' },
  'chat_mention.title_in_group': { actor: 'Elif Yılmaz', conversation: 'Satış Ekibi' },
  'chat_mention.title_unknown_actor': {},
  'chat_mention.title_unknown_actor_in_group': { conversation: 'Satış Ekibi' },
  'chat_mention.body': { excerpt: 'Yarın toplantı var mı?' },
  'chat_mention.body_no_content': {},
  // backend/app/Notifications/LeadAssignedNotification.php:521-536
  'lead_assigned.title': {},
  'lead_assigned.body': { person: 'Ahmet Demir' },
  'lead_assigned.body_with_company': { person: 'Ahmet Demir', company: 'Demir İnşaat' },
  // backend/app/Notifications/QuoteStatusChangedNotification.php:574-609 — only `body_default`
  // (the practically-unreachable fallback branch) interpolates `status`; the five named-status
  // bodies interpolate `quote_number` alone.
  'quote_status_changed.title': {},
  'quote_status_changed.body_draft': { quote_number: 'Q-0001' },
  'quote_status_changed.body_sent': { quote_number: 'Q-0001' },
  'quote_status_changed.body_accepted': { quote_number: 'Q-0001' },
  'quote_status_changed.body_rejected': { quote_number: 'Q-0001' },
  'quote_status_changed.body_expired': { quote_number: 'Q-0001' },
  'quote_status_changed.body_default': { quote_number: 'Q-0001', status: 'on_hold' },
  // backend/app/Notifications/TaskReminderNotification.php:698-712
  'task_reminder.title': {},
  'task_reminder.body': { title: 'Sözleşmeyi gönder' },
  'task_reminder.body_with_label': { title: 'Sözleşmeyi gönder', label: 'Fırsat: Yeni web sitesi' },
  // backend/app/Notifications/TicketAssignedNotification.php:744-754
  'ticket_assigned.title': {},
  'ticket_assigned.body': { ticket_number: 'T-0001', subject: 'Giriş yapamıyorum' },
  // backend/app/Notifications/TicketSlaBreachedNotification.php:788-799
  'ticket_sla_breached.title': {},
  'ticket_sla_breached.body': { ticket_number: 'T-0001', subject: 'Giriş yapamıyorum', minutes: '15' },
  // backend/app/Notifications/TicketSlaWarningNotification.php:832-843
  'ticket_sla_warning.title': {},
  'ticket_sla_warning.body': { ticket_number: 'T-0001', subject: 'Giriş yapamıyorum', minutes: '15' },
}

function notificationRow(titleKey: string, bodyKey: string, params: Record<string, string>): LocalRow {
  return {
    client_id: 'notif-catalog-1',
    data: {
      type: 'catalog.probe', // irrelevant to resolution; only echoed onto `Notification.type`
      title_key: titleKey,
      body_key: bodyKey,
      params,
    },
    read_at: null,
    created_at: '2026-08-30T12:00:00Z',
  }
}

const referenceCatalog = catalogMessageTypes(loadCatalog('tr'))
const messagePairs = Object.entries(referenceCatalog).flatMap(([type, fields]) =>
  fields.map((field) => `${type}.${field}`)
)

beforeAll(() => {
  // Real bundles for all four languages, loaded into the app's actual `i18next` singleton.
  // `tr` is already present (eager-loaded by `frontend/src/i18n/index.ts`), but re-adding it
  // here costs nothing and keeps this file's setup independent of that module's bootstrap
  // order. `deep`/`overwrite` true mirrors `ensureBundlesLoaded()`'s own call.
  for (const locale of LOCALES) {
    i18n.addResourceBundle(locale, 'notifications', loadCatalog(locale), true, true)
  }
})

describe('mapNotification — real 4-language notification catalog (defter O68)', () => {
  it('sanity: the catalog this file walks is non-trivial', () => {
    // A regression that emptied `catalogMessageTypes()` (e.g. a `CHROME_KEYS` typo swallowing
    // every real type) would otherwise make every `it.each` below vacuously pass on zero cases.
    assert.ok(messagePairs.length >= 12, `expected at least 12 type.field pairs, got ${messagePairs.length}`)
  })

  describe.each(LOCALES)('locale = %s', (locale) => {
    beforeAll(async () => {
      await i18n.changeLanguage(locale)
    })

    it.each(messagePairs)('%s resolves to real, interpolated text (not the raw key)', (pair) => {
      const rawKey = `notifications.${pair}`
      const params = PARAM_FIXTURES[pair] ?? {}

      const notification = mapNotification(notificationRow(rawKey, rawKey, params))

      for (const [field, text] of [
        ['title', notification.title],
        ['body', notification.body],
      ] as const) {
        // (a) never the raw key.
        assert.notEqual(text, rawKey, `[${locale}] ${pair} (${field}): resolved to the raw key, not a translation`)
        assert.ok(
          !text.startsWith('notifications.'),
          `[${locale}] ${pair} (${field}): output still looks like a raw dot-path key: "${text}"`
        )
        // (b) no leftover `{{...}}` — every param the template needs was actually supplied and
        // interpolated.
        assert.ok(
          !/\{\{\s*[\w.]+\s*\}\}/.test(text),
          `[${locale}] ${pair} (${field}): unfilled interpolation hole in "${text}" — PARAM_FIXTURES['${pair}'] is missing a param the template needs`
        )
      }
    })
  })
})
