// "You have unpushed changes — log out anyway?" (`SYNCDESKTOP.md` §5.2 `LogoutOutcome`).
//
// `auth::logout` refuses with `PendingMutations(n)` unless `force` is set, and the shared
// `Topbar` logout button knows nothing about that. `platform/auth.ts` catches the refusal on
// the redirected `POST /api/logout` and parks the question here; this component is the only
// thing that can answer it.
//
// ## i18n — the borrowed keys are gone (O20)
//
// AUTH-1 built this screen out of `common:layout.logout` + `desktop:sync.pendingChanges` +
// `desktop:storage.clearLocal.description`, because `desktop.logout.*` did not exist yet. The
// last of those was the real problem: it warns that local data will be erased, which is true,
// but it never says the thing the user has to decide on — that unsent work will be **lost**.
//
// FIX-I18N added the dedicated set in all four languages and nothing consumed it, so the keys
// were dead on arrival. This screen is the consumer:
//
//   title    `desktop:logout.title`
//   body     `desktop:logout.description`      — "sync before you sign out"
//   loss     `desktop:logout.discardWarning`   — pluralised on `count`, the actual stakes
//   confirm  `desktop:logout.force`            — "Sign out anyway", not a bare "Confirm"
//   cancel   `common:actions.cancel`           — still shared; there is no logout-specific cancel
import { useSyncExternalStore } from 'react'

import { Button, Modal } from '@/components/ui'

import { getLogoutPrompt, subscribeToLogoutPrompt } from '../platform/auth'
import { useT } from './useT'

export function LogoutConfirm() {
  const t = useT()
  const prompt = useSyncExternalStore(subscribeToLogoutPrompt, getLogoutPrompt, getLogoutPrompt)

  if (prompt === null) return null

  return (
    <Modal
      open
      // Dismissing the dialog is the same answer as pressing Cancel: the promise in
      // `platform/auth.ts` has to be settled either way or the logout request never returns.
      onClose={() => prompt.answer(false)}
      title={t('desktop:logout.title')}
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={() => prompt.answer(false)}>
            {t('common:actions.cancel')}
          </Button>
          <Button variant="danger" onClick={() => prompt.answer(true)}>
            {t('desktop:logout.force')}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-3 text-sm">
        <p className="font-medium text-warning">
          {t('desktop:logout.discardWarning', { count: prompt.pending })}
        </p>
        <p className="text-fg-secondary">{t('desktop:logout.description')}</p>
      </div>
    </Modal>
  )
}
