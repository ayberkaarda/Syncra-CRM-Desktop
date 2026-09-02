// Bildirim tipine göre ikon eşlemesi + kütüphanesiz göreli zaman biçimlendirme —
// `NotificationList`/`NotificationBell`/`NotificationsPage` ortak kullanır. Sabitler ayrı
// tutulur ki bileşen dosyaları yalnızca bir component export etsin (react-refresh/
// only-export-components uyarısını önler — desen: `features/tickets/components/
// ticketPriorityMeta.ts`).
import {
  AlarmClock,
  ArrowRightLeft,
  CircleAlert,
  CircleX,
  FileText,
  Handshake,
  Headset,
  ListChecks,
  TriangleAlert,
  Trophy,
  UserPlus,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import i18n, { getIntlLocale } from '../../../i18n'
import { parseMirrorTimestamp } from '../../../lib/mirrorTime'
import type { NotificationType } from '../types'

export const NOTIFICATION_TYPE_ICON: Record<NotificationType, LucideIcon> = {
  'deal.assigned': Handshake,
  'deal.stage_changed': ArrowRightLeft,
  'deal.won': Trophy,
  'deal.lost': CircleX,
  'task.assigned': ListChecks,
  'task.reminder': AlarmClock,
  'ticket.assigned': Headset,
  'ticket.sla_warning': TriangleAlert,
  'ticket.sla_breached': CircleAlert,
  'lead.assigned': UserPlus,
  'quote.status_changed': FileText,
}

/** Bilinmeyen/gelecekte eklenecek bir tip için güvenli varsayılan. */
export function notificationTypeIcon(type: NotificationType): LucideIcon {
  return NOTIFICATION_TYPE_ICON[type] ?? FileText
}

type RelativeStep = { limitSeconds: number; divisor: number; unit: Intl.RelativeTimeFormatUnit }

const RELATIVE_STEPS: RelativeStep[] = [
  { limitSeconds: 60, divisor: 1, unit: 'second' },
  { limitSeconds: 3600, divisor: 60, unit: 'minute' },
  { limitSeconds: 86400, divisor: 3600, unit: 'hour' },
  { limitSeconds: 604800, divisor: 86400, unit: 'day' },
  { limitSeconds: 2629800, divisor: 604800, unit: 'week' },
  { limitSeconds: 31557600, divisor: 2629800, unit: 'month' },
]

const relativeFormatterCache = new Map<string, Intl.RelativeTimeFormat>()

function getRelativeFormatter(intlLocale: string): Intl.RelativeTimeFormat {
  let formatter = relativeFormatterCache.get(intlLocale)
  if (!formatter) {
    formatter = new Intl.RelativeTimeFormat(intlLocale, { numeric: 'auto' })
    relativeFormatterCache.set(intlLocale, formatter)
  }
  return formatter
}

/**
 * ISO-8601 tarihi "5 dakika önce" gibi göreli metne çevirir. Projede `dayjs`/`date-fns` yok
 * (bkz. `package.json`) — yalnızca yerleşik `Intl.RelativeTimeFormat` kullanılır, ek bağımlılık
 * eklemez. Faz 14/İz D: aktif arayüz diline göre biçimlenir (bkz. `lib/datetime.ts`), "az önce"
 * eşiği `notifications` namespace'inden çözülür.
 *
 * TIMEZONE NOTE (English, per SYNCDESKTOP §0.6). Parsing goes through `parseMirrorTimestamp`
 * rather than `new Date(iso)`. On the WEB this changes nothing: `NotificationResource` emits
 * `toIso8601String()`, always offset-carrying, which the parser passes to `Date.parse`
 * untouched. On the DESKTOP the same list is fed from the SQLite mirror, where `created_at` is
 * MySQL `DATETIME` text — space-separated and zone-less (`2026-09-01 08:53:28`) — a UTC instant
 * that ECMA-262 reads as LOCAL time, so on UTC+3 a row written three minutes ago rendered as
 * "3 hours ago". Measured in the running shell, not theorised. See `lib/mirrorTime.ts`.
 *
 * The unparseable-value contract is unchanged: the raw `iso` string is returned, not `'—'`.
 */
export function formatRelativeTime(iso: string): string {
  const date = new Date(parseMirrorTimestamp(iso))
  if (Number.isNaN(date.getTime())) return iso

  const diffSeconds = (date.getTime() - Date.now()) / 1000
  const absSeconds = Math.abs(diffSeconds)

  if (absSeconds < 5) return i18n.t('notifications:relativeTime.justNow')

  const formatter = getRelativeFormatter(getIntlLocale())

  for (const { limitSeconds, divisor, unit } of RELATIVE_STEPS) {
    if (absSeconds < limitSeconds) {
      return formatter.format(Math.round(diffSeconds / divisor), unit)
    }
  }

  return formatter.format(Math.round(diffSeconds / 31557600), 'year')
}
