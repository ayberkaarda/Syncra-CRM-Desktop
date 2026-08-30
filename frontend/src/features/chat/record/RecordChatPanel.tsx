// Kayda bağlı sohbet paneli — fırsat/destek talebi detay sayfalarına gömülen, sabit yükseklikli
// ve kendi içinde kaydırılan sohbet kutusu (Faz 12 görev tanımı "ÜRETECEKLERİN").
//
// GÖRÜNÜRLÜK: backend zaten `presence-record.{type}.{id}` ile aynı kuralı zorluyor (ilgili
// modülün `.view` izni + kayıt var), UI burada sadece TUTARLI davranıyor. Ayrıca `chat.use`
// izni şarttır — bu izne sahip olmayan kullanıcıda (ör. Viewer rolü) panel HİÇ render edilmez;
// boş kutu/hata göstermek yerine `null` döner. Rules-of-hooks'u bozmadan bunu sağlamak için izin
// kontrolü ayrı bir üst bileşende yapılır, veri/soket hook'ları yalnızca izin VARSA mount edilen
// `RecordChatPanelContent` içinde çağrılır.
//
// VERİ KATMANI: `useRecordConversation` konuşmayı get-or-create eder; `useChatSocket` panel her
// zaman görünür olduğu için (bu iki sayfada sekme yapısı yok) konuşma hazır olur olmaz abone
// olur, panel unmount olduğunda (`conversationId` `null`'a döner ya da bileşen kaldırılır) hook
// kendi içinde temizlenir. Boş/yükleniyor durumlarını BU bileşen kendisi yönetir (görev tanımı
// açıkça ikisini de istiyor), bu yüzden `useMessages` de burada çağrılır — `MessageList` da aynı
// `conversationId` ile aynı query key'i kullanacağından (React Query) bu ekstra ağ isteği
// YARATMAZ, önbellek paylaşılır. `useMessages`'ın dönüşü ham `useInfiniteQuery` sonucunun üst
// kümesidir (`hooks/useMessages.ts`): düz `messages` (ESKİDEN YENİYE) zaten hazır, boş durumu
// `messages.length === 0` ile tespit edilir.
//
// `TypingIndicator`/`MessageComposer` kendi `useTyping` örneklerini AÇMAZ — `ChatPage.tsx`
// deseniyle AYNI: `useTyping(conversationId)` burada BİR KEZ çağrılır, `typingUsers`/
// `notifyTyping` prop olarak akar (bkz. o dosyanın başındaki gerekçe — çoğaltılmış abonelik
// önlenir). `MessageComposer`'ın istediği `members` de `useRecordConversation`'ın döndürdüğü
// `Conversation.members`'tan gelir — ekstra istek YOK.
import { useTranslation } from 'react-i18next'
import { MessageCircle } from 'lucide-react'
import { Card, CardBody, CardHeader, EmptyState, Skeleton } from '../../../components/ui'
import { usePermission } from '../../auth/hooks/usePermission'
import { useRecordConversation } from '../hooks/useRecordConversation'
import { useChatSocket } from '../hooks/useChatSocket'
import { useMessages } from '../hooks/useMessages'
import { useTyping } from '../hooks/useTyping'
import { MessageList } from '../components/MessageList'
import { MessageComposer } from '../components/MessageComposer'
import { TypingIndicator } from '../components/TypingIndicator'
import type { RecordConversableType } from '../types'

export type RecordChatPanelProps = {
  recordType: RecordConversableType
  recordId: number
}

/** Sabit yükseklik — detay sayfasının tamamını uzatmasın, kendi içinde kaydırılsın. */
const PANEL_HEIGHT_CLASS = 'h-[480px]'

export function RecordChatPanel({ recordType, recordId }: RecordChatPanelProps) {
  const { can } = usePermission()

  // Bu yalnızca UI görünürlüğü içindir; asıl yetki kontrolü daima backend'dedir (bkz.
  // `usePermission` başındaki not). `chat.use` yoksa alttaki veri/soket hook'ları hiç mount
  // edilmez — panel sekmesi/bölümü sayfada hiç görünmez.
  if (!can('chat.use')) {
    return null
  }

  return <RecordChatPanelContent recordType={recordType} recordId={recordId} />
}

function RecordChatPanelContent({ recordType, recordId }: RecordChatPanelProps) {
  const { t } = useTranslation('chat')
  const { data: conversation, isLoading: isConversationLoading } = useRecordConversation(recordType, recordId)
  const conversationId = conversation?.id ?? null

  // Panel her zaman görünür olduğundan (bu iki detay sayfasında sekme yapısı yok) konuşma
  // hazır olur olmaz abone olunur; panel görünmüyor olsaydı `null` geçilerek abonelik kapatılırdı
  // (görev tanımının DİKKAT bölümü).
  useChatSocket(conversationId)
  const { typingUsers, notifyTyping } = useTyping(conversationId)

  const { messages, isLoading: isMessagesLoading } = useMessages(conversationId)

  const isLoading = isConversationLoading || (conversationId !== null && isMessagesLoading)
  const isEmpty = !isLoading && conversationId !== null && messages.length === 0

  return (
    <Card>
      <CardHeader title={t('recordPanel.title')} subtitle={t('recordPanel.subtitle')} />
      <CardBody noPadding className="flex flex-col" aria-busy={isLoading}>
        <div className={`flex ${PANEL_HEIGHT_CLASS} flex-col`}>
          {isLoading || conversationId === null ? (
            <div className="flex flex-1 flex-col gap-3 p-4">
              <Skeleton variant="text" width="60%" />
              <Skeleton variant="text" width="40%" />
              <Skeleton variant="text" width="70%" />
            </div>
          ) : (
            <>
              <div className="flex-1 overflow-y-auto">
                {isEmpty ? (
                  <EmptyState
                    icon={<MessageCircle className="size-6" aria-hidden="true" />}
                    title={t('recordPanel.emptyTitle')}
                    description={t('recordPanel.emptyDescription')}
                  />
                ) : (
                  // `key`: konuşma değişince `MessageList`i sıfırdan mount eder — bkz. o dosyanın
                  // başındaki gerekçe (scroll/sayaç state'i ve ref'leri temizlemenin yolu budur).
                  <MessageList key={conversationId} conversationId={conversationId} />
                )}
              </div>
              <TypingIndicator users={typingUsers} />
              <div className="border-t border-border-subtle p-3">
                <MessageComposer
                  conversationId={conversationId}
                  members={conversation?.members ?? []}
                  notifyTyping={notifyTyping}
                />
              </div>
            </>
          )}
        </div>
      </CardBody>
    </Card>
  )
}
