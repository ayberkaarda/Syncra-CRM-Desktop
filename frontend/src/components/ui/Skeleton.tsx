// Yükleniyor iskeleti — içerik gelene kadar yer tutucu. Kullanan bileşen kapsayıcısına
// `aria-busy="true"` eklemesi önerilir; Skeleton'ın kendisi ekran okuyucudan gizlenir.
import { forwardRef } from 'react'
import type { CSSProperties, HTMLAttributes } from 'react'
import { cn } from '../../lib/cn'

export type SkeletonProps = {
  variant?: 'text' | 'circle' | 'rect'
  width?: string | number
  height?: string | number
  lines?: number
} & HTMLAttributes<HTMLDivElement>

const variantClasses: Record<NonNullable<SkeletonProps['variant']>, string> = {
  text: 'rounded-sm h-[1em]',
  circle: 'rounded-full',
  rect: 'rounded-md',
}

export const Skeleton = forwardRef<HTMLDivElement, SkeletonProps>(
  ({ className, variant = 'rect', width, height, lines = 1, style, ...props }, ref) => {
    const baseStyle: CSSProperties = { width, height, ...style }

    if (variant === 'text' && lines > 1) {
      return (
        <div ref={ref} className={cn('flex flex-col gap-2', className)} aria-hidden="true" {...props}>
          {Array.from({ length: lines }).map((_, index) => (
            <div
              key={index}
              className={cn('bg-surface-2 animate-pulse motion-reduce:animate-none text-sm leading-none', variantClasses.text)}
              style={{
                width: index === lines - 1 ? '60%' : (width ?? '100%'),
                height: height ?? '1em',
              }}
            />
          ))}
        </div>
      )
    }

    return (
      <div
        ref={ref}
        aria-hidden="true"
        className={cn('bg-surface-2 animate-pulse motion-reduce:animate-none', variantClasses[variant], className)}
        style={baseStyle}
        {...props}
      />
    )
  }
)

Skeleton.displayName = 'Skeleton'
