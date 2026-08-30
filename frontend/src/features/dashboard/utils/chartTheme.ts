// Grafik renk/ikon/biçimlendirme yardımcıları. Hardcode hex YOK: her renk `styles/tokens.css`teki
// `--app-*` özel özelliklerinden okunur (bkz. görev tanımı §ORTAK KURALLAR) ki tema değişince
// (`useTheme` → `resolvedTheme`) grafikler de otomatik değişsin. `useChartTheme` hook'u bu yüzden
// `resolvedTheme`i bağımlılık olarak alır — Recharts'a düz string renk vermek gerektiğinden
// (CSS değişkeni referansı değil, gerçek hesaplanmış değer) her tema değişiminde yeniden okunur.
import { useMemo } from 'react'
import { useTheme } from '../../../hooks/useTheme'
import i18n, { getIntlLocale } from '../../../i18n'

/** `pipeline_stages.color` ile aynı sabit token sözlüğü (bkz. `tokenBadgeVariant.ts`). Dashboard
 * huni ve raporlardaki aşama renkleri bu sözlüğü yeniden kullanır — yeni bir kategorik palet
 * İCAT EDİLMEZ; iş mantığı zaten her aşamaya bir semantik renk atamış durumda (Faz 7). */
export type SemanticColorToken = 'primary' | 'success' | 'danger' | 'warning' | 'neutral' | 'info'

const TOKEN_CSS_VAR: Record<Exclude<SemanticColorToken, 'info'>, string> = {
  primary: '--app-primary',
  success: '--app-success',
  danger: '--app-danger',
  warning: '--app-warning',
  neutral: '--app-border-strong',
}

// `getComputedStyle` başarısız olursa (SSR yok ama savunma amaçlı) diye açık tema değerleriyle
// eşleşen bir geri dönüş — gerçek render her zaman CSS değişkeninden okur, bu yalnızca bir ağ.
const FALLBACK_HEX: Record<Exclude<SemanticColorToken, 'info'>, string> = {
  primary: '#0672c4',
  success: '#16794a',
  danger: '#c81e1e',
  warning: '#a15c00',
  neutral: '#878e99',
}

function normalizeToken(token: string | null | undefined): Exclude<SemanticColorToken, 'info'> {
  const t = token === 'info' ? 'primary' : token
  if (t && t in TOKEN_CSS_VAR) return t as Exclude<SemanticColorToken, 'info'>
  return 'neutral'
}

function readCssVar(varName: string, fallback: string): string {
  if (typeof window === 'undefined' || typeof getComputedStyle !== 'function') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(varName).trim()
  return value || fallback
}

export type ChartTheme = {
  /** Semantik token adını (`primary`/`success`/...) gerçek renge çevirir — Recharts `fill`/`stroke`. */
  token: (color: string | null | undefined) => string
  /** Tek serili grafiklerin varsayılan rengi (çizgi/alan/bar). */
  accent: string
  /** Eksen etiketleri, ikincil metin. */
  axisText: string
  /** Grid çizgileri — bir adım yüzeyden koyu, kılcal, sönük. */
  grid: string
  /** Tooltip/kart kroması. */
  surface: string
  border: string
  fg: string
  fgMuted: string
}

/** Geçerli temaya göre grafik renk paletini okur; tema değiştiğinde yeniden hesaplanır. */
export function useChartTheme(): ChartTheme {
  const { resolvedTheme } = useTheme()

  return useMemo<ChartTheme>(() => {
    const token = (color: string | null | undefined) => {
      const key = normalizeToken(color)
      return readCssVar(TOKEN_CSS_VAR[key], FALLBACK_HEX[key])
    }

    return {
      token,
      accent: token('primary'),
      axisText: readCssVar('--app-fg-muted', '#667085'),
      grid: readCssVar('--app-border-subtle', '#eff2f4'),
      surface: readCssVar('--app-surface-1', '#ffffff'),
      border: readCssVar('--app-border', '#e2e6ec'),
      fg: readCssVar('--app-fg', '#1e1e1e'),
      fgMuted: readCssVar('--app-fg-muted', '#667085'),
    }
    // resolvedTheme değişince `data-theme` attribute'u değişir ve CSS değişkenleri yeni değerlere
    // döner — bu yüzden tek bağımlılık odur.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resolvedTheme])
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

/** ISO-8601 tarihi "5 dakika önce" gibi göreli metne çevirir. Projede `dayjs`/`date-fns` yok —
 * yalnızca yerleşik `Intl.RelativeTimeFormat` (aynı desen: `features/notifications`). Faz 14/İz D:
 * aktif arayüz diline göre biçimlenir (bkz. `lib/datetime.ts`), "az önce" eşiği `dashboard`
 * namespace'inden çözülür — modül seviyesinde sabit DEĞİL, çağrı anında `i18n.t()` ile okunur ki
 * dil değişince donmuş kalmasın. */
export function formatRelativeTime(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso

  const diffSeconds = (date.getTime() - Date.now()) / 1000
  const absSeconds = Math.abs(diffSeconds)

  if (absSeconds < 5) return i18n.t('dashboard:relativeTime.justNow')

  const formatter = getRelativeFormatter(getIntlLocale())

  for (const { limitSeconds, divisor, unit } of RELATIVE_STEPS) {
    if (absSeconds < limitSeconds) {
      return formatter.format(Math.round(diffSeconds / divisor), unit)
    }
  }

  return formatter.format(Math.round(diffSeconds / 31557600), 'year')
}
