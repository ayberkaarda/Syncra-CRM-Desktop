<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Services\Leads\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faz 6 — duplicate tespit motoru.
 *
 * Burada pinlenen şey SKOR SÖZLEŞMESİdir: hangi kural kaç puan, kurallar
 * çakışınca ne olur, hangi kayıtlar hiç görünmez. Skorlar UI'da renk/uyarı
 * seviyesine (`strong` / `possible`) çevriliyor ve kullanıcı "yine de kaydet"
 * kararını buna bakarak veriyor; sessizce kayan bir skor, kullanıcıya yanlış
 * bir kesinlik hissi satar.
 */
class DuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new DuplicateDetector;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function find(array $candidates, string $type, int $id): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['type'] === $type && $candidate['id'] === $id) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * En değerli tespit: lead formuna girilen e-posta ZATEN MÜŞTERİ olan
     * birine ait. Bu bulunmazsa iki satışçı aynı müşteriye ayrı ayrı gider.
     */
    public function test_existing_contact_with_the_same_email_is_found_with_score_100(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'ayse.demir@ornekmail.com',
        ]);

        $results = $this->detector->findCandidates(['email' => 'AYSE.DEMIR@ornekmail.com'])->all();

        $match = $this->find($results, 'contact', $contact->id);

        $this->assertNotNull($match, 'Aynı e-postalı mevcut kişi bulunamadı.');
        $this->assertSame(100, $match['score']);
        $this->assertSame('strong', $match['level']);
        $this->assertSame('contact', $match['type']);
        $this->assertSame(['email'], $match['matched_on']);
        $this->assertSame($contact->first_name.' '.$contact->last_name, $match['name']);
    }

    public function test_existing_lead_with_the_same_email_is_found(): void
    {
        $lead = Lead::factory()->create([
            'email' => 'mehmet.kaya@ornekmail.com',
        ]);

        $results = $this->detector->findCandidates(['email' => 'mehmet.kaya@ornekmail.com'])->all();

        $match = $this->find($results, 'lead', $lead->id);

        $this->assertNotNull($match, 'Aynı e-postalı mevcut lead bulunamadı.');
        $this->assertSame(100, $match['score']);
        $this->assertSame('strong', $match['level']);
    }

    /**
     * Motorun asıl zor kısmı: kolonda HAM string duruyor, karşılaştırma
     * normalize değer üzerinden yapılıyor. Bu test geçmezse telefon kuralı
     * pratikte hiç çalışmıyor demektir (formatlanmış numara ile ham numara
     * asla birebir eşit olmaz).
     */
    public function test_same_phone_written_in_a_different_format_is_found_with_score_90(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'farkli.eposta@ornekmail.com',
            'first_name' => 'Zeynep',
            'last_name' => 'Arslan',
            'phone' => '+90 532 111 22 33',
            'mobile' => null,
        ]);

        $results = $this->detector->findCandidates([
            'email' => 'hicbir.yerde.yok@ornekmail.com',
            'phone' => '05321112233',
        ])->all();

        $match = $this->find($results, 'contact', $contact->id);

        $this->assertNotNull($match, 'Farklı formatta yazılmış aynı telefon bulunamadı.');
        $this->assertSame(90, $match['score']);
        $this->assertSame('strong', $match['level']);
        $this->assertSame(['phone'], $match['matched_on']);
    }

    /**
     * Ham stringte aynı haneleri BARINDIRAN ama aynı numara OLMAYAN kayıt,
     * SQL üst kümesinden geçse bile PHP doğrulamasında elenmeli.
     */
    public function test_phone_superset_query_false_positives_are_rejected(): void
    {
        Contact::factory()->create([
            'email' => 'baska@ornekmail.com',
            'first_name' => 'Emre',
            'last_name' => 'Şahin',
            // İçinde 5,3,2,1,1,1,2,2,3,3 haneleri bu sırayla geçiyor ama
            // numaranın son 10 hanesi farklı.
            'phone' => '+90 532 111 22 33 12',
            'mobile' => null,
        ]);

        $results = $this->detector->findCandidates(['phone' => '05321112233'])->all();

        $this->assertSame([], $results, 'Telefon yanlış-pozitifi elenmedi.');
    }

    public function test_name_plus_company_scores_80_and_name_alone_scores_50(): void
    {
        $company = Company::factory()->create(['name' => 'Yılmaz Holding']);

        $withCompany = Contact::factory()->create([
            'first_name' => 'Ali',
            'last_name' => 'Yılmaz',
            'company_id' => $company->id,
            'email' => 'ali.yilmaz.1@ornekmail.com',
            'phone' => '+90 216 000 00 01',
            'mobile' => null,
        ]);

        $withoutCompany = Lead::factory()->create([
            'first_name' => 'Ali',
            'last_name' => 'Yılmaz',
            'company_name' => 'Bambaşka Lojistik',
            'email' => 'ali.yilmaz.2@ornekmail.com',
            'phone' => '+90 216 000 00 02',
        ]);

        $results = $this->detector->findCandidates([
            'first_name' => 'Ali',
            'last_name' => 'Yılmaz',
            'company_name' => 'yılmaz holding',
        ])->all();

        $strong = $this->find($results, 'contact', $withCompany->id);
        $possible = $this->find($results, 'lead', $withoutCompany->id);

        $this->assertNotNull($strong);
        $this->assertSame(80, $strong['score']);
        $this->assertSame('strong', $strong['level']);
        $this->assertSame(['name'], $strong['matched_on']);
        $this->assertSame('Yılmaz Holding', $strong['company']);

        $this->assertNotNull($possible);
        $this->assertSame(50, $possible['score']);
        $this->assertSame('possible', $possible['level']);

        // Güçlü aday zayıf adaydan ÖNCE gelmeli.
        $this->assertSame('contact', $results[0]['type']);
        $this->assertSame($withCompany->id, $results[0]['id']);
    }

    /**
     * Skorlar TOPLANMAZ. Toplansaydı 190 çıkardı ve 0-100 ölçeği anlamını
     * yitirirdi; ama uyan kuralların hepsi `matched_on`'da görünmeli.
     */
    public function test_a_record_matching_both_email_and_phone_scores_100_not_190(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'cift.eslesme@ornekmail.com',
            'phone' => '0532 999 88 77',
            'mobile' => null,
        ]);

        $results = $this->detector->findCandidates([
            'email' => 'cift.eslesme@ornekmail.com',
            'phone' => '+90 532 999 88 77',
        ])->all();

        $match = $this->find($results, 'contact', $contact->id);

        $this->assertNotNull($match);
        $this->assertSame(100, $match['score']);
        $this->assertSame(['email', 'phone'], $match['matched_on']);
        $this->assertCount(1, $results, 'Aynı kayıt iki kez listelendi.');
    }

    public function test_soft_deleted_records_are_never_returned(): void
    {
        $contact = Contact::factory()->create(['email' => 'silinmis@ornekmail.com']);
        $lead = Lead::factory()->create(['email' => 'silinmis@ornekmail.com']);

        $contact->delete();
        $lead->delete();

        $results = $this->detector->findCandidates(['email' => 'silinmis@ornekmail.com'])->all();

        $this->assertSame([], $results, 'Silinmiş kayıt duplicate olarak gösterildi.');
    }

    /**
     * Güncelleme senaryosu: bir lead düzenlenirken kendisi duplicate olarak
     * listelenmemeli, yoksa her kaydetme denemesi sahte uyarı üretir.
     */
    public function test_excluded_lead_is_not_returned(): void
    {
        $edited = Lead::factory()->create(['email' => 'ayni@ornekmail.com']);
        $other = Lead::factory()->create(['email' => 'ayni@ornekmail.com']);

        $results = $this->detector->findCandidates(['email' => 'ayni@ornekmail.com'], $edited->id)->all();

        $this->assertNull($this->find($results, 'lead', $edited->id), 'Hariç tutulan lead sonuçta çıktı.');
        $this->assertNotNull($this->find($results, 'lead', $other->id), 'Diğer lead kayboldu.');
    }

    /**
     * Boş formda "herkes duplicate" olmamalı. Erken çıkış olmasaydı filtresiz
     * sorgular tüm tabloyu döndürürdü.
     */
    public function test_empty_input_returns_no_candidates_instead_of_the_whole_table(): void
    {
        Contact::factory()->count(3)->create();
        Lead::factory()->count(3)->create();

        $this->assertSame([], $this->detector->findCandidates([])->all());
        $this->assertSame([], $this->detector->findCandidates([
            'email' => null,
            'phone' => '   ',
            'first_name' => '',
            'last_name' => null,
            'company_name' => 'Yılmaz Holding',
        ])->all());
    }

    /**
     * Sadece ad ya da sadece soyad isim kuralını tetiklemez — tek başına
     * "Mehmet" yüzlerce alakasız aday üretirdi.
     */
    public function test_partial_name_does_not_trigger_the_name_rule(): void
    {
        Contact::factory()->create(['first_name' => 'Mehmet', 'last_name' => 'Aydın']);

        $results = $this->detector->findCandidates(['first_name' => 'Mehmet'])->all();

        $this->assertSame([], $results);
    }

    public function test_result_count_is_capped(): void
    {
        Contact::factory()->count(DuplicateDetector::MAX_RESULTS + 5)->create([
            'email' => 'kalabalik@ornekmail.com',
        ]);

        $results = $this->detector->findCandidates(['email' => 'kalabalik@ornekmail.com']);

        $this->assertCount(DuplicateDetector::MAX_RESULTS, $results);
    }

    /**
     * `mobile` de gerçek bir telefon: kişinin cebini lead formuna yazmak,
     * sabit hattını yazmak kadar sık.
     */
    public function test_contact_mobile_number_also_matches(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'cep@ornekmail.com',
            'phone' => '+90 212 444 55 66',
            'mobile' => '+90 555 333 22 11',
        ]);

        $results = $this->detector->findCandidates(['phone' => '0555 333 22 11'])->all();

        $match = $this->find($results, 'contact', $contact->id);

        $this->assertNotNull($match, 'Cep telefonu eşleşmesi bulunamadı.');
        $this->assertSame(90, $match['score']);
    }
}
