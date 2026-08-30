// Kayıt tutarını kullanıcının tercih ettiği para biriminde gösteren ORTAK bileşen (Faz 14 /
// İz E, GÖREV 2) — fırsat liste/detay/Kanban yüzeylerinin üçü de bunu kullanır.
//
// DÜRÜSTLÜK KURALI (docs/PHASE-INTL.md görev tanımı, pazarlıksız): çevrilmiş bir tutar
// çevrildiğini BELLİ ETMELİ. Bu yüzden dönüşüm uygulandığında `title` niteliğinde (mevcut
// `DealsListPage` toplam tutarları tooltip deseniyle AYNI dil) kaydın kendi para birimindeki
// özgün tutar + kullanılan kurun tarihi gösterilir; kur bayatsa (`is_stale`) `RateInfoNote`
// ile AYNI görsel dil (amber `AlertTriangle`) kullanılır. Kur hiç yoksa tutar kaydın KENDİ
// para biriminde basılır ve ayrı bir "çevrilemiyor" işareti/tooltip'i gösterilir — uydurma
// kur yok, sessiz sayı YOK. Metin üretimi `useConvertedAmountText`e devredilir ki metin-yalnızca
// tüketiciler (ör. Kanban kartı `aria-label`ı) BİREBİR aynı dönüşümü anons etsin.
import { AlertTriangle, HelpCircle } from 'lucide-react'
import { cn } from '../../../lib/cn'
import { useConvertedAmountText } from '../hooks/useConvertedAmountText'

export type ConvertedAmountProps = {
  amount: number | string | null | undefined
  /** Kaydın KENDİ para birimi (ör. `deal.currency`) — asla değişmez, yalnızca gösterim çevrilir. */
  currency: string
  /** `compact`: Kanban kartları gibi dar alanlar (`formatMoneyCompact`, 0 ondalık). Varsayılan
   *  `default`: liste/detay (`formatMoney`, 2 ondalık) — `lib/money.ts` ayrımıyla birebir. */
  variant?: 'default' | 'compact'
  className?: string
}

export function ConvertedAmount({ amount, currency, variant = 'default', className }: ConvertedAmountProps) {
  const { text, tooltip, isStale, unavailable } = useConvertedAmountText(amount, currency, variant)

  if (tooltip === null) {
    return <span className={className}>{text}</span>
  }

  return (
    <span className={cn('inline-flex items-center gap-1', className)} title={tooltip}>
      {text}
      {isStale && <AlertTriangle className="size-3.5 shrink-0 text-warning" aria-hidden="true" />}
      {unavailable && <HelpCircle className="size-3.5 shrink-0 text-fg-muted" aria-hidden="true" />}
      <span className="sr-only">{tooltip}</span>
    </span>
  )
}
