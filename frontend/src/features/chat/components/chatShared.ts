// Chat modülü paylaşılan yardımcılar — göreli zaman/gün ayracı biçimlendirme, dosya boyutu,
// güvenli bağlantı ayrıştırma ve dışarı tıklama/Esc ile kapanan panel deseni (`ConversationHeader`
// / `MessageSearch` ortak kullanır — desen: `features/notifications/components/
// NotificationBell.tsx`). Sabitler/fonksiyonlar ayrı tutulur ki bileşen dosyaları yalnızca bir
// component export etsin (react-refresh/only-export-components uyarısını önler — desen:
// `features/tickets/components/ticketsShared.ts`, `features/notifications/components/
// notificationMeta.ts`). Projede `dayjs`/`date-fns` YOK — yalnızca yerleşik `Intl` kullanılır.
import { useEffect, useRef } from 'react'
import i18n, { getIntlLocale } from '../../../i18n'
import { formatDate, formatTime } from '../../../lib/datetime'

type RelativeStep = { limitSeconds: number; divisor: number; unit: Intl.RelativeTimeFormatUnit }

const RELATIVE_STEPS: RelativeStep[] = [
  { limitSeconds: 60, divisor: 1, unit: 'second' },
  { limitSeconds: 3600, divisor: 60, unit: 'minute' },
  { limitSeconds: 86400, divisor: 3600, unit: 'hour' },
  { limitSeconds: 604800, divisor: 86400, unit: 'day' },
  { limitSeconds: 2629800, divisor: 604800, unit: 'week' },
  { limitSeconds: 31557600, divisor: 2629800, unit: 'month' },
]

/** ISO-8601 tarihi "5 dakika önce" gibi göreli metne çevirir (konuşma listesi önizlemesi). */
export function formatRelativeTime(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso

  const diffSeconds = (date.getTime() - Date.now()) / 1000
  const absSeconds = Math.abs(diffSeconds)

  if (absSeconds < 5) return i18n.t('chat:time.justNow')

  // Aktif arayüz diline göre (sabit 'tr' DEĞİL, PHASE-INTL §1.8) — bkz. `components/shared/Timeline.tsx`.
  const relativeFormatter = new Intl.RelativeTimeFormat(getIntlLocale(), { numeric: 'auto' })

  for (const { limitSeconds, divisor, unit } of RELATIVE_STEPS) {
    if (absSeconds < limitSeconds) {
      return relativeFormatter.format(Math.round(diffSeconds / divisor), unit)
    }
  }

  return relativeFormatter.format(Math.round(diffSeconds / 31557600), 'year')
}

/** Mesaj balonundaki saat etiketi ("14:32") — merkezi `lib/datetime.ts` biçimlendiricisi (PHASE-INTL §1.8). */
export function formatMessageTime(iso: string): string {
  return formatTime(iso)
}

function isSameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/** Gün ayracı etiketi: "Bugün" / "Dün" / "12 Ocak 2026". */
export function formatDayDivider(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''

  const now = new Date()
  if (isSameDay(date, now)) return i18n.t('chat:time.today')

  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (isSameDay(date, yesterday)) return i18n.t('chat:time.yesterday')

  return formatDate(date)
}

/** İki ISO tarihinin aynı takvim gününe düşüp düşmediği — gün ayracı eklenip eklenmeyeceğine karar vermek için. */
export function isSameCalendarDay(isoA: string, isoB: string): boolean {
  const a = new Date(isoA)
  const b = new Date(isoB)
  if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return false
  return isSameDay(a, b)
}

/** Dosya boyutunu "12,3 KB" / "1,4 MB" gibi okunur biçime çevirir. */
export function formatFileSize(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes < 0) return ''
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let unitIndex = 0
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }
  return `${value.toFixed(1).replace('.', ',')} ${units[unitIndex]}`
}

export type MessageTextPart = { type: 'text'; value: string } | { type: 'link'; value: string; href: string }

const URL_PATTERN = /(https?:\/\/[^\s<>"']+)/g

/**
 * Düz metni parçalara ayırır — `http(s)://` ile başlayan geçerli URL'ler bağlantıya çevrilir,
 * geri kalan her şey düz metin olarak kalır. `dangerouslySetInnerHTML` KULLANILMAZ; çağıran taraf
 * bu parçaları kendi render eder (bkz. `MessageBubble`). Yalnızca `http`/`https` protokolü kabul
 * edilir (ör. `javascript:` reddedilir).
 */
export function linkifyMessageBody(text: string): MessageTextPart[] {
  const parts: MessageTextPart[] = []
  let lastIndex = 0

  for (const match of text.matchAll(URL_PATTERN)) {
    const raw = match[0]
    const index = match.index ?? 0

    let href: string | null = null
    try {
      const url = new URL(raw)
      if (url.protocol === 'http:' || url.protocol === 'https:') href = url.toString()
    } catch {
      href = null
    }

    if (!href) continue

    if (index > lastIndex) parts.push({ type: 'text', value: text.slice(lastIndex, index) })
    parts.push({ type: 'link', value: raw, href })
    lastIndex = index + raw.length
  }

  if (lastIndex < text.length) parts.push({ type: 'text', value: text.slice(lastIndex) })

  return parts
}

/**
 * Konuşma tipi etiketi — sabit bir metin haritası DEĞİL, çağrı anında `i18n.t()` ile çözülür
 * (PHASE-INTL §1.3: modül seviyesinde donmuş bir metin dil değişince güncellenmezdi).
 */
export function conversationTypeLabel(type: 'dm' | 'group' | 'record'): string {
  return i18n.t(`chat:conversationType.${type}`)
}

/**
 * Açık bir panel/menü için dışarı tıklama ve Esc tuşuyla kapanma — dönen ref panel kapsayıcısına
 * bağlanır. Desen `features/notifications/components/NotificationBell.tsx` ile AYNIDIR.
 */
export function useDismiss<T extends HTMLElement>(open: boolean, onClose: () => void) {
  const ref = useRef<T | null>(null)

  useEffect(() => {
    if (!open) return

    function handleClickOutside(event: MouseEvent) {
      if (!ref.current?.contains(event.target as Node)) onClose()
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open, onClose])

  return ref
}
