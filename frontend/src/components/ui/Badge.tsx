// Durum rozeti — soft zemin/eşleşen metin kalıbı. Inprogress/Pending/Completed, In Stock/Out of Stock, Active gibi durumlar için kullanılır.
import { forwardRef } from 'react'
import type { HTMLAttributes } from 'react'
import { cn } from '../../lib/cn'

export type BadgeProps = {
  variant?: 'primary' | 'success' | 'danger' | 'warning' | 'neutral'
  size?: 'sm' | 'md'
  dot?: boolean
} & HTMLAttributes<HTMLSpanElement>

const variantClasses: Record<NonNullable<BadgeProps['variant']>, string> = {
  primary: 'bg-primary-tint text-primary',
  success: 'bg-success-tint text-success',
  danger: 'bg-danger-tint text-danger',
  warning: 'bg-warning-tint text-warning',
  neutral: 'bg-surface-2 text-fg-muted',
}

const dotClasses: Record<NonNullable<BadgeProps['variant']>, string> = {
  primary: 'bg-primary',
  success: 'bg-success',
  danger: 'bg-danger',
  warning: 'bg-warning',
  neutral: 'bg-fg-muted',
}

const sizeClasses: Record<NonNullable<BadgeProps['size']>, string> = {
  sm: 'text-xs px-2 py-0.5',
  md: 'text-xs px-2.5 py-1',
}

export const Badge = forwardRef<HTMLSpanElement, BadgeProps>(
  ({ className, variant = 'neutral', size = 'md', dot = false, children, ...props }, ref) => {
    return (
      <span
        ref={ref}
        className={cn(
          'inline-flex items-center gap-1.5 rounded-md font-medium',
          variantClasses[variant],
          sizeClasses[size],
          className
        )}
        {...props}
      >
        {dot && <span className={cn('size-1.5 rounded-full', dotClasses[variant])} aria-hidden="true" />}
        {children}
      </span>
    )
  }
)

Badge.displayName = 'Badge'
