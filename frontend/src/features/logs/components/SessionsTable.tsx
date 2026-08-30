// Oturum sekmesi tablosu — server-side sayfalama/sıralama, yükleme/boş/hata durumları.
import { useTranslation } from 'react-i18next'
import { LogIn } from 'lucide-react'
import {
  Avatar,
  Badge,
  Button,
  EmptyState,
  Skeleton,
  TBody,
  Td,
  THead,
  Table,
  Th,
  Tr,
} from '../../../components/ui'
import type { SessionLog } from '../types'
import { SESSION_EVENT_BADGE, formatDateTime, formatDuration, sessionEventLabel } from '../utils'

export type SessionsTableProps = {
  data: SessionLog[]
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  perPage: number
  sortDirectionFor: (field: string) => 'asc' | 'desc' | null
  onSortToggle: (field: string) => void
}

export function SessionsTable({
  data,
  isLoading,
  isError,
  onRetry,
  perPage,
  sortDirectionFor,
  onSortToggle,
}: SessionsTableProps) {
  const { t } = useTranslation(['logs', 'common'])
  const isEmpty = !isLoading && !isError && data.length === 0

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('logs:sessions.loadError')}</p>
        <Button variant="secondary" onClick={onRetry}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  if (isEmpty) {
    return (
      <EmptyState
        icon={<LogIn className="size-6" aria-hidden="true" />}
        title={t('logs:sessions.emptyTitle')}
        description={t('logs:sessions.emptyDescription')}
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>{t('logs:sessions.columns.user')}</Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('event')}
            onSort={() => onSortToggle('event')}
          >
            {t('logs:sessions.columns.event')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('ip_address')}
            onSort={() => onSortToggle('ip_address')}
          >
            {t('logs:sessions.columns.ip')}
          </Th>
          <Th>{t('logs:sessions.columns.device')}</Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('logged_in_at')}
            onSort={() => onSortToggle('logged_in_at')}
          >
            {t('logs:sessions.columns.loginAt')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('logged_out_at')}
            onSort={() => onSortToggle('logged_out_at')}
          >
            {t('logs:sessions.columns.logoutAt')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('duration_seconds')}
            onSort={() => onSortToggle('duration_seconds')}
          >
            {t('logs:sessions.columns.duration')}
          </Th>
        </Tr>
      </THead>
      <TBody aria-busy={isLoading}>
        {isLoading
          ? Array.from({ length: perPage }).map((_, i) => (
              <Tr key={i}>
                <Td>
                  <div className="flex items-center gap-3">
                    <Skeleton variant="circle" width={32} height={32} />
                    <Skeleton variant="text" width={140} />
                  </div>
                </Td>
                <Td>
                  <Skeleton variant="text" width={70} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={90} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={140} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={100} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={100} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={60} />
                </Td>
              </Tr>
            ))
          : data.map((log) => (
              <Tr key={log.id}>
                <Td>
                  <div className="flex items-center gap-3">
                    <Avatar name={log.user?.name ?? log.email} size="sm" />
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-fg">
                        {log.user?.name ?? log.email}
                      </p>
                      <p className="truncate text-xs text-fg-muted">
                        {log.user?.email ?? log.email}
                      </p>
                    </div>
                  </div>
                </Td>
                <Td>
                  <Badge variant={SESSION_EVENT_BADGE[log.event]}>
                    {sessionEventLabel(log.event, t)}
                  </Badge>
                </Td>
                <Td className="font-mono text-xs">{log.ip_address ?? '—'}</Td>
                <Td>
                  <span className="text-xs text-fg-secondary">
                    {[log.device, log.browser, log.platform].filter(Boolean).join(' · ') || '—'}
                  </span>
                </Td>
                <Td>{formatDateTime(log.logged_in_at)}</Td>
                <Td>{formatDateTime(log.logged_out_at)}</Td>
                <Td>{formatDuration(log.duration_seconds, t)}</Td>
              </Tr>
            ))}
      </TBody>
    </Table>
  )
}
