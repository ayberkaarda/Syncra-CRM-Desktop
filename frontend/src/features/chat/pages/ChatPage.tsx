// Chat ana sayfası — iki panelli düzen (sol konuşma listesi, sağ mesajlar). Mobilde tek panel +
// geri butonu. Konuşma seçimi URL'de (`/chat/:conversationId`, seçim yoksa `/chat`).
// `useChatSocket(selectedId)`/`useTyping(selectedId)` burada BİR KEZ çağrılır (görev tanımında
// AÇIKÇA istendi — abonelik kurulumunun çoğaltılmaması için; ikisi de aynı `private-
// conversation.{id}` kanalını referans sayarak paylaşıyor, bkz. `hooks/conversationChannel.ts`).
// `notifyTyping`/`typingUsers` buradan `MessageComposer`/`TypingIndicator`'a prop olarak akar —
// o bileşenler kendi `useTyping` örneğini AÇMAZ.
import { useCallback, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { MessageSquarePlus } from 'lucide-react'
import { EmptyState, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { ConversationHeader } from '../components/ConversationHeader'
import { ConversationList } from '../components/ConversationList'
import { MessageComposer } from '../components/MessageComposer'
import { MessageList } from '../components/MessageList'
import { NewConversationModal } from '../components/NewConversationModal'
import { TypingIndicator } from '../components/TypingIndicator'
import { useChatSocket } from '../hooks/useChatSocket'
import { useConversation } from '../hooks/useConversations'
import { useTyping } from '../hooks/useTyping'

export function ChatPage() {
  const { t } = useTranslation('chat')
  const params = useParams<{ conversationId: string }>()
  const navigate = useNavigate()

  const parsedId = params.conversationId ? Number(params.conversationId) : null
  const selectedId = parsedId !== null && Number.isFinite(parsedId) ? parsedId : null

  const [newConversationOpen, setNewConversationOpen] = useState(false)
  // Her açılışta artar (kapanışta AYNI kalır) — `NewConversationModal`e `key` olarak verilir,
  // böylece form alanları modalın kendi içindeki bir sıfırlama efekti yerine YENİDEN MOUNT ile
  // temizlenir. Yalnızca açılışta artmasının nedeni: kapanışta değişseydi `Modal`in 150ms'lik
  // kapanış geçişi (bkz. `components/ui/Modal.tsx`) oynamadan bileşen sıfırdan mount edilirdi.
  const [newConversationKey, setNewConversationKey] = useState(0)

  const handleOpenNewConversation = useCallback(() => {
    setNewConversationKey((key) => key + 1)
    setNewConversationOpen(true)
  }, [])

  // Bu sayfa dışında ÇAĞRILMAZ — çoklu abonelik açılmasın diye (bkz. görev tanımı).
  useChatSocket(selectedId)
  const { typingUsers, notifyTyping } = useTyping(selectedId)

  const { data: conversation, isLoading: isConversationLoading } = useConversation(selectedId)

  const handleSelect = useCallback(
    (conversationId: number) => navigate(`/chat/${conversationId}`),
    [navigate]
  )

  const handleCreated = useCallback(
    (conversationId: number) => navigate(`/chat/${conversationId}`),
    [navigate]
  )

  const handleBack = useCallback(() => navigate('/chat'), [navigate])

  return (
    // `h-full`: `AppLayout`'taki `<main>` zaten `flex-1` ile kalan yüksekliği alıyor (bkz.
    // `components/layout/AppLayout.tsx`) — burada sabit bir `calc(100vh-...)` DEĞERİ TAHMİN
    // ETMEK yerine o yüksekliğin tamamı doldurulur. `main` `overflow-y-auto` olsa da içerik tam
    // oturduğunda kendisi kaymaz; kaydırma İÇERDEKİ `MessageList`/`ConversationList`e aittir.
    <div className="flex h-full min-h-0 overflow-hidden rounded-lg border border-border-subtle bg-surface-1">
      <aside
        className={cn(
          'flex min-h-0 w-full min-w-0 flex-col border-border-subtle lg:w-80 lg:shrink-0 lg:border-r',
          selectedId !== null && 'hidden lg:flex'
        )}
      >
        <ConversationList
          selectedId={selectedId}
          onSelect={handleSelect}
          onNewConversation={handleOpenNewConversation}
        />
      </aside>

      <section
        className={cn('flex min-h-0 min-w-0 flex-1 flex-col', selectedId === null && 'hidden lg:flex')}
      >
        {selectedId === null ? (
          <EmptyState
            icon={<MessageSquarePlus className="size-6" aria-hidden="true" />}
            title={t('page.emptyTitle')}
            className="m-auto"
            action={
              <button
                type="button"
                onClick={handleOpenNewConversation}
                className="text-sm font-medium text-primary hover:underline"
              >
                {t('common.startNewConversation')}
              </button>
            }
          />
        ) : isConversationLoading || !conversation ? (
          <div className="flex flex-col gap-3 p-4" aria-busy="true">
            <div className="flex items-center gap-3">
              <Skeleton variant="circle" width={40} height={40} />
              <Skeleton variant="text" width={160} />
            </div>
            <Skeleton variant="rect" height={1} />
            <div className="flex flex-col gap-2">
              <Skeleton variant="rect" width={220} height={36} className="self-start rounded-lg" />
              <Skeleton variant="rect" width={180} height={36} className="self-end rounded-lg" />
            </div>
          </div>
        ) : (
          <>
            <ConversationHeader conversation={conversation} onBack={handleBack} />
            {/* `key`: konuşma değişince `MessageList`i sıfırdan mount eder — scroll/sayaç state'i ve
                ref'leri temizlemenin yolu budur (bkz. `MessageList.tsx` başındaki gerekçe). */}
            <MessageList key={selectedId} conversationId={selectedId} />
            <TypingIndicator users={typingUsers} />
            <MessageComposer conversationId={selectedId} members={conversation.members} notifyTyping={notifyTyping} />
          </>
        )}
      </section>

      <NewConversationModal
        key={newConversationKey}
        open={newConversationOpen}
        onClose={() => setNewConversationOpen(false)}
        onCreated={handleCreated}
      />
    </div>
  )
}
