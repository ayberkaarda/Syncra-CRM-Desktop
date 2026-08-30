// Yeni sohbet başlatma modalı — DM mi grup mu seçimi, kullanıcı seçici (mevcut
// `features/users/api/usersApi.ts`teki `useUsers` ucundan besleniyor, YENİ bir uç yazılmadı —
// görev tanımında AÇIKÇA istendi), grup için ad alanı.
//
// `useCreateConversation().mutate(payload, { onSuccess })` — per-çağrı `onSuccess`'i oluşturulan
// `Conversation`u alır (hook'un kendi genel `onSuccess`'ine EK olarak çalışır, bkz.
// `hooks/useConversationMutations.ts`).
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Search } from 'lucide-react'
import { Avatar, Button, Checkbox, Input, Modal, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useAuthStore } from '../../auth/store'
import { useUsers } from '../../users/api/usersApi'
import { useCreateConversation } from '../hooks/useConversationMutations'
import type { Conversation, ConversationType } from '../types'

export type NewConversationModalProps = {
  open: boolean
  onClose: () => void
  onCreated: (conversationId: number) => void
}

// FORM SIFIRLAMA: bu bileşen `open`i izleyip alanları render sırasında/efektte SIFIRLAMAZ —
// çağıran taraf (`ChatPage.tsx`) her açılışta `key`i değiştirerek bileşeni yeniden mount eder,
// bu yüzden `useState` başlangıç değerleri zaten temiz gelir. `key` YALNIZCA açılış anında
// değişir (kapanışta AYNI kalır): `Modal`in kendi kapanış geçişi (150ms fade/scale, bkz.
// `components/ui/Modal.tsx`) aynı örnek üzerinde `open` prop'unun `false`'a düşmesine dayanıyor
// — `key`i kapanışta da değiştirseydik bileşen o anda sıfırdan mount edilir ve geçiş oynamadan
// kaybolurdu.

export function NewConversationModal({ open, onClose, onCreated }: NewConversationModalProps) {
  const { t } = useTranslation(['chat', 'common'])
  const currentUserId = useAuthStore((state) => state.user?.id)
  const createConversation = useCreateConversation()

  const [type, setType] = useState<Exclude<ConversationType, 'record'>>('dm')
  const [search, setSearch] = useState('')
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [groupName, setGroupName] = useState('')

  const { data, isLoading } = useUsers({ q: search || undefined, per_page: 20 })
  const users = (data?.data ?? []).filter((user) => user.id !== currentUserId)

  function toggleUser(userId: number) {
    if (type === 'dm') {
      setSelectedIds((ids) => (ids[0] === userId ? [] : [userId]))
      return
    }
    setSelectedIds((ids) => (ids.includes(userId) ? ids.filter((id) => id !== userId) : [...ids, userId]))
  }

  const isValid = type === 'dm' ? selectedIds.length === 1 : groupName.trim().length > 0 && selectedIds.length >= 1

  function handleSubmit() {
    if (!isValid) return
    createConversation.mutate(
      {
        type,
        name: type === 'group' ? groupName.trim() : undefined,
        member_ids: selectedIds,
      },
      {
        onSuccess: (conversation: Conversation) => {
          onCreated(conversation.id)
          onClose()
        },
      }
    )
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('chat:newConversation.title')}
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="button" disabled={!isValid} loading={createConversation.isPending} onClick={handleSubmit}>
            {t('common:actions.create')}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <div
          className="flex items-center gap-1 rounded-md bg-surface-2 p-1"
          role="tablist"
          aria-label={t('chat:newConversation.typeAria')}
        >
          <button
            type="button"
            role="tab"
            aria-selected={type === 'dm'}
            onClick={() => {
              setType('dm')
              setSelectedIds((ids) => ids.slice(0, 1))
            }}
            className={cn(
              'flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150 motion-reduce:transition-none',
              type === 'dm' ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg'
            )}
          >
            {t('chat:conversationType.dm')}
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={type === 'group'}
            onClick={() => setType('group')}
            className={cn(
              'flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150 motion-reduce:transition-none',
              type === 'group' ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg'
            )}
          >
            {t('chat:conversationType.group')}
          </button>
        </div>

        {type === 'group' && (
          <Input
            label={t('chat:common.groupNameLabel')}
            value={groupName}
            onChange={(event) => setGroupName(event.target.value)}
            placeholder={t('chat:newConversation.groupNamePlaceholder')}
          />
        )}

        <Input
          inputSize="sm"
          placeholder={t('chat:common.searchUsersPlaceholder')}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          leftIcon={<Search className="size-4" aria-hidden="true" />}
          aria-label={t('chat:common.searchUsersAria')}
        />

        <div className="flex max-h-64 flex-col gap-1 overflow-y-auto">
          {isLoading ? (
            Array.from({ length: 4 }).map((_, index) => (
              <div key={index} className="flex items-center gap-2.5 px-1 py-1.5">
                <Skeleton variant="circle" width={32} height={32} />
                <Skeleton variant="text" width={140} />
              </div>
            ))
          ) : users.length === 0 ? (
            <p className="px-1 py-4 text-center text-sm text-fg-muted">{t('chat:newConversation.noUsersFound')}</p>
          ) : (
            users.map((user) => {
              const checked = selectedIds.includes(user.id)
              return (
                <button
                  key={user.id}
                  type="button"
                  onClick={() => toggleUser(user.id)}
                  className={cn(
                    'flex items-center gap-2.5 rounded-md px-1.5 py-1.5 text-left',
                    'transition-colors duration-150 motion-reduce:transition-none hover:bg-surface-2',
                    checked && 'bg-primary-tint'
                  )}
                >
                  <Avatar name={user.name} size="sm" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-fg">{user.name}</p>
                    <p className="truncate text-xs text-fg-muted">{user.email}</p>
                  </div>
                  <Checkbox checked={checked} readOnly tabIndex={-1} />
                </button>
              )
            })
          )}
        </div>
      </div>
    </Modal>
  )
}
