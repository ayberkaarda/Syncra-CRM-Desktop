// Para/tarih biçimlendirme yardımcıları — `features/products/` VE `features/price-lists/`
// tarafından paylaşılır (ikisi de bu şeridin dosya sahipliğinde, bkz. görev tanımı).
//
// Para biçimlendirme artık merkezi `src/lib/money.ts`e devredilmiştir (bkz. o dosyanın
// docblock'u — projedeki 7 ayrı `Intl.NumberFormat` kopyası tek yerde toplandı). Bu dosya,
// `price-lists/` içindeki mevcut importları kırmamak için `formatCurrency` adını `formatMoney`
// olarak yeniden dışa aktarır; kuruş burada GÖSTERİLİR (2 ondalık) — birim fiyatlar ve liste
// fiyatları çoğu zaman küsuratlıdır (ör. 149,90), yuvarlamak kullanıcıyı yanıltır.
//
// Tarih biçimlendirme de aynı gerekçeyle (Faz 14 / İz D §1.8) merkezi `src/lib/datetime.ts`e
// devredilmiştir — burada sabit `'tr-TR'` yerine aktif arayüz diline göre basılır. İmza/davranış
// (`string | null | undefined` girdi, boşta `'—'`) mevcut çağrı yerlerini kırmadan korunur;
// `date-only` girdi (`YYYY-MM-DD`) merkezi `formatDate` de aynı `T00:00:00` yerelleştirmesini
// zaten uygular (bkz. o dosyanın `toDate` yardımcısı).
export { formatMoney as formatCurrency } from '../../../lib/money'
export { formatDate, formatDateTime } from '../../../lib/datetime'
