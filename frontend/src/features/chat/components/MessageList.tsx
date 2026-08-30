// Sağ panel — mesaj listesi. Sanal DEĞİL (basit liste) ama yukarı kaydırınca `loadMore()`
// çağrılır (`useMessages` içindeki imleçli sayfalama — bkz. o dosyanın gerekçesi). Gün ayracı,
// ardışık aynı gönderenin mesajları gruplanır (avatar/ad tekrar etmez). En alta otomatik kaydırma
// YALNIZCA kullanıcı zaten en alttaysa yapılır — yukarıda geçmiş okurken yeni mesaj gelince
// zıplamaz, bunun yerine "↓ Yeni mesaj" butonu gösterilir.
//
// `useMessages` `messages`i ZATEN eskiden yeniye sırada döndürür (bkz. `hooks/useMessages.ts`)
// — burada AYRICA bir flatten/reverse YAPILMAZ, hook'un kendi sözleşmesine güvenilir. Okundu/
// teslim bildirimleri de bu bileşenin işi DEĞİL: `useChatSocket` (ChatPage'de tek sefer çağrılır)
// konuşma açıkken önbellekteki en yeni mesaj değiştikçe bunu kendiliğinden yönetir.
//
// KONUŞMA DEĞİŞİNCE SIFIRLAMA: bu bileşen `conversationId`i ASLA prop değişimiyle takip ETMEZ
// — çağıran taraf `<MessageList key={conversationId} ... />` ile `key` verir (bkz. `ChatPage.tsx`
// ve `record/RecordChatPanel.tsx`), böylece konuşma değişince React bileşeni SIFIRDAN mount eder.
// Bu, render sırasında state/ref sıfırlayan bir efekte göre daha doğrudur: `key` değişimi
// useState'leri VE aşağıdaki dört ref'i (prevOldestIdRef, lastNewestIdRef, isAtBottomRef,
// prevScrollHeightRef) birlikte, garantili şekilde temizler.
import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowDown } from 'lucide-react'
import { EmptyState, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useAuthStore } from '../../auth/store'
import { useMessages } from '../hooks/useMessages'
import { MessageBubble } from './MessageBubble'
import { formatDayDivider, isSameCalendarDay } from './chatShared'

export type MessageListProps = {
  conversationId: number
}

const SCROLL_TOP_THRESHOLD = 80 // px — bu değere yaklaşınca eski mesajlar yüklenir
const BOTTOM_THRESHOLD = 48 // px — bu değere yaklaşınca "en altta" sayılır

export function MessageList({ conversationId }: MessageListProps) {
  const { t } = useTranslation('chat')
  const currentUserId = useAuthStore((state) => state.user?.id)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const prevScrollHeightRef = useRef(0)
  const prevOldestIdRef = useRef<number | null>(null)
  const lastNewestIdRef = useRef<number | null>(null)
  const isAtBottomRef = useRef(true)

  const [isAtBottom, setIsAtBottom] = useState(true)
  const [newMessageCount, setNewMessageCount] = useState(0)

  const { messages, hasMore, loadMore, isLoadingMore, isLoading } = useMessages(conversationId)

  const oldestId = messages[0]?.id ?? null
  const newestId = messages[messages.length - 1]?.id ?? null

  // Eski mesajlar tepeye eklendiğinde scroll konumunu koru; ilk yüklemede en alta in.
  useLayoutEffect(() => {
    const container = containerRef.current
    if (!container) return

    if (prevOldestIdRef.current !== null && oldestId !== null && oldestId !== prevOldestIdRef.current) {
      const delta = container.scrollHeight - prevScrollHeightRef.current
      container.scrollTop += delta
    } else if (prevOldestIdRef.current === null && messages.length > 0) {
      container.scrollTop = container.scrollHeight
    }

    prevOldestIdRef.current = oldestId
    // messages.length kasıtlı dışarıda bırakıldı — yalnızca en eski mesaj değiştiğinde tetiklenmeli.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [oldestId])

  // Yeni mesaj geldiğinde: kullanıcı zaten en alttaysa otomatik kaydır, değilse sayaç/buton göster.
  useEffect(() => {
    if (newestId === null) return
    if (lastNewestIdRef.current === null) {
      lastNewestIdRef.current = newestId
      return
    }
    if (newestId === lastNewestIdRef.current) return
    lastNewestIdRef.current = newestId

    const container = containerRef.current
    if (isAtBottomRef.current && container) {
      requestAnimationFrame(() => {
        container.scrollTop = container.scrollHeight
      })
    } else {
      setNewMessageCount((count) => count + 1)
    }
  }, [newestId])

  function handleScroll() {
    const container = containerRef.current
    if (!container) return

    prevScrollHeightRef.current = container.scrollHeight

    const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight
    const atBottom = distanceFromBottom < BOTTOM_THRESHOLD
    isAtBottomRef.current = atBottom
    setIsAtBottom(atBottom)
    if (atBottom) setNewMessageCount(0)

    if (container.scrollTop < SCROLL_TOP_THRESHOLD && hasMore && !isLoadingMore) {
      loadMore()
    }
  }

  function scrollToBottom() {
    const container = containerRef.current
    if (!container) return
    container.scrollTop = container.scrollHeight
    setNewMessageCount(0)
  }

  if (isLoading) {
    return (
      <div className="flex h-full flex-1 flex-col justify-end gap-3 p-4" aria-busy="true">
        {[1, 0, 1, 1, 0].map((align, index) => (
          <div key={index} className={cn('flex', align ? 'justify-end' : 'justify-start')}>
            <Skeleton variant="rect" width={160 + index * 20} height={40} className="rounded-lg" />
          </div>
        ))}
      </div>
    )
  }

  if (messages.length === 0) {
    return (
      <EmptyState
        title={t('messageList.emptyTitle')}
        description={t('messageList.emptyDescription')}
        className="h-full flex-1 justify-center"
      />
    )
  }

  return (
    <div className="relative min-h-0 flex-1">
      <div
        ref={containerRef}
        onScroll={handleScroll}
        role="log"
        aria-live="polite"
        aria-label={t('messageList.logAria')}
        className="flex h-full flex-col overflow-y-auto overflow-x-hidden px-4 py-3"
      >
        {isLoadingMore && (
          <div className="flex justify-center py-2">
            <Skeleton variant="rect" width={120} height={24} className="rounded-full" />
          </div>
        )}
        {messages.map((message, index) => {
          const previous = messages[index - 1]
          const showDayDivider = !previous || !isSameCalendarDay(previous.created_at, message.created_at)
          const sameSenderAsPrevious =
            !!previous &&
            previous.user?.id === message.user?.id &&
            !showDayDivider &&
            previous.type !== 'system' &&
            message.type !== 'system'

          return (
            <div key={message.id}>
              {showDayDivider && (
                <div className="flex justify-center py-2">
                  <span className="rounded-full bg-surface-2 px-3 py-1 text-xs font-medium text-fg-muted">
                    {formatDayDivider(message.created_at)}
                  </span>
                </div>
              )}
              <div className={sameSenderAsPrevious ? 'mt-0.5' : 'mt-2.5'}>
                <MessageBubble
                  message={message}
                  isOwn={message.user?.id === currentUserId}
                  showMeta={!sameSenderAsPrevious}
                />
              </div>
            </div>
          )
        })}
      </div>

      {!isAtBottom && newMessageCount > 0 && (
        <button
          type="button"
          onClick={scrollToBottom}
          className={cn(
            'absolute bottom-3 left-1/2 -translate-x-1/2 inline-flex items-center gap-1.5',
            'rounded-full bg-primary px-3 py-1.5 text-xs font-medium text-primary-fg shadow-popover',
            'transition-transform duration-150 motion-reduce:transition-none hover:brightness-95'
          )}
        >
          <ArrowDown className="size-3.5" aria-hidden="true" />
          {t('messageList.newMessage')}
        </button>
      )}
    </div>
  )
}
