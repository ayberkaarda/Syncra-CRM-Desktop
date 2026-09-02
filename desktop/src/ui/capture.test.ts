// `capture.ts` — the payload one quick capture becomes (`SYNCDESKTOP.md` §6.4 item 3, F5-3).
//
// What is worth testing here is not the form, it is the CONTRACT with the server. Every write
// this popup makes goes into the outbox first and is validated by Laravel later, so a payload
// missing a `required` field is not a form error the user sees — it is a row that sits in the
// queue being rejected on every push, forever. These tests are the `required` rules of
// `StoreLeadRequest`, `StoreTaskRequest` and `StoreActivityRequest`, transcribed.
import { describe, expect, it } from 'vitest'

import {
  ACTIVITY_TYPES,
  CAPTURE_TYPES,
  composeCapture,
  DEFAULT_LEAD_SOURCE,
  EMPTY_CAPTURE,
  splitName,
  type CaptureForm,
  type CaptureType,
} from './capture'

/** A fixed clock, so `occurred_at` is an assertable value rather than "whenever". */
const FIXED_NOW = new Date('2026-09-01T10:30:00.000Z')

function form(overrides: Partial<Omit<CaptureForm, 'now'>> = {}): CaptureForm {
  return { ...EMPTY_CAPTURE, ...overrides, now: () => FIXED_NOW }
}

describe('splitName', () => {
  it('puts the last word in last_name and everything before it in first_name', () => {
    expect(splitName('Ada Lovelace')).toEqual({ first_name: 'Ada', last_name: 'Lovelace' })
    expect(splitName('Jean Luc Picard')).toEqual({ first_name: 'Jean Luc', last_name: 'Picard' })
  })

  it('collapses whitespace instead of producing empty name parts', () => {
    expect(splitName('  Ada   Lovelace  ')).toEqual({ first_name: 'Ada', last_name: 'Lovelace' })
  })

  // The deliberate oddity, documented on the function: `last_name` is `required` server-side,
  // so an empty one would make the outbox entry permanently unpushable.
  it('uses a single word for both names rather than leaving last_name empty', () => {
    expect(splitName('Cher')).toEqual({ first_name: 'Cher', last_name: 'Cher' })
  })

  it('is null for a name that is only whitespace', () => {
    expect(splitName('')).toBeNull()
    expect(splitName('   ')).toBeNull()
  })
})

describe('composeCapture', () => {
  it('refuses every type when the one required text field is empty', () => {
    for (const type of CAPTURE_TYPES) {
      expect(composeCapture(type, form({ title: '   ' })), type).toBeNull()
    }
  })

  it('accepts every type once that field is filled', () => {
    for (const type of CAPTURE_TYPES) {
      expect(composeCapture(type, form({ title: 'something' })), type).not.toBeNull()
    }
  })

  // --- lead ---------------------------------------------------------------------------------

  it('composes a lead with the three fields StoreLeadRequest marks required', () => {
    const capture = composeCapture('lead', form({ title: 'Ada Lovelace' }))

    expect(capture?.entity).toBe('lead')
    expect(capture?.payload.first_name).toBe('Ada')
    expect(capture?.payload.last_name).toBe('Lovelace')
    expect(capture?.payload.source).toBe(DEFAULT_LEAD_SOURCE)
  })

  it("carries the lead's optional contact details, and null when they are blank", () => {
    const filled = composeCapture(
      'lead',
      form({ title: 'Ada Lovelace', email: ' ada@example.com ', phone: '+905550000000' }),
    )
    expect(filled?.payload.email).toBe('ada@example.com')
    expect(filled?.payload.phone).toBe('+905550000000')

    const bare = composeCapture('lead', form({ title: 'Ada Lovelace' }))
    expect(bare?.payload.email).toBeNull()
    expect(bare?.payload.phone).toBeNull()
  })

  // --- task ---------------------------------------------------------------------------------

  it('composes a task from the title alone', () => {
    const capture = composeCapture('task', form({ title: ' Call the supplier ', body: 'before 5' }))

    expect(capture?.entity).toBe('task')
    expect(capture?.payload.title).toBe('Call the supplier')
    expect(capture?.payload.description).toBe('before 5')
  })

  // --- note / activity ----------------------------------------------------------------------

  // The `note` type is not an entity: it is an `activity` with `type: 'note'` prefilled.
  it('writes a note as an activity of type note, whatever the activity picker says', () => {
    const capture = composeCapture('note', form({ title: 'They want a quote', activityType: 'call' }))

    expect(capture?.entity).toBe('activity')
    expect(capture?.payload.type).toBe('note')
    expect(capture?.payload.subject).toBe('They want a quote')
  })

  it('writes an activity with the type the user picked', () => {
    for (const activityType of ACTIVITY_TYPES) {
      const capture = composeCapture('activity', form({ title: 'Kickoff', activityType }))
      expect(capture?.payload.type).toBe(activityType)
    }
  })

  // `StoreActivityRequest` requires `occurred_at` AND refuses a future one.
  it('stamps an activity with the current instant, in ISO 8601', () => {
    const capture = composeCapture('activity', form({ title: 'Kickoff' }))

    expect(capture?.payload.occurred_at).toBe(FIXED_NOW.toISOString())
    expect(Date.parse(String(capture?.payload.occurred_at))).toBeLessThanOrEqual(
      FIXED_NOW.getTime(),
    )
  })

  // --- entity routing -----------------------------------------------------------------------

  it('routes the four capture types onto three writable entities', () => {
    const routed = Object.fromEntries(
      CAPTURE_TYPES.map((type: CaptureType) => [
        type,
        composeCapture(type, form({ title: 'x' }))?.entity,
      ]),
    )

    expect(routed).toEqual({
      lead: 'lead',
      note: 'activity',
      task: 'task',
      activity: 'activity',
    })
  })
})
