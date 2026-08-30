// Gezinme sekmesi tablosu — server-side sayfalama/sıralama, yükleme/boş/hata durumları.
import { useTranslation } from 'react-i18next'
import { Route } from 'lucide-react'
import {
  Avatar,
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
import type { PageVisitLog } from '../types'
import { formatDateTime, formatDuration } from '../utils'

export type PageVisitsTableProps = {
  data: PageVisitLog[]
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  perPage: number
  sortDirectionFor: (field: string) => 'asc' | 'desc' | null
  onSortToggle: (field: string) => void
}

export function PageVisitsTable({
  data,
  isLoading,
  isError,
  onRetry,
  perPage,
  sortDirectionFor,
  onSortToggle,
}: PageVisitsTableProps) {
  const { t } = useTranslation(['logs', 'common'])
  const isEmpty = !isLoading && !isError && data.length === 0

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('logs:pageVisits.loadError')}</p>
        <Button variant="secondary" onClick={onRetry}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  if (isEmpty) {
    return (
      <EmptyState
        icon={<Route className="size-6" aria-hidden="true" />}
        title={t('logs:pageVisits.emptyTitle')}
        description={t('logs:pageVisits.emptyDescription')}
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>{t('logs:pageVisits.columns.user')}</Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('route')}
            onSort={() => onSortToggle('route')}
          >
            {t('logs:pageVisits.columns.page')}
          </Th>
          <Th sortable sortDirection={sortDirectionFor('path')} onSort={() => onSortToggle('path')}>
            {t('logs:pageVisits.columns.path')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('entered_at')}
            onSort={() => onSortToggle('entered_at')}
          >
            {t('logs:pageVisits.columns.enteredAt')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('duration_seconds')}
            onSort={() => onSortToggle('duration_seconds')}
          >
            {t('logs:pageVisits.columns.duration')}
          </Th>
          <Th>{t('logs:pageVisits.columns.ip')}</Th>
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
                  <Skeleton variant="text" width={120} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={160} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={100} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={60} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={90} />
                </Td>
              </Tr>
            ))
          : data.map((log) => (
              <Tr key={log.id}>
                <Td>
                  {log.user ? (
                    <div className="flex items-center gap-3">
                      <Avatar name={log.user.name} size="sm" />
                      <p className="truncate text-sm font-medium text-fg">{log.user.name}</p>
                    </div>
                  ) : (
                    <span className="text-sm text-fg-muted">—</span>
                  )}
                </Td>
                <Td>{log.title || log.route || '—'}</Td>
                <Td>
                  <span
                    className="block max-w-[220px] truncate text-xs text-fg-secondary"
                    title={log.path}
                  >
                    {log.path}
                  </span>
                </Td>
                <Td>{formatDateTime(log.entered_at)}</Td>
                <Td>{formatDuration(log.duration_seconds, t)}</Td>
                <Td className="font-mono text-xs">{log.ip_address ?? '—'}</Td>
              </Tr>
            ))}
      </TBody>
    </Table>
  )
}
