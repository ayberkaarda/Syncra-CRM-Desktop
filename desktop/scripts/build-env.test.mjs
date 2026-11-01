// Release-host gate: the clear-text rule (security review, item 7).
//
// Run: `node --test scripts/build-env.test.mjs` (from `desktop/`). Uses only `node:test`, so it
// needs no package beyond Node itself.
import assert from 'node:assert/strict'
import { test } from 'node:test'

import { buildCsp, insecureSchemeReason, inspectReleaseTarget } from './build-env.mjs'

const production = {
  VITE_API_URL: 'https://crm.example.com',
  VITE_REVERB_SCHEME: 'https',
  VITE_REVERB_HOST: 'ws.example.com',
  VITE_REVERB_PORT: '443',
}

const fields = (violations) => violations.map((v) => v.field)

test('an https API with wss Reverb on public hosts is publishable', () => {
  assert.deepEqual(inspectReleaseTarget(production).violations, [])
})

test('a plain http API on a public host is refused', () => {
  const { violations } = inspectReleaseTarget({ ...production, VITE_API_URL: 'http://crm.example.com' })
  assert.ok(fields(violations).includes('VITE_API_URL'), JSON.stringify(violations))
  assert.match(violations.find((v) => v.field === 'VITE_API_URL').reason, /clear-text/)
})

test('ws Reverb on a public host is refused', () => {
  for (const scheme of [undefined, 'http', 'ws', 'HTTPS']) {
    const env = { ...production, VITE_REVERB_SCHEME: scheme }
    const { violations, reverbOrigin } = inspectReleaseTarget(env)
    assert.ok(reverbOrigin.startsWith('ws://'), reverbOrigin)
    assert.ok(fields(violations).includes('VITE_REVERB_SCHEME'), `${scheme}: ${JSON.stringify(violations)}`)
  }
})

test('every clear-text source in the emitted CSP is caught, not only the two variables', () => {
  const csp = buildCsp({ ...production, VITE_API_URL: 'http://crm.example.com' })
  assert.match(csp, /http:\/\/crm\.example\.com/)
  const { violations } = inspectReleaseTarget({ ...production, VITE_API_URL: 'http://crm.example.com' })
  // One violation per host, not one per CSP directive that repeats it.
  assert.equal(violations.filter((v) => v.value.includes('crm.example.com')).length, 1)
})

test('loopback stays a host problem, not a scheme problem, and is still refused in a release', () => {
  assert.equal(insecureSchemeReason('http://localhost:8000'), null)
  assert.equal(insecureSchemeReason('ws://127.0.0.1:8080'), null)
  const { violations } = inspectReleaseTarget({
    VITE_API_URL: 'http://localhost:8000',
    VITE_REVERB_HOST: 'localhost',
  })
  assert.ok(violations.some((v) => v.field === 'VITE_API_URL' && /localhost/.test(v.reason)))
  assert.ok(violations.some((v) => v.field === 'VITE_REVERB_HOST'))
})

test('the Tauri-internal ipc source is not mistaken for a clear-text host', () => {
  assert.equal(insecureSchemeReason('http://ipc.localhost'), null)
  assert.deepEqual(inspectReleaseTarget(production).violations, [])
})

test('the scheme rule itself', () => {
  assert.equal(insecureSchemeReason('https://crm.example.com'), null)
  assert.equal(insecureSchemeReason('wss://ws.example.com:443'), null)
  assert.notEqual(insecureSchemeReason('http://crm.example.com'), null)
  assert.notEqual(insecureSchemeReason('ws://ws.example.com:8080'), null)
  assert.notEqual(insecureSchemeReason('http://10.0.0.5'), null, 'a private address is not loopback')
})
