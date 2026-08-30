// Kayıtlı Görünümler — ORTAK bileşen (Faz 14 / İz F, Attio C2). Her modülün liste sayfasına
// AYNI bileşen bağlanır (görev tanımı: "TEK ortak bileşen yaz, her liste sayfasına ayrı ayrı
// kopyalama"). Sayfalar filtreyi zaten `useSearchParams` ile URL'de tutuyor (bkz.
// `tickets/pages/TicketsListPage.tsx` deseni) — bir görünümü "uygulamak" yalnızca bu URL
// parametrelerini YENİDEN YAZMAKTIR; bu bileşen KENDİ BAŞINA hiçbir veri (deal/lead/...)
// ÇEKMEZ. `filterKeys`, sayfanın kendi URL şemasındaki filtre parametrelerinin adlarıdır
// (backend `filter.*` alanlarıyla BİREBİR aynı isim — Faz 6 sözleşmesi, bkz. sayfa dosyaları).
//
// GÜVENLİK (docs/PHASE-AUDIT.md §5.4): paylaşılan bir görünümü açmak = `query_json`'ı bu
// sayfanın URL'ine yazmak. Gerçek veri her zaman sayfanın KENDİ `useXxx()` hook'undan, AÇAN
// kullanıcının kendi oturumu/izniyle çekilir — bu bileşen o akışa hiç karışmaz.
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bookmark, Loader2, Share2, Trash2 } from 'lucide-react'
import { Button, Checkbox, Input, Modal } from '../../../components/ui'
import { getFieldErrors } from '../../../lib/axios'
import { useCreateSavedView, useDeleteSavedView, useSavedViews, useUpdateSavedView } from '../api/savedViewsApi'
import type { SavedView, SavedViewModule, SavedViewQuery } from '../types'

export type SavedViewsBarProps = {
  module: SavedViewModule
  /**
   * Bu sayfanın URL'inde `q`/`sort`/`page`/`per_page` DIŞINDA taşıdığı filtre parametrelerinin
   * adları (ör. tickets için `['status','priority','assigned_to','company_id','category',
   * 'tag_id','sla_breached','from','to']`) — backend `filter.*` beyaz listesiyle aynı isimler.
   */
  filterKeys: string[]
}

function readQueryFromSearchParams(searchParams: URLSearchParams, filterKeys: string[]): SavedViewQuery {
  const filter: Record<string, string> = {}
  for (const key of filterKeys) {
    const value = searchParams.get(key)
    if (value !== null && value !== '') filter[key] = value
  }

  const perPageRaw = searchParams.get('per_page')
  const perPage = perPageRaw ? Number(perPageRaw) : null

  return {
    q: searchParams.get('q') || null,
    sort: searchParams.get('sort') || null,
    per_page: perPage && Number.isFinite(perPage) ? perPage : null,
    filter,
  }
}

export function SavedViewsBar({ module, filterKeys }: SavedViewsBarProps) {
  const { t } = useTranslation(['savedViews', 'common'])
  const [searchParams, setSearchParams] = useSearchParams()

  const { data: views, isLoading, isError } = useSavedViews(module)
  const createSavedView = useCreateSavedView(module)
  const updateSavedView = useUpdateSavedView(module)
  const deleteSavedView = useDeleteSavedView(module)

  const [manageOpen, setManageOpen] = useState(false)
  const [showSaveForm, setShowSaveForm] = useState(false)
  const [name, setName] = useState('')
  const [isShared, setIsShared] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})
  const [confirmingDeleteId, setConfirmingDeleteId] = useState<number | null>(null)

  // Modül erişimi yoksa (görev tanımı: bu ikincil bir kontrol, sayfanın ana işlevini
  // bozmamalı) bileşen kendini SESSİZCE gizler — sayfa zaten bu modüle erişebildiği için
  // bu normal şartlarda oluşmaz.
  if (isError) return null

  function closeSaveForm() {
    setShowSaveForm(false)
    setName('')
    setIsShared(false)
    setFieldErrors({})
  }

  function applyView(view: SavedView) {
    const patch: Record<string, string | null> = { page: '1' }
    patch.q = view.query_json.q ?? null
    patch.sort = view.query_json.sort ?? null
    patch.per_page = view.query_json.per_page ? String(view.query_json.per_page) : null
    for (const key of filterKeys) {
      patch[key] = view.query_json.filter?.[key] ?? null
    }

    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null) next.delete(key)
        else next.set(key, value)
      }
      return next
    })

    setManageOpen(false)
  }

  async function handleSaveSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})

    try {
      await createSavedView.mutateAsync({
        module,
        name: name.trim(),
        query_json: readQueryFromSearchParams(searchParams, filterKeys),
        is_shared: isShared,
      })
      closeSaveForm()
    } catch (error) {
      const errors = getFieldErrors(error)
      if (errors) setFieldErrors(errors)
    }
  }

  function toggleShare(view: SavedView) {
    void updateSavedView.mutateAsync({ id: view.id, payload: { is_shared: !view.is_shared } })
  }

  async function confirmDelete(view: SavedView) {
    await deleteSavedView.mutateAsync(view.id)
    setConfirmingDeleteId(null)
  }

  const moduleLabel = t(`common:nav.${module}`)

  return (
    <>
      <Button
        type="button"
        variant="secondary"
        size="sm"
        leftIcon={<Bookmark className="size-4" aria-hidden="true" />}
        onClick={() => setManageOpen(true)}
      >
        {t('savedViews:actions.manage')}
      </Button>

      <Modal
        open={manageOpen}
        onClose={() => {
          setManageOpen(false)
          closeSaveForm()
          setConfirmingDeleteId(null)
        }}
        title={t('savedViews:modal.title', { module: moduleLabel })}
        size="sm"
      >
        <div className="flex flex-col gap-4">
          {!showSaveForm ? (
            <Button type="button" variant="secondary" onClick={() => setShowSaveForm(true)}>
              {t('savedViews:actions.saveCurrent')}
            </Button>
          ) : (
            <form onSubmit={handleSaveSubmit} className="flex flex-col gap-3 rounded-md border border-border-subtle p-3">
              <Input
                label={t('savedViews:form.nameLabel')}
                placeholder={t('savedViews:form.namePlaceholder')}
                value={name}
                onChange={(e) => setName(e.target.value)}
                error={fieldErrors.name?.[0]}
                autoFocus
              />
              <Checkbox
                label={t('savedViews:form.sharedLabel')}
                checked={isShared}
                onChange={(e) => setIsShared(e.target.checked)}
              />
              <p className="text-xs text-fg-muted">{t('savedViews:form.sharedHint')}</p>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="secondary" size="sm" onClick={closeSaveForm}>
                  {t('common:actions.cancel')}
                </Button>
                <Button type="submit" size="sm" loading={createSavedView.isPending} disabled={!name.trim()}>
                  {t('common:actions.save')}
                </Button>
              </div>
            </form>
          )}

          <div className="flex flex-col gap-1">
            {isLoading ? (
              <div className="flex items-center gap-2 py-4 text-sm text-fg-muted">
                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                {t('common:states.loading')}
              </div>
            ) : !views || views.length === 0 ? (
              <p className="py-2 text-sm text-fg-muted">{t('savedViews:list.empty')}</p>
            ) : (
              views.map((view) => (
                <div
                  key={view.id}
                  className="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 hover:bg-surface-2"
                >
                  {confirmingDeleteId === view.id ? (
                    <div className="flex w-full items-center justify-between gap-2">
                      <span className="truncate text-sm text-fg">{t('savedViews:deleteConfirm.title', { name: view.name })}</span>
                      <div className="flex shrink-0 gap-1">
                        <Button type="button" variant="secondary" size="sm" onClick={() => setConfirmingDeleteId(null)}>
                          {t('savedViews:deleteConfirm.cancel')}
                        </Button>
                        <Button
                          type="button"
                          variant="danger"
                          size="sm"
                          loading={deleteSavedView.isPending}
                          onClick={() => confirmDelete(view)}
                        >
                          {t('savedViews:deleteConfirm.confirm')}
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <>
                      <button
                        type="button"
                        onClick={() => applyView(view)}
                        aria-label={t('savedViews:aria.apply', { name: view.name })}
                        className="flex min-w-0 flex-1 flex-col items-start text-left"
                      >
                        <span className="truncate text-sm font-medium text-fg">{view.name}</span>
                        {view.is_shared && (
                          <span className="text-xs text-fg-muted">
                            {view.is_mine ? t('savedViews:list.sharedBadge') : t('savedViews:list.ownedBy', { name: view.owner_name ?? '' })}
                          </span>
                        )}
                      </button>
                      {view.is_mine && (
                        <div className="flex shrink-0 items-center gap-1">
                          <button
                            type="button"
                            onClick={() => toggleShare(view)}
                            aria-label={view.is_shared ? t('savedViews:aria.unshare', { name: view.name }) : t('savedViews:aria.share', { name: view.name })}
                            title={view.is_shared ? t('savedViews:actions.unshare') : t('savedViews:actions.share')}
                            className="inline-flex size-7 items-center justify-center rounded-md text-fg-muted hover:bg-surface-3 hover:text-fg"
                          >
                            <Share2 className={view.is_shared ? 'size-3.5 text-primary' : 'size-3.5'} aria-hidden="true" />
                          </button>
                          <button
                            type="button"
                            onClick={() => setConfirmingDeleteId(view.id)}
                            aria-label={t('savedViews:aria.delete', { name: view.name })}
                            title={t('common:actions.delete')}
                            className="inline-flex size-7 items-center justify-center rounded-md text-fg-muted hover:bg-surface-3 hover:text-danger"
                          >
                            <Trash2 className="size-3.5" aria-hidden="true" />
                          </button>
                        </div>
                      )}
                    </>
                  )}
                </div>
              ))
            )}
          </div>
        </div>
      </Modal>
    </>
  )
}
