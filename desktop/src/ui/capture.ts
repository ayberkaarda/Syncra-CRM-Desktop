// What one quick capture becomes on the wire — `SYNCDESKTOP.md` §6.4 item 3, F5-3.
//
// Separated from `QuickCapture.tsx` so the composition is a pure function with no React and no
// `@/components/ui` behind it: this is the part that has to be RIGHT (a payload the server will
// refuse sits in the outbox being rejected forever), and it is the part a Node-environment
// `vitest` run can actually exercise. `capture.test.ts` is that run.
//
// ## The four types are three entities
//
// `note` is not an entity. In this CRM a note is an `activity` whose `type` is `note`
// (`backend/app/Http/Requests/Activities/StoreActivityRequest.php`: `type` is one of
// `call|meeting|email|note`), so the `note` capture type is a preset over the same table
// rather than a destination of its own.
//
// ## The server's `required` rules, enforced before the queue
//
//   * lead     — `first_name`, `last_name`, `source`   (`StoreLeadRequest`)
//   * task     — `title`                                (`StoreTaskRequest`)
//   * activity — `type`, `subject`, `occurred_at`, and `occurred_at` must not be in the future
//                (`StoreActivityRequest::withValidator`)
//
// A capture that cannot satisfy them returns `null`, which is what disables the save button.
import type { EntityName } from '../platform/data/engine'

/** The four capture types §6.4 names. */
export type CaptureType = 'lead' | 'note' | 'task' | 'activity'

/** In the order the popup shows them. */
export const CAPTURE_TYPES: readonly CaptureType[] = ['lead', 'note', 'task', 'activity']

/** `StoreActivityRequest` — the `type` enum, in the order the backend lists it. */
export const ACTIVITY_TYPES = ['call', 'meeting', 'email', 'note'] as const

export type ActivityType = (typeof ACTIVITY_TYPES)[number]

/**
 * `StoreLeadRequest::SOURCES`' neutral member.
 *
 * A lead needs a source and a capture window has no business asking for one — the point is a
 * name and a phone number in four seconds. `other` is a value the BACKEND defines, not a label
 * invented here, and it is the honest answer: the source really is unknown at capture time.
 */
export const DEFAULT_LEAD_SOURCE = 'other'

/** Everything the form holds. `now` is injected so the composer has no hidden clock. */
export interface CaptureForm {
  title: string
  body: string
  email: string
  phone: string
  activityType: ActivityType
  now: () => Date
}

/** The form's initial (and post-save) state, minus the clock. */
export const EMPTY_CAPTURE: Omit<CaptureForm, 'now'> = {
  title: '',
  body: '',
  email: '',
  phone: '',
  activityType: 'call',
}

/** One composed mutation, ready for `createRow`. */
export interface Capture {
  entity: EntityName
  payload: Record<string, unknown>
}

/**
 * Split a single free-text name into the `first_name` / `last_name` the server requires.
 *
 * Two name fields in a capture window would be asking the user to do the parsing, which is the
 * opposite of quick. Everything before the last space is the first name, the last word is the
 * surname, and runs of whitespace collapse first.
 *
 * A single word becomes BOTH names. That looks odd written down and is still the right call:
 * `StoreLeadRequest` marks `last_name` `required`, so an empty one produces an outbox entry the
 * server will reject on every push for the rest of time. A duplicated word is a record the user
 * can fix in one edit; an unpushable row is a queue that never drains.
 */
export function splitName(full: string): { first_name: string; last_name: string } | null {
  const trimmed = full.trim().replace(/\s+/g, ' ')
  if (trimmed === '') return null
  const cut = trimmed.lastIndexOf(' ')
  if (cut === -1) return { first_name: trimmed, last_name: trimmed }
  return { first_name: trimmed.slice(0, cut), last_name: trimmed.slice(cut + 1) }
}

/** The mutation `type` and `form` describe, or `null` when the form cannot make a valid one. */
export function composeCapture(type: CaptureType, form: CaptureForm): Capture | null {
  const title = form.title.trim()
  const body = form.body.trim()

  switch (type) {
    case 'lead': {
      const name = splitName(title)
      if (name === null) return null
      return {
        entity: 'lead',
        payload: {
          ...name,
          email: form.email.trim() || null,
          phone: form.phone.trim() || null,
          source: DEFAULT_LEAD_SOURCE,
          notes: body || null,
          custom_fields: {},
          tag_ids: [],
        },
      }
    }
    case 'task': {
      if (title === '') return null
      return { entity: 'task', payload: { title, description: body || null } }
    }
    case 'note':
    case 'activity': {
      if (title === '') return null
      return {
        entity: 'activity',
        payload: {
          type: type === 'note' ? 'note' : form.activityType,
          subject: title,
          body: body || null,
          // Required, and refused if it is in the future: an activity records something that
          // ALREADY happened (a future one would be a task). "Now" is the only answer a
          // capture window can give without asking a question it exists to avoid.
          occurred_at: form.now().toISOString(),
        },
      }
    }
  }
}
