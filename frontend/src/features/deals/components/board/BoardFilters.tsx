// Pano filtre çubuğu. Durumun tamamı URL query string'inde tutulur (bkz. `DealsBoardPage`);
// bu bileşen yalnızca taslak arama metnini kendi içinde tutar ve debounce ederek yukarı
// bildirir — her tuş vuruşunu URL'e yazmak geçmişi doldurur ve her karakterde pano
// sorgusunu yeniden tetiklerdi.
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Search, X } from 'lucide-react'
import { Button, Input, Select } from '../../../../components/ui'
import { useDealCompanyOptions, useDealOwnerOptions } from '../../api/boardApi'

const SEARCH_DEBOUNCE_MS = 300

function useDebouncedValue<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timeout = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timeout)
  }, [value, delay])
  return debounced
}

export type BoardFilterValues = {
  q: string
  owner_id: string
  company_id: string
  from: string
  to: string
}

export type BoardFiltersProps = {
  values: BoardFilterValues
  onChange: (patch: Partial<BoardFilterValues>) => void
  onClear: () => void
}

export function BoardFilters({ values, onChange, onClear }: BoardFiltersProps) {
  const { t } = useTranslation('deals')
  const owners = useDealOwnerOptions()
  const companies = useDealCompanyOptions()

  const [searchDraft, setSearchDraft] = useState(values.q)
  const debouncedSearch = useDebouncedValue(searchDraft, SEARCH_DEBOUNCE_MS)

  // Dışarıdan gelen `q` (geri tuşu, "temizle") taslağı da güncellemeli; aksi hâlde kutuda
  // eski metin kalır ve debounce onu bir sonraki turda geri yazar.
  const appliedQueryRef = useRef(values.q)
  useEffect(() => {
    if (values.q !== appliedQueryRef.current) {
      appliedQueryRef.current = values.q
      setSearchDraft(values.q)
    }
  }, [values.q])

  useEffect(() => {
    if (debouncedSearch === appliedQueryRef.current) return
    appliedQueryRef.current = debouncedSearch
    onChange({ q: debouncedSearch })
    // `onChange` her render'da yeniden üretilebilir; bağımlılığa eklemek her render'da
    // filtreyi yeniden uygulardı.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch])

  const hasActiveFilters =
    values.q !== '' ||
    values.owner_id !== '' ||
    values.company_id !== '' ||
    values.from !== '' ||
    values.to !== ''

  return (
    <div className="flex flex-wrap items-end gap-3 rounded-xl border border-border-subtle bg-surface-1 p-3">
      <div className="min-w-64 flex-1">
        <Input
          label={t('board.filters.searchLabel')}
          value={searchDraft}
          onChange={(event) => setSearchDraft(event.target.value)}
          placeholder={t('board.filters.searchPlaceholder')}
          leftIcon={<Search className="size-4" aria-hidden="true" />}
        />
      </div>

      {!owners.isForbidden && (
        <div className="w-48">
          <Select
            label={t('board.filters.ownerLabel')}
            value={values.owner_id}
            onChange={(event) => onChange({ owner_id: event.target.value })}
            options={[
              { value: '', label: t('board.filters.allOwners') },
              ...(owners.data ?? []).map((owner) => ({
                value: String(owner.id),
                label: owner.name,
              })),
            ]}
          />
        </div>
      )}

      {!companies.isForbidden && (
        <div className="w-48">
          <Select
            label={t('board.filters.companyLabel')}
            value={values.company_id}
            onChange={(event) => onChange({ company_id: event.target.value })}
            options={[
              { value: '', label: t('board.filters.allCompanies') },
              ...(companies.data ?? []).map((company) => ({
                value: String(company.id),
                label: company.name,
              })),
            ]}
          />
        </div>
      )}

      <div className="w-40">
        <Input
          label={t('board.filters.closeFrom')}
          type="date"
          value={values.from}
          onChange={(event) => onChange({ from: event.target.value })}
        />
      </div>

      <div className="w-40">
        <Input
          label={t('board.filters.closeTo')}
          type="date"
          value={values.to}
          onChange={(event) => onChange({ to: event.target.value })}
        />
      </div>

      {hasActiveFilters && (
        <Button
          variant="ghost"
          leftIcon={<X className="size-4" aria-hidden="true" />}
          onClick={() => {
            appliedQueryRef.current = ''
            setSearchDraft('')
            onClear()
          }}
        >
          {t('board.filters.clear')}
        </Button>
      )}
    </div>
  )
}
