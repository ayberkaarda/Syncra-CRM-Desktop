// Herkese açık güncel kur ucu tipleri (Faz 14 / İz E — docs/PHASE-INTL.md §2 Karar B,
// GÖREV 1). Backend sözleşmesi `ExchangeRateController::current()` docblock'unda BİREBİR
// tanımlıdır — buradaki alanlar oradan tahmin yürütülmeden alınmıştır.
//
// `/api/settings/exchange-rates` (yönetim ekranı, `settings.manage`) İLE KARIŞTIRILMASIN:
// o ayrı bir yanıt şekli taşır (`data[]` + `meta`) ve bu modülün KAPSAMI DIŞINDADIR.

/** Desteklenen (TRY hariç) bir para birimi satırı. */
export type ExchangeRateCurrentRow = {
  currency: string
  /** 1 birim `currency` kaç TRY — decimal STRING (asla float). Kur hiç girilmemişse `null`. */
  rate: string | null
  /** Kurun yayın tarihi (`Y-m-d`). `rate` `null` iken de `null`. */
  rate_date: string | null
  /** `rate_date` 4 takvim gününden eski mi. `rate` `null` iken daima `false`. */
  is_stale: boolean
  /** `rate_date` kaç gün eski. `rate` `null` iken daima `0`. */
  days_stale: number
}

export type ExchangeRatesCurrentResponse = {
  base_currency: string
  /** Dönen satırlar arasında kuru OLAN en eski `rate_date`; hiçbirinin kuru yoksa `null`. */
  as_of: string | null
  /** En eski satırın (yani `as_of`'un) bayatlığı; `as_of === null` iken `false`. */
  is_stale: boolean
  /** En eski satırın kaç gün eski olduğu; `as_of === null` iken `0`. */
  days_stale: number
  rates: ExchangeRateCurrentRow[]
}
