// Teklif liste görünümü — server-side sayfalama/sıralama/arama/filtreleme, tüm durum URL query
// string'inde (bkz. `tickets/pages/TicketsListPage.tsx`/`deals/pages/DealsListPage.tsx` deseni).
import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { Download, Eye, FileText, GitBranch, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import {
  Button,
  Card,
  CardBody,
  CardHeader,
  Checkbox,
  EmptyState,
  Input,
  Modal,
  Pagination,
  Select,
  Skeleton,
  Table,
  TBody,
  Td,
  THead,
  Th,
  Tr,
} from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { recordSyncState } from '../../../components/shared/recordSyncState'
import { SyncStateBadge } from '../../../components/shared/SyncStateBadge'
import { useOnlineOnly } from '../../../platform/useOnlineOnly'
import { usePermission } from '../../auth/hooks/usePermission'
import { SavedViewsBar } from '../../saved-views/components/SavedViewsBar'
import { QuoteStatusBadge } from '../components/QuoteStatusBadge'
import { formatDate } from '../../../lib/datetime'
import { formatMoney } from '../../../lib/money'
import { useCompanyFilterOptions, useDealFilterOptions } from '../api/catalogApi'
import { buildQuotePdfUrl, useDeleteQuote, useQuotes, useReviseQuote } from '../api/quotesApi'
import { useDebouncedValue } from '../hooks/useDebouncedValue'
import type { Quote, QuoteStatus, QuotesQuery } from '../types'

const DEFAULT_PER_PAGE = 10
const SEARCH_DEBOUNCE_MS = 300
const DEFAULT_SORT = '-created_at'

const QUOTE_STATUSES: QuoteStatus[] = ['draft', 'sent', 'accepted', 'rejected', 'expired']

export function QuotesListPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = usePermission()

  const [searchDraft, setSearchDraft] = useState(searchParams.get('q') ?? '')
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  const [deleteQuoteState, setDeleteQuoteState] = useState<Quote | null>(null)

  const deleteQuoteMutation = useDeleteQuote()
  const reviseQuoteMutation = useReviseQuote()
  // SYNCDESKTOP §8 (O102). Both are server-only; the row's detail/edit links are NOT guarded
  // because the pages they open read from the local mirror and work offline.
  const reviseGuard = useOnlineOnly('quotes.revise')
  const pdfGuard = useOnlineOnly('quotes.pdf')
  const [revisingId, setRevisingId] = useState<number | null>(null)

  function updateParams(patch: Record<string, string | null>) {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  useEffect(() => {
    const currentQ = searchParams.get('q') ?? ''
    if (debouncedSearch === currentQ) return
    updateParams({ q: debouncedSearch || null, page: '1' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const query: QuotesQuery = {
    page: Number(searchParams.get('page') ?? '1') || 1,
    per_page: Number(searchParams.get('per_page') ?? String(DEFAULT_PER_PAGE)) || DEFAULT_PER_PAGE,
    sort: searchParams.get('sort') ?? DEFAULT_SORT,
    q: searchParams.get('q') ?? undefined,
    status: (searchParams.get('status') ?? undefined) as QuoteStatus | undefined,
    deal_id: searchParams.get('deal_id') ? Number(searchParams.get('deal_id')) : undefined,
    company_id: searchParams.get('company_id') ? Number(searchParams.get('company_id')) : undefined,
    from: searchParams.get('from') ?? undefined,
    to: searchParams.get('to') ?? undefined,
    expired: searchParams.get('expired') === '1' ? true : undefined,
  }

  const { data, isLoading, isError, refetch } = useQuotes(query)
  const { data: companyOptions } = useCompanyFilterOptions()
  const { data: dealOptions } = useDealFilterOptions()

  function sortDirectionFor(field: string): 'asc' | 'desc' | null {
    if (query.sort === field) return 'asc'
    if (query.sort === `-${field}`) return 'desc'
    return null
  }

  function toggleSort(field: string) {
    const current = query.sort
    let nextSort: string | null
    if (current === field) nextSort = `-${field}`
    else if (current === `-${field}`) nextSort = null
    else nextSort = field
    updateParams({ sort: nextSort ?? DEFAULT_SORT, page: '1' })
  }

  async function handleRevise(quote: Quote) {
    setRevisingId(quote.id)
    try {
      await reviseQuoteMutation.mutateAsync(quote.id)
      void refetch()
    } finally {
      setRevisingId(null)
    }
  }

  const statusFilterOptions = [
    { value: '', label: t('quotes:list.allStatuses') },
    ...QUOTE_STATUSES.map((status) => ({ value: status, label: t(`enums:quote.status.${status}`) })),
  ]
  const companyFilterOptions = [
    { value: '', label: t('quotes:list.allCompanies') },
    ...(companyOptions ?? []).map((c) => ({ value: String(c.id), label: c.name })),
  ]
  const dealFilterOptions = [
    { value: '', label: t('quotes:list.allDeals') },
    ...(dealOptions ?? []).map((d) => ({ value: String(d.id), label: d.title })),
  ]

  const quotes = data?.data ?? []
  const total = data?.meta.pagination.total ?? 0
  const isEmpty = !isLoading && !isError && quotes.length === 0

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="text-xs text-fg-muted">
        <span>{t('quotes:breadcrumb.home')}</span>
        <span className="mx-1.5">/</span>
        <span className="text-primary">{t('quotes:breadcrumb.quotes')}</span>
      </nav>

      <Card>
        <CardHeader
          title={t('quotes:list.title')}
          subtitle={t('quotes:list.subtitle', { count: total })}
          action={
            <div className="flex items-center gap-2">
              <SavedViewsBar module="quotes" filterKeys={['status', 'deal_id', 'company_id', 'from', 'to', 'expired']} />
              {can('quotes.create') && (
                <Link to="/quotes/new">
                  <Button leftIcon={<Plus className="size-4" aria-hidden="true" />}>{t('quotes:list.createButton')}</Button>
                </Link>
              )}
            </div>
          }
        />
        <CardBody noPadding>
          <div className="flex flex-col gap-3 border-b border-border-subtle p-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
              <div className="w-full lg:max-w-xs">
                <Input
                  value={searchDraft}
                  onChange={(e) => setSearchDraft(e.target.value)}
                  placeholder={t('quotes:list.searchPlaceholder')}
                  leftIcon={<Search className="size-4" aria-hidden="true" />}
                  aria-label={t('quotes:list.searchAria')}
                />
              </div>
              <div className="w-full lg:w-44">
                <Select
                  value={query.status ?? ''}
                  onChange={(e) => updateParams({ status: e.target.value || null, page: '1' })}
                  options={statusFilterOptions}
                  aria-label={t('quotes:list.statusAria')}
                />
              </div>
              <div className="w-full lg:w-48">
                <Select
                  value={query.company_id ? String(query.company_id) : ''}
                  onChange={(e) => updateParams({ company_id: e.target.value || null, page: '1' })}
                  options={companyFilterOptions}
                  aria-label={t('quotes:list.companyAria')}
                />
              </div>
              <div className="w-full lg:w-48">
                <Select
                  value={query.deal_id ? String(query.deal_id) : ''}
                  onChange={(e) => updateParams({ deal_id: e.target.value || null, page: '1' })}
                  options={dealFilterOptions}
                  aria-label={t('quotes:list.dealAria')}
                />
              </div>
            </div>
            <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center lg:justify-between">
              <Checkbox
                label={t('quotes:list.expiredOnly')}
                checked={!!query.expired}
                onChange={(e) => updateParams({ expired: e.target.checked ? '1' : null, page: '1' })}
              />
              <div className="flex w-full items-end gap-2 lg:w-auto">
                <div className="w-full lg:w-40">
                  <Input
                    type="date"
                    value={query.from ?? ''}
                    onChange={(e) => updateParams({ from: e.target.value || null, page: '1' })}
                    aria-label={t('quotes:list.fromDateAria')}
                    max={query.to || undefined}
                  />
                </div>
                <span className="pb-2.5 text-xs text-fg-muted">—</span>
                <div className="w-full lg:w-40">
                  <Input
                    type="date"
                    value={query.to ?? ''}
                    onChange={(e) => updateParams({ to: e.target.value || null, page: '1' })}
                    aria-label={t('quotes:list.toDateAria')}
                    min={query.from || undefined}
                  />
                </div>
              </div>
            </div>
          </div>

          {isError ? (
            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
              <p className="text-sm text-fg-muted">{t('quotes:list.loadError')}</p>
              <Button variant="secondary" onClick={() => refetch()}>
                {t('quotes:retry')}
              </Button>
            </div>
          ) : isEmpty ? (
            <EmptyState
              icon={<FileText className="size-6" aria-hidden="true" />}
              title={t('quotes:list.emptyTitle')}
              description={t('quotes:list.emptyDescription')}
            />
          ) : (
            <Table>
              <THead>
                <Tr>
                  <Th sortable sortDirection={sortDirectionFor('quote_number')} onSort={() => toggleSort('quote_number')}>
                    {t('quotes:list.columns.quoteNumber')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('title')} onSort={() => toggleSort('title')}>
                    {t('quotes:list.columns.title')}
                  </Th>
                  <Th>{t('quotes:list.columns.companyContact')}</Th>
                  <Th>{t('quotes:list.columns.deal')}</Th>
                  <Th sortable sortDirection={sortDirectionFor('status')} onSort={() => toggleSort('status')}>
                    {t('quotes:list.columns.status')}
                  </Th>
                  <Th align="right" sortable sortDirection={sortDirectionFor('total')} onSort={() => toggleSort('total')}>
                    {t('quotes:list.columns.total')}
                  </Th>
                  <Th sortable sortDirection={sortDirectionFor('valid_until')} onSort={() => toggleSort('valid_until')}>
                    {t('quotes:list.columns.validUntil')}
                  </Th>
                  <Th align="right">{t('quotes:list.columns.items')}</Th>
                  <Th align="right">{t('quotes:list.columns.actions')}</Th>
                </Tr>
              </THead>
              <TBody aria-busy={isLoading}>
                {isLoading
                  ? Array.from({ length: query.per_page ?? DEFAULT_PER_PAGE }).map((_, i) => (
                      <Tr key={i}>
                        <Td><Skeleton variant="text" width={110} /></Td>
                        <Td><Skeleton variant="text" width={160} /></Td>
                        <Td><Skeleton variant="text" width={140} /></Td>
                        <Td><Skeleton variant="text" width={100} /></Td>
                        <Td><Skeleton variant="text" width={80} /></Td>
                        <Td><Skeleton variant="text" width={90} className="ml-auto" /></Td>
                        <Td><Skeleton variant="text" width={90} /></Td>
                        <Td><Skeleton variant="text" width={40} className="ml-auto" /></Td>
                        <Td align="right"><Skeleton variant="text" width={120} className="ml-auto" /></Td>
                      </Tr>
                    ))
                  : quotes.map((quote) => {
                      const isRevisable = ['sent', 'rejected', 'expired'].includes(quote.status)
                      // TÜM durumlarda gösterilir (`accepted` dahil) — gerekçe `QuoteDetailPage`'deki
                      // `canEdit` yorumuyla AYNI: backend kilidi `draft` dışına özel değil, yalnızca
                      // tutarı etkileyen alanları kısıtlıyor; başlık/not/şart/geçerlilik/fırsat her
                      // durumda düzenlenebilir ve `accepted` zaten revize edilemediği için bu, o
                      // durumdaki bir yazım hatasını düzeltmenin TEK yolu.
                      const canEditRow = can('quotes.update')
                      const canDeleteRow = can('quotes.delete') && quote.status !== 'accepted' && quote.status !== 'rejected'
                      const canReviseRow = isRevisable && can('quotes.create')
                      return (
                        <Tr key={quote.id}>
                          <Td>
                            <span className="inline-flex items-center gap-2">
                              <Link
                                to={`/quotes/${quote.id}`}
                                className="flex items-center gap-1.5 font-mono text-sm font-medium text-fg hover:text-primary hover:underline"
                              >
                                {quote.quote_number}
                                {quote.revision > 1 && (
                                  <span className="rounded-sm bg-surface-2 px-1.5 py-0.5 text-xs font-medium text-fg-muted">
                                    R{quote.revision}
                                  </span>
                                )}
                              </Link>
                              <SyncStateBadge state={recordSyncState(quote)} compact />
                            </span>
                          </Td>
                          <Td className="max-w-64 truncate">
                            <Link to={`/quotes/${quote.id}`} className="text-fg hover:text-primary hover:underline">
                              {quote.title}
                            </Link>
                          </Td>
                          <Td>
                            <div className="flex flex-col text-sm">
                              <span className="truncate text-fg">{quote.company?.name ?? '—'}</span>
                              {quote.contact && <span className="truncate text-xs text-fg-muted">{quote.contact.full_name}</span>}
                            </div>
                          </Td>
                          <Td>
                            {quote.deal ? (
                              <Link to={`/deals/${quote.deal.id}`} className="text-sm text-primary hover:underline">
                                {quote.deal.title}
                              </Link>
                            ) : (
                              <span className="text-sm text-fg-muted">—</span>
                            )}
                          </Td>
                          <Td>
                            <QuoteStatusBadge status={quote.status} />
                          </Td>
                          <Td align="right" className="whitespace-nowrap font-medium text-fg">
                            {formatMoney(quote.total, quote.currency)}
                          </Td>
                          <Td className={cn('whitespace-nowrap', quote.is_expired && 'font-medium text-warning')}>
                            {formatDate(quote.valid_until)}
                          </Td>
                          <Td align="right">{quote.items_count}</Td>
                          <Td align="right">
                            <div className="flex items-center justify-end gap-1">
                              <IconLinkButton label={t('quotes:actions.detail')} to={`/quotes/${quote.id}`}>
                                <Eye className="size-4" aria-hidden="true" />
                              </IconLinkButton>
                              {canEditRow && (
                                <IconLinkButton label={t('quotes:actions.edit')} to={`/quotes/${quote.id}/edit`}>
                                  <Pencil className="size-4" aria-hidden="true" />
                                </IconLinkButton>
                              )}
                              {/* Offline the anchor is replaced by a disabled button: an `<a>` has
                                  no disabled state, so keeping it would still navigate. Web build:
                                  `pdfGuard.offline` is always false and the anchor is unchanged. */}
                              {pdfGuard.offline ? (
                                <IconButton
                                  label={t('quotes:actions.pdf')}
                                  disabled
                                  title={pdfGuard.title}
                                  onClick={() => undefined}
                                >
                                  <Download className="size-4" aria-hidden="true" />
                                </IconButton>
                              ) : (
                                <a
                                  href={buildQuotePdfUrl(quote.id)}
                                  target="_blank"
                                  rel="noreferrer"
                                  aria-label={t('quotes:actions.pdf')}
                                  title={t('quotes:actions.pdf')}
                                >
                                  <IconButtonLike>
                                    <Download className="size-4" aria-hidden="true" />
                                  </IconButtonLike>
                                </a>
                              )}
                              {canReviseRow && (
                                <IconButton
                                  label={t('quotes:actions.revise')}
                                  onClick={() => handleRevise(quote)}
                                  loading={revisingId === quote.id}
                                  disabled={reviseGuard.offline}
                                  title={reviseGuard.title}
                                >
                                  <GitBranch className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                              {canDeleteRow && (
                                <IconButton label={t('quotes:actions.delete')} danger onClick={() => setDeleteQuoteState(quote)}>
                                  <Trash2 className="size-4" aria-hidden="true" />
                                </IconButton>
                              )}
                            </div>
                          </Td>
                        </Tr>
                      )
                    })}
              </TBody>
            </Table>
          )}

          {!isError && !isEmpty && (
            <div className="border-t border-border-subtle p-4">
              <Pagination
                currentPage={query.page ?? 1}
                totalItems={total}
                pageSize={query.per_page ?? DEFAULT_PER_PAGE}
                onPageChange={(page) => updateParams({ page: String(page) })}
              />
            </div>
          )}
        </CardBody>
      </Card>

      <Modal
        open={!!deleteQuoteState}
        onClose={() => setDeleteQuoteState(null)}
        title={t('quotes:deleteModal.title')}
        description={t('quotes:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteQuoteState(null)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteQuoteMutation.isPending}
              onClick={async () => {
                if (!deleteQuoteState) return
                await deleteQuoteMutation.mutateAsync(deleteQuoteState.id)
                setDeleteQuoteState(null)
              }}
            >
              {t('quotes:actions.delete')}
            </Button>
          </div>
        }
      >
        {deleteQuoteState && (
          <p className="text-sm text-fg-secondary">
            <Trans
              i18nKey="quotes:deleteModal.confirmText"
              values={{ number: deleteQuoteState.quote_number, title: deleteQuoteState.title }}
              components={{ bold: <strong className="text-fg" /> }}
            />
          </p>
        )}
      </Modal>
    </div>
  )
}

function IconButtonLike({ children }: { children: ReactNode }) {
  return (
    <span
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none',
      )}
    >
      {children}
    </span>
  )
}

function IconButton({
  label,
  onClick,
  children,
  danger,
  loading,
  disabled,
  title,
}: {
  label: string
  onClick: () => void
  children: ReactNode
  danger?: boolean
  loading?: boolean
  /** SYNCDESKTOP §8 (O102) — set while the action is refused offline. */
  disabled?: boolean
  /** Overrides the default `title={label}` so the refusal can name its own reason. */
  title?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={loading || disabled}
      aria-label={label}
      title={title ?? label}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none disabled:opacity-50',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
        danger && 'hover:text-danger',
      )}
    >
      {children}
    </button>
  )
}

function IconLinkButton({ label, to, children }: { label: string; to: string; children: ReactNode }) {
  return (
    <Link
      to={to}
      aria-label={label}
      title={label}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
        'transition-colors duration-150 motion-reduce:transition-none',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
      )}
    >
      {children}
    </Link>
  )
}
