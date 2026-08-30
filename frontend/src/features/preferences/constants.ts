// Desteklenen görüntüleme para birimleri — `backend/config/exchange.php` ile BİREBİR aynı küme
// (Faz 14 / İz E — docs/PHASE-INTL.md §2.2). TRY temel, USD/EUR/GBP dördü de TCMB `today.xml`de
// `Unit=1`. Kodlar ÇEVRİLMEZ (ISO 4217, evrensel) — yalnızca açıklayıcı adları
// `CurrencySwitcher`de dile göre çevrilir (bkz. `locales/*/common.json` → `currency.names`).
export const SUPPORTED_CURRENCIES = ['TRY', 'USD', 'EUR', 'GBP'] as const

export type SupportedCurrency = (typeof SUPPORTED_CURRENCIES)[number]

export function isSupportedCurrency(value: unknown): value is SupportedCurrency {
  return typeof value === 'string' && (SUPPORTED_CURRENCIES as readonly string[]).includes(value)
}
