// `datetime-local` <-> ISO8601 dönüştürücüleri. `activities/components/ActivityFormModal.tsx`
// da BUNU kullanır (aynı gerekçeyle `RelatedRecordPicker` `features/tasks/components/` altında
// tutuluyor: tarih alanı şekli iki modülde de aynı).
//
// `new Date(local)` TARAYICI YEREL saatine göre ayrıştırır, `toISOString()` UTC mutlak ana
// çevirir — bu round-trip sunucunun app timezone'undan BAĞIMSIZ olarak doğru sonucu verir
// (Carbon 'Z' sonekli bir ISO8601 dizisini her zaman doğru mutlak ana çözer).
export function isoToLocalInput(iso: string | null | undefined): string {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export function localInputToIso(local: string): string | null {
  if (!local) return null
  const date = new Date(local)
  if (Number.isNaN(date.getTime())) return null
  return date.toISOString()
}

/** Şu anki anın `datetime-local` değeri — `max` sınırı (aktivite `occurred_at`) için. */
export function nowLocalInput(): string {
  return isoToLocalInput(new Date().toISOString())
}
