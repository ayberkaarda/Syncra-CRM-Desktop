// Kanban kartı. İki dışa aktarım var:
// - `DealBoardCard`: panodaki sortable kart.
// - `DealCardPreview`: `DragOverlay` içinde imleci takip eden kopya (sortable değil).
//
// ERİŞİLEBİLİRLİK — İKİ AYRI ODAK HEDEFİ
// Kart gövdesi sürükleme tutamağıdır: Tab ile odaklanılır, Boşluk/Enter ile kart "alınır",
// ok tuşlarıyla taşınır (dnd-kit `KeyboardSensor`). Başlık ise ayrı bir bağlantıdır ve
// Enter ile detay sayfasını açar. İkisi tek elemanda toplanamaz — aynı tuş hem "kartı al"
// hem "sayfayı aç" anlamına gelemez. Bağlantı üzerindeki `keydown` bu yüzden yukarı
// SIÇRAMAZ; sıçrasaydı başlıkta Enter'a basmak sürüklemeyi başlatırdı.
import { Link, useNavigate } from 'react-router-dom'
import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { useTranslation } from 'react-i18next'
import { Building2, CalendarDays, Lock, TriangleAlert } from 'lucide-react'
import { Avatar, Badge } from '../../../../components/ui'
import { cn } from '../../../../lib/cn'
import { formatDate, tokenBadgeVariant } from './boardUtils'
import { ConvertedAmount } from '../../../exchange/components/ConvertedAmount'
import { useConvertedAmountText } from '../../../exchange/hooks/useConvertedAmountText'
import type { DealCard } from '../../types'

type DealCardBodyProps = {
  card: DealCard
  /** Kartı başkası taşıdıysa taşıyanın adı — kısa süreli görsel vurgu için. */
  movedBy?: string
  isOverlay?: boolean
  /**
   * Taşıma izni MODÜL düzeyinde var (`deals.move`), ama BU karta özel `can.move === false` —
   * yani kart sahipsiz/kendisine ait değil ve kullanıcı `deals.assign` taşımıyor. Panonun geri
   * kalanı sürüklenebilirken bu kart neden sürüklenemediğini kilit ikonu + tooltip ile açıklar
   * (bkz. `DealBoardCard` gerekçesi).
   */
  lockedByOwnership?: boolean
}

function DealCardBody({ card, movedBy, isOverlay = false, lockedByOwnership = false }: DealCardBodyProps) {
  const { t } = useTranslation('deals')

  return (
    <>
      <div className="flex items-start justify-between gap-2">
        <Link
          to={`/deals/${card.id}`}
          onClick={(event) => event.stopPropagation()}
          onKeyDown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') event.stopPropagation()
          }}
          className="rounded-sm text-sm font-medium text-fg hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          {card.title}
        </Link>
        <div className="flex shrink-0 items-center gap-1.5">
          {lockedByOwnership && !isOverlay && (
            <span
              className="inline-flex items-center text-fg-muted"
              title={t('board.card.lockedTooltip')}
            >
              <Lock className="size-3.5" aria-hidden="true" />
              <span className="sr-only">{t('board.card.lockedTooltip')}</span>
            </span>
          )}
          {card.probability !== null && <span className="text-xs text-fg-muted">%{card.probability}</span>}
        </div>
      </div>

      <p className="text-base font-semibold text-fg">
        <ConvertedAmount amount={card.amount} currency={card.currency} variant="compact" />
      </p>

      {card.company && (
        <p className="flex items-center gap-1.5 text-xs text-fg-muted">
          <Building2 className="size-3.5 shrink-0" aria-hidden="true" />
          <span className="truncate">{card.company.name}</span>
        </p>
      )}

      {card.tags.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {card.tags.map((tag) => (
            <Badge key={tag.id} size="sm" variant={tokenBadgeVariant(tag.color)}>
              {tag.name}
            </Badge>
          ))}
        </div>
      )}

      <div className="flex items-center justify-between gap-2 pt-1">
        <span
          className={cn(
            'flex items-center gap-1.5 text-xs',
            card.is_overdue ? 'text-danger' : 'text-fg-muted'
          )}
        >
          {card.is_overdue ? (
            <TriangleAlert className="size-3.5 shrink-0" aria-hidden="true" />
          ) : (
            <CalendarDays className="size-3.5 shrink-0" aria-hidden="true" />
          )}
          {formatDate(card.expected_close_date)}
          {card.is_overdue && <span className="sr-only">{t('board.card.overdueSrOnly')}</span>}
        </span>

        {card.owner ? (
          <Avatar size="xs" name={card.owner.name} title={card.owner.name} />
        ) : (
          <span className="text-xs text-fg-disabled">{t('board.card.unassigned')}</span>
        )}
      </div>

      {movedBy && !isOverlay && (
        <p className="text-xs text-primary">{t('board.card.movedBy', { name: movedBy })}</p>
      )}
    </>
  )
}

const CARD_BASE_CLASSES =
  'flex w-full flex-col gap-2 rounded-lg border border-border bg-surface-1 p-3 text-left'

export type DealBoardCardProps = {
  card: DealCard
  /** Modül düzeyinde taşıma izni (`usePermission('deals.move')`) — panonun geneli için. */
  dragEnabled: boolean
  movedBy?: string
}

export function DealBoardCard({ card, dragEnabled, movedBy }: DealBoardCardProps) {
  const { t } = useTranslation('deals')
  const navigate = useNavigate()
  // Faz 13 — yatay yazma izolasyonu: modül izni tek başına yetmez, BU kartın `can.move`'u da
  // `true` olmalı (sahip / sahipsiz / `deals.assign`, bkz. backend `DealPolicy::move`). dnd-kit'te
  // doğru yol `useSortable`in KENDİ `disabled` seçeneği — kartı DOM'dan çıkarmak ya da event'i
  // yutmak DEĞİL; bu hem fare hem klavye (Tab/Boşluk/ok tuşları) etkileşimini birlikte kapatır
  // (bkz. `useDraggable` içinde `listeners: disabled ? undefined : listeners`).
  const canDragThisCard = dragEnabled && card.can.move
  // Panonun geneli sürüklenebilirken YALNIZCA bu kart sahiplik yüzünden kilitliyse ayrı bir
  // görsel/ipucu gerekir — modül izni zaten yoksa (dragEnabled=false) pano üstündeki uyarı satırı
  // (bkz. `DealsBoardPage`) yeterlidir, her karta ayrı kilit ikonu eklemek gürültü olurdu.
  const lockedByOwnership = dragEnabled && !card.can.move
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: card.id,
    disabled: !canDragThisCard,
  })
  // Kartın gövdesinde GÖRÜNEN tutarla (`ConvertedAmount`, aynı hook) BİREBİR aynı metin —
  // ekran okuyucu görünenden farklı bir tutar/para birimi anons etmesin.
  const { text: amountLabel } = useConvertedAmountText(card.amount, card.currency, 'compact')

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      {...attributes}
      {...listeners}
      aria-roledescription={canDragThisCard ? t('board.card.draggableRole') : undefined}
      aria-label={t('board.card.ariaLabel', { title: card.title, amount: amountLabel })}
      title={lockedByOwnership ? t('board.card.lockedTooltip') : undefined}
      onClick={() => navigate(`/deals/${card.id}`)}
      className={cn(
        CARD_BASE_CLASSES,
        'shadow-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
        canDragThisCard ? 'cursor-grab active:cursor-grabbing' : lockedByOwnership ? 'cursor-not-allowed' : 'cursor-pointer',
        // Sürüklenen kartın yerinde bıraktığı boşluk: kart `DragOverlay`de zaten
        // görünüyor, aslını da tam opaklıkta çizmek aynı kartı iki kez gösterirdi.
        isDragging && 'opacity-40',
        // Başkasının taşıdığı kart 2 saniye belirgin kalır. Nabız animasyonu
        // `motion-reduce` altında kapanır; renk vurgusu bilgi taşıdığı için kalır.
        movedBy && 'ring-2 ring-primary animate-pulse motion-reduce:animate-none'
      )}
    >
      <DealCardBody card={card} movedBy={movedBy} lockedByOwnership={lockedByOwnership} />
    </div>
  )
}

/** `DragOverlay` içeriği — etkileşimsiz, yalnızca görsel kopya. */
export function DealCardPreview({ card }: { card: DealCard }) {
  return (
    <div className={cn(CARD_BASE_CLASSES, 'shadow-popover cursor-grabbing')} aria-hidden="true">
      <DealCardBody card={card} isOverlay />
    </div>
  )
}
