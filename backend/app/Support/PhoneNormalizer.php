<?php

namespace App\Support;

/**
 * Telefon numaralarını karşılaştırılabilir tek bir biçime indirger.
 *
 * ---------------------------------------------------------------------------
 * NEDEN VAR
 * ---------------------------------------------------------------------------
 * `+90 532 111 22 33`, `0532 111 22 33` ve `05321112233` aynı numaradır ama üç
 * farklı stringtir. Duplicate tespiti bunları ham string eşitliğiyle
 * karşılaştıramaz; tabloda ham (kullanıcının yazdığı) değer durduğu için
 * karşılaştırmadan hemen önce normalize edilmesi gerekir.
 *
 * ---------------------------------------------------------------------------
 * KURAL
 * ---------------------------------------------------------------------------
 * Rakam dışı her karakter atılır, kalan rakam dizisinin SON 10 HANESİ döner.
 * "Baştaki 90 / 0 ülke-alan kodunu soy" kuralı bunun içinde erir:
 *
 *   +90 532 111 22 33 -> 905321112233 -> 5321112233
 *   0532 111 22 33    -> 05321112233  -> 5321112233
 *   05321112233       -> 05321112233  -> 5321112233
 *   532 111 22 33     -> 5321112233   -> 5321112233
 *   +1 (555) 123-4567 -> 15551234567  -> 5551234567
 *
 * 10 haneden kısa girdiler KIRPILMAZ, rakamlaştırılmış hâliyle döner — kısa
 * numarayı daha da kısaltmak bilgi kaybıdır ve `555` ile `4555` gibi farklı
 * numaraları eşitleyebilirdi.
 *
 * ---------------------------------------------------------------------------
 * SINIRLARI (bilinçli kabul edilen yanlış eşleşmeler)
 * ---------------------------------------------------------------------------
 * 1. DAHİLİ NUMARALAR. `0212 555 44 33 dahili 12` -> `02125554433 12` ->
 *    `2555443312`. Dahili, abone numarasının sonuna yapışır ve numarayı
 *    tanınmaz hâle getirir. Bu, ayrı bir `extension` alanı olmadan çözülemez;
 *    şu an şemada yok.
 * 2. ÜLKE KODU GÖRMEZDEN GELİNİR. Son 10 hane kuralı ülke kodunu attığı için
 *    farklı ülkelerdeki iki numara teorik olarak aynı normalize değere
 *    düşebilir. Pratikte yanlış-pozitif olasılığı ihmal edilebilir ve
 *    duplicate tespiti zaten "aday gösterme" işi — nihai kararı insan verir.
 * 3. 10 HANEDEN UZUN ABONE NUMARASI OLAN ÜLKELER. Çoğu ülkede ulusal abone
 *    numarası <= 10 hanedir (TR 10, US 10, DE genellikle <= 11); 11 haneli
 *    ulusal numaralarda baştaki hane kesilir ve iki farklı numara aynı
 *    normalize değere düşebilir. Ürün Türkiye odaklı olduğu için kabul edildi.
 * 4. Bu sınıf numaranın GEÇERLİ olup olmadığını doğrulamaz — doğrulama
 *    FormRequest'in işi. Burada yalnızca karşılaştırma anahtarı üretilir.
 */
final class PhoneNormalizer
{
    /**
     * Karşılaştırmada anlamlı kabul edilen hane sayısı.
     */
    public const SIGNIFICANT_DIGITS = 10;

    /**
     * Telefonu karşılaştırma anahtarına çevirir.
     *
     * Null, boş string veya hiç rakam içermeyen girdi (`abc`) için null döner —
     * "normalize edilemedi" ile "boş numara" arasında ayrım yapılmaz, çünkü
     * ikisi de eşleştirmede kullanılamaz.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) <= self::SIGNIFICANT_DIGITS) {
            return $digits;
        }

        return substr($digits, -self::SIGNIFICANT_DIGITS);
    }
}
