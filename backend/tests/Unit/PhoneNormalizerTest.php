<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Duplicate tespitinin tamamı bu tek fonksiyonun kararlılığına dayanıyor:
 * normalizasyon kayarsa "aynı numara" tanımı kayar ve motor ya duplicate
 * kaçırır ya da alakasız kayıtları duplicate diye gösterir. Bu yüzden burada
 * pinlenen şey davranış değil SÖZLEŞMEdir.
 */
class PhoneNormalizerTest extends TestCase
{
    /**
     * Türkiye'de aynı numaranın günlük hayatta yazıldığı dört biçim.
     * Dördü de TEK bir karşılaştırma anahtarına düşmek zorunda.
     */
    public function test_turkish_formats_collapse_to_the_same_key(): void
    {
        $expected = '5321112233';

        $this->assertSame($expected, PhoneNormalizer::normalize('+90 532 111 22 33'));
        $this->assertSame($expected, PhoneNormalizer::normalize('0532 111 22 33'));
        $this->assertSame($expected, PhoneNormalizer::normalize('05321112233'));
        $this->assertSame($expected, PhoneNormalizer::normalize('532 111 22 33'));
    }

    /**
     * Ayraçlar (parantez, tire, nokta) ve ülke kodu atılır — "son 10 hane"
     * kuralı uluslararası numaralarda da mantıklı bir anahtar üretir.
     */
    public function test_international_number_keeps_its_last_ten_digits(): void
    {
        $this->assertSame('5551234567', PhoneNormalizer::normalize('+1 (555) 123-4567'));
        $this->assertSame('5551234567', PhoneNormalizer::normalize('001.555.123.4567'));
    }

    public function test_null_and_empty_input_produce_null(): void
    {
        $this->assertNull(PhoneNormalizer::normalize(null));
        $this->assertNull(PhoneNormalizer::normalize(''));
        $this->assertNull(PhoneNormalizer::normalize('   '));
    }

    /**
     * Rakam içermeyen girdi eşleştirmede kullanılamaz; boş string yerine null
     * döner ki çağıran "telefon kuralını hiç çalıştırma" diyebilsin. Boş string
     * dönseydi `LIKE '%%'` deseni üretilir ve TÜM tablo aday olurdu.
     */
    public function test_input_without_digits_produces_null(): void
    {
        $this->assertNull(PhoneNormalizer::normalize('abc'));
        $this->assertNull(PhoneNormalizer::normalize('---'));
        $this->assertNull(PhoneNormalizer::normalize('bilinmiyor'));
    }

    /**
     * 10 haneden kısa girdi KIRPILMAZ: `444` ile `1444` farklı numaralardır,
     * kısaltmak ikisini eşitlerdi.
     */
    public function test_short_numbers_are_returned_as_digits_without_truncation(): void
    {
        $this->assertSame('4441234', PhoneNormalizer::normalize('444 12 34'));
        $this->assertSame('112', PhoneNormalizer::normalize('112'));
        $this->assertSame('7', PhoneNormalizer::normalize('7'));
    }

    /**
     * Tam 10 hane sınır durumu — kırpma ne bir hane fazla ne bir hane eksik.
     */
    public function test_exactly_ten_digits_pass_through_unchanged(): void
    {
        $this->assertSame('5321112233', PhoneNormalizer::normalize('5321112233'));
        $this->assertSame(
            PhoneNormalizer::SIGNIFICANT_DIGITS,
            strlen((string) PhoneNormalizer::normalize('5321112233'))
        );
    }

    /**
     * Farklı numaralar farklı anahtarlara düşmeli — normalizasyon "her şeyi
     * eşitleyerek" testi geçmiyor.
     */
    public function test_different_numbers_do_not_collide(): void
    {
        $this->assertNotSame(
            PhoneNormalizer::normalize('0532 111 22 33'),
            PhoneNormalizer::normalize('0532 111 22 34')
        );
    }
}
