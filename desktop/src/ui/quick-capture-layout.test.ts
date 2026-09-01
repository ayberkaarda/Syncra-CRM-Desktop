// A structural regression test for the quick-capture layout bug measured live in the running
// app (CDP, 480x360 window): the Cancel/Save row sat inside the same `overflow-hidden` flex
// column as the rest of the form, positioned with `mt-auto`. On the Lead and Activity tabs the
// form's content (`scrollHeight 412`) was taller than the window's `clientHeight 358`, so
// `mt-auto` pushed the button row past the bottom of the clipped container — `top 357 / bottom
// 397`, entirely outside the visible area and untouchable by a pointer. Only Enter-to-submit
// still worked.
//
// ## Why this is a source-text structural assertion, not a rendered-DOM one
//
// `desktop/vitest.config.ts` runs this project with `environment: 'node'` and
// `include: ['src/**/*.test.ts']` (`.ts`, not `.tsx`) — deliberately, because every other test
// here is data-layer code with no DOM behind it. Neither `jsdom`/`happy-dom` nor
// `@testing-library/react` is a dependency anywhere in `desktop/package.json`. Actually
// rendering `QuickCapture` and reading `getBoundingClientRect()` — the only way to reproduce
// the bug exactly as it was measured — would mean adding a browser DOM environment and a
// rendering library to a project that has neither, for one component. That is an infrastructure
// change, not a bug fix, so it is out of scope here (SYNCDESKTOP §0.5 scope discipline).
//
// What CAN be proven without a DOM is the structural invariant that caused the bug: the button
// row must not be a descendant of the scrollable body. This test parses the actual JSX text of
// `QuickCapture.tsx` (matching `<div>`/`</div>` tags by depth — cheap, and exact for this file
// because every element in it is a `<div>`, a self-closing component, or a non-div tag with no
// nested divs of its own) and asserts:
//
//   1. the scrolling container (`overflow-y-auto`) exists and is a real element;
//   2. the action row (`data-testid="quick-capture-actions"`) exists, is a SIBLING of that
//      container — i.e. starts after the scrolling container's own matching `</div>` — not a
//      child of it;
//   3. the action row does not itself scroll or shrink (`shrink-0`, no `overflow-y-auto`),
//      so the flex layout reserves its height before the body claims the rest.
//
// `src-tauri/src/quick_capture.rs` already tests its own contracts this same way
// (`include_str!` + text assertions on `capabilities/default.json` and
// `vite.desktop.config.ts`) — the same shape of test as this file, for the same reason: no
// interpreter is available in that Rust test either.
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import { describe, expect, it } from 'vitest'

const SOURCE_PATH = fileURLToPath(new URL('./QuickCapture.tsx', import.meta.url))
// Comments are stripped before the div-matching below runs. `QuickCapture.tsx` documents this
// very layout with a `{/* ... */}` block that itself mentions `overflow-y-auto` in prose — left
// in, that text would be the FIRST match `divContaining` finds, well before the real tag.
// Comments are replaced with equal-length whitespace (not deleted) so every later character
// offset this test computes still lines up with the real file, for anyone reading a failure.
const source = readFileSync(SOURCE_PATH, 'utf-8').replace(/\{\/\*[\s\S]*?\*\/\}/g, (comment) =>
  ' '.repeat(comment.length),
)

/**
 * Find the `<div ...>` tag whose opening tag contains `marker`, and return the character range
 * `[start, end)` of that div's ENTIRE subtree — from `<div` to the end of its matching `</div>`,
 * found by counting nested `<div` / `</div>` occurrences from that point.
 *
 * Exact for this file: `QuickCapture.tsx` has no self-closing `<div />`, and no other tag name
 * beginning with "div".
 */
function divContaining(text: string, marker: string): { start: number; end: number } {
  const markerIndex = text.indexOf(marker)
  if (markerIndex === -1) throw new Error(`marker not found in source: ${marker}`)

  const start = text.lastIndexOf('<div', markerIndex)
  if (start === -1) throw new Error(`no enclosing <div for marker: ${marker}`)

  const tagPattern = /<div\b|<\/div>/g
  tagPattern.lastIndex = start
  let depth = 0
  let match: RegExpExecArray | null
  while ((match = tagPattern.exec(text)) !== null) {
    if (match[0] === '</div>') {
      depth -= 1
      if (depth === 0) return { start, end: match.index + match[0].length }
    } else {
      depth += 1
    }
  }
  throw new Error(`unbalanced <div> for marker: ${marker}`)
}

describe('QuickCapture layout — action row is outside the scrollable body', () => {
  const body = divContaining(source, 'overflow-y-auto')
  const actions = divContaining(source, 'data-testid="quick-capture-actions"')

  it('has exactly one scrollable body container', () => {
    // Sanity: the marker really is inside the div range it found (a false match would mean the
    // helper walked past an unrelated div and this test would be proving nothing).
    expect(source.slice(body.start, body.end)).toContain('overflow-y-auto')
  })

  it('places the action row as a sibling AFTER the scrollable body, not inside it', () => {
    // The bug this guards against: a button row nested inside the `overflow-y-auto` (or, as
    // measured live, `overflow-hidden`) container is clipped whenever the form's content is
    // taller than the window, however it is positioned inside that container (`mt-auto`
    // included). Being a later sibling is what makes the row immune to the body's content
    // height altogether.
    expect(actions.start).toBeGreaterThanOrEqual(body.end)
  })

  it('does not nest the scrollable body inside the action row (or vice versa)', () => {
    const bodyRange = source.slice(body.start, body.end)
    const actionsRange = source.slice(actions.start, actions.end)
    expect(bodyRange).not.toContain('data-testid="quick-capture-actions"')
    expect(actionsRange).not.toContain('overflow-y-auto')
  })

  it('reserves the action row height with shrink-0, so the body cannot squeeze it out', () => {
    const actionsRange = source.slice(actions.start, actions.end)
    const actionsTag = actionsRange.slice(0, actionsRange.indexOf('>') + 1)
    expect(actionsTag).toMatch(/\bshrink-0\b/)
  })

  it('gives the scrollable body min-h-0, the flex property that lets overflow-y-auto engage', () => {
    // Without `min-h-0`, a flex child's default `min-height: auto` keeps it from shrinking
    // below its content size in a column layout, so `overflow-y-auto` never gets tall enough
    // content to actually clip — the container just grows past the window instead.
    const bodyTag = source.slice(body.start, source.indexOf('>', body.start) + 1)
    expect(bodyTag).toMatch(/\bmin-h-0\b/)
  })
})
