// Pano yükleniyor durumu — gerçek sütun şeridiyle aynı ölçülerde iskelet.
// Aynı `w-80` genişliği ve aynı boşluklar kullanılır ki veri geldiğinde düzen zıplamasın.
import { Skeleton } from '../../../../components/ui'

const COLUMN_COUNT = 5
const CARD_COUNTS = [3, 4, 2, 3, 2]

export function BoardSkeleton() {
  return (
    <div className="flex h-full gap-3 overflow-x-hidden pb-2" aria-hidden="true">
      {Array.from({ length: COLUMN_COUNT }).map((_, columnIndex) => (
        <section
          key={columnIndex}
          className="flex h-full w-80 shrink-0 flex-col overflow-hidden rounded-xl border border-border-subtle bg-surface-2"
        >
          <Skeleton height={4} className="rounded-none" />
          <div className="flex flex-col gap-2 border-b border-border-subtle px-3 py-2.5">
            <Skeleton height={16} width="60%" />
            <Skeleton height={12} width="40%" />
          </div>
          <div className="flex flex-col gap-2 p-2">
            {Array.from({ length: CARD_COUNTS[columnIndex] ?? 3 }).map((__, cardIndex) => (
              <Skeleton key={cardIndex} height={112} className="rounded-lg" />
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
