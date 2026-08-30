<?php

namespace Tests\Feature\Security;

use App\Models\Lead;
use App\Services\Leads\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F6/H8 — Türkçe İ/ı katlama düzeltmesinin duplicate tespitinde KİLİTLENMESİ.
 *
 * Düzeltmeden ÖNCE: `DuplicateDetector::sameText()` `mb_strtolower()`
 * kullanıyordu; `mb_strtolower('İhsan')` -> `i̇hsan` (birleşik nokta, 7 kod
 * noktası) üretiyordu ve bu, `mb_strtolower('ihsan')` = `ihsan` (5 kod
 * noktası) ile BİREBİR EŞİT DEĞİLDİ — "İhsan Yılmaz" ile "ihsan yılmaz" iki
 * ayrı lead olarak kaydedilebiliyordu, duplicate uyarısı hiç çıkmıyordu.
 *
 * Düzeltmeden SONRA: `TurkishCase::fold()` ile İ/I/ı/i dördü de aynı kanonik
 * karaktere iniyor, iki yazım "ad+soyad" kuralıyla (skor 50, `possible`)
 * eşleşiyor.
 */
class TurkishCaseMatchingTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new DuplicateDetector;
    }

    public function test_turkish_dotted_capital_i_name_matches_its_lowercase_counterpart(): void
    {
        $existing = Lead::factory()->create([
            'first_name' => 'İhsan',
            'last_name' => 'Yılmaz',
            'email' => 'ihsan.yilmaz.mevcut@ornekmail.com',
            'company_name' => null,
        ]);

        $results = $this->detector->findCandidates([
            'first_name' => 'ihsan',
            'last_name' => 'yılmaz',
        ])->all();

        $match = null;
        foreach ($results as $candidate) {
            if ($candidate['type'] === 'lead' && $candidate['id'] === $existing->id) {
                $match = $candidate;
            }
        }

        $this->assertNotNull($match, '"İhsan Yılmaz" ile "ihsan yılmaz" duplicate olarak eşleşmedi.');
        $this->assertSame(50, $match['score']);
        $this->assertSame('possible', $match['level']);
        $this->assertSame(['name'], $match['matched_on']);
    }

    /**
     * BİLİNEN SINIR (PHASE-AUDIT §4-F6, kapsam dışı — bu testte KAYDA GEÇİYOR,
     * DEĞİŞTİRİLMİYOR): `collectLeads()`/`collectContacts()`'taki isim kuralı
     * SQL'de `where('first_name', $firstName)` ile TAM eşleşme arar; bu sorgu
     * `TurkishCase::fold()`'a hiç uğramadan doğrudan `utf8mb4_unicode_ci`
     * collation'ına gider. Ölçüldü: bu collation `İ`~`i`'yi eşit sayıyor
     * (`'İhsan' = 'ihsan' COLLATE utf8mb4_unicode_ci` → 1) ama `I`~`ı`'yı
     * SAYMIYOR (`'Irmak' = 'ırmak' COLLATE utf8mb4_unicode_ci` → 0). Yani
     * SADECE isimle (email/telefon eşleşmesi olmadan) aranan "Irmak" / "ırmak"
     * çifti SQL AŞAMASINDA hiç yakalanmıyor — `sameText()`'e/`TurkishCase`'e
     * hiç sıra gelmiyor. Bu, H8'in kapsamındaki PHP/JS katlama bug'ı DEĞİL,
     * PHASE-AUDIT'in bilinçli olarak kapsam dışı bıraktığı collation
     * sınırıdır ("Collation DEĞİŞTİRME, migration YAZMA"). Bu test bunun
     * yerine, satır SQL'e BAŞKA bir kuralla (e-posta) zaten girmişken
     * `sameText()`'in dotless `I`/`ı` farkını doğru kapattığını — yani H8'in
     * gerçekten kapsadığı PHP-seviyesi karşılaştırmayı — doğrular.
     */
    public function test_dotless_capital_i_name_bonus_applies_once_the_row_is_already_fetched(): void
    {
        $existing = Lead::factory()->create([
            'first_name' => 'Irmak',
            'last_name' => 'Demir',
            'email' => 'irmak.ortak@ornekmail.com',
            'company_name' => null,
        ]);

        // E-posta aynı olduğu için satır SQL'den KESİN gelir; asıl iddia
        // `matched_on`'da 'name'in de görünmesi — yani `sameText()` "Irmak"
        // ile "ırmak"ı PHP seviyesinde eşit saymalı.
        $results = $this->detector->findCandidates([
            'email' => 'irmak.ortak@ornekmail.com',
            'first_name' => 'ırmak',
            'last_name' => 'demir',
        ])->all();

        $match = null;
        foreach ($results as $candidate) {
            if ($candidate['type'] === 'lead' && $candidate['id'] === $existing->id) {
                $match = $candidate;
            }
        }

        $this->assertNotNull($match);
        $this->assertContains('name', $match['matched_on'], '"Irmak" ile "ırmak" PHP seviyesinde (sameText) eşleşmedi.');
        // E-posta zaten 100 verdiği için toplam skor değişmez (skorlar
        // TOPLANMAZ) — burada asıl iddia `matched_on` içeriğidir.
        $this->assertSame(100, $match['score']);
    }
}
