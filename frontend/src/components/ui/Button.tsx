// Buton bileşeni — birincil/ikincil/ghost/danger/link varyantları.
// Tasarımda "Logout", "Send", "+ Add Employee" (primary), "+ Invite Employee" (secondary/ghost), "Export Report" (link) için kullanılır.
import { forwardRef } from 'react'
import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Loader2 } from 'lucide-react'
import { cn } from '../../lib/cn'

export type ButtonProps = {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'link'
  size?: 'sm' | 'md' | 'lg'
  loading?: boolean
  leftIcon?: ReactNode
  rightIcon?: ReactNode
  fullWidth?: boolean
} & ButtonHTMLAttributes<HTMLButtonElement>

const variantClasses: Record<NonNullable<ButtonProps['variant']>, string> = {
  primary: 'bg-primary text-primary-fg hover:brightness-95 active:brightness-90',
  secondary: 'bg-surface-2 text-fg border border-border hover:bg-surface-3',
  ghost: 'bg-transparent text-fg hover:bg-surface-2',
  danger: 'bg-danger text-white hover:brightness-95 active:brightness-90',
  link: 'bg-transparent text-primary hover:underline p-0 h-auto',
}

const sizeClasses: Record<NonNullable<ButtonProps['size']>, string> = {
  sm: 'h-8 px-3 text-xs gap-1.5',
  md: 'h-10 px-4 text-sm gap-2',
  lg: 'h-12 px-5 text-base gap-2',
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  (
    {
      className,
      variant = 'primary',
      size = 'md',
      loading = false,
      leftIcon,
      rightIcon,
      fullWidth = false,
      disabled,
      children,
      ...props
    },
    ref
  ) => {
    const isLink = variant === 'link'
    return (
      <button
        ref={ref}
        className={cn(
          'inline-flex items-center justify-center rounded-md font-medium',
          'transition-colors duration-150 motion-reduce:transition-none',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1',
          'disabled:opacity-50 disabled:cursor-not-allowed',
          variantClasses[variant],
          !isLink && sizeClasses[size],
          isLink && (size === 'sm' ? 'text-xs gap-1.5' : size === 'lg' ? 'text-base gap-2' : 'text-sm gap-2'),
          fullWidth && 'w-full',
          className
        )}
        disabled={disabled || loading}
        aria-busy={loading || undefined}
        {...props}
      >
        {loading ? (
          <Loader2 className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
        ) : (
          leftIcon
        )}
        {children}
        {!loading && rightIcon}
      </button>
    )
  }
)

Button.displayName = 'Button'
