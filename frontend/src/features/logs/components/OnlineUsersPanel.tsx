// Online kullanıcılar paneli — Loglar sayfasının yan/üst tarafında sabit panel.
// `useOnlineUsers()` (Faz 4, `features/presence`) zaten `/api/presence/online`'ı çekip
// `presence-online` katılma/ayrılma olaylarıyla invalidate ediyor — burada polling YOK,
// hook'un kendisi kullanılıyor (bkz. görev tanımı §DOKUNMA: `features/presence/` değiştirilmez,
// yalnızca içe aktarılır).
//
// `/api/presence/online` `password.changed` kapısının arkasında — geçici şifreli bir kullanıcı
// 403 alır. `useOnlineUsers` bu durumda `isError` döner; panel bu durumda KIRILMAZ, yerine
// kısa bir açıklama notuyla kendini gizler.
import { useTranslation } from 'react-i18next'
import { Users } from 'lucide-react'
import { Avatar, Badge, Card, CardHeader, EmptyState, Skeleton } from '../../../components/ui'
import { useOnlineUsers } from '../../presence/hooks/useOnlineUsers'
import { roleLabel } from '../../users/utils/roleMeta'

export function OnlineUsersPanel() {
  const { t } = useTranslation(['logs', 'enums'])
  const { users, meta, isLoading, isError } = useOnlineUsers()

  if (isError) {
    return (
      <Card className="h-fit">
        <CardHeader title={t('logs:onlineUsers.title')} />
        <p className="px-5 pb-5 text-sm text-fg-muted">{t('logs:onlineUsers.loadError')}</p>
      </Card>
    )
  }

  return (
    <Card className="h-fit">
      <CardHeader
        title={t('logs:onlineUsers.title')}
        subtitle={isLoading ? undefined : t('logs:onlineUsers.count', { count: users.length })}
      />
      <div className="flex flex-col gap-1 p-3">
        {meta?.stale && (
          <div className="mx-1 mb-1 rounded-md bg-warning-tint px-2.5 py-1.5 text-xs text-warning">
            {t('logs:onlineUsers.staleWarning')}
          </div>
        )}

        {isLoading ? (
          <div className="flex flex-col gap-3 p-2">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="flex items-center gap-2.5">
                <Skeleton variant="circle" width={32} height={32} />
                <Skeleton variant="text" width={120} />
              </div>
            ))}
          </div>
        ) : users.length === 0 ? (
          <EmptyState
            icon={<Users className="size-6" aria-hidden="true" />}
            title={t('logs:onlineUsers.emptyTitle')}
            description={t('logs:onlineUsers.emptyDescription')}
            className="px-3 py-6"
          />
        ) : (
          <ul className="flex flex-col">
            {users.map((user) => (
              <li
                key={user.id}
                className="flex items-center gap-2.5 rounded-md px-2 py-2 hover:bg-surface-2"
              >
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
    </Card>
  )
}
