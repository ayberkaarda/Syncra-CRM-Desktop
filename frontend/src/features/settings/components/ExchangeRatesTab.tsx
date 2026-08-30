// Kur (döviz) sekmesi — Faz 14 / İz E (docs/PHASE-INTL.md §2.1, §2.6).
//
// TCMB'nin günlük otomatik çekmesi (`exchange:fetch-tcmb`, konsol komutu) BU EKRANIN dışında
// çalışır — burası yalnızca (a) mevcut durumu GÖSTERİR (para birimi başına en güncel kur +
// bayatlık) ve (b) TCMB'ye ulaşılamadığında yöneticinin ELLE düzeltme YAZMASINI sağlar
// (§2.1 "Kaynak politikası (Karar A)").
//
// BAYATLIK GÖSTERGESİ (§2.6): her satırda HER ZAMAN "Kur dd.mm.yyyy tarihli" etiketi
// basılır (sessiz eski-kur okuması Faz 9 KDV sınıfı hatadır); `is_stale` sunucuda
// hesaplanmış bir bayraktır (`ExchangeRateService::STALE_THRESHOLD_DAYS`) — istemci eşiği
// (> 4 gün) kendi başına tekrar HESAPLAMAZ, yalnızca gelen bayrağa göre amber uyarı gösterir.
//
// Henüz hiç kur girilmemiş bir para birimi (`rate: null`, backend bilinçli olarak satırı
// ATLAMIYOR) "kur girilmedi" durumuyla ayrı gösterilir — sessizce eksik satır YOK sayılmaz.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleAlert, TriangleAlert } from 'lucide-react'
import { Badge, Button, Input, Select, Skeleton } from '../../../components/ui'
import { formatDate } from '../../../lib/datetime'
import { getFieldErrors } from '../../../lib/axios'
import { useCreateManualExchangeRate, useExchangeRates } from '../hooks/useExchangeRates'
import type { SupportedCurrency } from '../types'

function todayIsoDate(): string {
  return new Date().toISOString().slice(0, 10)
}

export function ExchangeRatesTab() {
  const { t } = useTranslation(['settings', 'common'])
  const { data, isLoading, isError, refetch } = useExchangeRates()
  const createRate = useCreateManualExchangeRate()

  const [currency, setCurrency] = useState<SupportedCurrency | ''>('')
  const [rate, setRate] = useState('')
  const [rateDate, setRateDate] = useState(todayIsoDate())
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2" aria-busy="true">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} variant="rect" height={56} />
        ))}
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="flex flex-col items-center gap-3 py-12 text-center">
        <p className="text-sm text-fg-muted">{t('settings:exchangeRates.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('common:actions.retry')}
        </Button>
      </div>
    )
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})

    if (!currency) {
      setFieldErrors({ currency: [t('settings:exchangeRates.form.errors.currencyRequired')] })
      return
    }
    if (!rate.trim()) {
      setFieldErrors({ rate: [t('settings:exchangeRates.form.errors.rateRequired')] })
      return
    }

    createRate.mutate(
      { currency, rate: rate.trim(), rate_date: rateDate },
      {
        onSuccess: () => {
          setRate('')
        },
        onError: (error) => {
          const backendFields = getFieldErrors(error)
          if (backendFields) setFieldErrors(backendFields)
        },
      }
    )
  }

  const currencyOptions = data.meta.supported_currencies.map((code) => ({ value: code, label: code }))

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-2">
        {data.data.map((row) => (
          <div
            key={row.currency}
            className="flex flex-wrap items-center gap-3 rounded-lg border border-border-subtle bg-surface-1 px-3 py-2.5"
          >
            <div className="flex min-w-[3.5rem] flex-col">
              <span className="text-sm font-semibold text-fg">{row.currency}</span>
              <span className="text-xs text-fg-muted">
                1 {row.currency} = 1 {data.meta.base_currency}
              </span>
            </div>

            {row.rate === null ? (
              <div className="flex flex-1 items-center gap-2 text-sm text-fg-muted">
                <CircleAlert className="size-4 shrink-0" aria-hidden="true" />
                {t('settings:exchangeRates.noRate')}
              </div>
            ) : (
              <>
                <div className="flex min-w-[7rem] flex-col">
                  <span className="text-sm font-medium text-fg">
                    {row.rate.rate} {data.meta.base_currency}
                  </span>
                  <Badge variant={row.rate.source === 'manual' ? 'neutral' : 'primary'} size="sm">
                    {row.rate.source === 'manual'
                      ? t('settings:exchangeRates.source.manual')
                      : t('settings:exchangeRates.source.tcmb')}
                  </Badge>
                </div>

                <span className="text-xs text-fg-muted">
                  {t('settings:exchangeRates.rateAsOf', { date: formatDate(row.rate.rate_date) })}
                </span>

                {row.rate.is_stale && (
                  <span className="flex items-center gap-1.5 rounded-md bg-warning-tint px-2 py-1 text-xs text-warning">
                    <TriangleAlert className="size-3.5 shrink-0" aria-hidden="true" />
                    {t('settings:exchangeRates.stale', { count: row.rate.days_stale })}
                  </span>
                )}
              </>
            )}
          </div>
        ))}
      </div>

      <form
        onSubmit={handleSubmit}
        className="flex flex-col gap-3 rounded-lg border border-border-subtle bg-surface-1 p-4"
      >
        <h3 className="text-sm font-semibold text-fg">{t('settings:exchangeRates.form.title')}</h3>
        <p className="text-xs text-fg-muted">{t('settings:exchangeRates.form.hint')}</p>

        <div className="flex flex-wrap items-end gap-3">
          <Select
            label={t('settings:exchangeRates.form.currencyLabel')}
            className="w-32"
            value={currency}
            onChange={(event) => setCurrency(event.target.value as SupportedCurrency)}
            placeholder={t('settings:exchangeRates.form.currencyPlaceholder')}
            options={currencyOptions}
            error={fieldErrors.currency?.[0]}
          />

          <Input
            label={t('settings:exchangeRates.form.rateLabel')}
            className="w-40"
            inputMode="decimal"
            placeholder="34.1234"
            value={rate}
            onChange={(event) => setRate(event.target.value)}
            error={fieldErrors.rate?.[0]}
          />

          <Input
            label={t('settings:exchangeRates.form.dateLabel')}
            type="date"
            className="w-44"
            max={todayIsoDate()}
            value={rateDate}
            onChange={(event) => setRateDate(event.target.value)}
            error={fieldErrors.rate_date?.[0]}
          />

          <Button type="submit" loading={createRate.isPending}>
            {t('settings:exchangeRates.form.submit')}
          </Button>
        </div>
      </form>
    </div>
  )
}
