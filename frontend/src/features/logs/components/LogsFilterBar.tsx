// Ortak filtre çubuğu — dört sekmede de aynı: kullanıcı, tarih aralığı, serbest arama.
// Sekmeye özel ek filtreler (olay tipi, varlık tipi) `children` ile enjekte edilir.
import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Search } from 'lucide-react'
import { Input, Select } from '../../../components/ui'
import type { LogUserOption } from '../types'

export type LogsFilterBarProps = {
  searchDraft: string
  onSearchDraftChange: (value: string) => void
  userId: string
  onUserIdChange: (value: string) => void
  userOptions: LogUserOption[]
  showUserFilter: boolean
  from: string
  onFromChange: (value: string) => void
  to: string
  onToChange: (value: string) => void
  children?: ReactNode
}

export function LogsFilterBar({
  searchDraft,
  onSearchDraftChange,
  userId,
  onUserIdChange,
  userOptions,
  showUserFilter,
  from,
  onFromChange,
  to,
  onToChange,
  children,
}: LogsFilterBarProps) {
  const { t } = useTranslation('logs')

  const userSelectOptions = [
    { value: '', label: t('logs:filterBar.allUsers') },
    ...userOptions.map((user) => ({
      value: String(user.id),
      label: `${user.name} (${user.email})`,
    })),
  ]

  return (
    <div className="flex flex-col gap-3 border-b border-border-subtle p-4 lg:flex-row lg:flex-wrap lg:items-end">
      <div className="w-full lg:max-w-xs">
        <Input
          value={searchDraft}
          onChange={(e) => onSearchDraftChange(e.target.value)}
          placeholder={t('logs:filterBar.searchPlaceholder')}
          leftIcon={<Search className="size-4" aria-hidden="true" />}
          aria-label={t('logs:filterBar.searchAria')}
        />
      </div>

      {showUserFilter && (
        <div className="w-full lg:w-56">
          <Select
            value={userId}
            onChange={(e) => onUserIdChange(e.target.value)}
            options={userSelectOptions}
            aria-label={t('logs:filterBar.userFilterAria')}
          />
        </div>
      )}

      <div className="flex w-full items-end gap-2 lg:w-auto">
        <div className="w-full lg:w-40">
          <Input
            type="date"
            value={from}
            onChange={(e) => onFromChange(e.target.value)}
            aria-label={t('logs:filterBar.fromDateAria')}
            max={to || undefined}
          />
        </div>
        <span className="pb-2.5 text-xs text-fg-muted">—</span>
        <div className="w-full lg:w-40">
          <Input
            type="date"
            value={to}
            onChange={(e) => onToChange(e.target.value)}
            aria-label={t('logs:filterBar.toDateAria')}
            min={from || undefined}
          />
        </div>
      </div>

      {children}
    </div>
  )
}
