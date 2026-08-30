// Lead skoru göstergesi — 0-100 arası ince bir renk kodlu bar + rozet.
// 0-33 danger, 34-66 warning, 67-100 success (bkz. görev tanımı).
import { Badge } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { scoreVariant } from '../utils'

const BAR_COLOR: Record<'danger' | 'warning' | 'success', string> = {
  danger: 'bg-danger',
  warning: 'bg-warning',
  success: 'bg-success',
}

export function ScoreIndicator({ score }: { score: number }) {
  const clamped = Math.max(0, Math.min(100, score))
  const variant = scoreVariant(clamped)

  return (
    <div className="flex items-center gap-2">
      <div className="h-1.5 w-16 overflow-hidden rounded-full bg-surface-2">
        <div className={cn('h-full rounded-full', BAR_COLOR[variant])} style={{ width: `${clamped}%` }} />
      </div>
      <Badge variant={variant} size="sm">
        {clamped}
      </Badge>
    </div>
  )
}
