// Teklif oluşturma/düzenleme sayfası — `/quotes/new` ve `/quotes/:id/edit`. Ayrı bir SAYFA
// (Modal DEĞİL, görev tanımı: "Modal bu iş için dar") çünkü kalem editörü başlı başına geniş bir
// tablo + toplamlar bloğu taşıyor.
//
// KİLİT DAVRANIŞI (docs/QUOTE-FINANCIALS.md + QuoteService::AMOUNT_LOCKED_FIELDS): `draft`
// DIŞINDAKİ her durumda `items`, `discount_type`, `discount_value`, `company_id`, `contact_id`
// PATCH'TEN DEĞİŞTİRİLEMEZ (422 `QUOTE_LOCKED`). `title`, `notes`, `terms`, `valid_until`,
// `deal_id` KİLİTLİ DEĞİLDİR — sunum metni/idari bağ, tutarı etkilemez. Bu yüzden kilitli bir
// teklifte SADECE kalem editörü + indirim + firma/kişi salt-okunur olur; başlık/fırsat/tarih/
// notlar/şartlar yine düzenlenebilir kalır ve kaydedilebilir. Değişiklik payload'ı kilitli
// alanları HİÇ GÖNDERMEZ (undefined) — UpdateQuoteRequest'in "missing" kuralı yalnızca
// status/quote_number/toplamlar gibi sunucu-hesaplı alanlar için, company_id/contact_id/items/
// discount_* için `sometimes` kuralı geçerli: gövdede bulunurlarsa QUOTE_LOCKED tetiklenir.
import { useEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Trans, useTranslation } from 'react-i18next'
import { ArrowLeft, GitBranch, Info, Lock, Save } from 'lucide-react'
import { Button, Card, CardBody, CardHeader, Input, Modal, Select, Skeleton, Textarea, toast } from '../../../components/ui'
import { getErrorMessage, getFieldErrors } from '../../../lib/axios'
import { usePermission } from '../../auth/hooks/usePermission'
import { CompanyCombobox } from '../components/CompanyCombobox'
import { DealCombobox } from '../components/DealCombobox'
import { QuoteItemsEditor } from '../components/QuoteItemsEditor'
import { toEditableItem, toQuoteItemInput } from '../utils/quoteItems'
import type { EditableQuoteItem } from '../utils/quoteItems'
import { QuoteTotalsPanel } from '../components/QuoteTotalsPanel'
import { useOnlineOnly } from '../../../platform/useOnlineOnly'
import { useQuoteCalculate } from '../hooks/useQuoteCalculate'
import { resolveProductPrice, useContactOptionsSearch } from '../api/catalogApi'
import type { CompanyOption, ContactOption, DealOption } from '../api/catalogApi'
import { usePriceLists } from '../../price-lists/api/priceListsApi'
import { useCreateQuote, useQuote, useReviseQuote, useUpdateQuote } from '../api/quotesApi'
import type { DiscountType, QuotePayload } from '../types'

// Form bu sürümde bir para birimi SEÇİCİSİ SUNMUYOR (İz E'nin kapsamı — bu görev yalnızca doğru
// sembolü basıyor, dönüşüm/seçim eklemiyor). Yeni bir teklif henüz `currency` taşımadığından
// (backend `quotes.currency` kolonu `default('TRY')`, `backend/config/exchange.php`
// `base_currency` da `'TRY'`) oluşturma modunda bu sabit kullanılır — sunucunun zaten
// uygulayacağı varsayılanla birebir aynı, uydurma bir değer değil.
const NEW_QUOTE_DEFAULT_CURRENCY = 'TRY'

/** Kilitli bir alanın altında/yanında gösterilen küçük ipucu — kullanıcı ALAN BAZINDA neden
 * değiştiremediğini görsün (koordinatör düzeltmesi: yalnızca kart altlığı yetmiyordu). */
function LockedFieldHint() {
  const { t } = useTranslation()
  return (
    <p className="mt-1 flex items-center gap-1 text-xs text-fg-muted">
      <Lock className="size-3" aria-hidden="true" />
      {t('quotes:form.lockedFieldHint')}
    </p>
  )
}

export function QuoteFormPage() {
  const { t } = useTranslation()
  const params = useParams<{ id?: string }>()
  const navigate = useNavigate()
  const { can } = usePermission()

  const isEdit = params.id !== undefined
  const quoteId = isEdit ? Number(params.id) : undefined
  const { data: quote, isLoading, isError, refetch } = useQuote(quoteId, { enabled: isEdit })

  const createQuote = useCreateQuote()
  const updateQuote = useUpdateQuote()
  const reviseQuote = useReviseQuote()
  // SYNCDESKTOP §8 (O102). `quotes.calculate` is §8 too, but it has no trigger of its own on
  // this page — it is a debounced effect on the item rows — so only the revision button is
  // guarded here; the calculate call itself is refused by the data layer (`work.ts`).
  const reviseGuard = useOnlineOnly('quotes.revise')

  const [title, setTitle] = useState('')
  const [deal, setDeal] = useState<DealOption | null>(null)
  const [company, setCompany] = useState<CompanyOption | null>(null)
  const [contact, setContact] = useState<ContactOption | null>(null)
  const [validUntil, setValidUntil] = useState('')
  const [notes, setNotes] = useState('')
  const [terms, setTerms] = useState('')
  const [discountType, setDiscountType] = useState<DiscountType>('amount')
  const [discountValue, setDiscountValue] = useState('0')
  const [items, setItems] = useState<EditableQuoteItem[]>([])
  const [priceListId, setPriceListId] = useState<number | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [confirmPriceList, setConfirmPriceList] = useState<{ nextId: number | null } | null>(null)
  const [updatingPrices, setUpdatingPrices] = useState(false)
  const [lastCompanyId, setLastCompanyId] = useState<number | null>(null)

  // Düzenleme modunda kayıtlı teklif geldiğinde formu BİR KEZ doldurur — `DealFormModal`'daki
  // `openKey`/`lastOpenKey` render-phase deseniyle AYNI (useEffect YERİNE): state'i render
  // sırasında ayarlamak React'ı bu render'ın JSX'ini atıp YENİ state'le HEMEN yeniden render
  // etmeye zorlar (bkz. React dokümanı "adjusting state when a prop changes"), bu yüzden
  // `lastCompanyId` de AYNI senkron blokta yazılır — aşağıdaki "firma değişince kişiyi sıfırla"
  // kontrolü hydration anında YANLIŞLIKLA tetiklenip az önce doldurulan `contact`'ı SIFIRLAMAZ.
  // useEffect kullanılsaydı bu iki güncelleme İKİ AYRI COMMIT'E düşer ve arada bir render
  // hydrated `contact`'ı görüp henüz eski `lastCompanyId`'yle karşılaştırıp SİLERDİ.
  const hydrationKey = isEdit ? (quote ? `edit-${quote.id}` : null) : 'create'
  const [lastHydrationKey, setLastHydrationKey] = useState<string | null>(null)
  if (hydrationKey !== null && hydrationKey !== lastHydrationKey) {
    setLastHydrationKey(hydrationKey)
    if (isEdit && quote) {
      setTitle(quote.title)
      setDeal(quote.deal ? { id: quote.deal.id, title: quote.deal.title } : null)
      setCompany(quote.company ? { id: quote.company.id, name: quote.company.name } : null)
      setContact(quote.contact ? { id: quote.contact.id, full_name: quote.contact.full_name } : null)
      setValidUntil(quote.valid_until ?? '')
      setNotes(quote.notes ?? '')
      setTerms(quote.terms ?? '')
      setDiscountType(quote.discount_type)
      setDiscountValue(String(quote.discount_value))
      setItems((quote.items ?? []).map(toEditableItem))
      setLastCompanyId(quote.company?.id ?? null)
    } else {
      setLastCompanyId(null)
    }
  }
  const hydrated = hydrationKey !== null && hydrationKey === lastHydrationKey

  // Düzenlemede teklifin KENDİ para birimi (`quote.currency`); oluşturmada henüz bir kayıt/seçim
  // olmadığından `NEW_QUOTE_DEFAULT_CURRENCY` (bkz. dosya başı gerekçe).
  const currency = isEdit && quote ? quote.currency : NEW_QUOTE_DEFAULT_CURRENCY

  const locked = isEdit && !!quote && quote.status !== 'draft'
  const revisable = !!quote && (quote.status === 'sent' || quote.status === 'rejected' || quote.status === 'expired')

  // Firma değişince kişi seçimini sıfırlar (aşağıdaki liste seçili firmaya göre filtrelenir) —
  // yukarıdaki hydration bloğu `lastCompanyId`'yi de senkron ayarladığı için bu yalnızca
  // KULLANICININ firma seçimini gerçekten değiştirdiği durumda eşleşmez.
  const currentCompanyId = company?.id ?? null
  if (hydrated && currentCompanyId !== lastCompanyId) {
    setLastCompanyId(currentCompanyId)
    setContact(null)
  }

  const { data: contactOptions } = useContactOptionsSearch(company?.id, '', { enabled: hydrated && !locked })
  // D şeridin `features/price-lists/` modülünden DOĞRUDAN yeniden kullanılıyor.
  const { data: priceListsData } = usePriceLists({ is_active: true, per_page: 100, sort: 'name' })
  const priceListOptions = priceListsData?.data

  const calcInput = useMemo(
    () => ({
      items: items.map(toQuoteItemInput),
      discount_type: discountType,
      discount_value: Number(discountValue) || 0,
    }),
    [items, discountType, discountValue],
  )
  const { result: calcResult, isCalculating } = useQuoteCalculate(calcInput, hydrated && !locked)

  const totals = locked && quote
    ? {
        subtotal: quote.subtotal,
        discount_amount: quote.discount_amount,
        tax_amount: quote.tax_amount,
        total: quote.total,
        tax_breakdown: quote.tax_breakdown ?? [],
      }
    : {
        subtotal: calcResult?.subtotal ?? 0,
        discount_amount: calcResult?.discount_amount ?? 0,
        tax_amount: calcResult?.tax_amount ?? 0,
        total: calcResult?.total ?? 0,
        tax_breakdown: calcResult?.tax_breakdown ?? [],
      }

  // İtemsRef: fiyat listesi değişiminde ürün bazlı kalemleri yeniden fiyatlandırırken en GÜNCEL
  // `items` state'ine erişmek için (async `resolveProductPrice` çağrıları arasında kullanıcı
  // başka bir satırı düzenlemiş olabilir).
  const itemsRef = useRef(items)
  useEffect(() => {
    itemsRef.current = items
  }, [items])

  function handlePriceListChange(value: string) {
    const nextId = value ? Number(value) : null
    const hasProductItems = items.some((item) => item.product_id !== null)
    if (hasProductItems) {
      setConfirmPriceList({ nextId })
    } else {
      setPriceListId(nextId)
    }
  }

  async function applyPriceListChange(updateExisting: boolean) {
    if (!confirmPriceList) return
    const { nextId } = confirmPriceList
    setPriceListId(nextId)
    setConfirmPriceList(null)

    if (!updateExisting) return

    setUpdatingPrices(true)
    try {
      const current = itemsRef.current
      const updated = await Promise.all(
        current.map(async (item) => {
          if (item.product_id === null) return item
          try {
            const resolved = await resolveProductPrice(item.product_id, nextId)
            return { ...item, unit_price: String(resolved.unit_price), tax_rate: String(resolved.tax_rate) }
          } catch {
            return item
          }
        }),
      )
      setItems(updated)
      toast.success(t('quotes:form.priceListChangeModal.pricesUpdated'))
    } finally {
      setUpdatingPrices(false)
    }
  }

  function fieldError(field: string): string | undefined {
    return fieldErrors[field]?.[0]
  }

  function buildPayload(): QuotePayload {
    const payload: QuotePayload = {
      title: title.trim(),
      deal_id: deal?.id ?? null,
      valid_until: validUntil || null,
      notes: notes || null,
      terms: terms || null,
    }
    // Kilitli (draft dışı) bir teklifte tutarı etkileyen alanlar HİÇ GÖNDERİLMEZ — gönderilse
    // (null olsa dahi) 422 QUOTE_LOCKED tetiklenir (bkz. dosya başındaki not).
    if (!locked) {
      payload.company_id = company?.id ?? null
      payload.contact_id = contact?.id ?? null
      payload.discount_type = discountType
      payload.discount_value = Number(discountValue) || 0
      payload.items = items.map(toQuoteItemInput)
    }
    return payload
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const errors: Record<string, string[]> = {}
    if (!title.trim()) errors.title = [t('quotes:form.validation.titleRequired')]
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    const payload = buildPayload()
    try {
      if (isEdit && quote) {
        const updated = await updateQuote.mutateAsync({ id: quote.id, payload })
        navigate(`/quotes/${updated.id}`)
      } else {
        const created = await createQuote.mutateAsync(payload)
        navigate(`/quotes/${created.id}`)
      }
    } catch (error) {
      const serverFieldErrors = getFieldErrors(error)
      if (serverFieldErrors) setFieldErrors(serverFieldErrors)
      else toast.error(getErrorMessage(error))
    }
  }

  async function handleRevise() {
    if (!quote) return
    try {
      const revised = await reviseQuote.mutateAsync(quote.id)
      navigate(`/quotes/${revised.id}/edit`)
    } catch {
      // Hata zaten useReviseQuote içinde toast ile gösterildi.
    }
  }

  if (isEdit && isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton variant="text" width={200} />
        <Card>
          <CardBody>
            <Skeleton variant="text" width={320} height={24} />
          </CardBody>
        </Card>
      </div>
    )
  }

  if (isEdit && (isError || !quote)) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <p className="text-sm text-fg-muted">{t('quotes:form.loadError')}</p>
        <Button variant="secondary" onClick={() => refetch()}>
          {t('quotes:retry')}
        </Button>
      </div>
    )
  }

  const isPending = createQuote.isPending || updateQuote.isPending
  const priceListSelectOptions = [
    { value: '', label: t('quotes:form.priceListDefaultOption') },
    ...(priceListOptions ?? []).map((pl) => ({
      value: String(pl.id),
      label: pl.is_default ? t('quotes:form.priceListDefaultSuffix', { name: pl.name }) : pl.name,
    })),
  ]
  const contactSelectOptions = [
    { value: '', label: t('quotes:form.contactNone') },
    ...(contactOptions ?? []).map((c) => ({ value: String(c.id), label: c.full_name })),
  ]

  return (
    <div className="flex flex-col gap-4">
      <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-xs text-fg-muted">
        <Link to="/quotes" className="inline-flex items-center gap-1 hover:text-fg">
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          {t('quotes:breadcrumb.quotes')}
        </Link>
        <span className="mx-1">/</span>
        <span className="text-primary">{isEdit ? quote?.quote_number : t('quotes:form.titleCreate')}</span>
      </nav>

      {locked && quote && (
        <div className="flex flex-col gap-2 rounded-lg bg-warning-tint p-4 text-warning sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-start gap-2">
            <Info className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div className="flex flex-col gap-1 text-sm">
              <p>
                <Trans
                  i18nKey="quotes:form.lockedBanner.statusText"
                  values={{ status: t(`enums:quote.status.${quote.status}`) }}
                  components={{ bold: <strong /> }}
                />
              </p>
              {revisable ? (
                <p>{t('quotes:form.lockedBanner.revisableHint')}</p>
              ) : (
                <p>{t('quotes:form.lockedBanner.notRevisableHint')}</p>
              )}
            </div>
          </div>
          {revisable && can('quotes.create') && (
            <Button
              type="button"
              variant="secondary"
              leftIcon={<GitBranch className="size-4" aria-hidden="true" />}
              loading={reviseQuote.isPending}
              disabled={reviseGuard.offline}
              title={reviseGuard.title}
              onClick={handleRevise}
            >
              {t('quotes:actions.createRevision')}
            </Button>
          )}
        </div>
      )}

      <form onSubmit={handleSubmit} className="flex flex-col gap-4">
        <Card>
          <CardHeader title={isEdit ? t('quotes:form.cardTitleEdit', { number: quote?.quote_number }) : t('quotes:form.titleCreate')} />
          <CardBody className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Input
                  label={t('quotes:fields.title')}
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  error={fieldError('title')}
                  required
                />
              </div>
              <DealCombobox value={deal} onChange={setDeal} error={fieldError('deal_id')} />
              <Input
                label={t('quotes:fields.validUntil')}
                type="date"
                value={validUntil}
                onChange={(e) => setValidUntil(e.target.value)}
                error={fieldError('valid_until')}
              />
              <div>
                <CompanyCombobox value={company} onChange={setCompany} error={fieldError('company_id')} disabled={locked} />
                {locked && <LockedFieldHint />}
              </div>
              <div>
                <Select
                  label={t('quotes:fields.contact')}
                  value={contact ? String(contact.id) : ''}
                  onChange={(e) => {
                    const id = e.target.value ? Number(e.target.value) : null
                    const found = (contactOptions ?? []).find((c) => c.id === id)
                    setContact(found ?? null)
                  }}
                  options={contactSelectOptions}
                  disabled={locked}
                  error={fieldError('contact_id')}
                />
                {locked && <LockedFieldHint />}
              </div>
            </div>
            <Textarea label={t('quotes:fields.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} />
            <Textarea label={t('quotes:fields.terms')} value={terms} onChange={(e) => setTerms(e.target.value)} rows={3} />
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title={t('quotes:form.itemsTitle')}
            subtitle={
              locked && (
                <span className="flex items-center gap-1">
                  <Lock className="size-3" aria-hidden="true" />
                  {t('quotes:form.itemsLockedHint')}
                </span>
              )
            }
            action={
              !locked && (
                <div className="w-56">
                  <Select
                    label={t('quotes:form.priceListLabel')}
                    value={priceListId ? String(priceListId) : ''}
                    onChange={(e) => handlePriceListChange(e.target.value)}
                    options={priceListSelectOptions}
                    disabled={updatingPrices}
                  />
                </div>
              )
            }
          />
          <CardBody>
            <QuoteItemsEditor
              items={items}
              onChange={setItems}
              priceListId={priceListId}
              currency={currency}
              fieldErrors={fieldErrors}
              readOnly={locked}
            />
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title={t('quotes:form.discountTitle')}
            subtitle={
              locked && (
                <span className="flex items-center gap-1">
                  <Lock className="size-3" aria-hidden="true" />
                  {t('quotes:form.discountLockedHint')}
                </span>
              )
            }
          />
          <CardBody>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:max-w-md">
              <Select
                label={t('quotes:form.discountTypeLabel')}
                value={discountType}
                onChange={(e) => setDiscountType(e.target.value as DiscountType)}
                options={[
                  { value: 'amount', label: t('quotes:form.discountAmountOption') },
                  { value: 'percent', label: t('quotes:form.discountPercentOption') },
                ]}
                disabled={locked}
                error={fieldError('discount_type')}
              />
              <Input
                label={t('quotes:form.discountValueLabel')}
                type="number"
                min={0}
                max={discountType === 'percent' ? 100 : undefined}
                step="0.01"
                value={discountValue}
                onChange={(e) => setDiscountValue(e.target.value)}
                disabled={locked}
                // Faz 14 kapanışı — sabit 'TRY' yerine bu teklifin KENDİ `currency`'si (yukarıdaki
                // `currency` yereli: düzenlemede `quote.currency`, oluşturmada
                // `NEW_QUOTE_DEFAULT_CURRENCY`). ISO kodu basılıyor, SİMGE (₺/$/€/£) değil:
                // `lib/money.ts` bağımsız bir "yalnızca sembol" yolu sunmuyor (yalnızca
                // `formatMoney`/`formatMoneyCompact` tam biçimli tutar döner) ve money.ts bu
                // şeridin kapsamında DEĞİŞTİRİLEMEZ; formatMoney(0, currency) çıktısından sembolü
                // string ayrıştırmayla çekmek locale'e göre önek/sonek sırası değiştiği için
                // kırılgan olur, kod->sembol eşlemesini elle yazmak da yasak — bu yüzden ham ISO
                // kodu (`TRY`/`USD`/...) tercih edildi.
                rightIcon={<span className="text-xs text-fg-muted">{discountType === 'percent' ? '%' : currency}</span>}
                error={fieldError('discount_value')}
              />
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title={t('quotes:form.totalsTitle')} />
          <CardBody>
            <QuoteTotalsPanel
              subtotal={totals.subtotal}
              discountType={discountType}
              discountValue={Number(discountValue) || 0}
              discountAmount={totals.discount_amount}
              taxAmount={totals.tax_amount}
              total={totals.total}
              taxBreakdown={totals.tax_breakdown}
              currency={currency}
              isCalculating={!locked && isCalculating}
            />
          </CardBody>
        </Card>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" leftIcon={<Save className="size-4" aria-hidden="true" />} loading={isPending}>
            {t('common:actions.save')}
          </Button>
        </div>
      </form>

      <Modal
        open={!!confirmPriceList}
        onClose={() => applyPriceListChange(false)}
        title={t('quotes:form.priceListChangeModal.title')}
        description={t('quotes:form.priceListChangeModal.description')}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => applyPriceListChange(false)}>
              {t('quotes:form.priceListChangeModal.keepPrices')}
            </Button>
            <Button onClick={() => applyPriceListChange(true)}>{t('quotes:form.priceListChangeModal.updatePrices')}</Button>
          </div>
        }
      >
        <p className="text-sm text-fg-secondary">{t('quotes:form.priceListChangeModal.note')}</p>
      </Modal>
    </div>
  )
}
