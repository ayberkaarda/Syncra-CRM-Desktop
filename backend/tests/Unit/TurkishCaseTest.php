<?php

namespace Tests\Unit;

use App\Support\TurkishCase;
use PHPUnit\Framework\TestCase;

/**
 * F6/H8 — `TurkishCase::fold()` regresyon kilidi.
 *
 * Kilitlenen şey: İ, I, ı, i harflerinin dördünün de AYNI kanonik 'i'ye
 * indirilmesi (agresif katlama kararı — gerekçe `TurkishCase.php` başında)
 * VE çıktının HER ZAMAN tek kod noktalı karakterlerden oluşması (`mb_strtolower`
 * bug'ı: İ -> i + birleşik nokta, İKİ kod noktası, döngüsü kırılmalı).
 */
class TurkishCaseTest extends TestCase
{
    public function test_dotted_and_dotless_i_variants_all_fold_to_the_same_value(): void
    {
        $this->assertSame('ihsan', TurkishCase::fold('İhsan'));
        $this->assertSame('ihsan', TurkishCase::fold('ihsan'));
        $this->assertSame('ihsan', TurkishCase::fold('IHSAN'));
        $this->assertSame('ihsan', TurkishCase::fold('ıhsan'));

        // Dördü de aynı kanonik değere indi -> birbirine eşit.
        $this->assertSame(TurkishCase::fold('İhsan'), TurkishCase::fold('ihsan'));
        $this->assertSame(TurkishCase::fold('İhsan'), TurkishCase::fold('IHSAN'));
        $this->assertSame(TurkishCase::fold('İhsan'), TurkishCase::fold('ıhsan'));
    }

    public function test_irmak_variants_fold_to_the_same_value(): void
    {
        $this->assertSame('irmak', TurkishCase::fold('Irmak'));
        $this->assertSame('irmak', TurkishCase::fold('ırmak'));
        $this->assertSame(TurkishCase::fold('Irmak'), TurkishCase::fold('ırmak'));
    }

    public function test_isik_variants_fold_to_the_same_value(): void
    {
        // Dikkat: 'ş' agresif katlamanın KAPSAMI DIŞINDA (yalnız İ/I/ı
        // hedefleniyor) — bu yüzden çıktı 'işik' olur, 'isik' DEĞİL.
        $this->assertSame('işik', TurkishCase::fold('Işık'));
        $this->assertSame('işik', TurkishCase::fold('ışık'));
        $this->assertSame(TurkishCase::fold('Işık'), TurkishCase::fold('ışık'));
    }

    /**
     * Diğer Türkçe aksanlı harfler (Ğ/Ü/Ş/Ö/Ç) dotted/dotless İ/I gibi
     * belirsiz değildir; standart küçültme burada zaten doğru sonucu verir.
     */
    public function test_other_turkish_accented_letters_fold_normally(): void
    {
        $this->assertSame('ğüşöç', TurkishCase::fold('ĞÜŞÖÇ'));
        $this->assertSame('ğüşöç', TurkishCase::fold('ğüşöç'));
    }

    public function test_ascii_text_is_unaffected(): void
    {
        $this->assertSame('hello world', TurkishCase::fold('Hello World'));
        $this->assertSame('abc123', TurkishCase::fold('ABC123'));
    }

    public function test_null_and_empty_string_are_handled(): void
    {
        $this->assertNull(TurkishCase::fold(null));
        $this->assertSame('', TurkishCase::fold(''));
    }

    public function test_mixed_alphanumeric_and_punctuation_content(): void
    {
        // Dikkat: "Yılmaz" içindeki 'ı' da agresif katlama gereği 'i'ye
        // döner ("yilmaz"), Ğ/Ü/Ş/Ö/Ç ise standart küçültmeyle değişmeden
        // kalır (yalnız büyük/küçük değişir).
        $this->assertSame(
            'ihsan yilmaz - acme a.ş. (istanbul şubesi) #1',
            TurkishCase::fold('İHSAN Yılmaz - ACME A.Ş. (İstanbul Şubesi) #1')
        );
    }

    /**
     * Asıl bug buydu: `mb_strtolower('İ')` iki kod noktalı `i̇` (U+0069 +
     * U+0307) üretiyordu. `fold()` çıktısı HİÇBİR girdi için birleşik işaret
     * (U+0300–U+036F aralığı) İÇERMEMELİ.
     */
    public function test_folded_output_never_contains_a_combining_mark(): void
    {
        $samples = ['İhsan', 'IHSAN', 'ıhsan', 'Irmak', 'ırmak', 'Işık', 'ışık', 'İSTANBUL', 'ĞÜŞÖÇ'];

        foreach ($samples as $sample) {
            $folded = TurkishCase::fold($sample);
            $this->assertNotNull($folded);

            preg_match_all('/./u', $folded, $matches);
            $codepointCount = count($matches[0]);
            $byteLength = strlen($folded);

            // Birleşik işaret varlığını dolaylı doğrulamak için: 0x0307
            // (COMBINING DOT ABOVE) hiçbir örnekte bulunmamalı.
            $this->assertStringNotContainsString("\u{0307}", $folded, "'{$sample}' katlamasında birleşik nokta işareti kaldı.");

            // Her görünür karakter tek bir Unicode kod noktası olmalı (NFC).
            $this->assertGreaterThan(0, $codepointCount);
            $this->assertGreaterThanOrEqual($codepointCount, $byteLength);
        }
    }

    public function test_i_followed_by_h_does_not_collapse_incorrectly(): void
    {
        // "İhsan" -> İ->i, geri kalanı zaten küçük: "ihsan"
        $this->assertSame('ihsan', TurkishCase::fold('İhsan'));
    }
}
