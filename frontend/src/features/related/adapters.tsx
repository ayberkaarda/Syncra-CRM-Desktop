// Faz 14 / İz F — C3 ilişkili-kayıtlar paneli: her modülün `RelatedGroupData`'sını
// `RelatedRecordsPanel`'in beklediği `RelatedGroupConfig`'e çeviren saf fonksiyonlar.
//
// `t()` SONUCU MODÜL SEVİYESİNDE SAKLANMAZ (bu fazın tekrar eden hata sınıfı —
// bkz. `docs/PHASE-INTL.md` uyarısı): her fonksiyon `t`'yi PARAMETRE olarak alır
// ve metni HER ÇAĞRIDA (render anında) çözer; yalnızca değişmeyen anahtar
// dizeleri (`STATUS_LABEL_KEY` gibi) modül seviyesinde sabittir — `activities/
// components/activityTypeMeta.ts` ile aynı desen.
import { Building2, FileText, LifeBuoy, Target, Users } from 'lucide-react'
import type { TFunction } from 'i18next'
import { formatMoney } from '../../lib/money'
import type { RelatedGroupConfig } from './RelatedRecordsPanel'
import type {
  CompanyRelatedItem,
  ContactRelatedItem,
  DealRelatedItem,
  QuoteRelatedItem,
  RelatedGroupData,
  TicketRelatedItem,
} from './types'

const DEAL_STATUS_KEY: Record<string, string> = {
  open: 'deal.status.open',
  won: 'deal.status.won',
  lost: 'deal.status.lost',
}

const QUOTE_STATUS_KEY: Record<string, string> = {
  draft: 'quote.status.draft',
  sent: 'quote.status.sent',
  accepted: 'quote.status.accepted',
  rejected: 'quote.status.rejected',
  expired: 'quote.status.expired',
}

const TICKET_STATUS_KEY: Record<string, string> = {
  open: 'ticket.status.open',
  pending: 'ticket.status.pending',
  in_progress: 'ticket.status.in_progress',
  resolved: 'ticket.status.resolved',
  closed: 'ticket.status.closed',
}

function enumLabel(t: TFunction, map: Record<string, string>, value: string): string {
  const key = map[value]
  // Tam nitelikli `ns:key` — bu dosyada yerel bir `useTranslation()` bağlaması yok
  // (`t` parametre olarak geliyor), i18n-parite denetleyicisi namespace'i yalnızca
  // `ns:key` önekinden veya çağıranın `useTranslation` argümanından çözebiliyor.
  return key ? t(`enums:${key}`) : value
}

export function dealsGroupConfig(
  t: TFunction,
  data: RelatedGroupData<DealRelatedItem> | undefined
): RelatedGroupConfig<DealRelatedItem> {
  return {
    key: 'deals',
    title: t('common:nav.deals'),
    icon: <Target className="size-4" aria-hidden="true" />,
    data,
    emptyText: t('related:empty.deals'),
    renderItem: (deal) => ({
      label: deal.title,
      sublabel: `${formatMoney(deal.amount, deal.currency)} — ${enumLabel(t, DEAL_STATUS_KEY, deal.status)}`,
      href: `/deals/${deal.id}`,
    }),
  }
}

export function quotesGroupConfig(
  t: TFunction,
  data: RelatedGroupData<QuoteRelatedItem> | undefined
): RelatedGroupConfig<QuoteRelatedItem> {
  return {
    key: 'quotes',
    title: t('common:nav.quotes'),
    icon: <FileText className="size-4" aria-hidden="true" />,
    data,
    emptyText: t('related:empty.quotes'),
    renderItem: (quote) => ({
      label: `${quote.quote_number} — ${quote.title}`,
      sublabel: `${formatMoney(quote.total, quote.currency)} — ${enumLabel(t, QUOTE_STATUS_KEY, quote.status)}`,
      href: `/quotes/${quote.id}`,
    }),
  }
}

export function ticketsGroupConfig(
  t: TFunction,
  data: RelatedGroupData<TicketRelatedItem> | undefined
): RelatedGroupConfig<TicketRelatedItem> {
  return {
    key: 'tickets',
    title: t('common:nav.tickets'),
    icon: <LifeBuoy className="size-4" aria-hidden="true" />,
    data,
    emptyText: t('related:empty.tickets'),
    renderItem: (ticket) => ({
      label: `${ticket.ticket_number} — ${ticket.subject}`,
      sublabel: enumLabel(t, TICKET_STATUS_KEY, ticket.status),
      href: `/tickets/${ticket.id}`,
    }),
  }
}

/**
 * `contactGroupConfig`/`companyGroupConfig`/`dealGroupConfig` iki FARKLI bağlamda
 * kullanılır (`TicketDetailPage`'in tekil `company`/`contact` alanı VE
 * `LeadDetailPage`'in `converted_*` alanı) — başlık ve boş-durum metni bu
 * yüzden çağıran sayfadan PARAMETRE olarak gelir, burada sabitlenmez.
 */
export function contactGroupConfig(
  key: string,
  title: string,
  emptyText: string,
  data: RelatedGroupData<ContactRelatedItem> | undefined
): RelatedGroupConfig<ContactRelatedItem> {
  return {
    key,
    title,
    icon: <Users className="size-4" aria-hidden="true" />,
    data,
    emptyText,
    renderItem: (contact) => ({
      label: contact.full_name,
      href: `/contacts/${contact.id}`,
    }),
  }
}

export function companyGroupConfig(
  key: string,
  title: string,
  emptyText: string,
  data: RelatedGroupData<CompanyRelatedItem> | undefined
): RelatedGroupConfig<CompanyRelatedItem> {
  return {
    key,
    title,
    icon: <Building2 className="size-4" aria-hidden="true" />,
    data,
    emptyText,
    renderItem: (company) => ({
      label: company.name,
      href: `/companies/${company.id}`,
    }),
  }
}

export function dealGroupConfig(
  key: string,
  title: string,
  emptyText: string,
  data: RelatedGroupData<{ id: number; title: string }> | undefined
): RelatedGroupConfig<{ id: number; title: string }> {
  return {
    key,
    title,
    icon: <Target className="size-4" aria-hidden="true" />,
    data,
    emptyText,
    renderItem: (deal) => ({
      label: deal.title,
      href: `/deals/${deal.id}`,
    }),
  }
}
