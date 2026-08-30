// Kart bileşik bileşeni — panel yüzeyi. "Projects Overview", "Active Projects", "Best Selling Products" gibi
// tasarımdaki kartların gövdesini oluşturur. Card + CardHeader (başlık/alt başlık/sağ aksiyon) + CardBody + CardFooter.
import { forwardRef } from 'react'
import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

export type CardProps = HTMLAttributes<HTMLDivElement>

export const Card = forwardRef<HTMLDivElement, CardProps>(({ className, ...props }, ref) => {
  return (
    <div
      ref={ref}
      className={cn('bg-surface-1 border border-border-subtle rounded-lg shadow-card', className)}
      {...props}
    />
  )
})

Card.displayName = 'Card'

export type CardHeaderProps = {
  title?: ReactNode
  subtitle?: ReactNode
  action?: ReactNode
} & HTMLAttributes<HTMLDivElement>

export const CardHeader = forwardRef<HTMLDivElement, CardHeaderProps>(
  ({ className, title, subtitle, action, children, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn('flex items-start justify-between gap-4 border-b border-border-subtle p-5', className)}
        {...props}
      >
        <div className="flex min-w-0 flex-col gap-1">
          {title && <h3 className="text-md font-medium text-fg">{title}</h3>}
          {subtitle && <p className="text-sm text-fg-muted">{subtitle}</p>}
          {children}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
    )
  }
)

CardHeader.displayName = 'CardHeader'

export type CardBodyProps = {
  noPadding?: boolean
} & HTMLAttributes<HTMLDivElement>

export const CardBody = forwardRef<HTMLDivElement, CardBodyProps>(
  ({ className, noPadding = false, ...props }, ref) => {
    return <div ref={ref} className={cn(!noPadding && 'p-5', className)} {...props} />
  }
)

CardBody.displayName = 'CardBody'

export type CardFooterProps = HTMLAttributes<HTMLDivElement>

export const CardFooter = forwardRef<HTMLDivElement, CardFooterProps>(({ className, ...props }, ref) => {
  return <div ref={ref} className={cn('border-t border-border-subtle p-5', className)} {...props} />
})

CardFooter.displayName = 'CardFooter'
