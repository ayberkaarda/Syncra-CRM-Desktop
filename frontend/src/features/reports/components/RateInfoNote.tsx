// Kur şeffaflığı satırı (Faz 14 / İz E — docs/PHASE-INTL.md §2.4/§2.6). Para taşıyan HER rapor
// sekmesinin ve dashboard'un ALTINDA gösterilir — backend `rate_info`yu döndüğü her yerde tek
// bileşen: hangi kurun kullanıldığını + tarihini açıklar, bayat kur için amber uyarı verir,
// kuru bulunamadığı için çevrilemeyen (ve toplamlara sessizce 0 giren) verileri GÖRÜNÜR kılar.
//
// NEDEN `as_of === null` İKEN DİPNOT HİÇ BASILMAZ: hiçbir dönüşüm yapılmadıysa (rakamlar zaten
// kayıtların/temel para biriminde), "şu kurla çevrildi" cümlesi sahte bir dipnot olurdu (§2.4
// son madde — "aksi hâlde rakamlar açıklanamaz" ilkesinin ters yönü: açıklanacak bir şey yoksa
// açıklama uydurulmaz). Çevrilemeyen veri uyarısı ise BUNDAN BAĞIMSIZ gösterilir — o veri hiç
// dönüşüm olmasa da eksik kalmış olabilir (ör. hiçbir kur bulunamadığı için TEK bir dönüşüm bile
// başarılı olmamış olabilir).
import { useTranslation } from 'react-i18next'
import { AlertTriangle } from 'lucide-react'
import { formatDateLabel } from '../utils'
import type { RateInfo } from '../types'

export type RateInfoNoteProps = {
  rateInfo: RateInfo | undefined
}

export function RateInfoNote({ rateInfo }: RateInfoNoteProps) {
  const { t } = useTranslation('reports')
  if (!rateInfo) return null

  const {
    closed_basis,
    as_of,
    is_stale,
    days_stale,
    unconverted_open,
    unconverted_closed_count,
    display_currency,
  } = rateInfo

  const showFootnote = as_of !== null
  const hasUnconvertedOpen = unconverted_open.length > 0
  const hasUnconvertedClosed = unconverted_closed_count > 0

  if (!showFootnote && !hasUnconvertedOpen && !hasUnconvertedClosed) return null

  const dateLabel = as_of !== null ? formatDateLabel(as_of) : ''

  return (
    <div className="flex flex-col gap-1.5 border-t border-border-subtle pt-3 text-xs text-fg-muted">
      {showFootnote && (
        <p>
          {t('reports:rateInfo.open', { date: dateLabel, currency: display_currency })}{' '}
          {closed_basis === 'frozen_base_converted'
            ? t('reports:rateInfo.closedConverted', { date: dateLabel, currency: display_currency })
            : t('reports:rateInfo.closedFrozen')}
        </p>
      )}

      {showFootnote && is_stale && (
        <p className="flex items-center gap-1.5 text-warning">
          <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />
          {t('reports:rateInfo.stale', { count: days_stale })}
        </p>
      )}

      {hasUnconvertedOpen && (
        <p className="flex items-center gap-1.5 text-warning">
          <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />
          {/* `unconverted_open` para birimi başına BİR kova (bkz. types.ts) — sayı çevrilemeyen
              FIRSAT değil, çevrilemeyen PARA BİRİMİ kovası sayısıdır. */}
          {t('reports:rateInfo.unconvertedOpen', { count: unconverted_open.length })}
        </p>
      )}

      {hasUnconvertedClosed && (
        <p className="flex items-center gap-1.5 text-warning">
          <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />
          {t('reports:rateInfo.unconvertedClosed', { count: unconverted_closed_count })}
        </p>
      )}
    </div>
  )
}
