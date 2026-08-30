// Faz 14 / İz F — C3 çift-yönlü "ilişkili kayıtlar" paneli (docs/PHASE-INTL.md §3,
// docs/PHASE-AUDIT.md §5.1 C3 satırı).
//
// Backend sözleşmesi: her `show()` ucu, izinli olduğu modüller için
// `data.related.<grup>` altında `{ total, items }` döner. İzinsiz modül anahtarı
// yanıtta HİÇ YOKTUR (boş dizi bile değil) — bkz. CompanyController/
// ContactController/DealController/LeadController::loadRelatedRecords().
// FE tarafı bu yüzden `RelatedGroupData | undefined` ayrımını korur: `undefined`
// = "bu grubu HİÇ BASMA" (yetkisiz modül sızıntısı olur), boş `{total:0,items:[]}`
// = "grup var ama kayıt yok" (boş durum basılır).

/** Backend'in her ilişkili kayıt için döndürdüğü minimum alan: id. Geri kalanı gruba özgüdür. */
export interface RelatedItem {
  id: number
}

export interface DealRelatedItem extends RelatedItem {
  title: string
  amount: number
  currency: string
  status: string
}

export interface QuoteRelatedItem extends RelatedItem {
  quote_number: string
  title: string
  status: string
  total: number
  currency: string
}

export interface TicketRelatedItem extends RelatedItem {
  ticket_number: string
  subject: string
  status: string
  priority: string
}

export interface ContactRelatedItem extends RelatedItem {
  full_name: string
}

export interface CompanyRelatedItem extends RelatedItem {
  name: string
}

export interface RelatedGroupData<T extends RelatedItem = RelatedItem> {
  total: number
  items: T[]
}
