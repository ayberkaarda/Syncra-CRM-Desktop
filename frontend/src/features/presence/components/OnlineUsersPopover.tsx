// Online kullanıcılar göstergesi — Topbar'da üst üste binmiş avatar grubu +
// toplam sayı; tıklanınca tam liste açılır (avatar, isim, rol, departman).
import { useEffect, useRef, useState } from 'react'
import { Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Avatar, AvatarGroup, Badge, EmptyState } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useOnlineUsers } from '../hooks/useOnlineUsers'
import { roleLabel } from '../../users/utils/roleMeta'

const MAX_AVATARS_IN_TRIGGER = 4

export function OnlineUsersPopover() {
  const { t } = useTranslation(['presence', 'enums'])
  const { users, meta, isLoading } = useOnlineUsers()
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const triggerRef = useRef<HTMLButtonElement | null>(null)

  useEffect(() => {
    if (!open) return

    function handleClickOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={t('trigger.aria', { count: users.length })}
        className={cn(
          'flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-surface-2',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
        )}
      >
        {users.length > 0 ? (
          <AvatarGroup max={MAX_AVATARS_IN_TRIGGER} size="xs">
            {users.map((user) => (
              <Avatar key={user.id} name={user.name} status="online" />
            ))}
          </AvatarGroup>
        ) : (
          <Users className="size-4 text-fg-muted" aria-hidden="true" />
        )}
        <span className="text-sm font-medium text-fg-secondary">{users.length}</span>
      </button>

      {open && (
        <div
          role="dialog"
          aria-label={t('panel.aria')}
          className="absolute right-0 top-full z-50 mt-2 w-72 rounded-lg border border-border bg-surface-3 py-2 shadow-popover"
        >
          <div className="flex items-center justify-between px-3 pb-2">
            <p className="text-sm font-medium text-fg">{t('panel.title', { count: users.length })}</p>
          </div>

          {meta?.stale && (
            <div className="mx-3 mb-2 rounded-md bg-warning-tint px-2.5 py-1.5 text-xs text-warning">
              {t('panel.staleWarning')}
            </div>
          )}

          <div className="max-h-80 overflow-y-auto">
            {isLoading ? null : users.length === 0 ? (
              <EmptyState
                icon={<Users className="size-6" aria-hidden="true" />}
                title={t('empty.title')}
                description={t('empty.description')}
                className="px-4 py-6"
              />
            ) : (
              <ul className="flex flex-col">
                {users.map((user) => (
                  <li key={user.id} className="flex items-center gap-2.5 px-3 py-2 hover:bg-surface-2">
                    <Avatar name={user.name} size="sm" status="online" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-fg">{user.name}</p>
                      <p className="truncate text-xs text-fg-muted">{user.department ?? user.email}</p>
                    </div>
                    {user.role && (
                      <Badge variant="neutral" size="sm" className="shrink-0">
                        {roleLabel(user.role, t)}
                      </Badge>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
