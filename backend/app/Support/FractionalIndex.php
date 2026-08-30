<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * =============================================================================
 * FRACTIONAL INDEX — Kanban kart sıralamasının tek kaynağı
 * =============================================================================
 *
 * `deals.position` bir SAYAÇ DEĞİL, bir KESİRDİR. Kartlar arasına ekleme
 * yaparken tüm sütunu yeniden numaralamak (renumbering) iki nedenle kabul
 * edilemez: (1) tek bir sürükle-bırak, sütundaki N kartın hepsine UPDATE atar
 * ve N audit satırı üretir; (2) iki kullanıcı aynı anda sürüklerse yeniden
 * numaralama işlemleri birbirini ezer ve sıra kalıcı olarak bozulur.
 *
 * Bunun yerine her kart string bir anahtar taşır ve iki kartın ARASINA daima
 * yeni bir string sığdırılabilir. Taşınan kart dışında hiçbir satıra
 * dokunulmaz.
 *
 * -----------------------------------------------------------------------------
 * ALFABE VE SIRALAMA SÖZLEŞMESİ
 * -----------------------------------------------------------------------------
 * Alfabe base36'nın KÜÇÜK HARFLİ hâlidir: `0-9a-z`. Bu seçim tesadüfi değil:
 *
 *   - Sıralama `ORDER BY position` ile VERİTABANINDA yapılır (composite index
 *     `deals(pipeline_stage_id, position)`). Kolonun collation'ı
 *     `utf8mb4_unicode_ci` — yani BÜYÜK/KÜÇÜK HARF DUYARSIZ. Alfabede yalnızca
 *     küçük harf ve rakam bulundurmak, MySQL'in sıralamasıyla PHP'nin
 *     `strcmp()` sıralamasının BİREBİR aynı olmasını garantiler. Alfabeye 'A'
 *     eklenseydi PHP 'A' < 'a' derdi, MySQL 'A' = 'a' derdi; testler yeşil
 *     kalırken üretimde sıra bozulurdu.
 *   - Hem unicode collation'da hem ASCII'de rakamlar harflerden önce gelir,
 *     dolayısıyla alfabenin kendi sırası da iki tarafta aynıdır.
 *
 * Anahtarlar "0." ön ekli birer kesir gibi okunur: 'a0001' yaklaşık olarak
 * 0.a0001 (base36). Leksikografik karşılaştırma bu kesirlerin sayısal
 * karşılaştırmasıdır.
 *
 * -----------------------------------------------------------------------------
 * SONDAKİ '0' NEDEN YASAK (ÜRETİLEN DEĞERLERDE)
 * -----------------------------------------------------------------------------
 * Leksikografik sırada bir string, kendi öneklerinden BÜYÜKTÜR: 'a' < 'a0'.
 * Dolayısıyla 'a' ile 'a0' arasına HİÇBİR string sığmaz — '0' alfabenin en
 * küçük basamağı olduğu için 'a' + (bir şey) daima 'a0'dan büyüktür ya da ona
 * eşittir. Bu ölü nokta, sonu '0' ile biten bir anahtar üretildiği anda oluşur.
 * Bu sınıfın ÜRETTİĞİ hiçbir değer '0' ile bitmez (bkz. `decrement()` ve
 * `midpoint()` içindeki MID_DIGIT ekleri).
 *
 * MEVCUT veride sonu '0' ile biten değerler VARDIR (DemoDataSeeder'ın
 * `a` + 4 hane base36 biçimi, ör. `a0080`) ve bu sorun değildir: ölü nokta
 * ancak "X" ile "X0" ikilisi KOMŞU olduğunda ortaya çıkar, sabit genişlikli
 * demo değerlerinde böyle bir çift bulunamaz. Yine de teorik olarak
 * karşılaşılırsa sessizce bozulmak yerine `RuntimeException` atılır.
 *
 * -----------------------------------------------------------------------------
 * MEVCUT BİÇİMLE UYUM
 * -----------------------------------------------------------------------------
 * DemoDataSeeder ve (bu sınıfa devredilmeden önce) LeadConversionService
 * `a` + 4 haneli base36 üretiyordu ('a000w', 'a001s', ...). O değerler bu
 * alfabenin geçerli üyeleridir; algoritma onları olduğu gibi kabul eder,
 * aralarına ekleme yapabilir ve ürettiği yeni değerler onlarla doğru sıralanır.
 * Demo verinin YENİDEN YAZILMASINA gerek yoktur.
 *
 * -----------------------------------------------------------------------------
 * UZUNLUK SINIRI
 * -----------------------------------------------------------------------------
 * Aynı iki kartın arasına üst üste ekleme yapmak anahtarı uzatır (en kötü
 * durumda her ~17 ekleme +1 karakter). `MAX_LENGTH` kolonun kendi sınırıdır
 * (`string(64)`). Aşılırsa sessizce kırpmak yerine `RuntimeException` atılır:
 * kırpma, iki kartın aynı `position`'a düşmesi demektir ve sıralama kalıcı
 * olarak bozulur. 64 karakter pratikte aynı noktaya ~1000 ardışık ekleme
 * demektir; oraya gelinirse doğru çözüm sütunu bir kez yeniden numaralamaktır.
 */
final class FractionalIndex
{
    /**
     * Base36, yalnızca küçük harf. Sırası hem PHP `strcmp()` hem MySQL
     * `utf8mb4_unicode_ci` altında aynıdır.
     */
    public const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * `deals.position` kolonunun genişliği (string(64)).
     */
    public const MAX_LENGTH = 64;

    private const BASE = 36;

    /**
     * Alfabenin ortası ('i', index 18). Bir basamak eklemek gerektiğinde
     * kullanılır: ortadan başlamak sonraki eklemelere iki yönde de en fazla
     * yeri bırakır.
     */
    private const MID_DIGIT = 'i';

    /**
     * Bomboş bir aşamanın ilk kartının anahtarı.
     *
     * DemoDataSeeder'ın ürettiği ilk değerle BİREBİR aynıdır (`a` + 4 hane
     * base36). Böylece boş bir sütuna eklenen kart, demo/seed verisiyle aynı
     * biçimde görünür ve `stepUp()`/`stepDown()` sabit genişlikte çalışabilir:
     * yukarıda ~1.6 milyon, aşağıda ~16 milyon sıra yeri kalır.
     */
    private const DEFAULT_KEY = 'a000w';

    /**
     * `$before` ile `$after` arasına KESİN OLARAK giren yeni bir anahtar.
     *
     * `$before === null` → listenin başı, `$after === null` → listenin sonu,
     * ikisi de null → listenin ilk kaydı.
     *
     * @throws InvalidArgumentException geçersiz alfabe ya da `$before >= $after`
     * @throws RuntimeException araya sığmıyor ya da uzunluk sınırı aşıldı
     */
    public static function between(?string $before, ?string $after): string
    {
        $before = self::normalize($before, 'before');
        $after = self::normalize($after, 'after');

        if ($before === null && $after === null) {
            return self::DEFAULT_KEY;
        }

        if ($after === null) {
            return self::guardLength(self::stepUp($before));
        }

        if ($before === null) {
            return self::guardLength(self::stepDown($after));
        }

        if (strcmp($before, $after) >= 0) {
            // Çağıran komşuları ters sırada verdi. Sessizce düzeltmek YANLIŞ
            // olurdu: istemcinin gördüğü sıra ile sunucunun sırası ayrışmış
            // demektir, kart görünenden başka bir yere düşer.
            throw new InvalidArgumentException(
                "Sıralama anahtarları ters: '{$before}' değeri '{$after}' değerinden küçük olmalı."
            );
        }

        return self::guardLength(self::midpoint($before, $after));
    }

    /**
     * Listenin BAŞINA: `$existingFirst`'ten kesin olarak küçük bir anahtar.
     */
    public static function first(?string $existingFirst): string
    {
        return self::between(null, $existingFirst);
    }

    /**
     * Listenin SONUNA: `$existingLast`'ten kesin olarak büyük bir anahtar.
     */
    public static function last(?string $existingLast): string
    {
        return self::between($existingLast, null);
    }

    /**
     * İki anahtarın leksikografik ortası.
     *
     * Ortak öneki tüketir, sonra ilk ayrışan basamağa bakar:
     *   - arada en az bir basamak varsa onu seçer (uzunluk ARTMAZ),
     *   - basamaklar bitişikse (`b === a + 1`) alt sınırın basamağını alıp bir
     *     seviye derine iner (uzunluk +1) — fractional index'in varlık sebebi
     *     tam olarak budur.
     */
    private static function midpoint(string $lower, string $upper): string
    {
        $prefix = '';
        $index = 0;
        $lowerLength = strlen($lower);
        $upperLength = strlen($upper);

        while (true) {
            if ($index >= $upperLength) {
                // `$upper`, `$lower`ın öneki olurdu; bu `$lower >= $upper`
                // demektir ve çağıran tarafından zaten elenmiştir.
                throw new RuntimeException(
                    "Sıralama anahtarı üretilemedi: '{$lower}' ile '{$upper}' arasında yer yok."
                );
            }

            // -1 = "basamak yok": `$lower` tükendi. Alfabenin en küçük
            // basamağından ('0') bile küçük sayılır, çünkü bir önek daima
            // kendisinden uzun stringlerden küçüktür.
            $a = $index < $lowerLength ? self::digit($lower[$index]) : -1;
            $b = self::digit($upper[$index]);

            if ($a === $b) {
                $prefix .= $upper[$index];
                $index++;

                continue;
            }

            if ($a === -1) {
                // `$lower` bitti: bu basamakta 0..($b-1) aralığındaki HER
                // basamak işe yarar, çünkü herhangi bir ek karakter `$lower`ı
                // zaten aşar.
                if ($b >= 2) {
                    return $prefix.self::ALPHABET[intdiv($b, 2)];
                }

                if ($b === 1) {
                    // Tek aday '0'; sonda '0' bırakmamak için bir basamak daha.
                    return $prefix.'0'.self::MID_DIGIT;
                }

                // $b === 0: `$upper` bu noktada '0' ile devam ediyor. Aynı '0'ı
                // alıp `$upper`ın KALANINDAN küçük bir kuyruk üretmek gerekir.
                $rest = substr($upper, $index + 1);

                if ($rest === '') {
                    // `$upper === $lower . '0'` — ölü nokta (sınıf başlığındaki
                    // "sondaki '0'" notu). Sessiz bozulma yerine açık hata.
                    throw new RuntimeException(
                        "Sıralama anahtarı üretilemedi: '{$lower}' ile '{$upper}' arasında yer yok."
                    );
                }

                return $prefix.'0'.self::decrement($rest);
            }

            if ($b - $a >= 2) {
                // Arada boşluk var: tek basamakla bitir, uzunluk artmaz.
                return $prefix.self::ALPHABET[intdiv($a + $b, 2)];
            }

            // Bitişik basamaklar: alt sınırın basamağında kalıp onun kuyruğunun
            // ÜSTÜNE çık. Sonuç `$upper`dan küçük kalır, çünkü bu basamakta
            // zaten `$a < $b`.
            return $prefix.self::ALPHABET[$a].self::increment(substr($lower, $index + 1));
        }
    }

    /**
     * LİSTENİN SONU: `$key` ile aynı GENİŞLİKTE bir sonraki base36 değeri.
     *
     * Neden `increment()` değil? Sona ekleme, bir Kanban sütununda en sık
     * yapılan işlemdir ve `last()` daima o an ki EN BÜYÜK anahtardan
     * hesaplanır — yani üretilen değer bir sonraki çağrının girdisidir.
     * Kırpan `increment()` bu zincirde anahtarı sürekli KISALTIR
     * ('a000z' → 'a001' → ... → 'z') ve her kısalma kalan sıra alanını 36 kat
     * daraltır: 5 haneli anahtarda ~1.6 milyon yer varken 1 hanelide 36 kalır,
     * sonrasında her ~35 eklemede anahtar bir karakter uzar. Genişliği koruyan
     * artırma bu çöküşü tamamen ortadan kaldırır.
     *
     * Sonu '0' olan aday atlanır (sınıf başlığındaki "sondaki '0'" kuralı);
     * 36 değerden birini kaybetmek, ölü nokta üretme riskine yeğdir.
     *
     * Genişlik taşarsa (tüm basamaklar 'z') tek çare bir basamak eklemektir.
     */
    private static function stepUp(string $key): string
    {
        $digits = self::toDigits($key);
        $last = count($digits) - 1;

        while (true) {
            $index = $last;

            while ($index >= 0 && $digits[$index] === self::BASE - 1) {
                $digits[$index] = 0;
                $index--;
            }

            if ($index < 0) {
                // Tüm basamaklar 'z' idi: aynı genişlikte daha büyüğü yok.
                return $key.'1';
            }

            $digits[$index]++;

            if ($digits[$last] !== 0) {
                return self::toKey($digits);
            }
        }
    }

    /**
     * LİSTENİN BAŞI: `$key` ile aynı GENİŞLİKTE bir önceki base36 değeri.
     *
     * `stepUp()` ile aynı gerekçe, ters yönde. Genişlik tükenirse (anahtar
     * tamamen '0' basamaklarına inerse) kırpan `decrement()`'e düşülür; o da
     * gerekirse anahtarı uzatarak yer açar.
     */
    private static function stepDown(string $key): string
    {
        $digits = self::toDigits($key);
        $last = count($digits) - 1;

        while (true) {
            $index = $last;

            while ($index >= 0 && $digits[$index] === 0) {
                $digits[$index] = self::BASE - 1;
                $index--;
            }

            if ($index < 0) {
                // Aynı genişlikte daha küçüğü yok; anahtarı uzatan çareye geç.
                return self::decrement($key);
            }

            $digits[$index]--;

            if ($digits[$last] !== 0) {
                return self::toKey($digits);
            }
        }
    }

    /**
     * `$key`'den kesin olarak BÜYÜK, mümkün olan en kısa anahtar.
     *
     * Yalnızca `midpoint()` kullanır: orada anahtar zaten bir üst sınıra karşı
     * daraltılmış durumdadır, dolayısıyla KISALIK önceliklidir.
     *
     * Sağdan ilk 'z' olmayan basamağı bir artırır ve KUYRUĞU ATAR — böylece
     * arka arkaya sona ekleme yapıldıkça anahtarlar uzamaz, hatta kısalır
     * ('a000z' → 'a001'). Tüm basamaklar 'z' ise tek çare bir basamak
     * eklemektir ('zzz' → 'zzzi').
     *
     * Sonuç asla '0' ile bitmez: artırılan basamak tanımı gereği en az 1'dir.
     */
    private static function increment(string $key): string
    {
        if ($key === '') {
            return self::MID_DIGIT;
        }

        for ($index = strlen($key) - 1; $index >= 0; $index--) {
            $digit = self::digit($key[$index]);

            if ($digit < self::BASE - 1) {
                return substr($key, 0, $index).self::ALPHABET[$digit + 1];
            }
        }

        return $key.self::MID_DIGIT;
    }

    /**
     * `$key`'den kesin olarak KÜÇÜK bir anahtar.
     *
     * Soldan ilk sıfır olmayan basamağı bir azaltır ve kuyruğu atar. Azaltma
     * sonucu '0' olursa sonda '0' bırakmamak için bir orta basamak eklenir
     * ('1' → '0i').
     *
     * Tamamı '0' olan bir anahtardan küçüğü üretilemez (yalnızca kendi
     * önekleri küçüktür, onlar da yine sıfır kuyruklu ölü noktalardır) — bu
     * sınıfın ürettiği hiçbir değer bu hâlde olamaz, dışarıdan gelirse açık
     * hata.
     */
    private static function decrement(string $key): string
    {
        $length = strlen($key);

        for ($index = 0; $index < $length; $index++) {
            $digit = self::digit($key[$index]);

            if ($digit === 0) {
                continue;
            }

            $candidate = substr($key, 0, $index).self::ALPHABET[$digit - 1];

            return $digit - 1 === 0 ? $candidate.self::MID_DIGIT : $candidate;
        }

        throw new RuntimeException(
            "Sıralama anahtarı üretilemedi: '{$key}' değerinden küçük bir anahtar yok."
        );
    }

    /**
     * Boş string `null` ile eşdeğerdir (kolonu boş bırakılmış eski kayıt).
     *
     * @throws InvalidArgumentException
     */
    private static function normalize(?string $key, string $label): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (preg_match('/^[0-9a-z]+$/', $key) !== 1) {
            throw new InvalidArgumentException(
                "Geçersiz sıralama anahtarı ({$label}): '{$key}'. Yalnızca 0-9 ve a-z kullanılabilir."
            );
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                "Sıralama anahtarı ({$label}) çok uzun: ".strlen($key).' karakter, en fazla '.self::MAX_LENGTH.'.'
            );
        }

        return $key;
    }

    /**
     * @throws RuntimeException
     */
    private static function guardLength(string $key): string
    {
        if (strlen($key) > self::MAX_LENGTH) {
            throw new RuntimeException(
                'Sıralama anahtarı '.self::MAX_LENGTH.' karakter sınırını aştı; '
                .'bu sütunun bir kez yeniden numaralanması gerekiyor.'
            );
        }

        return $key;
    }

    /**
     * @return array<int, int>
     */
    private static function toDigits(string $key): array
    {
        $digits = [];
        $length = strlen($key);

        for ($index = 0; $index < $length; $index++) {
            $digits[] = self::digit($key[$index]);
        }

        return $digits;
    }

    /**
     * @param  array<int, int>  $digits
     */
    private static function toKey(array $digits): string
    {
        $key = '';

        foreach ($digits as $digit) {
            $key .= self::ALPHABET[$digit];
        }

        return $key;
    }

    private static function digit(string $character): int
    {
        $position = strpos(self::ALPHABET, $character);

        if ($position === false) {
            throw new InvalidArgumentException("Geçersiz sıralama basamağı: '{$character}'.");
        }

        return $position;
    }
}
