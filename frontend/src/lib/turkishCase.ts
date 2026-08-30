// Türkçe metinleri EŞLEŞTİRME (chat @mention arama filtresi) amaçlı agresif
// biçimde küçük harfe indirger. Backend karşılığı: `backend/app/Support/TurkishCase.php`
// — TAM AYNI kural burada da uygulanmalı, aksi halde aynı isim backend'de
// (DuplicateDetector) eşleşip frontend'de (mention araması) eşleşmeyebilir.
//
// BUG (bu yardımcının var oluş nedeni) — PHASE-AUDIT §4 F6 / H8:
// JS'in `toLowerCase()`'i (ve `toLocaleLowerCase()` default locale'i) Türkçe
// İ/I kuralını uygulamaz. `toLocaleLowerCase('tr')` DOĞRU Türkçe kuralını
// uygular (İ->i, I->ı) ama bu "doğru" kural, TUTARSIZ girilmiş veride (ör.
// İngilizce/ASCII klavyeden "İhsan" yerine "Ihsan" yazılması — Türkçe'ye özgü
// İ/ı harfleri çoğu klavye düzeninde yok) aynı kişinin iki farklı yazımını
// birbirine EŞLEMEZ: doğru("Ihsan")="ıhsan" ile doğru("İhsan")="ihsan" birebir
// aynı DEĞİL. Bu yüzden backend ile birebir aynı AGRESİF karar uygulanıyor:
// İ, I, ı, i dördü de tek bir 'i'ye indirilir. Bedeli Türkçede gerçekten
// farklı iki harf olan ı/i'nin birleşmesi ("sıra" ile "sira" aynı sayılır) —
// ama bu yalnız bir mention ARAMA filtresidir (görüntüleme değil), yanlış-
// pozitif (öneri listesinde fazladan bir isim) yanlış-negatiften (aranan
// kullanıcı hiç bulunamaz) çok daha ucuzdur. Ayrıntılı gerekçe için
// backend/app/Support/TurkishCase.php dosyasının başındaki yorum.
//
// `.normalize('NFC')` çağrısı, girdi zaten birleşik işaretli (NFD) gelmişse
// (örn. dış sistemden kopyala-yapıştır) çıktının tek kod noktalı karakterler
// içermesini garanti eder — backend tarafındaki `Normalizer::FORM_C` adımının
// JS karşılığı.
export function foldTurkish(value: string): string {
  return value
    .replace(/[İIı]/g, 'i')
    .toLowerCase()
    .normalize('NFC')
}
