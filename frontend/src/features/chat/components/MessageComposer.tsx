// Mesaj yazma alanı — çok satırlı giriş (Enter gönderir, Shift+Enter yeni satır), dosya ekleme
// (buton + sürükle-bırak) yükleme yüzdesi göstergesiyle, `@mention` önerisi (konuşma üyeleri
// arasından filtrelenir, seçilen kullanıcı `mentions: number[]`e eklenir).
//
// `notifyTyping`/`typingUsers` bu bileşende DEĞİL, çağıran sayfada (`ChatPage`) tek sefer
// `useTyping(conversationId)` ile üretilip prop olarak geçirilir — `useChatSocket` için görev
// tanımında açıkça istenen "sayfada bir kez çağır" kuralının aynısı `useTyping` için de temkinli
// biçimde uygulanır (aksi halde bu bileşen + `ChatPage` iki ayrı abonelik açabilir).
//
// `useUploadAttachment()` `progress` (0-100) + `isPending` expose eder (bkz.
// `hooks/useUploadAttachment.ts`). `useSendMessage` iyimser (`hooks/useMessageMutations.ts`)
// olduğundan gönderi sonrası taslak metni sunucu yanıtı beklenmeden hemen temizlenir; yüklenmiş
// ek varsa `attachment` alanıyla da birlikte gönderilir (iyimser balonda önizleme için).
import { useRef, useState } from 'react'
import type { ChangeEvent, DragEvent, KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Paperclip, Send, X } from 'lucide-react'
import { Button, Textarea } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useOnlineOnly } from '../../../platform/useOnlineOnly'
import { foldTurkish } from '../../../lib/turkishCase'
import { useSendMessage } from '../hooks/useMessageMutations'
import { useUploadAttachment } from '../hooks/useUploadAttachment'
import { formatFileSize } from './chatShared'
import type { Attachment, ChatUser } from '../types'

export type MessageComposerProps = {
  conversationId: number
  /** @mention önerileri için konuşma üyeleri. */
  members: ChatUser[]
  notifyTyping: () => void
}

const MENTION_PATTERN = /@([^\s@]*)$/

export function MessageComposer({ conversationId, members, notifyTyping }: MessageComposerProps) {
  const { t } = useTranslation('chat')
  const sendMessage = useSendMessage(conversationId)
  const uploadAttachment = useUploadAttachment()
  // SYNCDESKTOP §8 (O102) — "attachments upload (kuyruk)". There is no queue yet
  // (`files::attach_from_paths` is F5-5, see `platform/data/comms.ts`), so offline the attach
  // button is disabled and the dictionary sentence explains what will happen to the file.
  const attachGuard = useOnlineOnly('attachments.upload')

  const textareaRef = useRef<HTMLTextAreaElement | null>(null)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

  const [text, setText] = useState('')
  const [mentionIds, setMentionIds] = useState<number[]>([])
  const [pendingFileName, setPendingFileName] = useState<string | null>(null)
  const [attachment, setAttachment] = useState<Attachment | null>(null)
  const [isDragging, setIsDragging] = useState(false)

  const [mentionOpen, setMentionOpen] = useState(false)
  const [mentionQuery, setMentionQuery] = useState('')
  const [mentionStart, setMentionStart] = useState<number | null>(null)
  const [highlightedIndex, setHighlightedIndex] = useState(0)

  // F6/H8: `.toLowerCase()` Türkçe İ/ı kuralını uygulamıyordu (İ -> i̇ birleşik
  // nokta), bu yüzden "İhsan" adlı üye "@ihsan" ile aranınca listede hiç
  // çıkmıyordu. `foldTurkish` backend `TurkishCase::fold()` ile aynı agresif
  // kararı uygular — bkz. `lib/turkishCase.ts`.
  const mentionResults = mentionOpen
    ? members.filter((member) => foldTurkish(member.name).includes(foldTurkish(mentionQuery))).slice(0, 6)
    : []

  function handleChange(event: ChangeEvent<HTMLTextAreaElement>) {
    const value = event.target.value
    setText(value)
    notifyTyping()

    const cursor = event.target.selectionStart ?? value.length
    const beforeCursor = value.slice(0, cursor)
    const match = beforeCursor.match(MENTION_PATTERN)

    if (match) {
      setMentionOpen(true)
      setMentionQuery(match[1])
      setMentionStart(cursor - match[0].length)
      setHighlightedIndex(0)
    } else {
      setMentionOpen(false)
      setMentionQuery('')
      setMentionStart(null)
    }
  }

  function selectMention(member: ChatUser) {
    if (mentionStart === null) return
    const textarea = textareaRef.current
    const cursor = textarea?.selectionStart ?? text.length
    const before = text.slice(0, mentionStart)
    const after = text.slice(cursor)
    const inserted = `@${member.name} `
    const nextText = `${before}${inserted}${after}`

    setText(nextText)
    setMentionIds((ids) => (ids.includes(member.id) ? ids : [...ids, member.id]))
    setMentionOpen(false)
    setMentionQuery('')
    setMentionStart(null)

    requestAnimationFrame(() => {
      const nextCursor = before.length + inserted.length
      textarea?.focus()
      textarea?.setSelectionRange(nextCursor, nextCursor)
    })
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (mentionOpen && mentionResults.length > 0) {
      if (event.key === 'ArrowDown') {
        event.preventDefault()
        setHighlightedIndex((index) => (index + 1) % mentionResults.length)
        return
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault()
        setHighlightedIndex((index) => (index - 1 + mentionResults.length) % mentionResults.length)
        return
      }
      if (event.key === 'Enter' || event.key === 'Tab') {
        event.preventDefault()
        selectMention(mentionResults[highlightedIndex])
        return
      }
      if (event.key === 'Escape') {
        setMentionOpen(false)
        return
      }
    }

    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      handleSubmit()
    }
  }

  function handleFile(file: File) {
    // The drop zone is the attach button's twin: a disabled button would otherwise be trivially
    // bypassed by dragging the file in. `attachGuard.offline` is always false on the web build.
    if (attachGuard.offline) return
    setPendingFileName(file.name)
    setAttachment(null)
    uploadAttachment.mutate(file, {
      onSuccess: (uploaded: Attachment) => setAttachment(uploaded),
      onError: () => {
        setPendingFileName(null)
      },
    })
  }

  function handleFileInputChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    if (file) handleFile(file)
    event.target.value = ''
  }

  function handleDrop(event: DragEvent<HTMLDivElement>) {
    event.preventDefault()
    setIsDragging(false)
    const file = event.dataTransfer.files?.[0]
    if (file) handleFile(file)
  }

  function removeAttachment() {
    setPendingFileName(null)
    setAttachment(null)
  }

  function handleSubmit() {
    const trimmed = text.trim()
    if (!trimmed && !attachment) return
    if (uploadAttachment.isPending) return // dosya yüklemesi bitmeden gönderme

    sendMessage.mutate({
      body: trimmed || undefined,
      attachment_id: attachment?.id,
      // Sunucu onayı beklenmeden gösterilen iyimser balonda önizleme için — istek gövdesine
      // GİRMEZ (bkz. `useMessageMutations.ts` → `SendMessageVariables`).
      attachment: attachment ?? undefined,
      mentions: mentionIds.length > 0 ? mentionIds : undefined,
    })

    setText('')
    setMentionIds([])
    setPendingFileName(null)
    setAttachment(null)
    textareaRef.current?.focus()
  }

  const canSend = (text.trim().length > 0 || !!attachment) && !uploadAttachment.isPending

  return (
    <div
      className={cn(
        'relative border-t border-border-subtle p-3',
        isDragging && 'bg-primary-tint'
      )}
      onDragOver={(event) => {
        event.preventDefault()
        setIsDragging(true)
      }}
      onDragLeave={() => setIsDragging(false)}
      onDrop={handleDrop}
    >
      {isDragging && (
        <div className="pointer-events-none absolute inset-2 flex items-center justify-center rounded-md border-2 border-dashed border-primary text-sm font-medium text-primary">
          {t('composer.dropFile')}
        </div>
      )}

      {(pendingFileName || attachment) && (
        <div className="mb-2 flex items-center gap-2 rounded-md bg-surface-2 px-2.5 py-1.5 text-xs">
          <span className="min-w-0 flex-1 truncate text-fg">{attachment?.original_name ?? pendingFileName}</span>
          {attachment && <span className="shrink-0 text-fg-muted">{formatFileSize(attachment.size)}</span>}
          {uploadAttachment.isPending && (
            <div className="h-1 w-16 shrink-0 overflow-hidden rounded-full bg-surface-3">
              <div
                className="h-full bg-primary transition-[width] duration-150 motion-reduce:transition-none"
                style={{ width: `${uploadAttachment.progress ?? 0}%` }}
              />
            </div>
          )}
          <button
            type="button"
            onClick={removeAttachment}
            aria-label={t('composer.removeAttachmentAria')}
            className="shrink-0 rounded p-0.5 text-fg-muted hover:bg-surface-3 hover:text-fg"
          >
            <X className="size-3.5" aria-hidden="true" />
          </button>
        </div>
      )}

      <div className="relative flex items-end gap-2">
        <input ref={fileInputRef} type="file" className="hidden" onChange={handleFileInputChange} />
        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          disabled={attachGuard.offline}
          title={attachGuard.title}
          aria-label={t('composer.attachFileAria')}
          className={cn(
            'inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
            'transition-colors duration-150 motion-reduce:transition-none',
            'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent disabled:hover:text-fg-muted',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
          )}
        >
          <Paperclip className="size-4" aria-hidden="true" />
        </button>

        <div className="relative min-w-0 flex-1">
          {mentionOpen && mentionResults.length > 0 && (
            <ul
              className="absolute bottom-full left-0 z-10 mb-1 w-56 overflow-hidden rounded-md border border-border-subtle bg-surface-1 py-1 shadow-popover"
              role="listbox"
              aria-label={t('composer.mentionSuggestionsAria')}
            >
              {mentionResults.map((member, index) => (
                <li key={member.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={index === highlightedIndex}
                    onMouseEnter={() => setHighlightedIndex(index)}
                    onClick={() => selectMention(member)}
                    className={cn(
                      'flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm',
                      index === highlightedIndex ? 'bg-primary-tint text-primary' : 'text-fg hover:bg-surface-2'
                    )}
                  >
                    <span className="truncate">{member.name}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <Textarea
            ref={textareaRef}
            value={text}
            onChange={handleChange}
            onKeyDown={handleKeyDown}
            rows={1}
            placeholder={t('composer.placeholder')}
            aria-label={t('composer.inputAria')}
            className="max-h-40 min-h-10 resize-none py-2"
          />
        </div>

        <Button
          type="button"
          size="md"
          onClick={handleSubmit}
          disabled={!canSend}
          loading={sendMessage.isPending}
          aria-label={t('composer.sendAria')}
        >
          <Send className="size-4" aria-hidden="true" />
        </Button>
      </div>
    </div>
  )
}
