// Mesaj yazma kancaları: gönderme (İYİMSER), düzenleme ve silme.
//
// İYİMSERLİK NEDEN SADECE BURADA: sohbet, gecikmenin doğrudan hissedildiği tek ekrandır —
// yazıp Enter'a bastığında mesaj ANINDA görünmezse arayüz bozuk hissettirir. Üyelik/ayar
// mutasyonları (bkz. `useConversationMutations`) bilerek sunucu yanıtını bekler.
//
// BAŞARISIZLIKTA MESAJ KAYBOLMAZ: iyimser kayıt listede kalır, `client.status = 'failed'`
// olur ve `retry(clientId)` ile aynı gövde yeniden gönderilir. "Sessizce yok olan mesaj"
// kabul edilebilir bir davranış değildir.
import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { UseMutationResult } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import { useAuthStore } from '../../auth/store'
import {
  createOptimisticMessage,
  findMessage,
  markOptimisticFailed,
  nextOptimisticId,
  patchMessage,
  removeMessage,
  replaceOptimisticMessage,
  tombstoneMessage,
  upsertIncomingMessage,
} from '../utils'
import type { MessagesInfiniteData } from '../utils'
import { bumpConversationPreview, getMessagesCache, updateMessagesCache } from './chatCache'
import type { Attachment, ChatMessage, ChatUser, Message, SendMessagePayload } from '../types'
import { getPlatform } from '../../../platform'

/** İyimser kaydı en yeni sayfanın başına ekler (sıralama sözleşmesi: `utils.ts` başı). */
function insertOptimistic(data: MessagesInfiniteData, message: ChatMessage): MessagesInfiniteData {
  if (data.pages.length === 0) return data
  const pages = data.pages.slice()
  pages[0] = { ...pages[0], data: [message, ...pages[0].data] }
  return { ...data, pages }
}

function toChatUser(user: { id: number; name: string; email: string } | null | undefined): ChatUser | null {
  return user ? { id: user.id, name: user.name, email: user.email } : null
}

export type SendMessageVariables = SendMessagePayload & {
  /** Yüklenmiş ek — iyimser balonda önizleme gösterebilmek için (istek gövdesine GİRMEZ). */
  attachment?: Attachment | null
  /** İç kullanım: tekrar denemede aynı geçici kaydı yeniden kullanmak için. */
  clientId?: number
}

export type UseSendMessageResult = UseMutationResult<Message, Error, SendMessageVariables, { clientId: number }> & {
  /** Başarısız iyimser mesajı aynı gövdeyle yeniden gönderir. */
  retry: (clientId: number) => void
  /** Başarısız iyimser mesajı listeden tamamen kaldırır (kullanıcı vazgeçti). */
  discard: (clientId: number) => void
}

export function useSendMessage(conversationId: number): UseSendMessageResult {
  const queryClient = useQueryClient()
  const currentUser = useAuthStore((state) => state.user)
  const currentUserId = currentUser?.id ?? null

  const mutation = useMutation<Message, Error, SendMessageVariables, { clientId: number }>({
    mutationFn: (variables) =>
      getPlatform().data.chat.sendMessage(conversationId, {
        body: variables.body,
        attachment_id: variables.attachment_id,
        mentions: variables.mentions,
      }),

    onMutate: (variables) => {
      const clientId = variables.clientId ?? nextOptimisticId()
      const payload: SendMessagePayload = {
        body: variables.body ?? null,
        attachment_id: variables.attachment_id ?? null,
        mentions: variables.mentions,
      }
      const optimistic = createOptimisticMessage({
        conversationId,
        user: toChatUser(currentUser),
        payload,
        attachment: variables.attachment ?? null,
        clientId,
      })

      updateMessagesCache(queryClient, conversationId, (data) =>
        // Tekrar denemede kayıt ZATEN listededir: yenisini eklemek yerine durumu
        // `pending`'e geri alınır, böylece mesaj yerinden oynamaz.
        findMessage(data, clientId)
          ? patchMessage(data, clientId, (message) => ({
              ...message,
              client: { status: 'pending', payload, clientId },
            }))
          : insertOptimistic(data, optimistic),
      )

      bumpConversationPreview(queryClient, conversationId, optimistic)
      return { clientId }
    },

    onSuccess: (real, _variables, context) => {
      updateMessagesCache(queryClient, conversationId, (data) =>
        replaceOptimisticMessage(data, context.clientId, real, currentUserId),
      )
      bumpConversationPreview(queryClient, conversationId, real)
    },

    onError: (error, _variables, context) => {
      if (context) {
        updateMessagesCache(queryClient, conversationId, (data) =>
          markOptimisticFailed(data, context.clientId),
        )
      }
      toast.error(getErrorMessage(error))
    },
  })

  const retry = useCallback(
    (clientId: number) => {
      const message = findMessage(getMessagesCache(queryClient, conversationId), clientId)
      if (!message?.client) return
      mutation.mutate({ ...message.client.payload, attachment: message.attachment, clientId })
    },
    [conversationId, mutation, queryClient],
  )

  const discard = useCallback(
    (clientId: number) => {
      updateMessagesCache(queryClient, conversationId, (data) => removeMessage(data, clientId))
    },
    [conversationId, queryClient],
  )

  // `Object.assign({}, ...)` KASITLI: `UseMutationResult` bir birleşim (union) tipidir ve
  // nesne yayılımı (spread) o birleşimi genişletip dönüş tipiyle uyuşmazlık üretebilir;
  // `Object.assign` tam olarak istediğimiz kesişim (intersection) tipini verir.
  return Object.assign({}, mutation, { retry, discard })
}

export type EditMessageVariables = { messageId: number; body: string }

/**
 * Düzenleme de iyimserdir ama GERİ ALINABİLİR: hata durumunda mesajın önceki hali
 * (anlık görüntü) aynen geri yazılır — yarım kalmış bir düzenleme ekranda kalmaz.
 */
export function useEditMessage(conversationId: number) {
  const queryClient = useQueryClient()
  const currentUserId = useAuthStore((state) => state.user?.id ?? null)

  return useMutation<Message, Error, EditMessageVariables, { previous: ChatMessage | undefined }>({
    mutationFn: ({ messageId, body }) => getPlatform().data.chat.editMessage(messageId, body),

    onMutate: ({ messageId, body }) => {
      const previous = findMessage(getMessagesCache(queryClient, conversationId), messageId)
      updateMessagesCache(queryClient, conversationId, (data) =>
        patchMessage(data, messageId, (message) => ({
          ...message,
          body,
          edited_at: new Date().toISOString(),
        })),
      )
      return { previous }
    },

    onSuccess: (updated) => {
      updateMessagesCache(queryClient, conversationId, (data) =>
        upsertIncomingMessage(data, updated, currentUserId),
      )
    },

    onError: (error, _variables, context) => {
      const previous = context?.previous
      if (previous) {
        updateMessagesCache(queryClient, conversationId, (data) =>
          patchMessage(data, previous.id, () => previous),
        )
      }
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Silme. İki ayrı durum vardır:
 *  - Geçici (negatif id) kayıt: sunucuda KARŞILIĞI YOK, istek atılmaz, doğrudan listeden düşer.
 *  - Gerçek mesaj: listeden çıkarılmaz, TOMBSTONE'a çevrilir (`deleted_at` dolar, `body`/
 *    `attachment` boşalır) — sunucunun davranışının aynısı, UI "Bu mesaj silindi" yazar.
 */
export function useDeleteMessage(conversationId: number) {
  const queryClient = useQueryClient()

  return useMutation<void, Error, number, { previous: ChatMessage | undefined; local: boolean }>({
    mutationFn: async (messageId) => {
      if (messageId < 0) return
      await getPlatform().data.chat.deleteMessage(messageId)
    },

    onMutate: (messageId) => {
      const previous = findMessage(getMessagesCache(queryClient, conversationId), messageId)
      const local = messageId < 0
      updateMessagesCache(queryClient, conversationId, (data) =>
        local ? removeMessage(data, messageId) : tombstoneMessage(data, messageId),
      )
      return { previous, local }
    },

    onError: (error, messageId, context) => {
      const previous = context?.previous
      if (previous) {
        updateMessagesCache(queryClient, conversationId, (data) =>
          context?.local ? insertOptimistic(data, previous) : patchMessage(data, messageId, () => previous),
        )
      }
      toast.error(getErrorMessage(error))
    },
  })
}
