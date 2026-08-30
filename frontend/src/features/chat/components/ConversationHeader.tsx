// Sağ üst konuşma başlığı — `display_name`, üye sayısı/üye listesi, sessize alma, grup adını
// değiştirme, üye ekleme/çıkarma, gruptan ayrılma, grubu silme.
//
// YETKİ KURALLARI (görev tanımında AÇIKÇA belirtildi):
//  - Üye çıkarma ve grubu silme: yalnızca `conversation.created_by === currentUser.id`.
//  - Üye ekleme: her üyede görünür.
//  - DM'de bu kontrollerin HİÇBİRİ yok (grup yönetimi anlamsız — sabit 2 üyeli).
// Sessize alma her konuşma tipinde geçerlidir (DM dahil).
//
// `useConversationMembers(conversationId)` → `{ addMembers(userIds), removeMember(userId),
// leave(), rename(name), remove(), toggleMute(isMuted?) }` (bkz.
// `hooks/useConversationMutations.ts` → `UseConversationMembersResult`). Bu mutasyonlar BİLEREK
// iyimser DEĞİLDİR (yetki hataları 403 dönebilir) — sunucu yanıtı beklenip önbellek onunla
// yazılır.
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Bell, BellOff, LogOut, MoreVertical, Pencil, Search, Trash2, UserPlus, X } from 'lucide-react'
import { Avatar, Button, Checkbox, Input, Modal, Skeleton } from '../../../components/ui'
import { cn } from '../../../lib/cn'
import { useAuthStore } from '../../auth/store'
import { useUsers } from '../../users/api/usersApi'
import { useConversationMembers } from '../hooks/useConversationMutations'
import { useDismiss } from './chatShared'
import { MessageSearch } from './MessageSearch'
import type { Conversation } from '../types'

export type ConversationHeaderProps = {
  conversation: Conversation
  /** Mobilde (<lg) konuşma listesine dönüş oku — verilirse başlığın solunda `lg:hidden` gösterilir. */
  onBack?: () => void
}

export function ConversationHeader({ conversation, onBack }: ConversationHeaderProps) {
  const navigate = useNavigate()
  const { t } = useTranslation(['chat', 'common'])
  const currentUserId = useAuthStore((state) => state.user?.id)
  const members = useConversationMembers(conversation.id)

  const isGroup = conversation.type === 'group'
  const isOwner = conversation.created_by !== null && conversation.created_by === currentUserId

  const [menuOpen, setMenuOpen] = useState(false)
  const menuRef = useDismiss<HTMLDivElement>(menuOpen, () => setMenuOpen(false))

  const [membersOpen, setMembersOpen] = useState(false)
  const membersRef = useDismiss<HTMLDivElement>(membersOpen, () => setMembersOpen(false))

  const [renameOpen, setRenameOpen] = useState(false)
  const [renameValue, setRenameValue] = useState(conversation.name ?? '')
  const [addMembersOpen, setAddMembersOpen] = useState(false)
  // Her açılışta artar (kapanışta AYNI kalır) — `AddMembersModal`e `key` olarak verilir, böylece
  // arama/seçim alanları modalın kendi içindeki bir sıfırlama efekti yerine YENİDEN MOUNT ile
  // temizlenir. Yalnızca açılışta artmasının nedeni: kapanışta değişseydi `Modal`in 150ms'lik
  // kapanış geçişi (bkz. `components/ui/Modal.tsx`) oynamadan bileşen sıfırdan mount edilirdi.
  const [addMembersKey, setAddMembersKey] = useState(0)
  const [confirmLeaveOpen, setConfirmLeaveOpen] = useState(false)
  const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false)

  function handleRenameOpen() {
    setRenameValue(conversation.name ?? '')
    setRenameOpen(true)
    setMenuOpen(false)
  }

  function handleRenameSubmit() {
    const trimmed = renameValue.trim()
    if (!trimmed) return
    members.rename(trimmed)
    setRenameOpen(false)
  }

  function handleRemoveMember(userId: number) {
    if (!window.confirm(t('chat:confirm.removeMember'))) return
    members.removeMember(userId)
  }

  function handleLeave() {
    members.leave()
    setConfirmLeaveOpen(false)
    navigate('/chat')
  }

  function handleDeleteGroup() {
    members.remove()
    setConfirmDeleteOpen(false)
    navigate('/chat')
  }

  return (
    <div className="relative flex items-center justify-between gap-3 border-b border-border-subtle px-2 py-3 lg:px-4">
      <div className="flex min-w-0 items-center gap-1">
        {onBack && (
          <button
            type="button"
            onClick={onBack}
            aria-label={t('chat:header.backAria')}
            className="inline-flex size-9 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg lg:hidden"
          >
            <ArrowLeft className="size-4" aria-hidden="true" />
          </button>
        )}
        <button
          type="button"
          onClick={() => isGroup && setMembersOpen((prev) => !prev)}
          disabled={!isGroup}
          aria-haspopup={isGroup ? 'dialog' : undefined}
          aria-expanded={isGroup ? membersOpen : undefined}
          className={cn('flex min-w-0 items-center gap-3 text-left', isGroup && 'cursor-pointer')}
        >
          <Avatar name={conversation.display_name} size="md" />
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-fg">{conversation.display_name}</p>
            {isGroup && (
              <p className="truncate text-xs text-fg-muted">
                {t('chat:header.membersCount', { count: conversation.members.length })}
              </p>
            )}
          </div>
        </button>
      </div>

      <div className="flex shrink-0 items-center gap-1">
        <MessageSearch conversationId={conversation.id} onSelectResult={(id) => navigate(`/chat/${id}`)} />

        <button
          type="button"
          onClick={() => members.toggleMute()}
          aria-label={conversation.is_muted ? t('chat:header.unmuteAria') : t('chat:header.muteAria')}
          aria-pressed={conversation.is_muted}
          className={cn(
            'inline-flex size-9 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
            'transition-colors duration-150 motion-reduce:transition-none',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
          )}
        >
          {conversation.is_muted ? (
            <BellOff className="size-4" aria-hidden="true" />
          ) : (
            <Bell className="size-4" aria-hidden="true" />
          )}
        </button>

        {isGroup && (
          <div ref={menuRef} className="relative">
            <button
              type="button"
              onClick={() => setMenuOpen((prev) => !prev)}
              aria-haspopup="menu"
              aria-expanded={menuOpen}
              aria-label={t('chat:header.optionsAria')}
              className={cn(
                'inline-flex size-9 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg',
                'transition-colors duration-150 motion-reduce:transition-none',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1'
              )}
            >
              <MoreVertical className="size-4" aria-hidden="true" />
            </button>
            {menuOpen && (
              <div
                role="menu"
                aria-label={t('chat:header.optionsAria')}
                className="absolute right-0 top-full z-20 mt-2 w-52 rounded-lg border border-border bg-surface-3 py-1 shadow-popover"
              >
                <button
                  role="menuitem"
                  type="button"
                  onClick={() => {
                    setAddMembersKey((key) => key + 1)
                    setAddMembersOpen(true)
                    setMenuOpen(false)
                  }}
                  className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-fg hover:bg-surface-2"
                >
                  <UserPlus className="size-4" aria-hidden="true" />
                  {t('chat:header.addMember')}
                </button>
                <button
                  role="menuitem"
                  type="button"
                  onClick={handleRenameOpen}
                  className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-fg hover:bg-surface-2"
                >
                  <Pencil className="size-4" aria-hidden="true" />
                  {t('chat:header.renameGroup')}
                </button>
                <button
                  role="menuitem"
                  type="button"
                  onClick={() => {
                    setConfirmLeaveOpen(true)
                    setMenuOpen(false)
                  }}
                  className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-fg hover:bg-surface-2"
                >
                  <LogOut className="size-4" aria-hidden="true" />
                  {t('chat:header.leaveGroup')}
                </button>
                {isOwner && (
                  <button
                    role="menuitem"
                    type="button"
                    onClick={() => {
                      setConfirmDeleteOpen(true)
                      setMenuOpen(false)
                    }}
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-danger hover:bg-danger-tint"
                  >
                    <Trash2 className="size-4" aria-hidden="true" />
                    {t('chat:header.deleteGroup')}
                  </button>
                )}
              </div>
            )}
          </div>
        )}
      </div>

      {isGroup && membersOpen && (
        <div
          ref={membersRef}
          role="dialog"
          aria-label={t('chat:header.membersDialogAria')}
          className="absolute left-4 top-full z-20 mt-1 w-64 rounded-lg border border-border bg-surface-3 py-2 shadow-popover"
        >
          <p className="px-3 pb-1 text-xs font-medium text-fg-muted">
            {t('chat:header.membersCount', { count: conversation.members.length })}
          </p>
          <ul className="max-h-64 overflow-y-auto">
            {conversation.members.map((member) => (
              <li key={member.id} className="flex items-center gap-2 px-3 py-1.5">
                <Avatar name={member.name} size="xs" />
                <span className="min-w-0 flex-1 truncate text-sm text-fg">{member.name}</span>
                {isOwner && member.id !== currentUserId && (
                  <button
                    type="button"
                    onClick={() => handleRemoveMember(member.id)}
                    aria-label={t('chat:header.removeMemberAria', { name: member.name })}
                    className="shrink-0 rounded p-1 text-fg-muted hover:bg-danger-tint hover:text-danger"
                  >
                    <X className="size-3.5" aria-hidden="true" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      <Modal
        open={renameOpen}
        onClose={() => setRenameOpen(false)}
        title={t('chat:header.renameGroup')}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setRenameOpen(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button type="button" onClick={handleRenameSubmit}>
              {t('common:actions.save')}
            </Button>
          </div>
        }
      >
        <Input
          label={t('chat:common.groupNameLabel')}
          value={renameValue}
          onChange={(event) => setRenameValue(event.target.value)}
        />
      </Modal>

      <AddMembersModal
        key={addMembersKey}
        open={addMembersOpen}
        onClose={() => setAddMembersOpen(false)}
        existingMemberIds={conversation.members.map((member) => member.id)}
        onAdd={(ids) => members.addMembers(ids)}
      />

      <Modal
        open={confirmLeaveOpen}
        onClose={() => setConfirmLeaveOpen(false)}
        title={t('chat:header.leaveGroup')}
        description={t('chat:header.leaveConfirmDescription')}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setConfirmLeaveOpen(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button type="button" variant="danger" onClick={handleLeave}>
              {t('chat:header.leaveConfirmButton')}
            </Button>
          </div>
        }
      >
        <div />
      </Modal>

      <Modal
        open={confirmDeleteOpen}
        onClose={() => setConfirmDeleteOpen(false)}
        title={t('chat:header.deleteGroup')}
        description={t('chat:header.deleteConfirmDescription')}
        size="sm"
        footer={
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={() => setConfirmDeleteOpen(false)}>
              {t('common:actions.cancel')}
            </Button>
            <Button type="button" variant="danger" onClick={handleDeleteGroup}>
              {t('common:actions.delete')}
            </Button>
          </div>
        }
      >
        <div />
      </Modal>
    </div>
  )
}

type AddMembersModalProps = {
  open: boolean
  onClose: () => void
  existingMemberIds: number[]
  onAdd: (ids: number[]) => void
}

// (2) numaralı çözümle AYNI yaklaşım: `open`i izleyip alanları sıfırlayan bir efekt yerine,
// çağıran taraf her açılışta `key`i artırarak bu bileşeni yeniden mount eder (bkz. yukarısı
// `addMembersKey` ve kullanım yeri) — `useState` başlangıç değerleri zaten temiz gelir.
function AddMembersModal({ open, onClose, existingMemberIds, onAdd }: AddMembersModalProps) {
  const { t } = useTranslation(['chat', 'common'])
  const currentUserId = useAuthStore((state) => state.user?.id)
  const [search, setSearch] = useState('')
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const { data, isLoading } = useUsers({ q: search || undefined, per_page: 20 })
  const excluded = new Set<number | undefined>([...existingMemberIds, currentUserId])
  const users = (data?.data ?? []).filter((user) => !excluded.has(user.id))

  function toggle(userId: number) {
    setSelectedIds((ids) => (ids.includes(userId) ? ids.filter((id) => id !== userId) : [...ids, userId]))
  }

  function handleSubmit() {
    if (selectedIds.length === 0) return
    onAdd(selectedIds)
    onClose()
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('chat:header.addMember')}
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="button" disabled={selectedIds.length === 0} onClick={handleSubmit}>
            {t('common:actions.add')}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-3">
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
            Array.from({ length: 3 }).map((_, index) => <Skeleton key={index} variant="text" />)
          ) : users.length === 0 ? (
            <p className="px-1 py-4 text-center text-sm text-fg-muted">{t('chat:header.noUsersToAdd')}</p>
          ) : (
            users.map((user) => {
              const checked = selectedIds.includes(user.id)
              return (
                <button
                  key={user.id}
                  type="button"
                  onClick={() => toggle(user.id)}
                  className={cn(
                    'flex items-center gap-2.5 rounded-md px-1.5 py-1.5 text-left',
                    'transition-colors duration-150 motion-reduce:transition-none hover:bg-surface-2',
                    checked && 'bg-primary-tint'
                  )}
                >
                  <Avatar name={user.name} size="sm" />
                  <span className="min-w-0 flex-1 truncate text-sm text-fg">{user.name}</span>
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
