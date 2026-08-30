// İlgili kayıt (taskable/activityable) türü -> ikon + bağlantı yolu + görünen ad eşleyicisi.
// Hem `TasksPage`/`TaskFormModal` hem de `activities` modülü (aynı `TaskableType` şeklini
// kullanır) BU dosyadan import eder — iki modülde aynı eşleme iki kez yazılmasın diye.
//
// `ticket` artık BEŞ tipin hepsi gibi seçilebilir: `GET /api/tickets?q=` ucu D şeridi
// tarafından tamamlandı (bkz. `RelatedRecordPicker`'ın ticket arama dalı). `tickets.view`
// izni olmayan bir kullanıcı için seçeneği GİZLEME kararı bu sabit listede DEĞİL, kullanım
// yerinde (`RelatedRecordPicker`, 403 tespitiyle) verilir — bu dosya salt statik bir eşleme,
// izin durumundan haberi yok.
//
// Etiket metinleri `tasks:relatedType.*` anahtarındadır (Faz 14 / İz D). Bu dosya bir React
// component'i DEĞİL — çağıran component kendi `t` fonksiyonunu parametre olarak geçirir.
import { Building2, Handshake, Ticket, User, UserPlus } from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import type { TFunction } from 'i18next'
import type { TaskableType } from '../types'

export const RELATED_RECORD_SELECTABLE_TYPES: TaskableType[] = ['deal', 'lead', 'contact', 'company', 'ticket']

type RelatedRecordMeta = {
  label: string
  icon: ComponentType<SVGProps<SVGSVGElement>>
  path: (id: number) => string
}

const RELATED_RECORD_ICON: Record<TaskableType, ComponentType<SVGProps<SVGSVGElement>>> = {
  deal: Handshake,
  lead: UserPlus,
  contact: User,
  company: Building2,
  ticket: Ticket,
}

const RELATED_RECORD_PATH: Record<TaskableType, (id: number) => string> = {
  deal: (id) => `/deals/${id}`,
  lead: (id) => `/leads/${id}`,
  contact: (id) => `/contacts/${id}`,
  company: (id) => `/companies/${id}`,
  ticket: (id) => `/tickets/${id}`,
}

export function relatedRecordTypeLabel(type: TaskableType, t: TFunction): string {
  return t(`tasks:relatedType.${type}`)
}

export function relatedRecordMeta(type: TaskableType, t: TFunction): RelatedRecordMeta {
  return { label: relatedRecordTypeLabel(type, t), icon: RELATED_RECORD_ICON[type], path: RELATED_RECORD_PATH[type] }
}
