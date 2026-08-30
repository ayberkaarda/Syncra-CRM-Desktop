// Talep durumu sabitleri — `TicketStatusBadge.tsx`'ten AYRI (bkz. `ticketPriorityMeta.ts`
// başındaki aynı gerekçe, i18n dahil).
//
// `STATUS_TRANSITIONS` — `docs/SLA-DESIGN.md` §4'teki `TicketStatusMachine::TRANSITIONS`
// sabitinin İSTEMCİ TARAFI AYNASI. Backend'de geçiş kararı `PATCH /api/tickets/{id}/status`
// içinde `lockForUpdate` ile verilir (eşzamanlılık) — bu yüzden istemci burada YALNIZCA
// "geçersiz seçeneği KULLANICIYA HİÇ GÖSTERME" amacıyla aynı tabloyu tutar (görev tanımı: "UI
// yalnızca geçerli geçişleri sunmalı"). Sunucu her zaman OTORİTEDİR: eşzamanlı bir istek
// bu tabloyu bayatlatırsa (ör. iki sekme aynı anda durum değiştirirse) sunucu yine de 422
// `INVALID_STATUS_TRANSITION` döner ve `TicketStatusControl` bunu normal hata olarak gösterir —
// bu tablo yalnızca İYİMSER bir ön filtredir, ikinci bir doğruluk kaynağı değildir.
//
// `closed` TERMİNALDİR (boş dizi): §4 gerekçesiyle aynı — kapanmış dönem raporları geriye dönük
// değişmez kalmalı, bu yüzden yeniden açılamaz.
//
// Etiket metinleri `enums:ticket.status.*` anahtarındadır (Faz 14 / İz D) — bkz.
// `ticketPriorityMeta.ts` başındaki `t` parametre geçişi gerekçesi.
import type { TFunction } from 'i18next'
import type { TicketStatus } from '../types'
import type { BadgeProps } from '../../../components/ui'

export const STATUS_TRANSITIONS: Record<TicketStatus, TicketStatus[]> = {
  open: ['in_progress', 'pending', 'resolved'],
  in_progress: ['open', 'pending', 'resolved'],
  pending: ['open', 'in_progress', 'resolved'],
  resolved: ['open', 'closed'],
  closed: [],
}

export const STATUS_VARIANT: Record<TicketStatus, NonNullable<BadgeProps['variant']>> = {
  open: 'neutral',
  pending: 'warning',
  in_progress: 'primary',
  resolved: 'success',
  closed: 'neutral',
}

const STATUS_ORDER: TicketStatus[] = ['open', 'pending', 'in_progress', 'resolved', 'closed']

export function statusLabel(status: TicketStatus, t: TFunction): string {
  return t(`enums:ticket.status.${status}`)
}

export function statusOptions(t: TFunction) {
  return STATUS_ORDER.map((value) => ({ value, label: statusLabel(value, t) }))
}

export function allowedTransitions(from: TicketStatus): TicketStatus[] {
  return STATUS_TRANSITIONS[from]
}
