<?php

namespace Tests\Unit;

use App\Support\FractionalIndex;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Faz 7 — Kanban sıralama anahtarı.
 *
 * Bu sınıfın tek sözleşmesi şudur: ÜRETİLEN DEĞER, VERİLEN İKİ SINIRIN
 * ARASINDA SIRALANIR. Sıralama veritabanında `ORDER BY position` ile
 * yapıldığı için testler de karşılaştırmayı `strcmp()` ile yapar — MySQL
 * `utf8mb4_unicode_ci` ile PHP `strcmp()` bu alfabede (yalnızca `0-9a-z`)
 * birebir aynı sırayı verir.
 *
 * Kritik senaryo "bitişik iki değerin arasına ekleme"dir: `a0001` ile `a0002`
 * arasında bir tamsayı yoktur, dolayısıyla sayaç mantığı burada çöker.
 * Fractional index'in varlık sebebi tam olarak bu durumdur.
 */
class FractionalIndexTest extends TestCase
{
    /**
     * DemoDataSeeder'ın ürettiği biçim: `a` + 4 hane base36, 32'şer adım.
     */
    private function demoKey(int $sequence): string
    {
        return 'a'.str_pad(base_convert((string) ($sequence * 32), 10, 36), 4, '0', STR_PAD_LEFT);
    }

    private function assertBetween(string $lower, string $key, string $upper): void
    {
        $this->assertLessThan(0, strcmp($lower, $key), "'{$key}' değeri '{$lower}' değerinden büyük olmalıydı.");
        $this->assertLessThan(0, strcmp($key, $upper), "'{$key}' değeri '{$upper}' değerinden küçük olmalıydı.");
    }

    /* ----------------------------------------------------------------------
     * Araya ekleme
     * ------------------------------------------------------------------- */

    public function test_it_inserts_between_two_far_apart_keys(): void
    {
        $key = FractionalIndex::between('a0001', 'a0009');

        $this->assertBetween('a0001', $key, 'a0009');
    }

    /**
     * ASIL TEST: 'a0001' ile 'a0002' arasında tamsayı yoktur. Sayaç mantığı
     * burada ya çakışır ya da tüm sütunu yeniden numaralamak zorunda kalır.
     */
    public function test_it_inserts_between_two_adjacent_keys(): void
    {
        $key = FractionalIndex::between('a0001', 'a0002');

        $this->assertBetween('a0001', $key, 'a0002');
        $this->assertGreaterThan(5, strlen($key), 'Bitişik değerlerin arasına girmek için anahtar uzamalıydı.');
    }

    /**
     * Aynı iki kartın arasına art arda 50 ekleme (kullanıcı listenin aynı
     * noktasına üst üste kart bırakıyor). Her adım bir öncekiyle alt sınır
     * arasına girer — en dar durum budur.
     */
    public function test_fifty_consecutive_inserts_into_the_same_gap_stay_ordered(): void
    {
        $lower = 'a0001';
        $upper = 'a0002';
        $keys = [];

        for ($i = 0; $i < 50; $i++) {
            $key = FractionalIndex::between($lower, $upper);

            $this->assertBetween($lower, $key, $upper);
            $this->assertNotContains($key, $keys, 'Aynı anahtar iki kez üretildi.');

            $keys[] = $key;
            // Yeni kart, bir sonraki bırakmanın ÜST komşusu olur.
            $upper = $key;
        }

        // Üretim sırası tersine sıralamayı vermeli: her yeni anahtar bir
        // öncekinden küçük ama daima alt sınırdan büyük.
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame(array_reverse($keys), $sorted);
        $this->assertLessThanOrEqual(FractionalIndex::MAX_LENGTH, max(array_map('strlen', $keys)));
    }

    public function test_fifty_consecutive_inserts_against_the_upper_bound_stay_ordered(): void
    {
        $lower = 'a0001';
        $upper = 'a0002';
        $keys = [];

        for ($i = 0; $i < 50; $i++) {
            $key = FractionalIndex::between($lower, $upper);

            $this->assertBetween($lower, $key, $upper);

            $keys[] = $key;
            // Bu kez yeni kart, bir sonraki bırakmanın ALT komşusu olur.
            $lower = $key;
        }

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($keys, $sorted);
    }

    /* ----------------------------------------------------------------------
     * Başa / sona ekleme ve null bileşimleri
     * ------------------------------------------------------------------- */

    public function test_last_returns_a_key_greater_than_the_current_last(): void
    {
        $this->assertLessThan(0, strcmp('a0020', FractionalIndex::last('a0020')));
    }

    public function test_first_returns_a_key_smaller_than_the_current_first(): void
    {
        $this->assertLessThan(0, strcmp(FractionalIndex::first('a000w'), 'a000w'));
    }

    public function test_first_and_last_on_an_empty_list_return_the_same_seed_key(): void
    {
        $this->assertSame(FractionalIndex::first(null), FractionalIndex::last(null));
        $this->assertSame(FractionalIndex::between(null, null), FractionalIndex::last(null));
    }

    public function test_between_treats_an_empty_string_like_null(): void
    {
        $this->assertSame(FractionalIndex::last('a0001'), FractionalIndex::between('a0001', ''));
        $this->assertSame(FractionalIndex::first('a0001'), FractionalIndex::between('', 'a0001'));
    }

    /**
     * Sona ekleme SABİT GENİŞLİKTE ilerler. Kırpan bir artırma her seferinde
     * anahtarı kısaltıp kalan sıra alanını 36 kat daraltırdı; 200 ekleme
     * sonunda anahtarların hâlâ 5 hanede kalması bunun kanıtı.
     */
    public function test_two_hundred_appends_stay_ordered_and_do_not_grow(): void
    {
        $keys = [];
        $key = null;

        for ($i = 0; $i < 200; $i++) {
            $key = FractionalIndex::last($key);
            $keys[] = $key;
        }

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($keys, $sorted);
        $this->assertSame(5, max(array_map('strlen', $keys)));
    }

    public function test_two_hundred_prepends_stay_ordered_and_do_not_grow(): void
    {
        $keys = [];
        $key = null;

        for ($i = 0; $i < 200; $i++) {
            $key = FractionalIndex::first($key);
            $keys[] = $key;
        }

        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame(array_reverse($keys), $sorted);
        $this->assertSame(5, max(array_map('strlen', $keys)));
    }

    /**
     * Üretilen hiçbir anahtar '0' ile bitmez: 'x' ile 'x0' arasına hiçbir
     * string sığmadığı için sonu sıfırlı bir anahtar, ileride araya ekleme
     * yapılamayan bir ölü nokta yaratır.
     */
    public function test_generated_keys_never_end_with_a_zero_digit(): void
    {
        $key = null;

        for ($i = 0; $i < 60; $i++) {
            $key = FractionalIndex::last($key);
            $this->assertStringEndsNotWith('0', $key);
        }

        $key = null;

        for ($i = 0; $i < 60; $i++) {
            $key = FractionalIndex::first($key);
            $this->assertStringEndsNotWith('0', $key);
        }

        $upper = 'a0002';

        for ($i = 0; $i < 60; $i++) {
            $upper = FractionalIndex::between('a0001', $upper);
            $this->assertStringEndsNotWith('0', $upper);
        }
    }

    /* ----------------------------------------------------------------------
     * Mevcut demo verisiyle uyum
     * ------------------------------------------------------------------- */

    /**
     * Demo veri YENİDEN YAZILMADAN çalışmalı: seeder'ın ürettiği sabit
     * genişlikli değerlerle yeni üretilen değerler tek bir listede doğru
     * sıralanmalı.
     */
    public function test_new_keys_sort_correctly_among_existing_demo_keys(): void
    {
        $demo = [];

        for ($i = 1; $i <= 10; $i++) {
            $demo[] = $this->demoKey($i);
        }

        $inserted = FractionalIndex::between($demo[3], $demo[4]);
        $prepended = FractionalIndex::first($demo[0]);
        $appended = FractionalIndex::last($demo[9]);

        $this->assertBetween($demo[3], $inserted, $demo[4]);

        $all = array_merge($demo, [$inserted, $prepended, $appended]);
        sort($all, SORT_STRING);

        $expected = array_merge(
            [$prepended],
            array_slice($demo, 0, 4),
            [$inserted],
            array_slice($demo, 4),
            [$appended]
        );

        $this->assertSame($expected, $all);
    }

    /**
     * Demo biçiminde sonu '0' ile biten değerler VAR (ör. 9. kart 'a0080').
     * Bunlar alt sınır olarak sorunsuz kullanılabilmeli.
     */
    public function test_it_handles_existing_keys_that_end_with_a_zero_digit(): void
    {
        $lower = $this->demoKey(9); // 'a0080'
        $upper = $this->demoKey(10); // 'a008w'

        $this->assertSame('a0080', $lower);

        $key = FractionalIndex::between($lower, $upper);

        $this->assertBetween($lower, $key, $upper);
        $this->assertLessThan(0, strcmp($lower, FractionalIndex::last($lower)));
    }

    /* ----------------------------------------------------------------------
     * Sınırlar ve reddedilen girdiler
     * ------------------------------------------------------------------- */

    public function test_it_refuses_to_exceed_the_column_length_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('64 karakter sınırını aştı');

        FractionalIndex::last(str_repeat('z', FractionalIndex::MAX_LENGTH));
    }

    public function test_it_rejects_inverted_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FractionalIndex::between('a0002', 'a0001');
    }

    public function test_it_rejects_identical_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FractionalIndex::between('a0001', 'a0001');
    }

    /**
     * Alfabe dışı karakter reddedilir. Büyük harf özellikle tehlikelidir:
     * PHP 'A' < 'a' der, MySQL `utf8mb4_unicode_ci` 'A' = 'a' der — sıralama
     * testte doğru, üretimde yanlış olurdu.
     */
    public function test_it_rejects_keys_outside_the_alphabet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FractionalIndex::between('A0001', 'a0002');
    }

    /**
     * 'x' ile 'x0' arasına hiçbir string sığmaz. Sessizce çakışan bir anahtar
     * üretmek yerine açık hata verilir.
     */
    public function test_it_refuses_the_unreachable_gap_before_a_trailing_zero_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('arasında yer yok');

        FractionalIndex::between('a001', 'a0010');
    }
}
