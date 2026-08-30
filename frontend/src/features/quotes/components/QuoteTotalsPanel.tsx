// Toplamlar bloğu — ara toplam, indirim, KDV oranı kırılımı, genel toplam. Hem teklif formunda
// (`POST /api/quotes/calculate` canlı sonucuyla) hem detay sayfasında (kayıtlı `Quote` alanlarıyla)
// AYNI bileşen kullanılır: `POST /api/quotes/calculate` ve `GET /api/quotes/{id}` birebir aynı
// `tax_breakdown` şeklini (`rate/net/discount/base/tax`) döndürüyor (bkz. types.ts dokümanı).
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { formatMoney, formatPercent } from '../../../lib/money'
import type { DiscountType, QuoteTaxBreakdownRow } from '../types'

export type QuoteTotalsPanelProps = {
  subtotal: number
  discountType: DiscountType
  discountValue: number
  discountAmount: number
  taxAmount: number
  total: number
  taxBreakdown: QuoteTaxBreakdownRow[]
  /**
   * Teklifin KENDİ para birimi (`quote.currency`) — ZORUNLU, varsayılan verilmez (bkz.
   * `QuoteItemsEditor`'daki aynı gerekçe: docs/PHASE-INTL.md §2 Karar B, dönüşüm değil sembol).
   */
  currency: string
  /** Form bağlamında: yeni bir `calculate` isteği uçarken hafif bir gösterge — değerler SIFIRLANMAZ. */
  isCalculating?: boolean
}

export function QuoteTotalsPanel({
  subtotal,
  discountType,
  discountValue,
  discountAmount,
  taxAmount,
  total,
  taxBreakdown,
  currency,
  isCalculating,
}: QuoteTotalsPanelProps) {
  const { t } = useTranslation()
  const hasDiscount = discountAmount > 0
  const discountLabel =
    discountType === 'percent'
      ? t('quotes:totals.discountPercent', { value: formatPercent(discountValue) })
      : t('quotes:totals.discountFixed')

  return (
    <div className="flex flex-col gap-4">
      {isCalculating && (
        <div className="flex items-center gap-1.5 text-xs text-fg-muted">
          <Loader2 className="size-3.5 animate-spin motion-reduce:hidden" aria-hidden="true" />
          {t('quotes:totals.calculating')}
        </div>
      )}

      <dl className="flex flex-col gap-2 text-sm">
        <div className="flex items-center justify-between">
          <dt className="text-fg-secondary">{t('quotes:totals.subtotal')}</dt>
          <dd className="font-medium text-fg">{formatMoney(subtotal, currency)}</dd>
        </div>
        {hasDiscount && (
          <div className="flex items-center justify-between">
            <dt className="text-fg-secondary">{t('quotes:totals.discountRow', { label: discountLabel })}</dt>
            <dd className="font-medium text-danger">-{formatMoney(discountAmount, currency)}</dd>
          </div>
        )}
        <div className="flex items-center justify-between">
          <dt className="text-fg-secondary">{t('quotes:totals.taxTotal')}</dt>
          <dd className="font-medium text-fg">{formatMoney(taxAmount, currency)}</dd>
        </div>
      </dl>

      {taxBreakdown.length > 0 && (
        <div className="flex flex-col gap-1.5">
          <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('quotes:totals.taxBreakdownTitle')}</p>
          <div className="overflow-x-auto rounded-md border border-border-subtle">
            <table className="w-full border-collapse text-left text-xs">
              <thead>
                <tr className="border-b border-border-subtle bg-surface-2">
                  <th className="px-3 py-2 font-medium text-fg-muted">{t('quotes:totals.taxBreakdownColumns.rate')}</th>
                  <th className="px-3 py-2 text-right font-medium text-fg-muted">{t('quotes:totals.taxBreakdownColumns.net')}</th>
                  <th className="px-3 py-2 text-right font-medium text-fg-muted">{t('quotes:totals.taxBreakdownColumns.discountShare')}</th>
                  <th className="px-3 py-2 text-right font-medium text-fg-muted">{t('quotes:totals.taxBreakdownColumns.base')}</th>
                  <th className="px-3 py-2 text-right font-medium text-fg-muted">{t('quotes:totals.taxBreakdownColumns.tax')}</th>
                </tr>
              </thead>
              <tbody>
                {taxBreakdown.map((row) => (
                  <tr key={row.rate} className="border-b border-border-subtle last:border-0">
                    <td className="px-3 py-2 text-fg">%{row.rate}</td>
                    <td className="px-3 py-2 text-right text-fg">{formatMoney(row.net, currency)}</td>
                    <td className="px-3 py-2 text-right text-fg">{formatMoney(row.discount, currency)}</td>
                    <td className="px-3 py-2 text-right text-fg">{formatMoney(row.base, currency)}</td>
                    <td className="px-3 py-2 text-right text-fg">{formatMoney(row.tax, currency)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="flex items-center justify-between border-t border-border-subtle pt-3">
        <span className="text-sm font-medium text-fg">{t('quotes:totals.grandTotal')}</span>
        <span className="text-xl font-semibold text-fg">{formatMoney(total, currency)}</span>
      </div>
    </div>
  )
}
