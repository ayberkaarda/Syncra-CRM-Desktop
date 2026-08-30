// Duplicate uyarı paneli — Faz 6/E'nin kilit özelliği. Kullanıcı e-posta/telefon/
// ad-soyad alanlarını doldurdukça (debounce ~500ms) `POST /api/leads/check-duplicates`
// çağrılır (bkz. `LeadFormModal`/`ConvertLeadModal`); sonuç varsa burada gösterilir.
// KAYDETMEYİ ENGELLEMEZ — yalnızca uyarır, karar kullanıcının.
import { useTranslation } from 'react-i18next'
import { AlertTriangle, ExternalLink } from 'lucide-react'
import { cn } from '../../../lib/cn'
import { DUPLICATE_LEVEL_LABEL_KEY, MATCH_REASON_LABEL_KEY } from '../utils'
import type { DuplicateCandidate } from '../types'

export type DuplicateWarningPanelProps = {
  candidates: DuplicateCandidate[]
  loading?: boolean
}

function candidateHref(candidate: DuplicateCandidate): string {
  return candidate.type === 'lead' ? `/leads/${candidate.id}` : `/contacts/${candidate.id}`
}

export function DuplicateWarningPanel({ candidates, loading }: DuplicateWarningPanelProps) {
  const { t } = useTranslation('leads')
  if (!loading && candidates.length === 0) return null

  return (
    <div className="flex flex-col gap-2" aria-live="polite">
      {loading && candidates.length === 0 && (
        <p className="text-xs text-fg-muted">{t('leads:duplicateWarningPanel.checking')}</p>
      )}
      {candidates.map((candidate) => {
        const isStrong = candidate.level === 'strong'
        return (
          <a
            key={`${candidate.type}-${candidate.id}`}
            href={candidateHref(candidate)}
            target="_blank"
            rel="noreferrer"
            className={cn(
              'flex flex-col gap-1.5 rounded-md p-3 text-sm transition-colors duration-150 motion-reduce:transition-none',
              'hover:opacity-90',
              isStrong ? 'bg-danger-tint text-danger' : 'bg-warning-tint text-warning'
            )}
          >
            <div className="flex items-center justify-between gap-2">
              <span className="flex items-center gap-1.5 font-medium">
                <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                {candidate.type === 'lead' ? t('leads:duplicateWarningPanel.leadLabel') : t('leads:duplicateWarningPanel.contactLabel')}:{' '}
                {candidate.name || '—'}
                <span className="font-normal opacity-80">— {t(DUPLICATE_LEVEL_LABEL_KEY[candidate.level])}</span>
              </span>
              <ExternalLink className="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-0.5 text-xs opacity-90">
              {candidate.email && <span>{t('leads:duplicateWarningPanel.emailLabel', { value: candidate.email })}</span>}
              {candidate.phone && <span>{t('leads:duplicateWarningPanel.phoneLabel', { value: candidate.phone })}</span>}
              {candidate.company && <span>{t('leads:duplicateWarningPanel.companyLabel', { value: candidate.company })}</span>}
            </div>
            <div className="flex flex-wrap gap-1.5 text-xs">
              {candidate.matched_on.map((reason) => (
                <span key={reason} className="rounded-sm bg-surface-0 px-1.5 py-0.5">
                  {t(MATCH_REASON_LABEL_KEY[reason])}
                </span>
              ))}
            </div>
          </a>
        )
      })}
    </div>
  )
}
