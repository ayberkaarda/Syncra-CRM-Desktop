// "Elif yazıyor…" / "Elif ve Mert yazıyor…" / 3+ için "Birkaç kişi yazıyor…".
// `typingUsers` `ChatUser[]` — boşsa hiçbir şey render edilmez.
import { useTranslation } from 'react-i18next'
import type { ChatUser } from '../types'

export type TypingIndicatorProps = {
  users: ChatUser[]
}

export function TypingIndicator({ users }: TypingIndicatorProps) {
  const { t } = useTranslation('chat')
  if (users.length === 0) return null

  let text: string
  if (users.length === 1) {
    text = t('typing.single', { name: users[0].name })
  } else if (users.length === 2) {
    text = t('typing.double', { name1: users[0].name, name2: users[1].name })
  } else {
    text = t('typing.multiple')
  }

  return (
    <div className="px-4 pb-1 text-xs text-fg-muted" role="status" aria-live="polite">
      {text}
    </div>
  )
}
