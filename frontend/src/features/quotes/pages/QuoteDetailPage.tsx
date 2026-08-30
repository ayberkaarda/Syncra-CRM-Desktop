// Teklif detay sayfası — özet, revizyon şeridi, kalem tablosu, toplamlar, PDF önizleme, aksiyonlar.
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Briefcase,
  Building2,
  Download,
  FileText,
  GitBranch,
  Pencil,
  Send,
  Trash2,
  User as UserIcon,
} from 'lucide-react'
import { Badge, Button, Card, CardBody, CardHeader, Modal, Select, Skeleton, Textarea } from '../../../components/ui'
import { usePermission } from '../../auth/hooks/usePermission'
import { QuoteStatusBadge } from '../components/QuoteStatusBadge'
import { QuoteTotalsPanel } from '../components/QuoteTotalsPanel'
import { useQuotePdfPreview } from '../hooks/useQuotePdfPreview'
import { formatDate, formatDateTime } from '../../../lib/datetime'
import { formatMoney } from '../../../lib/money'
import {
  buildQuotePdfUrl,
  useChangeQuoteStatus,
  useDeleteQuote,
  useParentQuote,
  useQuote,
  useQuoteRevisionFamily,
  useReviseQuote,
  useSendQuote,
} from '../api/quotesApi'
import { MANUAL_QUOTE_STATUSES } from '../types'
import type { QuoteStatus } from '../types'
import { companyGroupConfig, contactGroupConfig, dealGroupConfig } from '../../related/adapters'
import { RelatedRecordsPanel } from '../../related/RelatedRecordsPanel'

export function QuoteDetailPage() {
  const { t } = useTranslation()
  const params = useParams<{ id: string }>()
  const quoteId = Number(params.id)
  const navigate = useNavigate()
  const { can } = usePermission()

  const { data: quote, isLoading, isError, refetch } = useQuote(Number.isFinite(quoteId) ? quoteId : undefined)
  const { data: parentQuote } = useParentQuote(quote?.parent_quote_id)
  const { data: revisionFamily } = useQuoteRevisionFamily(quote)
  // `quote` bu satırda henüz `undefined` olabilir (sayfa hâlâ yükleniyor) — hook `quoteId
  // undefined` iken hiç istek atmaz (bkz. `useQuotePdfPreview` dosya başı sözleşmesi, madde 6).
  // Çağrı, altındaki `isLoading`/`isError` early return'lerinden ÖNCE durur; aksi hâlde Hooks
  // Kuralları ihlal edilir (koşullu hook çağrısı).
  const pdfPreview = useQuotePdfPreview(quote?.id)

  const sendQuote = useSendQuote()
  const deleteQuote = useDeleteQuote()
  const reviseQuote = useReviseQuote()
  const changeStatus = useChangeQuoteStatus()

  const [deleteOpen, setDeleteOpen] = useState(false)
  const [statusOpen, setStatusOpen] = useState(false)
  const [statusTarget, setStatusTarget] = useState<QuoteStatus>('accepted')
  const [statusReason, setStatusReason] = useState('')

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton variant="text" width={200} />
        <Card>
          <CardBody>
            <Skeleton variant="text" width={280} height={24} />
          </CardBody>
        </Card>
      </div>
    )
  }

  if (isError || !quote) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <p className="text-sm text-fg-muted">{t('quotes:detail.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('quotes:retry')}
        </Button>
      </div>
    )
  }

  // KARAR: "Düzenle" TÜM durumlarda gösterilir, yalnızca `draft`'ta değil — backend
  // `QuoteService::AMOUNT_LOCKED_FIELDS` kilidi `status !== 'draft'` bazlıdır ve `accepted`'ı
  // diğerlerinden AYIRMAZ; `title`/`notes`/`terms`/`valid_until`/`deal_id` her durumda
  // düzenlenebilir kalır (form zaten bu alanları locked=false gönderir, kilitli alanları hiç
  // göndermez). `accepted` için ayrıca ÖZEL KISITLAMA KONMADI: `revise()` `accepted`'ı reddeder
  // (QUOTE_NOT_REVISABLE) — yani kabul edilmiş bir teklifte başlıkta/notta bir yazım hatasını
  // düzeltmenin TEK yolu bu düzenleme formudur; "Düzenle"yi burada da gizlemek kullanıcıyı
  // çıkışsız bırakırdı. Bu, backend'in bilinçli yetki sınırına UYMAKTIR — UI'da fazladan bir
  // kısıtlama icat etmek `usePermission.ts`'in kendi notuyla ("asıl yetki kontrolü daima
  // backend'dedir") çelişirdi.
  const canEdit = can('quotes.update')
  const canSend = quote.status === 'draft' && quote.items_count > 0 && can('quotes.send')
  const canChangeStatus = quote.status === 'sent' && can('quotes.update')
  const isRevisable = ['sent', 'rejected', 'expired'].includes(quote.status)
  const canRevise = isRevisable && can('quotes.create')
  const canDelete = can('quotes.delete') && quote.status !== 'accepted' && quote.status !== 'rejected'

  const siblings = (revisionFamily ?? []).filter((q) => q.id !== quote.id)
  const pdfUrl = buildQuotePdfUrl(quote.id)

  async function handleSend() {
    await sendQuote.mutateAsync(quote!.id)
  }

  async function handleRevise() {
    try {
      const revised = await reviseQuote.mutateAsync(quote!.id)
      navigate(`/quotes/${revised.id}/edit`)
    } catch {
      // toast zaten hook içinde gösterildi
    }
  }

  async function handleChangeStatus() {
    try {
      await changeStatus.mutateAsync({ id: quote!.id, status: statusTarget, reason: statusReason || undefined })
      setStatusOpen(false)
      setStatusReason('')
    } catch {
      // toast zaten hook içinde gösterildi
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/quotes" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('quotes:breadcrumb.quotes')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{quote.quote_number}</span>
      </nav>

      {quote.parent_quote_id !== null && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-primary-tint px-4 py-3 text-sm text-primary">
          <GitBranch className="size-4 shrink-0" aria-hidden="true" />
          <span>
            {parentQuote ? (
              <Trans
                i18nKey="quotes:detail.revisionNoteWithParent"
                values={{ quoteNumber: parentQuote.quote_number, revision: quote.revision }}
                components={{ link: <Link to={`/quotes/${parentQuote.id}`} className="font-medium underline" /> }}
              />
            ) : (
              t('quotes:detail.revisionNoteNoParent', { revision: quote.revision })
            )}
          </span>
        </div>
      )}

      {siblings.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-surface-2 px-4 py-3 text-sm text-fg-secondary">
          <GitBranch className="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
          <span>{t('quotes:detail.siblingsLabel')}</span>
          {siblings.map((sibling) => (
            <Link key={sibling.id} to={`/quotes/${sibling.id}`} className="font-medium text-primary hover:underline">
              {sibling.quote_number}
            </Link>
          ))}
        </div>
      )}

      <Card>
        <CardHeader
          // `CardHeader`'ın `title` prop'u `HTMLAttributes<HTMLDivElement>`'i de içerdiğinden
          // (native `title` tooltip özniteliğiyle isim çakışması) yalnızca STRING kabul eder —
          // JSX verilirse tip hatası. Teklif no + başlık burada düz metin, monospace vurgusu
          // olmadan; büyük toplam `subtitle`'da (o alan çakışmıyor, JSX kabul eder).
          title={`${quote.quote_number} — ${quote.title}`}
          subtitle={<span className="text-2xl font-semibold text-fg">{formatMoney(quote.total, quote.currency)}</span>}
          action={
            <div className="flex flex-wrap items-center justify-end gap-2">
              {canEdit && (
                <Button variant="secondary" leftIcon={<Pencil className="size-4" aria-hidden="true" />} onClick={() => navigate(`/quotes/${quote.id}/edit`)}>
                  {t('quotes:actions.edit')}
                </Button>
              )}
              {canSend && (
                <Button leftIcon={<Send className="size-4" aria-hidden="true" />} loading={sendQuote.isPending} onClick={handleSend}>
                  {t('quotes:actions.send')}
                </Button>
              )}
              {canChangeStatus && (
                <Button variant="secondary" onClick={() => setStatusOpen(true)}>
                  {t('quotes:actions.changeStatus')}
                </Button>
              )}
              {canRevise && (
                <Button
                  variant="secondary"
                  leftIcon={<GitBranch className="size-4" aria-hidden="true" />}
                  loading={reviseQuote.isPending}
                  onClick={handleRevise}
                >
                  {t('quotes:actions.revise')}
                </Button>
              )}
              <a href={pdfUrl} target="_blank" rel="noreferrer">
                <Button variant="secondary" leftIcon={<Download className="size-4" aria-hidden="true" />}>
                  {t('quotes:actions.pdfDownload')}
                </Button>
              </a>
              {canDelete && (
                <Button variant="danger" leftIcon={<Trash2 className="size-4" aria-hidden="true" />} onClick={() => setDeleteOpen(true)}>
                  {t('quotes:actions.delete')}
                </Button>
              )}
            </div>
          }
        >
          <div className="flex flex-wrap items-center gap-1.5 pt-1">
            <QuoteStatusBadge status={quote.status} />
            {quote.revision > 1 && <Badge variant="neutral">{t('quotes:detail.revisionBadge', { revision: quote.revision })}</Badge>}
            {quote.is_expired && quote.status === 'sent' && <Badge variant="warning">{t('enums:quote.status.expired')}</Badge>}
          </div>
        </CardHeader>
        <CardBody className="flex flex-col gap-6">
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <DetailField label={t('quotes:fields.company')}>
              {quote.company ? (
                <Link to={`/companies/${quote.company.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <Building2 className="size-3.5" aria-hidden="true" />
                  {quote.company.name}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('quotes:fields.contact')}>
              {quote.contact ? (
                <Link to={`/contacts/${quote.contact.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <UserIcon className="size-3.5" aria-hidden="true" />
                  {quote.contact.full_name}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('quotes:fields.deal')}>
              {quote.deal ? (
                <Link to={`/deals/${quote.deal.id}`} className="flex items-center gap-1.5 text-sm text-primary hover:underline">
                  <Briefcase className="size-3.5" aria-hidden="true" />
                  {quote.deal.title}
                </Link>
              ) : (
                <span className="text-sm text-fg-muted">—</span>
              )}
            </DetailField>
            <DetailField label={t('quotes:fields.validUntil')}>
              <span className={quote.is_expired ? 'text-sm font-medium text-warning' : 'text-sm text-fg'}>
                {formatDate(quote.valid_until)}
                {quote.is_expired && t('quotes:detail.expiredSuffix')}
              </span>
            </DetailField>
            <DetailField label={t('quotes:fields.creator')}>
              <span className="text-sm text-fg">{quote.creator?.name ?? '—'}</span>
            </DetailField>
            <DetailField label={t('quotes:fields.createdAt')}>
              <span className="text-sm text-fg">{formatDateTime(quote.created_at)}</span>
            </DetailField>
            {quote.sent_at && (
              <DetailField label={t('quotes:fields.sentAt')}>
                <span className="text-sm text-fg">{formatDateTime(quote.sent_at)}</span>
              </DetailField>
            )}
            {quote.accepted_at && (
              <DetailField label={t('quotes:fields.acceptedAt')}>
                <span className="text-sm text-success">{formatDateTime(quote.accepted_at)}</span>
              </DetailField>
            )}
            {quote.rejected_at && (
              <DetailField label={t('quotes:fields.rejectedAt')}>
                <span className="text-sm text-danger">{formatDateTime(quote.rejected_at)}</span>
              </DetailField>
            )}
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={t('quotes:form.itemsTitle')} subtitle={t('quotes:detail.itemsCount', { count: quote.items_count })} />
        <CardBody noPadding>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-border-subtle">
                  <th className="px-4 py-3 text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.position')}</th>
                  <th className="px-4 py-3 text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.item')}</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.quantity')}</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.unitPrice')}</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.discountPercent')}</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.taxRate')}</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-fg-muted">{t('quotes:detail.itemsTable.columns.lineTotal')}</th>
                </tr>
              </thead>
              <tbody>
                {(quote.items ?? []).map((item) => (
                  <tr key={item.id} className="border-b border-border-subtle last:border-0">
                    <td className="px-4 py-3 text-fg-muted">{item.position}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-col">
                        <span className="text-fg">{item.name}</span>
                        {item.description && <span className="text-xs text-fg-muted">{item.description}</span>}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right text-fg">{item.quantity}</td>
                    <td className="px-4 py-3 text-right text-fg">{formatMoney(item.unit_price, quote.currency)}</td>
                    <td className="px-4 py-3 text-right text-fg">%{item.discount_percent}</td>
                    <td className="px-4 py-3 text-right text-fg">%{item.tax_rate}</td>
                    <td className="px-4 py-3 text-right font-medium text-fg">{formatMoney(item.line_total, quote.currency)}</td>
                  </tr>
                ))}
                {(quote.items ?? []).length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-sm text-fg-muted">
                      {t('quotes:detail.itemsTable.empty')}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={t('quotes:form.totalsTitle')} />
        <CardBody>
          <QuoteTotalsPanel
            subtotal={quote.subtotal}
            discountType={quote.discount_type}
            discountValue={quote.discount_value}
            discountAmount={quote.discount_amount}
            taxAmount={quote.tax_amount}
            total={quote.total}
            taxBreakdown={quote.tax_breakdown ?? []}
            currency={quote.currency}
          />
        </CardBody>
      </Card>

      {(quote.notes || quote.terms) && (
        <Card>
          <CardHeader title={t('quotes:detail.notesTermsTitle')} />
          <CardBody className="flex flex-col gap-4">
            {quote.notes && (
              <div className="flex flex-col gap-1.5">
                <span className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('quotes:fields.notes')}</span>
                <p className="whitespace-pre-wrap text-sm text-fg-secondary">{quote.notes}</p>
              </div>
            )}
            {quote.terms && (
              <div className="flex flex-col gap-1.5">
                <span className="text-xs font-medium uppercase tracking-wide text-fg-muted">{t('quotes:fields.terms')}</span>
                <p className="whitespace-pre-wrap text-sm text-fg-secondary">{quote.terms}</p>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {/* Faz 14 / İz F — C3 ilişkili-kayıtlar paneli (docs/PHASE-INTL.md §3). Ters yön
          (`firma → teklifler`, `fırsat → teklifler`) CompanyController/DealController'da zaten
          var — burada YENİ açılan tek şey teklifin KENDİ yönü: teklif → firma/fırsat/kişi.
          Backend `related.*` yalnızca ilgili modülün izniyle doludur (izinsiz anahtar hiç
          gelmez) — bu yüzden `quote.related?.x` üzerinden `undefined` ayrımı korunuyor,
          `quote.company`/`quote.deal`/`quote.contact` (yukarıdaki özet alanları) DEĞİL. */}
      <RelatedRecordsPanel
        groups={[
          companyGroupConfig('company', t('related:groups.company'), t('related:empty.company'), quote.related?.company),
          dealGroupConfig('deal', t('related:groups.deal'), t('related:empty.deal'), quote.related?.deal),
          contactGroupConfig('contact', t('related:groups.contact'), t('related:empty.contact'), quote.related?.contact),
        ]}
      />

      <Card>
        <CardHeader
          title={t('quotes:detail.pdfPreviewTitle')}
          action={
            <a href={pdfUrl} target="_blank" rel="noreferrer">
              <Button variant="secondary" size="sm" leftIcon={<FileText className="size-4" aria-hidden="true" />}>
                {t('quotes:actions.openNewTab')}
              </Button>
            </a>
          }
        />
        <CardBody noPadding>
          {/* Sabit yükseklik TOKEN SÖZLEŞMESİ gereği arbitrary Tailwind sınıfıyla (`h-[720px]`)
              DEĞİL, inline `style` ile verilir — `ScoreIndicator`/`SlaCountdown` ile aynı
              kabul edilmiş desen (dinamik/precise boyut için tek çıkış yolu).

              `iframe`'e DOĞRUDAN `pdfUrl` (çapraz origin API adresi) VERİLMEZ: backend'in
              `SecurityHeaders` middleware'i `X-Frame-Options: DENY` gönderdiğinden tarayıcı bu
              çerçevelemeyi reddeder (bkz. `useQuotePdfPreview` dosya başı yorumu). Bunun yerine
              PDF axios ile blob olarak indirilip aynı-origin bir `blob:` URL'e çevrilir. */}
          {pdfPreview.status === 'loading' && (
            <Skeleton variant="rect" className="w-full rounded-b-lg" style={{ height: 720 }} />
          )}
          {pdfPreview.status === 'error' && (
            <div
              className="flex w-full flex-col items-center justify-center gap-1.5 rounded-b-lg bg-surface-2 px-6 text-center"
              style={{ height: 720 }}
            >
              <p className="text-sm text-fg-secondary">{t('quotes:detail.pdfPreview.error', { message: pdfPreview.message })}</p>
              <p className="text-xs text-fg-muted">{t('quotes:detail.pdfPreview.errorHint')}</p>
            </div>
          )}
          {pdfPreview.status === 'success' && (
            <iframe
              src={pdfPreview.url}
              title={t('quotes:detail.pdfPreview.iframeTitle')}
              className="w-full rounded-b-lg border-0"
              style={{ height: 720 }}
            />
          )}
        </CardBody>
      </Card>

      <Modal
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title={t('quotes:deleteModal.title')}
        description={t('quotes:deleteModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleteOpen(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={deleteQuote.isPending}
              onClick={async () => {
                await deleteQuote.mutateAsync(quote.id)
                setDeleteOpen(false)
                navigate('/quotes')
              }}
            >
              {t('quotes:actions.delete')}
            </Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">
          <Trans
            i18nKey="quotes:deleteModal.confirmText"
            values={{ number: quote.quote_number, title: quote.title }}
            components={{ bold: <strong className="text-fg" /> }}
          />
        </p>
      </Modal>

      <Modal
        open={statusOpen}
        onClose={() => setStatusOpen(false)}
        title={t('quotes:statusModal.title')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setStatusOpen(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button loading={changeStatus.isPending} onClick={handleChangeStatus}>
              {t('common:actions.save')}
            </Button>
          </div>
        }
      >
        <div className="flex flex-col gap-4">
          <Select
            label={t('quotes:statusModal.newStatusLabel')}
            value={statusTarget}
            onChange={(e) => setStatusTarget(e.target.value as QuoteStatus)}
            options={MANUAL_QUOTE_STATUSES.map((status) => ({ value: status, label: t(`enums:quote.status.${status}`) }))}
          />
          <Textarea
            label={t('quotes:statusModal.reasonLabel')}
            value={statusReason}
            onChange={(e) => setStatusReason(e.target.value)}
            rows={3}
          />
        </div>
      </Modal>
    </div>
  )
}

function DetailField({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <p className="text-xs font-medium uppercase tracking-wide text-fg-muted">{label}</p>
      {children}
    </div>
  )
}
