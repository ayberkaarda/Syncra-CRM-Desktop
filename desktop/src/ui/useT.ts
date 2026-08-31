// Translation access for `desktop/src` — `SYNCDESKTOP.md` §0.6 ("UI metni hard-code YASAK").
//
// ## Why not `useTranslation` from `react-i18next`
//
// KARAR A1/A2 keep the two dependency trees apart: `desktop/package.json` carries the Tauri
// bindings, `frontend/package.json` carries the app's runtime. `react-i18next` lives in the
// second one, and Node resolution from `desktop/src/**` walks `desktop/node_modules` and then
// the repo root — neither of which has it — so a bare `import { useTranslation } from
// 'react-i18next'` does not resolve here. (`bridge/events.ts` documents the same fact for
// `@tanstack/react-query`, which is why its import of the query client is type-only.)
//
// Adding the package to `desktop/package.json` would install a SECOND i18next, and i18next is
// a singleton whose entire configuration — the eager `tr` bundle, the lazy `en/de/fr` chunks,
// the throwing `missingKeyHandler` — lives in `frontend/src/i18n/index.ts`. Two instances mean
// the desktop chrome reads an uninitialised one and renders raw keys.
//
// So this module reaches the app's OWN instance through the `@` alias (the same seam
// `platform/desktop.ts` uses for `@/components/ui`) and re-implements the one thing
// `useTranslation` gives that `i18n.t` does not: a re-render when the language changes.
import { useCallback, useSyncExternalStore } from 'react'

import i18n, { getIntlLocale } from '@/i18n'

/**
 * The subset of `TFunction` this shell uses. Keys are always fully qualified
 * (`'desktop:conflicts.title'`) because `defaultNS` is `common`.
 */
export type Translate = (key: string, options?: Record<string, unknown>) => string

/**
 * `languageChanged` alone is not enough: `setLocale()` adds the new bundle and only then calls
 * `changeLanguage`, but `applyUserLocale` and the opening gate can land a bundle for the
 * language that is ALREADY active (`frontend/src/i18n/index.ts`), which changes what `t`
 * returns without changing `i18n.language`. `loaded` covers that second case.
 */
function subscribe(onStoreChange: () => void): () => void {
  i18n.on('languageChanged', onStoreChange)
  i18n.on('loaded', onStoreChange)
  return () => {
    i18n.off('languageChanged', onStoreChange)
    i18n.off('loaded', onStoreChange)
  }
}

function readLanguage(): string {
  return i18n.resolvedLanguage ?? i18n.language
}

/** Reactive `t`, bound to the app's i18next instance. */
export function useT(): Translate {
  const language = useSyncExternalStore(subscribe, readLanguage, readLanguage)

  // `language` is not read inside the callback — it is the dependency that makes React hand
  // out a NEW function identity after a language change, which is what re-renders the memoised
  // subtrees below this hook. Without it the string would change but nothing would repaint.
  return useCallback<Translate>((key, options) => String(i18n.t(key, options)), [language])
}

/** The active `Intl.*` tag (`tr-TR`, `en-GB`, …), re-read on every language change. */
export function useIntlLocale(): string {
  const language = useSyncExternalStore(subscribe, readLanguage, readLanguage)
  return getIntlLocale(language)
}
