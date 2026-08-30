// Notlar + Etkileşimler bölümü — talebe bağlı `activities` kayıtlarını TEK istekle çeker
// (`GET /api/activities?filter[activityable_type]=ticket&filter[activityable_id]={id}`) ve
// `type='note'` olanları "Notlar", diğerlerini ("call"/"email"/"meeting") "Etkileşimler" olarak
// AYRI kartlarda gösterir — görev tanımının bıraktığı iki seçenekten biri ("ayrı gösterilebilir
// veya notlarla birlikte, kararını ver"): ayrı gösterildi çünkü ikisinin ARAÇLARI farklıdır
// (etkileşim süre/sonuç taşır, not taşımaz) ve "İlk Yanıt"ı TETİKLEYEN yalnızca etkileşimlerdir
// (bkz. `TicketActivityFormModal` başındaki gerekçe) — ayrı kart bu farkı görsel olarak da
// vurgular.
//
// C şeridin `features/activities/api/activitiesApi.ts` (`useActivities`) ve
// `features/activities/components/ActivityTypeBadge.tsx` dosyaları DOĞRUDAN kullanılır — kopya
// YAZILMAZ (görev tanımı).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MessageSquarePlus, PhoneCall } from 'lucide-react'
import { Avatar, Button, Card, CardBody, CardHeader, Skeleton } from '../../../components/ui'
import { formatDateTime } from '../../../lib/datetime'
import { useActivities } from '../../activities/api/activitiesApi'
import { ActivityTypeBadge } from '../../activities/components/ActivityTypeBadge'
import { TicketActivityFormModal } from './TicketActivityFormModal'
import type { Ticket } from '../types'

export function TicketActivityPanel({ ticket }: { ticket: Ticket }) {
  const { t } = useTranslation('tickets')
  const { data, isLoading, isError, refetch } = useActivities({
    activityable_type: 'ticket',
    activityable_id: ticket.id,
    per_page: 100,
    sort: '-occurred_at',
  })
  const [modalKind, setModalKind] = useState<'note' | 'interaction' | null>(null)

  const activities = data?.data ?? []
  const notes = activities.filter((a) => a.type === 'note')
  const interactions = activities.filter((a) => a.type !== 'note')

  return (
    <>
      <Card>
        <CardHeader
          title={t('activity.notesTitle')}
          subtitle={t('activity.notesSubtitle', { count: notes.length })}
          action={
            <Button size="sm" leftIcon={<MessageSquarePlus className="size-4" aria-hidden="true" />} onClick={() => setModalKind('note')}>
              {t('activity.addNote')}
            </Button>
          }
        />
        <CardBody className="flex flex-col gap-3">
          {isLoading ? (
            <Skeleton variant="text" lines={3} />
          ) : isError ? (
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm text-fg-muted">{t('activity.notesLoadError')}</p>
              <Button variant="secondary" size="sm" onClick={() => refetch()}>
                {t('activity.retry')}
              </Button>
            </div>
          ) : notes.length === 0 ? (
            <p className="text-sm text-fg-muted">{t('activity.noNotes')}</p>
          ) : (
            notes.map((note) => (
              <div key={note.id} className="flex flex-col gap-1.5 rounded-md border border-border-subtle p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Avatar name={note.user?.name ?? '—'} size="xs" />
                    <span className="text-sm font-medium text-fg">{note.user?.name ?? t('activity.unknownUser')}</span>
                  </div>
                  <span className="text-xs text-fg-muted">{formatDateTime(note.occurred_at)}</span>
                </div>
                {note.subject && <p className="text-sm font-medium text-fg">{note.subject}</p>}
                {note.body && <p className="whitespace-pre-wrap text-sm text-fg-secondary">{note.body}</p>}
              </div>
            ))
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title={t('activity.interactionsTitle')}
          subtitle={t('activity.interactionsSubtitle', { count: interactions.length })}
          action={
            <Button
              size="sm"
              variant="secondary"
              leftIcon={<PhoneCall className="size-4" aria-hidden="true" />}
              onClick={() => setModalKind('interaction')}
            >
              {t('activity.addInteraction')}
            </Button>
          }
        />
        <CardBody className="flex flex-col gap-3">
          {isLoading ? (
            <Skeleton variant="text" lines={3} />
          ) : isError ? (
            <p className="text-sm text-fg-muted">{t('activity.interactionsLoadError')}</p>
          ) : interactions.length === 0 ? (
            <p className="text-sm text-fg-muted">{t('activity.noInteractions')}</p>
          ) : (
            interactions.map((activity) => (
              <div key={activity.id} className="flex flex-col gap-1.5 rounded-md border border-border-subtle p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <ActivityTypeBadge type={activity.type} size="sm" />
                    <span className="text-sm font-medium text-fg">{activity.subject}</span>
                  </div>
                  <span className="text-xs text-fg-muted">{formatDateTime(activity.occurred_at)}</span>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-xs text-fg-muted">
                  {activity.user && (
                    <span className="inline-flex items-center gap-1.5">
                      <Avatar name={activity.user.name} size="xs" />
                      {activity.user.name}
                    </span>
                  )}
                  {activity.duration_minutes !== null && (
                    <span>{t('activity.durationMinutes', { count: activity.duration_minutes })}</span>
                  )}
                  {activity.outcome && <span>{t('activity.outcomeLabel', { outcome: activity.outcome })}</span>}
                </div>
                {activity.body && <p className="whitespace-pre-wrap text-sm text-fg-secondary">{activity.body}</p>}
              </div>
            ))
          )}
        </CardBody>
      </Card>

      {modalKind && (
        <TicketActivityFormModal open onClose={() => setModalKind(null)} ticket={ticket} kind={modalKind} />
      )}
    </>
  )
}
