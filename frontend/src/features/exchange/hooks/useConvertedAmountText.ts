// `ConvertedAmount`in görsel gövdesiyle metin-yalnızca tüketicilerin (ör. Kanban kartındaki
// `aria-label`, bkz. `DealBoardCard`) AYNI dönüşüm metnini üretmesi için tek yer. İkisi ayrı
// mantık kullansaydı ekran okuyucusu görünenden FARKLI bir tutar/para birimi anons ederdi.
import { useTranslation } from 'react-i18next'
import { formatMoney, formatMoneyCompact } from '../../../lib/money'
import { formatDate } from '../../../lib/datetime'
import { useAmountConverter } from './useAmountConverter'

export type ConvertedAmountText = {
  /** Basılacak asıl metin — dönüşüm varsa ÇEVRİLMİŞ tutar, yoksa/olamıyorsa kaydın kendisi. */
  text: string
  /** Dönüşüm uygulandığında (veya çevrilemediğinde) ek açıklama; aksi halde `null`. */
  tooltip: string | null
  isStale: boolean
  /** `true`: para birimleri farklı ama gereken kur yok — `text` kaydın kendi para biriminde. */
  unavailable: boolean
}

export function useConvertedAmountText(
  amount: number | string | null | undefined,
  currency: string,
  variant: 'default' | 'compact' = 'default'
): ConvertedAmountText {
  const { t } = useTranslation('exchange')
  const { convert, isLoading } = useAmountConverter()
  const format = variant === 'compact' ? formatMoneyCompact : formatMoney

  const conversion = convert(currency)

  if (conversion.sameCurrency || (isLoading && !conversion.canConvert)) {
    return { text: format(amount, currency), tooltip: null, isStale: false, unavailable: false }
  }

  if (!conversion.canConvert) {
    return {
      text: format(amount, currency),
      tooltip: t('exchange:unavailable', { currency }),
      isStale: false,
      unavailable: true,
    }
  }

  const originalText = format(amount, currency)
  const dateLabel = conversion.rateDate ? formatDate(`${conversion.rateDate}T00:00:00`) : ''
  const convertedText = format(amount, currency, {
    displayCurrency: conversion.displayCurrency,
    rate: conversion.rate ?? undefined,
  })

  const tooltip = conversion.isStale
    ? `${t('exchange:convertedTooltip', { amount: originalText, date: dateLabel })} ${t('exchange:stale', { count: conversion.daysStale })}`
    : t('exchange:convertedTooltip', { amount: originalText, date: dateLabel })

  return { text: convertedText, tooltip, isStale: conversion.isStale, unavailable: false }
}
