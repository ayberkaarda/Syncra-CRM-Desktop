// 404 sayfası — bilinmeyen route'lar için.
import { useTranslation } from 'react-i18next'
import { SearchX } from 'lucide-react'
import { EmptyState } from '../components/ui'

export function NotFoundPage() {
  const { t } = useTranslation('common')
  return (
    <div className="flex min-h-screen items-center justify-center bg-surface-0">
      <EmptyState
        icon={<SearchX className="size-6" aria-hidden="true" />}
        title={t('pages.notFound.title')}
        description={t('pages.notFound.description')}
      />
    </div>
  )
}
