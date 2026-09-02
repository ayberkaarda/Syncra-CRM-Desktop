import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

// Desktop unit-test project — the runner the repo had no home for until defter O53.
//
// Two test files (`platform/data/comms.test.ts`, `platform/data/mappers.test.ts`) already
// existed and were executed by hand through a scratchpad loader shim, which meant nothing
// noticed when they broke. This config is that shim's replacement: it lives in the repo, it is
// reachable as `npm test`, and it is what the wire-fixture conformance suite
// (`platform/data/wire-fixtures.test.ts`) runs on.
//
// It deliberately does NOT reuse `vite.desktop.config.ts`. That config carries the Tauri dev
// server, the React and Tailwind plugins, `publicDir`, `envDir` and the HMR watch rules — none
// of which a Node-environment unit run needs, and `strictPort: 1420` would make two concurrent
// runs collide. The one thing the two MUST agree on is the `@` alias; it is mirrored here (and
// in `tsconfig.json`'s `paths`), and a drift between them shows up as an unresolved import on
// the very first test.
export default defineConfig({
  resolve: {
    // §4.2: `desktop/src` reaches the shared React app through `@`. Same target as
    // `vite.desktop.config.ts` -> `resolve.alias` and `tsconfig.json` -> `paths`.
    alias: {
      '@': fileURLToPath(new URL('../frontend/src', import.meta.url)),
    },
    // `frontend/` has its own `node_modules`; a module resolved twice would give the platform
    // modules a different copy of the auth store than the test holds.
    dedupe: ['react', 'react-dom'],
  },
  test: {
    // Only `desktop/src`. `frontend/src` has its own (web) suite and is not this runner's job.
    include: ['src/**/*.test.ts'],
    // No DOM: everything under test here is data-layer code — mappers, the search merge, the
    // mutation composers. A jsdom environment would add a dependency and hide nothing.
    environment: 'node',
  },
})
