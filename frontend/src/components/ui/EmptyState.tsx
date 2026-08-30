// Boş durum bileşeni — liste/tablo içeriği yokken ortalanmış ikon + başlık + açıklama + aksiyon gösterir.
import { forwardRef } from 'react'
import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

export type EmptyStateProps = {
  icon?: ReactNode
  title: string
  description?: string
  action?: ReactNode
} & HTMLAttributes<HTMLDivElement>

export const EmptyState = forwardRef<HTMLDivElement, EmptyStateProps>(
  ({ className, icon, title, description, action, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn('flex flex-col items-center justify-center gap-4 px-6 py-12 text-center', className)}
        {...props}
      >
        {icon && (
          <div className="flex size-14 items-center justify-center rounded-full bg-surface-2 text-fg-muted">
            {icon}
          </div>
        )}
        <div className="flex flex-col gap-1">
          <p className="text-base font-medium text-fg">{title}</p>
          {description && <p className="max-w-sm text-sm text-fg-muted">{description}</p>}
        </div>
        {action && <div>{action}</div>}
      </div>
    )
  }
)

EmptyState.displayName = 'EmptyState'
