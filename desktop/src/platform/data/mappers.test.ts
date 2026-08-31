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
import { describe, it } from 'vitest'

import i18n from '@/i18n'

import { mapNotification, mapTicket } from './mappers'
import { EMPTY_REFS } from './refs'
import type { LocalRow } from './engine'
import type { TicketRefs } from './mappers'

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
          body_with_due: '{{title}} — vade {{due_at}}',
        },
      },
      true,
      true
    )

    const row = notificationRow({
      type: 'task.assigned',
      title_key: 'notifications.task_assigned.title', // unresolved on purpose; only body_key is under test here
      body_key: 'notifications.task_assigned.body_with_due',
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
