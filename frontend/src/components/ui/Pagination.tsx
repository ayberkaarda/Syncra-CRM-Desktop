// Sayfalama bileşeni — tasarımdaki "Showing X to Y of Z entries" deseni. Çok sayfada
// `1 … 4 5 6 … 20` şeklinde kısaltma yapar.
import { forwardRef } from 'react'
import type { HTMLAttributes } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/cn'

export type PaginationProps = {
  currentPage: number
  totalItems: number
  pageSize: number
  onPageChange: (page: number) => void
  showSummary?: boolean
} & Omit<HTMLAttributes<HTMLElement>, 'onChange'>

const ELLIPSIS = 'ellipsis' as const

function buildPageList(currentPage: number, totalPages: number): Array<number | typeof ELLIPSIS> {
  const pages = new Set<number>()
  pages.add(1)
  pages.add(totalPages)
  for (let p = currentPage - 1; p <= currentPage + 1; p++) {
    if (p >= 1 && p <= totalPages) pages.add(p)
  }

  const sorted = Array.from(pages).sort((a, b) => a - b)
  const result: Array<number | typeof ELLIPSIS> = []
  let previous = 0
  for (const page of sorted) {
    if (previous && page - previous > 1) {
      result.push(ELLIPSIS)
    }
    result.push(page)
    previous = page
  }
  return result
}

export const Pagination = forwardRef<HTMLElement, PaginationProps>(
  ({ className, currentPage, totalItems, pageSize, onPageChange, showSummary = true, ...props }, ref) => {
    const { t } = useTranslation('common')
    const totalPages = Math.max(1, Math.ceil(totalItems / pageSize))
    const clampedPage = Math.min(Math.max(currentPage, 1), totalPages)
    const rangeStart = totalItems === 0 ? 0 : (clampedPage - 1) * pageSize + 1
    const rangeEnd = Math.min(clampedPage * pageSize, totalItems)
    const pageList = buildPageList(clampedPage, totalPages)

    function goTo(page: number) {
      if (page < 1 || page > totalPages || page === clampedPage) return
      onPageChange(page)
    }

    return (
      <nav
        ref={ref}
        aria-label={t('pagination.aria')}
        className={cn('flex flex-wrap items-center justify-between gap-3', className)}
        {...props}
      >
        {showSummary && (
          <p className="text-sm text-fg-muted">
            {t('pagination.summary', { from: rangeStart, to: rangeEnd, total: totalItems })}
          </p>
        )}
        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={() => goTo(clampedPage - 1)}
            disabled={clampedPage === 1}
            aria-label={t('pagination.previous')}
            className={cn(
              'inline-flex size-8 items-center justify-center rounded-md text-fg-muted',
              'hover:bg-surface-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
            )}
          >
            <ChevronLeft className="size-4" aria-hidden="true" />
          </button>

          {pageList.map((item, index) =>
            item === ELLIPSIS ? (
              <span key={`ellipsis-${index}`} className="px-2 text-sm text-fg-muted" aria-hidden="true">
                …
              </span>
            ) : (
              <button
                key={item}
                type="button"
                onClick={() => goTo(item)}
                aria-current={item === clampedPage ? 'page' : undefined}
                className={cn(
                  'inline-flex size-8 items-center justify-center rounded-md text-sm',
                  'transition-colors duration-150 motion-reduce:transition-none',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
                  item === clampedPage ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:bg-surface-2'
                )}
              >
                {item}
              </button>
            )
          )}

          <button
            type="button"
            onClick={() => goTo(clampedPage + 1)}
            disabled={clampedPage === totalPages}
            aria-label={t('pagination.next')}
            className={cn(
              'inline-flex size-8 items-center justify-center rounded-md text-fg-muted',
              'hover:bg-surface-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
            )}
          >
            <ChevronRight className="size-4" aria-hidden="true" />
          </button>
        </div>
      </nav>
    )
  }
)

Pagination.displayName = 'Pagination'
