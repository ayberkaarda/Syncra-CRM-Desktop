// Canlı Akış sekmesi — `private-logs` / `.activity.logged`. Bağlantı durumu göstergesi,
// Duraklat/Devam butonu, yeni kayıt vurgusu (`motion-reduce` ile devre dışı) ve en fazla 100
// kayıt sınırı `useActivityStream` içinde uygulanır. Bu bileşen unmount olduğunda (sekme
// değişince `TabPanel` onu unmount eder) hook'un `useEffect` temizleyicisi `echo.leave()` çağırır
// — ayrıca bir "leave" çağrısına gerek yok.
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { Pause, Play, Radio, ScrollText, WifiOff } from 'lucide-react'
import { Badge, Button, EmptyState } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useActivityStream } from '../hooks/useActivityStream'
import type { StreamEntry } from '../hooks/useActivityStream'
import {
  activityEventBadgeVariant,
  activityEventLabel,
  contextLabel,
  formatDateTime,
  subjectTypeLabel,
} from '../utils'

const HIGHLIGHT_MS = 1400

function StreamRow({ entry, t }: { entry: StreamEntry; t: TFunction }) {
  const [highlight, setHighlight] = useState(true)

  useEffect(() => {
    const timeout = setTimeout(() => setHighlight(false), HIGHLIGHT_MS)
    return () => clearTimeout(timeout)
  }, [])

  return (
    <li
      className={cn(
        'flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border-subtle px-4 py-3 text-sm',
        'transition-colors duration-700 motion-reduce:transition-none',
        highlight && 'bg-primary-tint',
      )}
    >
      <span className="w-40 shrink-0 truncate font-medium text-fg">
        {entry.causer_name ?? (
          <span className="italic text-fg-muted">{contextLabel(entry.context, t)}</span>
        )}
      </span>
      <Badge variant={activityEventBadgeVariant(entry.event)}>
        {activityEventLabel(entry.event, t)}
      </Badge>
      <span className="text-fg-secondary">
        {subjectTypeLabel(entry.subject_type, t)}
        {entry.subject_label && <span className="text-fg-muted"> · {entry.subject_label}</span>}
      </span>
      {entry.description && (
        <span className="min-w-0 flex-1 truncate text-fg-muted">{entry.description}</span>
      )}
      <span className="ml-auto shrink-0 text-xs text-fg-muted">
        {formatDateTime(entry.created_at)}
      </span>
    </li>
  )
}

export function LiveStreamTab() {
  const { t } = useTranslation(['logs', 'common'])
  const { entries, paused, togglePause, connectionState } = useActivityStream()

  const connectionBadge =
    connectionState === 'connected' ? (
      <Badge variant="success" dot>
        {t('logs:liveStream.connection.connected')}
      </Badge>
    ) : connectionState === 'connecting' ? (
      <Badge variant="warning" dot>
        {t('logs:liveStream.connection.connecting')}
      </Badge>
    ) : (
      <Badge variant="danger" dot>
        {t('logs:liveStream.connection.disconnected')}
      </Badge>
    )

  return (
    <div className="flex flex-col">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle p-4">
        <div className="flex items-center gap-2">
          <Radio className="size-4 text-fg-muted" aria-hidden="true" />
          <span className="text-sm text-fg-secondary">
            {t('logs:liveStream.recordCount', { count: entries.length })}
          </span>
          {connectionBadge}
        </div>
        <Button
          variant="secondary"
          size="sm"
          leftIcon={
            paused ? (
              <Play className="size-4" aria-hidden="true" />
            ) : (
              <Pause className="size-4" aria-hidden="true" />
            )
          }
          onClick={togglePause}
        >
          {paused ? t('logs:liveStream.resume') : t('logs:liveStream.pause')}
        </Button>
      </div>

      {connectionState !== 'connected' && (
        <div className="flex items-center gap-2 bg-warning-tint px-4 py-2 text-xs text-warning">
          <WifiOff className="size-3.5" aria-hidden="true" />
          {t('logs:liveStream.disconnectedBanner')}
        </div>
      )}

      {entries.length === 0 ? (
        <EmptyState
          icon={<ScrollText className="size-6" aria-hidden="true" />}
          title={t('logs:liveStream.emptyTitle')}
          description={t('logs:liveStream.emptyDescription')}
        />
      ) : (
        <ul className={cn(paused && 'opacity-90')}>
          {entries.map((entry) => (
            <StreamRow key={entry._key} entry={entry} t={t} />
          ))}
        </ul>
      )}
    </div>
  )
}
