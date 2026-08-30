// Tek mesaj balonu — kendi mesajın sağda, diğerleri solda. Silinmiş mesajlarda gövde yerine
// soluk italik "Bu mesaj silindi" gösterilir (`deleted_at` doluysa `body`/`attachment` zaten
// `null` gelir). `type='system'` ortalanmış küçük bir bilgi satırı olarak render edilir (balon
// YOK).
//
// GÜVENLİK: mesaj gövdesi kullanıcı girdisidir — `dangerouslySetInnerHTML` KULLANILMAZ. Düz metin
// `whitespace-pre-wrap` ile satır sonları korunarak render edilir; yalnızca `http(s)://` ile
// başlayan geçerli URL'ler `linkifyMessageBody` ile `<a rel="noopener noreferrer">`e çevrilir.
//
// İYİMSER GÖNDERİM: `message.client` doluysa bu kayıt henüz sunucuda onaylanmamıştır (bkz.
// `types.ts` → `ChatMessage`/`ChatMessageClientMeta`, `hooks/useMessageMutations.ts`).
// `status: 'pending'` iken soluk gösterilir ve düzenle/sil menüsü YOK (henüz gerçek bir sunucu
// kaydı değil); `status: 'failed'` iken "Gönderilemedi" + "Tekrar dene"/"Sil" satırı gösterilir
// (sırasıyla `useSendMessage().retry`/`.discard`).
import { useState } from 'react'
import type { KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, CheckCheck, Download, FileText, Pencil, RotateCcw, Trash2, TriangleAlert } from 'lucide-react'
import { Avatar, Button, Textarea } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useDeleteMessage, useEditMessage, useSendMessage } from '../hooks/useMessageMutations'
import { formatFileSize, formatMessageTime, linkifyMessageBody } from './chatShared'
import type { ChatMessage, TickState } from '../types'

export type MessageBubbleProps = {
  message: ChatMessage
  isOwn: boolean
  /** Ardışık aynı gönderenin mesajlarında avatar/ad tekrar etmesin diye — bkz. `MessageList`. */
  showMeta: boolean
}

export function MessageBubble({ message, isOwn, showMeta }: MessageBubbleProps) {
  const { t } = useTranslation(['chat', 'common'])
  const editMessage = useEditMessage(message.conversation_id)
  const deleteMessage = useDeleteMessage(message.conversation_id)
  const sendMessage = useSendMessage(message.conversation_id)

  const [isEditing, setIsEditing] = useState(false)
  const [draft, setDraft] = useState(message.body ?? '')

  const isDeleted = !!message.deleted_at
  const isSystem = message.type === 'system'
  const isPendingOptimistic = message.client?.status === 'pending'
  const isFailedOptimistic = message.client?.status === 'failed'
  const isOptimistic = !!message.client

  if (isSystem) {
    return (
      <div className="flex justify-center py-1">
        <p className="rounded-full bg-surface-2 px-3 py-1 text-xs text-fg-muted">{message.body}</p>
      </div>
    )
  }

  function startEdit() {
    setDraft(message.body ?? '')
    setIsEditing(true)
  }

  function cancelEdit() {
    setIsEditing(false)
    setDraft(message.body ?? '')
  }

  function submitEdit() {
    const trimmed = draft.trim()
    if (!trimmed || trimmed === message.body) {
      setIsEditing(false)
      return
    }
    editMessage.mutate({ messageId: message.id, body: trimmed }, { onSuccess: () => setIsEditing(false) })
  }

  function handleEditKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      submitEdit()
    } else if (event.key === 'Escape') {
      cancelEdit()
    }
  }

  function handleDelete() {
    if (!window.confirm(t('chat:confirm.deleteMessage'))) return
    deleteMessage.mutate(message.id)
  }

  function handleRetry() {
    if (message.client) sendMessage.retry(message.client.clientId)
  }

  function handleDiscard() {
    if (message.client) sendMessage.discard(message.client.clientId)
  }

  const canEdit = isOwn && !isDeleted && !isOptimistic && message.type === 'text'
  const canDelete = isOwn && !isDeleted && !isOptimistic

  return (
    <div className={cn('group flex items-end gap-2', isOwn ? 'flex-row-reverse' : 'flex-row')}>
      <div className="w-8 shrink-0">
        {!isOwn && showMeta && <Avatar name={message.user?.name ?? '?'} size="sm" />}
      </div>

      <div className={cn('flex max-w-[70%] min-w-0 flex-col gap-1', isOwn ? 'items-end' : 'items-start')}>
        {!isOwn && showMeta && (
          <p className="px-1 text-xs font-medium text-fg-muted">{message.user?.name ?? t('chat:message.unknownUser')}</p>
        )}

        <div className={cn('relative flex items-center gap-1.5', isOwn ? 'flex-row-reverse' : 'flex-row')}>
          <div
            className={cn(
              'min-w-0 rounded-lg px-3 py-2 text-sm',
              isOwn ? 'bg-primary text-primary-fg' : 'bg-surface-2 text-fg',
              isDeleted && 'italic opacity-60',
              isPendingOptimistic && 'opacity-60',
              isFailedOptimistic && 'ring-1 ring-danger'
            )}
          >
            {isDeleted ? (
              <p>{t('chat:message.deletedPreview')}</p>
            ) : isEditing ? (
              <div className="flex min-w-64 flex-col gap-2">
                <Textarea
                  value={draft}
                  onChange={(event) => setDraft(event.target.value)}
                  onKeyDown={handleEditKeyDown}
                  rows={2}
                  autoFocus
                  aria-label={t('chat:message.editAria')}
                  className="bg-surface-1 text-fg"
                />
                <div className="flex justify-end gap-1.5">
                  <Button type="button" size="sm" variant="ghost" onClick={cancelEdit}>
                    {t('common:actions.cancel')}
                  </Button>
                  <Button type="button" size="sm" loading={editMessage.isPending} onClick={submitEdit}>
                    {t('common:actions.save')}
                  </Button>
                </div>
              </div>
            ) : (
              <>
                {message.body && (
                  <p className="whitespace-pre-wrap break-words">
                    {linkifyMessageBody(message.body).map((part, index) =>
                      part.type === 'link' ? (
                        <a
                          key={index}
                          href={part.href}
                          target="_blank"
                          rel="noopener noreferrer"
                          className={cn('underline break-words', isOwn ? 'text-primary-fg' : 'text-primary')}
                        >
                          {part.value}
                        </a>
                      ) : (
                        <span key={index}>{part.value}</span>
                      )
                    )}
                  </p>
                )}
                {message.attachment && <AttachmentPreview attachment={message.attachment} isOwn={isOwn} />}
                <div className={cn('mt-1 flex items-center gap-1', isOwn ? 'justify-end' : 'justify-start')}>
                  {isPendingOptimistic ? (
                    <span className={cn('text-[11px] italic', isOwn ? 'text-primary-fg/70' : 'text-fg-muted')}>
                      {t('chat:message.sending')}
                    </span>
                  ) : isFailedOptimistic ? (
                    <span className="inline-flex items-center gap-1 text-[11px] text-danger">
                      <TriangleAlert className="size-3" aria-hidden="true" />
                      {t('chat:message.failed')}
                    </span>
                  ) : (
                    <>
                      <span className={cn('text-[11px]', isOwn ? 'text-primary-fg/70' : 'text-fg-muted')}>
                        {formatMessageTime(message.created_at)}
                      </span>
                      {message.edited_at && (
                        <span className={cn('text-[11px] italic', isOwn ? 'text-primary-fg/70' : 'text-fg-muted')}>
                          {t('chat:message.editedLabel')}
                        </span>
                      )}
                      {isOwn && <TickIndicator tick={message.tick} />}
                    </>
                  )}
                </div>
                {isFailedOptimistic && (
                  <div className="mt-1 flex items-center gap-2">
                    <button
                      type="button"
                      onClick={handleRetry}
                      className={cn(
                        'inline-flex items-center gap-1 text-[11px] font-medium underline',
                        isOwn ? 'text-primary-fg' : 'text-primary'
                      )}
                    >
                      <RotateCcw className="size-3" aria-hidden="true" />
                      {t('common:actions.retry')}
                    </button>
                    <button
                      type="button"
                      onClick={handleDiscard}
                      className={cn(
                        'text-[11px] font-medium underline',
                        isOwn ? 'text-primary-fg/80' : 'text-fg-muted'
                      )}
                    >
                      {t('common:actions.delete')}
                    </button>
                  </div>
                )}
              </>
            )}
          </div>

          {(canEdit || canDelete) && !isEditing && (
            <div className="hidden shrink-0 items-center gap-0.5 rounded-md bg-surface-1 p-0.5 shadow-card group-hover:flex">
              {canEdit && (
                <button
                  type="button"
                  onClick={startEdit}
                  aria-label={t('chat:message.editAria')}
                  className="inline-flex size-6 items-center justify-center rounded text-fg-muted hover:bg-surface-2 hover:text-fg"
                >
                  <Pencil className="size-3.5" aria-hidden="true" />
                </button>
              )}
              {canDelete && (
                <button
                  type="button"
                  onClick={handleDelete}
                  aria-label={t('chat:message.deleteAria')}
                  className="inline-flex size-6 items-center justify-center rounded text-fg-muted hover:bg-danger-tint hover:text-danger"
                >
                  <Trash2 className="size-3.5" aria-hidden="true" />
                </button>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function TickIndicator({ tick }: { tick: TickState }) {
  const { t } = useTranslation('chat')
  if (tick === 'sent') {
    return <Check className="size-3.5 text-primary-fg/70" aria-label={t('message.tickSent')} />
  }
  if (tick === 'delivered') {
    return <CheckCheck className="size-3.5 text-primary-fg/70" aria-label={t('message.tickDelivered')} />
  }
  return <CheckCheck className="size-3.5 text-primary-fg" aria-label={t('message.tickRead')} />
}

function AttachmentPreview({ attachment, isOwn }: { attachment: NonNullable<ChatMessage['attachment']>; isOwn: boolean }) {
  if (attachment.is_image) {
    return (
      <a href={`${attachment.url}?inline=1`} target="_blank" rel="noopener noreferrer" className="mt-1 block">
        <img
          src={`${attachment.url}?inline=1`}
          alt={attachment.original_name}
          className="max-h-64 max-w-full rounded-md object-cover"
        />
      </a>
    )
  }

  return (
    <a
      href={attachment.url}
      target="_blank"
      rel="noopener noreferrer"
      download
      className={cn(
        'mt-1 flex items-center gap-2 rounded-md px-2.5 py-2',
        isOwn ? 'bg-primary-fg/10' : 'bg-surface-1'
      )}
    >
      <FileText className="size-5 shrink-0" aria-hidden="true" />
      <span className="min-w-0 flex-1 truncate text-xs font-medium">{attachment.original_name}</span>
      <span className="shrink-0 text-[11px] opacity-80">{formatFileSize(attachment.size)}</span>
      <Download className="size-3.5 shrink-0" aria-hidden="true" />
    </a>
  )
}
