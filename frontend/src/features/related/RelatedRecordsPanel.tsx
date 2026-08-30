// Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md §3).
//
// TEK ORTAK BİLEŞEN: `CompanyDetailPage`, `ContactDetailPage`, `DealDetailPage`,
// `TicketDetailPage`, `LeadDetailPage` bu bileşeni birebir kullanır — kopyalama
// yok. Sayfaya özgü olan tek şey `groups` prop'unun İÇERİĞİ (hangi modüller,
// hangi ikon/başlık/link), sunum mantığı tamamen burada.
//
// Yetkisiz modül grubu HİÇ BASILMAZ: `data === undefined` olan bir grup
// dizide bile yer almaz (bkz. `RelatedGroupConfig.data`) — boş grup başlığı
// göstermek bile "bu modül var" bilgisini sızdırır (C1'deki kural, §5.1).
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Card, CardBody, CardHeader } from '../../components/ui'
import { formatNumber } from '../../lib/money'
import type { RelatedGroupData, RelatedItem } from './types'

export interface RelatedGroupConfig<T extends RelatedItem = RelatedItem> {
  /** React key + i18n-parity denetleyicisinden bağımsız stabil tanımlayıcı. */
  key: string
  title: string
  icon: ReactNode
  /** `undefined` = kullanıcının bu modülü görme izni yok → grup HİÇ basılmaz. */
  data: RelatedGroupData<T> | undefined
  emptyText: string
  // Metot imzası (property function DEĞİL) BİLEREK: farklı modüllerin
  // `RelatedGroupConfig<DealRelatedItem>` / `RelatedGroupConfig<QuoteRelatedItem>` gibi somut
  // türleri, tek bir `groups: RelatedGroupConfig[]` dizisinde birlikte kullanılabilsin diye
  // (metot söz dizimi TS'te parametre türünü bivariant kontrol eder).
  renderItem(item: T): { label: string; sublabel?: string; href: string }
}

export interface RelatedRecordsPanelProps {
  groups: RelatedGroupConfig[]
}

export function RelatedRecordsPanel({ groups }: RelatedRecordsPanelProps) {
  const { t } = useTranslation('related')

  const visibleGroups = groups.filter((group) => group.data !== undefined)

  if (visibleGroups.length === 0) {
    return null
  }

  return (
    <Card>
      <CardHeader title={t('panel.title')} />
      <CardBody className="flex flex-col gap-6">
        {visibleGroups.map((group) => (
          <RelatedGroupSection key={group.key} group={group} />
        ))}
      </CardBody>
    </Card>
  )
}

function RelatedGroupSection({ group }: { group: RelatedGroupConfig }) {
  const { t } = useTranslation('related')
  const data = group.data as RelatedGroupData

  return (
    <section className="flex flex-col gap-2.5">
      <div className="flex items-center gap-2">
        <span className="flex size-6 items-center justify-center text-fg-muted">{group.icon}</span>
        <h4 className="text-sm font-medium text-fg">{group.title}</h4>
        <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-fg-muted">
          {formatNumber(data.total)}
        </span>
      </div>

      {data.items.length === 0 ? (
        <p className="pl-8 text-sm text-fg-muted">{group.emptyText}</p>
      ) : (
        <ul className="flex flex-col gap-1.5 pl-8">
          {data.items.map((item) => {
            const rendered = group.renderItem(item)
            return (
              <li key={item.id}>
                <Link
                  to={rendered.href}
                  className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-surface-2"
                >
                  <span className="truncate text-primary">{rendered.label}</span>
                  {rendered.sublabel && (
                    <span className="shrink-0 text-xs text-fg-muted">{rendered.sublabel}</span>
                  )}
                </Link>
              </li>
            )
          })}
          {data.total > data.items.length && (
            <li className="pl-2 pt-0.5 text-xs text-fg-muted">
              {t('panel.moreNotShown', { count: data.total - data.items.length })}
            </li>
          )}
        </ul>
      )}
    </section>
  )
}
