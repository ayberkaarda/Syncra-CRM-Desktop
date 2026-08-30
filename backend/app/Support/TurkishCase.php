<?php

namespace App\Support;

/**
 * Türkçe metinleri EŞLEŞTİRME (duplicate tespiti, mention arama) amaçlı
 * deterministik/locale-duyarlı biçimde küçük harfe indirger.
 *
 * ---------------------------------------------------------------------------
 * BUG (bu sınıfın var oluş nedeni) — PHASE-AUDIT §4 F6 / H8
 * ---------------------------------------------------------------------------
 * PHP'nin `mb_strtolower()`'ı (ve JS'in `toLowerCase()`'i) locale'den bağımsız
 * standart Unicode katlaması yapar, Türkçe İ/I kuralını UYGULAMAZ:
 *   - `İ` (U+0130, noktalı büyük I) -> `i̇` (U+0069 + U+0307 BİRLEŞEN NOKTA,
 *     İKİ kod noktası) — beklenen tek kod noktalı `i` (U+0069).
 *   - `I` (U+0049, noktasız büyük I) -> `i` — Türkçede beklenen `ı` (U+0131).
 * Sonuç: "İhsan" ile "ihsan" `mb_strtolower` sonrası BİREBİR EŞİT DEĞİL
 * (`i̇hsan` [7 kod noktası] ≠ `ihsan` [5 kod noktası]) — duplicate tespiti ve
 * mention araması sessizce ıskalıyor.
 *
 * ---------------------------------------------------------------------------
 * TASARIM KARARI — NEDEN "DOĞRU" KATLAMA (İ->i, I->ı) DEĞİL, AGRESİF KATLAMA
 * ---------------------------------------------------------------------------
 * Dilbilgisel olarak doğru Türkçe katlama İ->i, I->ı şeklindedir ve PHP intl
 * eklentisiyle (`Transliterator`, ya da `mb_convert_case` + locale) elde
 * edilebilir. Bu, TEK BAŞINA yazım tutarlı girdilerde işe yarar: "İhsan" ->
 * "ihsan" (İ->i) ve "Irmak" -> "ırmak" (I->ı) aslında "ırmak" ile birebir
 * eşleşir — yani doğru katlama, TUTARLI yazılmış Türkçe metinde sorunu çözer.
 *
 * Ama bu sınıfın kullanıldığı yer (isim/firma/kullanıcı adı EŞLEŞTİRMESİ,
 * GÖRÜNTÜLEME değil) gerçek dünyada TUTARSIZ girdiyle karşılaşıyor: CRM
 * verisi elle girilir, CSV import edilir, e-posta imzasından kopyalanır —
 * çoğu zaman İngilizce/ASCII klavyeden. Türkçe'ye özgü `İ` ve `ı` harfleri
 * birçok klavye düzeninde YOK; kullanıcı "İhsan" yazmak isterken ASCII `I`
 * tuşuna basıp "Ihsan" yazabilir, ya da "ırmak" yazmak isterken "irmak"
 * yazabilir. DOĞRU katlamayla bu iki tutarsız yazım BİRBİRİYLE EŞLEŞMEZ:
 *   doğru("Ihsan") = "ıhsan"  (I->ı)   ≠   doğru("İhsan") = "ihsan"  (İ->i)
 * yani asıl çözülmesi gereken durumu (aynı kişi, iki farklı yazım biçimi)
 * doğru katlama ÇÖZMEZ.
 *
 * Bu yüzden İ, I, ı, i DÖRDÜ de tek bir kanonik `i`'ye indirilir (agresif
 * katlama). Bedeli: Türkçede GERÇEKTEN farklı iki harf olan `ı`/`i` birleşir
 * (ör. "sıra" ile "sira" bu fonksiyonla aynı sayılır) — ama bu sınıf yalnız
 * EŞLEŞTİRME için kullanılıyor, hiçbir yerde kullanıcıya GÖSTERİLMİYOR.
 * Bağlam: DuplicateDetector bir UYARI üretir, kaydetmeyi ENGELLEMEZ (Faz 6
 * kararı); mention araması bir ÖNERİ LİSTESİDİR, kullanıcı yanlış olanı
 * seçmez. İki bağlamda da yanlış-pozitif (fazladan/gereksiz bir öneri) ucuz,
 * yanlış-negatif (aynı kişi iki kez kaydedilir / mention hiç bulunamaz)
 * pahalıdır — agresif katlama bu asimetriyle uyumlu.
 *
 * PHP ve JS tarafı (`frontend/src/lib/turkishCase.ts`) AYNI kuralı uygular;
 * aksi halde aynı isim backend'de eşleşip frontend'de eşleşmeyebilir (ya da
 * tersi).
 */
final class TurkishCase
{
    /**
     * Eşleştirme için katlanmış (agresif küçük harf) hâli döner.
     *
     * Adımlar:
     *   1. İ/I/ı harfleri (dotted/dotless büyük-küçük hepsi) doğrudan ASCII
     *      'i' karakterine eşlenir — mb_strtolower'a UĞRAMADAN, çünkü
     *      mb_strtolower('İ') iki kod noktalı `i̇` üretiyor (yukarıdaki bug).
     *   2. Kalan harfler (Ğ/Ü/Ş/Ö/Ç dahil) standart `mb_strtolower` ile
     *      küçültülür — bunlarda dotted/dotless türü bir belirsizlik yok,
     *      `mb_strtolower` zaten doğru sonucu veriyor.
     *   3. `Normalizer::normalize(..., FORM_C)` ile NFC'ye indirilir: girdi
     *      zaten birleşik işaretli (NFD) gelmiş olabilir (örn. dış sistemden
     *      kopyala-yapıştır) — çıktının HER ZAMAN tek kod noktalı karakterler
     *      içermesini garanti eder (`TurkishCaseTest` bunu kilitliyor).
     */
    public static function fold(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        // 1) Türkçe İ/I/ı -> 'i'. Büyük harfler mb_strtolower'dan ÖNCE
        // değiştirilir; sıra tersine çevrilirse İ zaten bozuk (i̇) hâle gelmiş
        // olur ve bir daha düzeltilemez.
        $folded = str_replace(['İ', 'I', 'ı'], 'i', $value);

        // 2) Geri kalan her şey standart Türkçe-duyarsız küçültme.
        $folded = mb_strtolower($folded, 'UTF-8');

        // 3) NFC normalizasyonu — birleşik işaret kalmasın garantisi.
        // `intl` eklentisi bu ortamda açık (docs/PROGRESS.md); yine de
        // savunmacı olarak class_exists ile korunuyor.
        if (class_exists(\Normalizer::class)) {
            $normalizedForm = \Normalizer::normalize($folded, \Normalizer::FORM_C);

            if ($normalizedForm !== false) {
                $folded = $normalizedForm;
            }
        }

        return $folded;
    }
}
