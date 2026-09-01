// `SYNCDESKTOP.md` §8 defence layer 2, for the places a React hook cannot reach — defter O102.
//
// The tooltip half of §8 is a hook (`platform/useOnlineOnly.ts`), because a disabled button has
// to re-render when connectivity changes. The REFUSAL half is not: a rejected mutation is
// handled in `onError` inside a `features/*/api/*.ts` module, where `useTranslation()` is not
// available and the i18next singleton is already what those files use for their own toasts.
//
// Why the message is not simply `getErrorMessage(error)`: an `OnlineOnlyError` carries an
// English developer sentence (`"quotes.send" requires a connection.`, see
// `desktop/src/platform/onlineOnly.ts`) that is deliberately NOT user-facing — the user-facing
// text is the `desktop:onlineOnly.<action>` leaf, in the user's own language, and it names the
// action rather than the transport.
//
// Web behaviour is unchanged: `platform/web.ts` never produces `ONLINE_ONLY`, so this returns
// `undefined` for every failure in the web bundle and each call site falls through to the exact
// error message it produced before (KARAR A19 — the branch is on the error's shape, not on the
// platform).
import i18n from '../../i18n'
import { onlineOnlyActionOf } from '../../platform/onlineOnly'

/**
 * The §8 sentence for a rejected online-only action, or `undefined` for any other failure.
 *
 * Intended shape at a call site:
 * `toast.error(onlineOnlyMessage(error) ?? getErrorMessage(error))`.
 */
export function onlineOnlyMessage(error: unknown): string | undefined {
  const action = onlineOnlyActionOf(error)
  return action === undefined ? undefined : i18n.t(`desktop:onlineOnly.${action}`)
}
