// Kategori seçenekleri — backend `tickets.category` serbest bir string kolonudur (enum/whitelist
// YOK, bkz. `StoreTicketRequest`/`IndexTicketRequest`: `['sometimes','nullable','string','max:255']`).
// Burada listelenen değerler `DemoDataSeeder::seedTickets()`teki gerçek demo verisiyle BİREBİR
// aynıdır — form ve liste filtresi tutarlı bir sözlük sunsun diye curated bir set olarak
// tutulur; sunucu bunun dışında bir değeri REDDETMEZ, bu yüzden serbest metin girişini
// KISITLAMAZ (bkz. `TicketFormModal`'daki `Select` + "Diğer" davranışı).
//
// FAZ 14 TAKİP DÜZELTMESİ: bu liste BİZİM seed ettiğimiz bir taksonomidir (müşteri verisi
// DEĞİL) — dolayısıyla ETİKETİ çevrilir. `value` (backend'in `tickets.category` kolonuna
// AYNEN yazdığı Türkçe string) DEĞİŞMEZ — yalnızca `label` `t()` ile çözülür, tıpkı
// `ticketStatusMeta.ts` / `ticketPriorityMeta.ts` deseninde olduğu gibi. Serbest metin girişi
// (sözlükte olmayan bir kategori) `defaultValue` sayesinde ham haliyle basılır, ÇÖKMEZ.
//
// MODÜL SEVİYESİNDE `t()` SONUCU SAKLANMAZ (dil değişiminde donar) — `ticketCategoryLabel` /
// `ticketCategoryOptions` her çağrıda `t`'yi parametre olarak alır.
import type { TFunction } from 'i18next'

export const TICKET_CATEGORIES = ['Teknik Destek', 'Faturalandırma', 'Ürün Bilgisi', 'Şikayet', 'Kurulum', 'Eğitim Talebi'] as const

type TicketCategory = (typeof TICKET_CATEGORIES)[number]

/** `enums` namespace anahtarı (önek `ticketCategory.*`). */
const CATEGORY_LABEL_KEY: Record<TicketCategory, string> = {
  'Teknik Destek': 'ticketCategory.technical_support',
  Faturalandırma: 'ticketCategory.billing',
  'Ürün Bilgisi': 'ticketCategory.product_info',
  Şikayet: 'ticketCategory.complaint',
  Kurulum: 'ticketCategory.installation',
  'Eğitim Talebi': 'ticketCategory.training_request',
}

/**
 * Ham kategori değerini (`tickets.category`) aktif dile çevrilmiş etikete çözer.
 *
 * Curated listenin dışındaki serbest metin (`Select` + "Diğer" davranışı) için sözlükte
 * karşılık ARANMAZ — ham değerin kendisi basılır (bu bir hata durumu değil, tasarımın parçası).
 */
export function ticketCategoryLabel(value: string, t: TFunction): string {
  const key = CATEGORY_LABEL_KEY[value as TicketCategory]
  if (!key) return value
  return t(`enums:${key}`, { defaultValue: value })
}

export function ticketCategoryOptions(t: TFunction): { value: string; label: string }[] {
  return TICKET_CATEGORIES.map((value) => ({ value, label: ticketCategoryLabel(value, t) }))
}
