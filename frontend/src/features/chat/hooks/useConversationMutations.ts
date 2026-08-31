// Konuşma YAZMA kancaları: oluşturma ve üyelik/ayar yönetimi.
import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { QueryClient } from '@tanstack/react-query'
import { toast } from '../../../components/ui'
import { getErrorMessage } from '../../../lib/axios'
import i18n from '../../../i18n'
import { chatKeys } from '../api'
import { syncConversationCaches } from './chatCache'
import { useConversation } from './useConversations'
import { useChatStore } from '../store'
import type { ChatUser, Conversation, CreateConversationPayload } from '../types'
import { getPlatform } from '../../../platform'

/**
 * Detayı + tüm liste önbelleklerini sunucu yanıtıyla yazar (`chatCache.syncConversationCaches`)
 * ve ardından listeleri tazeler: üyelik/ad değişikliği bir konuşmanın aktif filtrelere
 * (`filter[type]`, `q`) uyumunu değiştirebilir, bunu yalnızca sunucu bilir.
 */
function syncConversation(queryClient: QueryClient, conversation: Conversation) {
  syncConversationCaches(queryClient, conversation)
  void queryClient.invalidateQueries({ queryKey: chatKeys.conversations })
}

/** Konuşma artık erişilebilir değil (silindi ya da ayrıldık): tüm izlerini önbellekten sil. */
function forgetConversation(queryClient: QueryClient, conversationId: number) {
  queryClient.removeQueries({ queryKey: chatKeys.conversation(conversationId) })
  queryClient.removeQueries({ queryKey: chatKeys.messages(conversationId) })
  void queryClient.invalidateQueries({ queryKey: chatKeys.conversations })
  void queryClient.invalidateQueries({ queryKey: chatKeys.unreadCount })
}

/**
 * Yeni konuşma (`dm` / `group`). Kayda bağlı sohbet BU UÇ İLE AÇILMAZ — onun için
 * `useRecordConversation` (get-or-create) kullanılır.
 */
export function useCreateConversation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateConversationPayload) => getPlatform().data.chat.createConversation(payload),
    onSuccess: (conversation) => {
      syncConversation(queryClient, conversation)
      toast.success(i18n.t('chat:toast.created'))
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  })
}

export type UseConversationMembersResult = {
  conversation: Conversation | undefined
  members: ChatUser[]
  isMuted: boolean
  isLoading: boolean
  /** Herhangi bir üyelik/ayar mutasyonu sürüyor mu (butonları kilitlemek için). */
  isPending: boolean
  addMembers: (userIds: number[]) => void
  removeMember: (userId: number) => void
  leave: () => void
  rename: (name: string) => void
  /** Konuşmayı SİLER (`DELETE /api/conversations/{id}`) — "ayrılmak" ile karıştırılmamalı. */
  remove: () => void
  /** Argüman verilmezse mevcut durumu tersler. */
  toggleMute: (isMuted?: boolean) => void
}

/**
 * Üyelik ve konuşma ayarları. Konuşma detayını `useConversation` ile okur — aynı sorgu
 * anahtarını paylaştığı için ekranda detay zaten açıksa İKİNCİ BİR İSTEK ATILMAZ.
 *
 * Buradaki mutasyonlar bilerek İYİMSER DEĞİLDİR: üyelik değişiklikleri yetki kontrolüne takılıp
 * 403 dönebilir ve yanlış bir "eklendi" görüntüsü mesajlaşmada yanıltıcı olur; sunucu yanıtı
 * beklenip önbellek onunla yazılır. (İyimserlik yalnızca mesaj gönderiminde vardır — orada
 * gecikme doğrudan hissedilir, bkz. `useSendMessage`.)
 */
export function useConversationMembers(conversationId: number | null): UseConversationMembersResult {
  const queryClient = useQueryClient()
  const clearConversationUnread = useChatStore((state) => state.clearConversationUnread)
  const { data: conversation, isLoading } = useConversation(conversationId)

  const onError = useCallback((error: unknown) => toast.error(getErrorMessage(error)), [])

  const addMembersMutation = useMutation({
    mutationFn: (userIds: number[]) => getPlatform().data.chat.addMembers(conversationId as number, userIds),
    onSuccess: (updated) => {
      syncConversation(queryClient, updated)
      toast.success(i18n.t('chat:toast.memberAdded'))
    },
    onError,
  })

  const removeMemberMutation = useMutation({
    mutationFn: (userId: number) => getPlatform().data.chat.removeMember(conversationId as number, userId),
    onSuccess: () => {
      if (conversationId !== null) {
        void queryClient.invalidateQueries({ queryKey: chatKeys.conversation(conversationId) })
      }
      void queryClient.invalidateQueries({ queryKey: chatKeys.conversations })
      toast.success(i18n.t('chat:toast.memberRemoved'))
    },
    onError,
  })

  const leaveMutation = useMutation({
    mutationFn: () => getPlatform().data.chat.leaveConversation(conversationId as number),
    onSuccess: () => {
      if (conversationId !== null) {
        clearConversationUnread(conversationId)
        forgetConversation(queryClient, conversationId)
      }
      toast.success(i18n.t('chat:toast.left'))
    },
    onError,
  })

  const renameMutation = useMutation({
    mutationFn: (name: string) => getPlatform().data.chat.renameConversation(conversationId as number, name),
    onSuccess: (updated) => {
      syncConversation(queryClient, updated)
      toast.success(i18n.t('chat:toast.renamed'))
    },
    onError,
  })

  const removeMutation = useMutation({
    mutationFn: () => getPlatform().data.chat.deleteConversation(conversationId as number),
    onSuccess: () => {
      if (conversationId !== null) {
        clearConversationUnread(conversationId)
        forgetConversation(queryClient, conversationId)
      }
      toast.success(i18n.t('chat:toast.deleted'))
    },
    onError,
  })

  const muteMutation = useMutation({
    mutationFn: (isMuted: boolean) => getPlatform().data.chat.muteConversation(conversationId as number, isMuted),
    onSuccess: (updated) => {
      syncConversation(queryClient, updated)
      toast.success(updated.is_muted ? i18n.t('chat:toast.muted') : i18n.t('chat:toast.unmuted'))
    },
    onError,
  })

  const isMuted = conversation?.is_muted ?? false

  const toggleMute = useCallback(
    (next?: boolean) => {
      if (conversationId === null) return
      muteMutation.mutate(next ?? !isMuted)
    },
    [conversationId, isMuted, muteMutation],
  )

  return {
    conversation,
    members: conversation?.members ?? [],
    isMuted,
    isLoading,
    isPending:
      addMembersMutation.isPending ||
      removeMemberMutation.isPending ||
      leaveMutation.isPending ||
      renameMutation.isPending ||
      removeMutation.isPending ||
      muteMutation.isPending,
    addMembers: (userIds) => {
      if (conversationId === null || userIds.length === 0) return
      addMembersMutation.mutate(userIds)
    },
    removeMember: (userId) => {
      if (conversationId === null) return
      removeMemberMutation.mutate(userId)
    },
    leave: () => {
      if (conversationId === null) return
      leaveMutation.mutate()
    },
    rename: (name) => {
      if (conversationId === null) return
      renameMutation.mutate(name)
    },
    remove: () => {
      if (conversationId === null) return
      removeMutation.mutate()
    },
    toggleMute,
  }
}
