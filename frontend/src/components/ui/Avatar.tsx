// Kullanıcı avatarı — görsel yoksa/yüklenemezse baş harflere düşer, durum noktası gösterebilir.
// AvatarGroup ile üst üste bindirilmiş liste oluşturulur (Active Projects tablosu, sohbet listesi).
import { Children, cloneElement, forwardRef, isValidElement, useState } from 'react'
import type { HTMLAttributes, ReactNode } from 'react'
import { cn } from '../../lib/cn'

export type AvatarProps = {
  src?: string
  name: string
  size?: 'xs' | 'sm' | 'md' | 'lg'
  status?: 'online' | 'offline' | 'busy'
} & HTMLAttributes<HTMLDivElement>

const sizeClasses: Record<NonNullable<AvatarProps['size']>, string> = {
  xs: 'size-6 text-[10px]',
  sm: 'size-8 text-xs',
  md: 'size-10 text-sm',
  lg: 'size-12 text-base',
}

const statusSizeClasses: Record<NonNullable<AvatarProps['size']>, string> = {
  xs: 'size-1.5',
  sm: 'size-2',
  md: 'size-2.5',
  lg: 'size-3',
}

const statusColorClasses: Record<NonNullable<AvatarProps['status']>, string> = {
  online: 'bg-success',
  offline: 'bg-fg-disabled',
  busy: 'bg-danger',
}

function getInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return ''
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

export const Avatar = forwardRef<HTMLDivElement, AvatarProps>(
  ({ className, src, name, size = 'md', status, ...props }, ref) => {
    const [imgError, setImgError] = useState(false)
    const showImage = !!src && !imgError

    return (
      <div ref={ref} className={cn('relative inline-flex shrink-0', sizeClasses[size], className)} {...props}>
        {showImage ? (
          <img
            src={src}
            alt={name}
            onError={() => setImgError(true)}
            className={cn('size-full rounded-full object-cover', sizeClasses[size])}
          />
        ) : (
          <span
            role="img"
            aria-label={name}
            className={cn(
              'flex size-full items-center justify-center rounded-full bg-surface-3 font-medium text-fg-secondary',
              sizeClasses[size]
            )}
          >
            {getInitials(name)}
          </span>
        )}
        {status && (
          <span
            className={cn(
              'absolute bottom-0 right-0 rounded-full ring-2 ring-surface-1',
              statusSizeClasses[size],
              statusColorClasses[status]
            )}
            aria-hidden="true"
          />
        )}
      </div>
    )
  }
)

Avatar.displayName = 'Avatar'

export type AvatarGroupProps = {
  max?: number
  size?: AvatarProps['size']
  children: ReactNode
}

export function AvatarGroup({ max = 4, size = 'md', children }: AvatarGroupProps) {
  const items = Children.toArray(children)
  const visible = items.slice(0, max)
  const overflow = items.length - visible.length

  return (
    <div className="flex items-center -space-x-2">
      {visible.map((child, index) => (
        <div key={index} className="rounded-full ring-2 ring-surface-1">
          {isValidElement<AvatarProps>(child) ? cloneElement(child, { size }) : child}
        </div>
      ))}
      {overflow > 0 && (
        <div
          className={cn(
            'relative inline-flex shrink-0 items-center justify-center rounded-full bg-surface-3 font-medium text-fg-secondary ring-2 ring-surface-1',
            sizeClasses[size ?? 'md']
          )}
        >
          +{overflow}
        </div>
      )}
    </div>
  )
}

AvatarGroup.displayName = 'AvatarGroup'
