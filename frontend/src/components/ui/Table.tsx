// Tablo bileşik bileşeni — Table, THead, TBody, Tr, Th (sıralanabilir), Td. "Active Projects" ve
// "Employees" tablolarının temelini oluşturur. Yatay taşmayı Table kendi içinde sarmalar.
import { forwardRef } from 'react'
import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react'
import { ChevronDown, ChevronsUpDown, ChevronUp } from 'lucide-react'
import { cn } from '../../lib/cn'

export type TableProps = HTMLAttributes<HTMLTableElement>

export const Table = forwardRef<HTMLTableElement, TableProps>(({ className, ...props }, ref) => {
  return (
    <div className="w-full overflow-x-auto">
      <table ref={ref} className={cn('w-full border-collapse text-left', className)} {...props} />
    </div>
  )
})

Table.displayName = 'Table'

export type THeadProps = HTMLAttributes<HTMLTableSectionElement>

export const THead = forwardRef<HTMLTableSectionElement, THeadProps>(({ className, ...props }, ref) => {
  return <thead ref={ref} className={cn(className)} {...props} />
})

THead.displayName = 'THead'

export type TBodyProps = HTMLAttributes<HTMLTableSectionElement>

export const TBody = forwardRef<HTMLTableSectionElement, TBodyProps>(({ className, ...props }, ref) => {
  return <tbody ref={ref} className={cn(className)} {...props} />
})

TBody.displayName = 'TBody'

export type TrProps = HTMLAttributes<HTMLTableRowElement>

export const Tr = forwardRef<HTMLTableRowElement, TrProps>(({ className, ...props }, ref) => {
  return (
    <tr
      ref={ref}
      className={cn(
        'border-b border-border-subtle transition-colors duration-150 motion-reduce:transition-none',
        'hover:bg-surface-2',
        className
      )}
      {...props}
    />
  )
})

Tr.displayName = 'Tr'

export type ThProps = {
  sortable?: boolean
  sortDirection?: 'asc' | 'desc' | null
  onSort?: () => void
  align?: 'left' | 'center' | 'right'
} & ThHTMLAttributes<HTMLTableCellElement>

const alignClasses: Record<NonNullable<ThProps['align']>, string> = {
  left: 'text-left justify-start',
  center: 'text-center justify-center',
  right: 'text-right justify-end',
}

export const Th = forwardRef<HTMLTableCellElement, ThProps>(
  ({ className, sortable = false, sortDirection = null, onSort, align = 'left', children, ...props }, ref) => {
    const ariaSort = !sortable ? undefined : sortDirection === 'asc' ? 'ascending' : sortDirection === 'desc' ? 'descending' : 'none'

    const SortIcon = sortDirection === 'asc' ? ChevronUp : sortDirection === 'desc' ? ChevronDown : ChevronsUpDown

    return (
      <th
        ref={ref}
        scope="col"
        aria-sort={ariaSort}
        className={cn(
          'border-b border-border-subtle px-4 py-3 text-xs font-medium text-fg-muted',
          alignClasses[align],
          className
        )}
        {...props}
      >
        {sortable ? (
          <button
            type="button"
            onClick={onSort}
            className={cn(
              'inline-flex items-center gap-1 text-xs font-medium text-fg-muted hover:text-fg',
              'transition-colors duration-150 motion-reduce:transition-none',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1 rounded-sm',
              alignClasses[align]
            )}
          >
            {children}
            <SortIcon className="size-3.5" aria-hidden="true" />
          </button>
        ) : (
          children
        )}
      </th>
    )
  }
)

Th.displayName = 'Th'

export type TdProps = {
  align?: 'left' | 'center' | 'right'
} & TdHTMLAttributes<HTMLTableCellElement>

export const Td = forwardRef<HTMLTableCellElement, TdProps>(({ className, align = 'left', ...props }, ref) => {
  return (
    <td
      ref={ref}
      className={cn('px-4 py-3 text-sm text-fg', alignClasses[align].split(' ')[0], className)}
      {...props}
    />
  )
})

Td.displayName = 'Td'
