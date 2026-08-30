// Aksiyon sekmesi tablosu — causer null olduğunda `properties._context`'e göre Sistem/Konsol/
// Kuyruk/Test/Tohumlama ayrımı yapılır (Canlı Akış sekmesiyle aynı mantık, bkz. `utils.ts` →
// `contextLabel`). `_context` REST yanıtında yoksa (eski/uyumsuz backend) `contextLabel`
// varsayılan olarak "Sistem" döner, tablo kırılmaz.
import { useTranslation } from 'react-i18next'
import { Eye, ScrollText } from 'lucide-react'
import {
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
import type { ActivityLog } from '../types'
import {
  activityEventBadgeVariant,
  activityEventLabel,
  contextLabel,
  formatDateTime,
  subjectTypeLabel,
} from '../utils'

export type ActivitiesTableProps = {
  data: ActivityLog[]
  isLoading: boolean
  isError: boolean
  onRetry: () => void
  perPage: number
  sortDirectionFor: (field: string) => 'asc' | 'desc' | null
  onSortToggle: (field: string) => void
  onViewDetail: (activity: ActivityLog) => void
}

export function ActivitiesTable({
  data,
  isLoading,
  isError,
  onRetry,
  perPage,
  sortDirectionFor,
  onSortToggle,
  onViewDetail,
}: ActivitiesTableProps) {
  const { t } = useTranslation(['logs', 'common'])
  const isEmpty = !isLoading && !isError && data.length === 0

  if (isError) {
    return (
      <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('logs:activities.loadError')}</p>
        <Button variant="secondary" onClick={onRetry}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  if (isEmpty) {
    return (
      <EmptyState
        icon={<ScrollText className="size-6" aria-hidden="true" />}
        title={t('logs:activities.emptyTitle')}
        description={t('logs:activities.emptyDescription')}
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          <Th>{t('logs:activities.columns.user')}</Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('event')}
            onSort={() => onSortToggle('event')}
          >
            {t('logs:activities.columns.event')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('subject_type')}
            onSort={() => onSortToggle('subject_type')}
          >
            {t('logs:activities.columns.subject')}
          </Th>
          <Th
            sortable
            sortDirection={sortDirectionFor('created_at')}
            onSort={() => onSortToggle('created_at')}
          >
            {t('logs:activities.columns.date')}
          </Th>
          <Th align="right">{t('logs:activities.columns.detail')}</Th>
        </Tr>
      </THead>
      <TBody aria-busy={isLoading}>
        {isLoading
          ? Array.from({ length: perPage }).map((_, i) => (
              <Tr key={i}>
                <Td>
                  <Skeleton variant="text" width={140} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={80} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={120} />
                </Td>
                <Td>
                  <Skeleton variant="text" width={110} />
                </Td>
                <Td align="right">
                  <Skeleton variant="text" width={70} className="ml-auto" />
                </Td>
              </Tr>
            ))
          : data.map((activity) => (
              <Tr key={activity.id}>
                <Td>
                  {activity.causer ? (
                    <span className="text-sm font-medium text-fg">{activity.causer.name}</span>
                  ) : (
                    <span className="text-sm italic text-fg-muted">
                      {contextLabel(activity.properties._context ?? 'system', t)}
                    </span>
                  )}
                </Td>
                <Td>
                  <Badge variant={activityEventBadgeVariant(activity.event)}>
                    {activityEventLabel(activity.event, t)}
                  </Badge>
                </Td>
                <Td>
                  <span className="text-sm text-fg">{subjectTypeLabel(activity.subject_type, t)}</span>
                  <span className="ml-1.5 text-xs text-fg-muted">
                    {activity.subject_label ??
                      (activity.subject_id ? `#${activity.subject_id}` : '')}
                  </span>
                </Td>
                <Td>{formatDateTime(activity.created_at)}</Td>
                <Td align="right">
                  <Button
                    variant="ghost"
                    size="sm"
                    leftIcon={<Eye className="size-4" aria-hidden="true" />}
                    onClick={() => onViewDetail(activity)}
                  >
                    {t('logs:activities.detailButton')}
                  </Button>
                </Td>
              </Tr>
            ))}
      </TBody>
    </Table>
  )
}
