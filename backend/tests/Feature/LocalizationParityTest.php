<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Faz 14 / İz D — GÖREV 1 (docs/PHASE-INTL.md §1.7): `backend/lang/{tr,en,de,fr}/*.php`
 * sözlüklerinin anahtar kümeleri BİREBİR eşit olmalı (dosya bazlı, iç içe diziler
 * düzleştirilerek).
 *
 * "Sessizce bozulan" sınıfı: bir dilde eksik anahtar Laravel'in kendi `fallback_locale`
 * mekanizmasıyla SESSİZCE başka bir dile düşer — istek yine 200/403/... döner, yalnızca
 * metin yanlış dilde çıkar. Hata görünmez; ancak böyle bir parite denetimiyle yakalanır.
 *
 * Neden AYRI bir dosya (mevcut `LocalizationTest::test_backend_language_files_have_identical_key_sets`
 * dururken): o test sabit 4 grupla (`errors`, `auth`, `passwords`, `notifications`) sınırlı ve
 * `validation.php`'yi bilinçli olarak dışarıda bırakıyor (o dosya bilinçli boş bir iskelet —
 * bkz. dosyanın kendi docblock'u). Bu test `lang/tr` dizinini REFERANS alıp içindeki TÜM
 * `*.php` dosyalarını dinamik keşfeder (validation.php dahil — boş olduğu için trivially
 * eşleşir, zarar vermez) ve `docs/PHASE-INTL.md`'nin "dosya bazlı" ifadesini birebir karşılar;
 * yeni bir `lang/*.php` dosyası eklendiğinde test GÜNCELLENMEDEN otomatik kapsar.
 *
 * `--filter=LocalizationParity` bu dosyayı hedefler (bkz. docs/PHASE-INTL.md §1.7 doğrulama).
 */
class LocalizationParityTest extends TestCase
{
    private const LOCALES = ['tr', 'en', 'de', 'fr'];

    private const REFERENCE_LOCALE = 'tr';

    public function test_all_backend_language_files_have_identical_key_sets(): void
    {
        $referenceDir = lang_path(self::REFERENCE_LOCALE);
        $this->assertDirectoryExists($referenceDir, 'Referans dil dizini yok: lang/'.self::REFERENCE_LOCALE);

        $files = collect(glob($referenceDir.'/*.php'))
            ->map(fn (string $path) => basename($path))
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($files, 'lang/'.self::REFERENCE_LOCALE.' altında hiç .php dosyası yok.');

        $failures = [];

        foreach ($files as $file) {
            $keysByLocale = [];

            foreach (self::LOCALES as $locale) {
                $path = lang_path("{$locale}/{$file}");

                if (! is_file($path)) {
                    $failures[] = "[{$locale}] {$file}: DOSYA YOK";

                    continue;
                }

                $keys = $this->flattenKeys(require $path);
                sort($keys);
                $keysByLocale[$locale] = $keys;
            }

            // Referans: dosyası mevcut tüm locale'lerin anahtar BİRLEŞİMİ. Bir anahtar yalnız
            // tek bir dilde varsa (yani diğerlerine göre "fazla"ysa), bu birleşim sayesinde
            // diğer dillerde otomatik "eksik anahtar" olarak yakalanır — eksik/fazla için ayrı
            // bir geçiş gerekmez, tek karşılaştırma iki yönlü simetrik farkı da kapsar.
            $union = [];
            foreach ($keysByLocale as $keys) {
                $union = array_merge($union, $keys);
            }
            $union = array_values(array_unique($union));
            sort($union);

            foreach (self::LOCALES as $locale) {
                if (! isset($keysByLocale[$locale])) {
                    continue; // dosya-yok zaten yukarıda raporlandı
                }

                $missing = array_values(array_diff($union, $keysByLocale[$locale]));

                foreach ($missing as $missingKey) {
                    $failures[] = "[{$locale}] {$file}: eksik anahtar '{$missingKey}'";
                }
            }
        }

        sort($failures);

        $this->assertSame(
            [],
            $failures,
            'Backend dil dosyalarında anahtar paritesi bozuk ('.count($failures)." sorun):\n".implode("\n", $failures)
        );
    }

    /**
     * @param  array<mixed>  $lines
     * @return array<int, string>
     */
    private function flattenKeys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = array_merge($keys, is_array($value) ? $this->flattenKeys($value, $full) : [$full]);
        }

        return $keys;
    }
}
