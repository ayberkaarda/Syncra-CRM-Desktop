// Loglar modülü biçimlendirme yardımcıları — insan-okunur süre/tarih ve enum etiketleri.
//
// Faz 14 / İz D: bu dosya saf bir yardımcı (React/i18next bağımsız) — etiketler METİN DEĞİL
// `logs` sözlüğündeki ANAHTARI taşır (bkz. `activityTypeMeta.ts`'teki aynı gerekçe: bir modül
// sabiti değerlendirme anında `t()` çağırsaydı metin ilk yüklenen dile donardı). Tüketiciler
// `sessionEventLabel(event, t)` gibi `t: TFunction` alan fonksiyonlarla çözer. Tarih biçimi
// merkezi `lib/datetime.ts`'e devredildi (§1.8 — sabit `Intl.DateTimeFormat('tr-TR')` KALMADI).
import type { TFunction } from 'i18next'
import { formatDateTime as formatDateTimeIntl } from '../../lib/datetime'
import type { BadgeProps } from '../../components/ui'
import type { ActivityEvent, LogContext, SessionEvent } from './types'

export { formatDateTimeIntl as formatDateTime }

/** "2 sa 14 dk" / "14 dk" / "45 sn" biçiminde insan-okunur süre. */
export function formatDuration(seconds: number | null | undefined, t: TFunction): string {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 60) return t('logs:duration.secondsShort', { count: Math.max(0, Math.round(seconds)) })

  const totalMinutes = Math.floor(seconds / 60)
  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60

  if (hours > 0) {
    return minutes > 0
      ? `${t('logs:duration.hoursShort', { count: hours })} ${t('logs:duration.minutesShort', { count: minutes })}`
      : t('logs:duration.hoursShort', { count: hours })
  }
  return t('logs:duration.minutesShort', { count: minutes })
}

export const SESSION_EVENT_LABEL_KEY: Record<SessionEvent, string> = {
  login: 'logs:enums.sessionEvent.login',
  logout: 'logs:enums.sessionEvent.logout',
  failed_login: 'logs:enums.sessionEvent.failed_login',
  locked_out: 'logs:enums.sessionEvent.locked_out',
}

export const SESSION_EVENT_BADGE: Record<SessionEvent, NonNullable<BadgeProps['variant']>> = {
  login: 'success',
  logout: 'neutral',
  failed_login: 'danger',
  locked_out: 'warning',
}

export function sessionEventLabel(event: SessionEvent, t: TFunction): string {
  return t(SESSION_EVENT_LABEL_KEY[event])
}

export function sessionEventOptions(t: TFunction): Array<{ value: string; label: string }> {
  return (Object.keys(SESSION_EVENT_LABEL_KEY) as SessionEvent[]).map((value) => ({
    value,
    label: sessionEventLabel(value, t),
  }))
}

export const ACTIVITY_EVENT_LABEL_KEY: Record<string, string> = {
  created: 'logs:enums.activityEvent.created',
  updated: 'logs:enums.activityEvent.updated',
  deleted: 'logs:enums.activityEvent.deleted',
  restored: 'logs:enums.activityEvent.restored',
}

export const ACTIVITY_EVENT_BADGE: Record<string, NonNullable<BadgeProps['variant']>> = {
  created: 'success',
  updated: 'primary',
  deleted: 'danger',
  restored: 'warning',
}

export function activityEventLabel(event: ActivityEvent | string, t: TFunction): string {
  const key = ACTIVITY_EVENT_LABEL_KEY[event]
  return key ? t(key) : event
}

export function activityEventBadgeVariant(event: ActivityEvent | string): NonNullable<BadgeProps['variant']> {
  return ACTIVITY_EVENT_BADGE[event] ?? 'neutral'
}

/** `IndexLogRequest`'in kabul ettiği 4 değer (soft-delete geri alma dahil). */
export function activityFilterEventOptions(t: TFunction): Array<{ value: string; label: string }> {
  return ['created', 'updated', 'deleted', 'restored'].map((value) => ({
    value,
    label: activityEventLabel(value, t),
  }))
}

/** `LogRepository::SUBJECT_TYPE_MAP` ile birebir — kısa ad -> `logs:enums.subjectType.*` anahtarı. */
export const SUBJECT_TYPE_LABEL_KEY: Record<string, string> = {
  lead: 'logs:enums.subjectType.lead',
  contact: 'logs:enums.subjectType.contact',
  company: 'logs:enums.subjectType.company',
  deal: 'logs:enums.subjectType.deal',
  task: 'logs:enums.subjectType.task',
  activity: 'logs:enums.subjectType.activity',
  ticket: 'logs:enums.subjectType.ticket',
  quote: 'logs:enums.subjectType.quote',
  product: 'logs:enums.subjectType.product',
  user: 'logs:enums.subjectType.user',
}

export function subjectTypeOptions(t: TFunction): Array<{ value: string; label: string }> {
  return Object.keys(SUBJECT_TYPE_LABEL_KEY).map((value) => ({
    value,
    label: t(SUBJECT_TYPE_LABEL_KEY[value]),
  }))
}

export function subjectTypeLabel(subjectType: string | null, t: TFunction): string {
  if (!subjectType) return '—'
  const key = SUBJECT_TYPE_LABEL_KEY[subjectType]
  return key ? t(key) : subjectType
}

/** Canlı akış payload'ındaki `context` -> causer'sız satır etiketi. */
export const CONTEXT_LABEL_KEY: Record<string, string> = {
  http: 'logs:enums.context.system',
  system: 'logs:enums.context.system',
  console: 'logs:enums.context.console',
  queue: 'logs:enums.context.queue',
  seed: 'logs:enums.context.seed',
  test: 'logs:enums.context.test',
}

export function contextLabel(context: LogContext | string, t: TFunction): string {
  return t(CONTEXT_LABEL_KEY[context] ?? CONTEXT_LABEL_KEY.system)
}

/** Bir değeri okunabilir kısa metne çevirir — diff görünümünde `<pre>` yerine kullanılır. */
export function formatDiffValue(value: unknown, t: TFunction): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? t('common:actions.yes') : t('common:actions.no')
  if (typeof value === 'number') return String(value)
  if (typeof value === 'string') return value
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}
