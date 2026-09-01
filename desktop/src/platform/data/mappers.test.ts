// Tests for `mapTicket`'s SLA field consumption (defter O2 / KARAR A26).
//
// `backend/app/Services/Sync/SyncPullService.php::attachTicketSla()` attaches four
// server-computed fields to every `tickets` pull row — `sla_remaining_seconds`,
// `sla_total_seconds`, `sla_target_hours`, `sla_breached` — using the SAME `SlaService`
// methods `TicketResource` already uses for the web client (`backend/tests/Feature/Sync/
// SyncPullTicketSlaTest.php` proves pull and `TicketResource` never diverge). This file proves
// `mapTicket` (in `./mappers.ts`) reads those four fields into the `Ticket` DTO instead of
// dropping them, AND — the actual point of this test, per KARAR A23 — that it does not collapse
// `null` ("no SLA / clock stopped") and `0` ("clock still running, deadline reached") into each
// other. A naive `?? 0` or truthy check would do exactly that.
//
// Runner: `vitest` (`desktop/vitest.config.ts`, `npm test` in `desktop/`) — defter O53. The
// file previously declared `node:test` and was executed by hand through a scratchpad loader
// shim, so a break in it reached nobody. `describe`/`it` now come from the runner that the
// gate actually invokes; the assertions stay on `node:assert/strict`, which vitest runs
// unchanged.
import assert from 'node:assert/strict'
import { afterEach, beforeEach, describe, it, vi } from 'vitest'

import i18n from '@/i18n'

import { mapMessage, mapNotification, mapTask, mapTicket } from './mappers'
import { EMPTY_REFS } from './refs'
import type { LocalRow } from './engine'
import type { TaskRefs, TicketRefs } from './mappers'

const refs: TicketRefs = { companies: EMPTY_REFS, contacts: EMPTY_REFS, users: EMPTY_REFS, tags: EMPTY_REFS }

/** A minimal but complete `tickets` mirror row, overridable per test. */
function ticketRow(overrides: Partial<LocalRow> = {}): LocalRow {
  return {
    client_id: 'ticket-1',
    server_id: 1,
    ticket_number: 'T-0001',
    subject: 'Cannot log in',
    description: 'User reports a 500 on login.',
    priority: 'high',
    status: 'open',
    category: null,
    contact_id: null,
    contact_client_id: null,
    company_id: null,
    company_client_id: null,
    assigned_to: null,
    assigned_to_client_id: null,
    created_by: null,
    created_by_client_id: null,
    sla_due_at: '2026-09-01T00:00:00Z',
    first_response_at: null,
    resolved_at: null,
    closed_at: null,
    sla_paused_seconds: 0,
    tags: [],
    custom_fields: {},
    created_at: '2026-08-01T00:00:00Z',
    updated_at: '2026-08-01T00:00:00Z',
    ...overrides,
  }
}

describe('mapTicket — SLA field consumption (KARAR A26)', () => {
  it('reads all four server-computed SLA fields onto the DTO (positive case)', () => {
    const row = ticketRow({
      sla_remaining_seconds: 3600,
      sla_total_seconds: 72000,
      sla_target_hours: 20,
      sla_breached: false,
    })

    const ticket = mapTicket(row, refs)

    assert.equal(ticket.sla_remaining_seconds, 3600)
    assert.equal(ticket.sla_total_seconds, 72000)
    assert.equal(ticket.sla_target_hours, 20)
    assert.equal(ticket.sla_breached, false)
  })

  it('preserves a fractional sla_target_hours (SlaService::targetHoursForTicket rounds to 2dp)', () => {
    const row = ticketRow({
      sla_remaining_seconds: 100,
      sla_total_seconds: 200,
      sla_target_hours: 4.25,
      sla_breached: false,
    })

    assert.equal(mapTicket(row, refs).sla_target_hours, 4.25)
  })

  it('NEGATIVE CONTROL 1: sla_remaining_seconds: null stays null, never becomes 0 (resolved ticket, clock stopped)', () => {
    const row = ticketRow({
      status: 'resolved',
      resolved_at: '2026-08-05T00:00:00Z',
      sla_remaining_seconds: null,
      sla_total_seconds: 14400,
      sla_target_hours: 4,
      sla_breached: true, // historically breached — resolved after sla_due_at
    })

    const ticket = mapTicket(row, refs)

    assert.equal(ticket.sla_remaining_seconds, null)
    assert.notEqual(ticket.sla_remaining_seconds, 0)
    // sla_breached itself must NOT be derived from remaining-seconds being null/0 — it is its
    // own wire field, read independently.
    assert.equal(ticket.sla_breached, true)
  })

  it('NEGATIVE CONTROL 2: sla_remaining_seconds: 0 stays 0, never becomes null ("SLA yok")', () => {
    const row = ticketRow({
      sla_remaining_seconds: 0,
      sla_total_seconds: 72000,
      sla_target_hours: 20,
      sla_breached: false,
    })

    const ticket = mapTicket(row, refs)

    assert.equal(ticket.sla_remaining_seconds, 0)
    assert.notEqual(ticket.sla_remaining_seconds, null)
  })

  it('a ticket without an sla_due_at falls back exactly like the server does: remaining null, breached false', () => {
    // Mirrors SyncPullTicketSlaTest::test_a_ticket_without_an_sla_due_date_falls_back_to_the_priority_target
    const row = ticketRow({
      priority: 'low',
      sla_due_at: null,
      sla_remaining_seconds: null,
      sla_total_seconds: 72 * 3600,
      sla_target_hours: 72,
      sla_breached: false,
    })

    const ticket = mapTicket(row, refs)

    assert.equal(ticket.sla_due_at, null)
    assert.equal(ticket.sla_remaining_seconds, null)
    assert.equal(ticket.sla_total_seconds, 72 * 3600)
    assert.equal(ticket.sla_target_hours, 72)
    assert.equal(ticket.sla_breached, false)
  })

  it('falls back to 0/null (not a crash) when a pull row predates KARAR A26 and carries no SLA fields at all', () => {
    const row = ticketRow() // no sla_remaining_seconds / sla_total_seconds / sla_target_hours / sla_breached keys

    const ticket = mapTicket(row, refs)

    assert.equal(ticket.sla_total_seconds, 0)
    assert.equal(ticket.sla_remaining_seconds, null)
    assert.equal(ticket.sla_target_hours, 0)
    assert.equal(ticket.sla_breached, false)
  })

  it('sla_paused still mirrors ticket status locally (unchanged behavior, no server field for it)', () => {
    const pending = mapTicket(ticketRow({ status: 'pending' }), refs)
    const open = mapTicket(ticketRow({ status: 'open' }), refs)

    assert.equal(pending.sla_paused, true)
    assert.equal(open.sla_paused, false)
  })
})

/**
 * `mapNotification` — defter O61 / finding B3.
 *
 * `title`/`body` used to fall back straight to the raw `title_key`/`body_key` (e.g.
 * `notifications.deal_assigned.title`), because the frontend's i18n catalogue is a different
 * tree from the Laravel one the server resolves against — see `resolveNotificationText()`'s
 * docblock (`frontend/src/features/notifications/notificationText.ts`) for the full picture,
 * including why the real 4-language sentence catalogue does not exist yet and is out of this
 * task's scope (`frontend/src/i18n/**`). These tests inject a small fake `notifications` bundle
 * via `i18n.addResourceBundle` — the same shape (namespace `notifications`, `{{param}}`
 * interpolation) that follow-up catalogue would use — to prove the RESOLUTION MECHANISM (key
 * lookup, param interpolation, `_at` date formatting, fallback chain) is correct independent of
 * whether that catalogue has shipped.
 */
describe('mapNotification — title_key/body_key resolution (defter O61 / B3)', () => {
  // Pin the language. These tests seed a `tr` bundle and assert on Turkish output, so they only
  // mean anything while `tr` is the active language — and the active language is a property of
  // the i18next singleton, not of this file. CI proved the difference: it resolved the English
  // catalogue and read back "— due 5 Sept 2026" where the assertion wanted "— vade …", so the
  // suite was green on a Turkish-defaulting machine and red everywhere else.
  beforeEach(async () => {
    await i18n.changeLanguage('tr')
  })

  function notificationRow(data: Record<string, unknown>, overrides: Partial<LocalRow> = {}): LocalRow {
    return {
      client_id: 'notif-1',
      data,
      read_at: null,
      created_at: '2026-08-30T12:00:00Z',
      ...overrides,
    }
  }

  it('POSITIVE: a key-mode row (title_key/body_key + params) resolves to translated, interpolated text', () => {
    i18n.addResourceBundle(
      'tr',
      'notifications',
      {
        deal_assigned: {
          title: 'Size bir fırsat atandı',
          body: '{{subject}} — {{amount}}',
        },
      },
      true,
      true
    )

    const row = notificationRow({
      type: 'deal.assigned',
      title_key: 'notifications.deal_assigned.title',
      body_key: 'notifications.deal_assigned.body',
      params: { subject: 'Acme A.Ş.', amount: '₺12.500,00' },
      link: '/deals/42',
      meta: { deal_id: 42 },
    })

    const notification = mapNotification(row)

    assert.equal(notification.title, 'Size bir fırsat atandı')
    assert.equal(notification.body, 'Acme A.Ş. — ₺12.500,00')
    // Never the raw key — that is exactly the bug this closes.
    assert.notEqual(notification.title, 'notifications.deal_assigned.title')
  })

  it('POSITIVE: a `_at`-suffixed param is reformatted at read time, not passed through as raw ISO-8601', () => {
    i18n.addResourceBundle(
      'tr',
      'notifications',
      {
        task_assigned: {
          body_seeded_by_this_test_only: '{{title}} — vade {{due_at}}',
        },
      },
      true,
      true
    )

    const row = notificationRow({
      type: 'task.assigned',
      title_key: 'notifications.task_assigned.title', // unresolved on purpose; only body_key is under test here
      body_key: 'notifications.task_assigned.body_seeded_by_this_test_only',
      params: { title: 'Sözleşmeyi gönder', due_at: '2026-09-05T14:30:00Z' },
    })

    const notification = mapNotification(row)

    assert.ok(!notification.body.includes('2026-09-05T14:30:00Z'), 'the raw ISO string must not leak through')
    assert.ok(notification.body.startsWith('Sözleşmeyi gönder — vade '), notification.body)
  })

  it('NEGATIVE CONTROL 1: an unresolved key falls back to a generic translated label, never the raw key', () => {
    const row = notificationRow({
      type: 'deal.assigned',
      title_key: 'notifications.made_up_future_type.title',
      body_key: 'notifications.made_up_future_type.body',
      params: { subject: 'Acme A.Ş.' },
    })

    const notification = mapNotification(row)

    assert.notEqual(notification.title, 'notifications.made_up_future_type.title')
    assert.notEqual(notification.body, 'notifications.made_up_future_type.body')
    // `desktop:entities.notification` (tr) — already-shipped, already-translated, generic.
    assert.equal(notification.title, 'Bildirim')
  })

  it('NEGATIVE CONTROL 2: a legacy plain-text row (no title_key) is unaffected — no regression', () => {
    const row = notificationRow({
      type: 'deal.assigned',
      title: 'A deal was assigned to you',
      body: 'Acme Inc — $12,500.00',
    })

    const notification = mapNotification(row)

    assert.equal(notification.title, 'A deal was assigned to you')
    assert.equal(notification.body, 'Acme Inc — $12,500.00')
  })
})

/**
 * `mapNotification` — the `data` column arrives as JSON **text**, not as an object.
 *
 * The tests above hand `data` over as a plain object, which is the shape a fixture finds
 * convenient and NOT the shape the mirror stores. `notifications.data` is a `TEXT` column
 * (`migrations/0001_init.sql`), and `Entity::Notification` lists no `embedded` columns
 * (`crates/syncra-sync/src/db/schema.rs`), so `row_to_json()` re-parses nothing and the webview
 * receives the raw JSON string. `mapNotification` used to accept only the object, so every real
 * notification resolved to `title: ''` / `body: ''` — an entirely blank in-app list, and a
 * native-toast producer whose empty-text guard then discarded the lot.
 *
 * These cases therefore pass `data` exactly as the mirror holds it: a string.
 */
describe('mapNotification — `data` as the mirror actually stores it (JSON text)', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('tr')
  })

  /** A `notifications` row in mirror shape: `data` is JSON text, `created_at` has no zone. */
  function mirrorRow(data: Record<string, unknown>, overrides: Partial<LocalRow> = {}): LocalRow {
    return {
      client_id: '5c2f4a1e-9d3b-4f7a-8c11-6de0a2f9b100',
      data: JSON.stringify(data),
      read_at: null,
      created_at: '2026-09-01 07:58:01',
      ...overrides,
    }
  }

  it('POSITIVE: a plain-text row stored as JSON text resolves its title and body', () => {
    const notification = mapNotification(
      mirrorRow({
        type: 'deal.assigned',
        title: 'A deal was assigned to you',
        body: 'Acme Inc — $12,500.00',
        link: '/deals/42',
        meta: { deal_id: 42 },
      })
    )

    assert.equal(notification.title, 'A deal was assigned to you')
    assert.equal(notification.body, 'Acme Inc — $12,500.00')
    assert.equal(notification.type, 'deal.assigned')
    assert.equal(notification.link, '/deals/42')
    assert.deepEqual(notification.meta, { deal_id: 42 })
  })

  it('POSITIVE: a key-mode row stored as JSON text still resolves through the i18n catalogue', () => {
    i18n.addResourceBundle(
      'tr',
      'notifications',
      { deal_assigned: { title: 'Size bir fırsat atandı', body: '{{subject}} — {{amount}}' } },
      true,
      true
    )

    const notification = mapNotification(
      mirrorRow({
        type: 'deal.assigned',
        title_key: 'notifications.deal_assigned.title',
        body_key: 'notifications.deal_assigned.body',
        params: { subject: 'Acme A.Ş.', amount: '₺12.500,00' },
      })
    )

    assert.equal(notification.title, 'Size bir fırsat atandı')
    assert.equal(notification.body, 'Acme A.Ş. — ₺12.500,00')
  })

  it('NEGATIVE CONTROL: undecodable or non-object `data` degrades to empty, it does not throw', () => {
    for (const data of ['{"type":"deal.assigned",', '', 'null', '[1,2,3]', '"a string"', null]) {
      const notification = mapNotification({
        client_id: '5c2f4a1e-9d3b-4f7a-8c11-6de0a2f9b101',
        data,
        read_at: null,
        created_at: '2026-09-01 07:58:01',
      })

      // A generic translated label, never a crash and never a raw key.
      assert.equal(notification.type, '')
      assert.equal(notification.link, '')
      assert.deepEqual(notification.meta, {})
      assert.equal(typeof notification.title, 'string')
    }
  })

  it('the id is the row `client_id` — protocol §6.1 P12 makes it the server id too', () => {
    const notification = mapNotification(mirrorRow({ title: 'x', body: 'y' }))
    assert.equal(notification.id, '5c2f4a1e-9d3b-4f7a-8c11-6de0a2f9b100')
  })
})

/**
 * `mapTask` — `is_overdue` reads `due_at` through `parseMirrorTimestamp`
 * (`platform/data/timestamps.ts`), not `Date.parse` directly.
 *
 * `tasks.due_at` is a `dateTime()` migration column, so a pulled row holds it in MySQL's own
 * text form — space-separated, no zone (`"2026-09-01 09:30:00"`) — exactly like
 * `notifications.created_at`. The instant is UTC (`APP_TIMEZONE=UTC`); `Date.parse` reads a
 * zone-less string as LOCAL time, so on UTC+3 a `due_at` that has not arrived yet gets read as
 * three hours EARLIER than it is and `is_overdue` flips to `true` at the wrong moment — the
 * same class of bug `notifications`' toast timing had.
 *
 * The clock is faked (not just the zone) so "now" is a fixed, known instant and the boundary
 * case is deterministic regardless of when this suite actually runs.
 */
describe('mapTask — is_overdue reads mirror-shaped `due_at` correctly', () => {
  const realTz = process.env.TZ
  /** "Now", as the mirror would spell it: 2026-09-01T09:00:00Z. */
  const NOW = Date.UTC(2026, 8, 1, 9, 0, 0)

  beforeEach(() => {
    process.env.TZ = 'Asia/Istanbul'
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
    process.env.TZ = realTz
  })

  const taskRefs: TaskRefs = { users: EMPTY_REFS, morphs: new Map() }

  function taskRow(overrides: Partial<LocalRow> = {}): LocalRow {
    return {
      client_id: 'task-1',
      server_id: 1,
      title: 'Follow up with Acme',
      description: null,
      due_at: null,
      reminder_at: null,
      priority: 'normal',
      status: 'pending',
      completed_at: null,
      assigned_to: null,
      assigned_to_client_id: null,
      created_by: null,
      created_by_client_id: null,
      taskable_type: null,
      taskable_id: null,
      created_at: '2026-08-01T00:00:00Z',
      updated_at: '2026-08-01T00:00:00Z',
      ...overrides,
    }
  }

  it('the host really is UTC+3 — otherwise the cases below prove nothing', () => {
    assert.equal(new Date(2026, 8, 1, 12, 0, 0).getTimezoneOffset(), -180)
  })

  it('a mirror-shaped `due_at` 30 minutes in the future is NOT overdue', () => {
    // Correct UTC reading: 2026-09-01T09:30:00Z, 30 minutes after `NOW`.
    const task = mapTask(taskRow({ due_at: '2026-09-01 09:30:00' }), taskRefs)
    assert.equal(task.is_overdue, false)
  })

  // NEGATIVE CONTROL, spelled out: read as LOCAL Istanbul time (UTC+3), "09:30:00" becomes
  // 06:30:00Z — two and a half hours BEFORE `NOW` — which is exactly the wrong answer a naive
  // `Date.parse(row.due_at)` would give for the case directly above.
  it('NEGATIVE CONTROL: the naive local-time reading of the same value would already be in the past', () => {
    const buggyInstant = Date.parse('2026-09-01 09:30:00')
    assert.equal(buggyInstant, Date.UTC(2026, 8, 1, 6, 30, 0))
    assert.ok(buggyInstant < NOW, 'the naive reading must land before "now" for this to be a real negative control')
  })

  it('a mirror-shaped `due_at` 30 minutes in the past IS overdue', () => {
    const task = mapTask(taskRow({ due_at: '2026-09-01 08:30:00' }), taskRefs)
    assert.equal(task.is_overdue, true)
  })

  it('a completed task is never overdue, even with a past `due_at`', () => {
    const task = mapTask(taskRow({ due_at: '2026-09-01 08:30:00', status: 'completed' }), taskRefs)
    assert.equal(task.is_overdue, false)
  })

  it('a task with no `due_at` is never overdue', () => {
    const task = mapTask(taskRow({ due_at: null }), taskRefs)
    assert.equal(task.is_overdue, false)
  })
})

/**
 * `mapMessage` — `attachment` reconstruction from the four flattened `attachment_*` fields
 * (KARAR A29, defter O90; `wire-fixtures/pull/message.row.json`).
 *
 * Before this fix `mapMessage` hard-coded `attachment: null`, so every attached-message bubble
 * rendered with a timestamp and nothing else — no thumbnail, no filename — for every device,
 * regardless of whether the attachment was an image or not. See `mapAttachment`'s docblock in
 * `./mappers.ts` for why `is_image` is unconditionally forced to `false` rather than read off
 * `attachment_is_image` (desktop's bearer-auth `<img>` request would 401).
 */
describe('mapMessage — attachment reconstruction (KARAR A29)', () => {
  /** A minimal `messages` mirror row, overridable per test. */
  function messageRow(overrides: Partial<LocalRow> = {}): LocalRow {
    return {
      client_id: 'msg-1',
      server_id: 1,
      conversation_id: 1,
      user_id: 1,
      user_client_id: null,
      body: 'Destek talebi çözüldü, müşteri onayladı.',
      type: 'file',
      edited_at: null,
      created_at: '2026-09-01 11:03:31',
      deleted_at: null,
      ...overrides,
    }
  }

  it('POSITIVE: the four wire fields build an Attachment DTO, with is_image forced false', () => {
    const row = messageRow({
      attachment_id: 1,
      attachment_name: 'ekran-goruntusu.png',
      attachment_mime: 'image/png',
      attachment_size: 7079718,
      attachment_is_image: true, // the wire says true — the DTO must NOT say true.
    })

    const message = mapMessage(row, EMPTY_REFS)

    assert.ok(message.attachment, 'attachment must not be null when all four fields are present')
    assert.equal(message.attachment?.id, 1)
    assert.equal(message.attachment?.original_name, 'ekran-goruntusu.png')
    assert.equal(message.attachment?.mime_type, 'image/png')
    assert.equal(message.attachment?.size, 7079718)
    assert.equal(message.attachment?.url, '/api/attachments/1')
    // The bound: is_image is NEVER re-derived and NEVER forwarded from the wire — see A29.
    assert.equal(message.attachment?.is_image, false)
  })

  it('a non-image attachment (e.g. a PDF) also maps, still with is_image false', () => {
    const row = messageRow({
      attachment_id: 2,
      attachment_name: 'Sözleşme_Taslağı.pdf',
      attachment_mime: 'application/pdf',
      attachment_size: 204800,
      attachment_is_image: false,
    })

    const message = mapMessage(row, EMPTY_REFS)

    assert.equal(message.attachment?.original_name, 'Sözleşme_Taslağı.pdf')
    assert.equal(message.attachment?.mime_type, 'application/pdf')
    assert.equal(message.attachment?.is_image, false)
  })

  it('NULL/missing attachment_* fields (a message with no attachment, or a pre-A29 row) -> attachment: null, no throw', () => {
    const row = messageRow({
      attachment_id: null,
      attachment_name: null,
      attachment_mime: null,
      attachment_size: null,
      attachment_is_image: null,
    })

    assert.doesNotThrow(() => mapMessage(row, EMPTY_REFS))
    const message = mapMessage(row, EMPTY_REFS)
    assert.equal(message.attachment, null)
  })

  it('a row that never carried the four keys at all (undefined, not null) also maps to attachment: null', () => {
    const row = messageRow() // no attachment_* keys present on the object

    assert.doesNotThrow(() => mapMessage(row, EMPTY_REFS))
    assert.equal(mapMessage(row, EMPTY_REFS).attachment, null)
  })

  it('NEGATIVE CONTROL: a tombstone (deleted_at set) maps to attachment: null even when the four fields are present', () => {
    const row = messageRow({
      deleted_at: '2026-09-01 12:00:00',
      attachment_id: 1,
      attachment_name: 'ekran-goruntusu.png',
      attachment_mime: 'image/png',
      attachment_size: 7079718,
      attachment_is_image: true,
    })

    const message = mapMessage(row, EMPTY_REFS)

    assert.equal(message.attachment, null)
    assert.equal(message.deleted_at, '2026-09-01 12:00:00')
  })
})
