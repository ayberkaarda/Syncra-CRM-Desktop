<?php

namespace App\Services\Leads;

use App\Models\Contact;
use App\Models\Lead;
use App\Support\PhoneNormalizer;
use App\Support\TurkishCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Bir lead girdisine benzeyen MEVCUT kayıtları bulur.
 *
 * ---------------------------------------------------------------------------
 * NEDEN HEM `leads` HEM `contacts`
 * ---------------------------------------------------------------------------
 * İki lead'in çakışması sinir bozucudur; bir lead'in ZATEN MÜŞTERİ olan biriyle
 * çakışması ise para kaybettirir — iki satışçı aynı firmaya ayrı ayrı teklif
 * götürür. Asıl değerli tespit `contacts` tarafındadır, bu yüzden iki tablo da
 * aynı kurallarla taranır.
 *
 * ---------------------------------------------------------------------------
 * SKORLAMA (sözleşme — değiştirilmesi UI ve testleri kırar)
 * ---------------------------------------------------------------------------
 *   E-posta tam eşleşme (case-insensitive) .......... 100  strong
 *   Telefon tam eşleşme (normalize edilmiş) .........  90  strong
 *   Ad + Soyad + Firma adı tam eşleşme ..............  80  strong
 *   Ad + Soyad tam eşleşme (firma yok/farklı) .......  50  possible
 *
 * Bir kayıt birden fazla kurala uyarsa EN YÜKSEK skoru alır; skorlar TOPLANMAZ.
 * Toplasaydık e-posta+telefon eşleşen bir kayıt 190 alırdı ve 0-100 ölçeği
 * anlamını yitirirdi; ayrıca "e-postası aynı" tek başına zaten kesin sinyaldir,
 * üstüne telefon eklenmesi kesinliği artırmaz. Uyan kuralların TAMAMI
 * `matched_on` alanında görünür — kullanıcı neden aday gösterildiğini görür.
 *
 * ---------------------------------------------------------------------------
 * TELEFON EŞLEŞTİRME: NEDEN İKİ AŞAMALI
 * ---------------------------------------------------------------------------
 * Kolonda HAM değer duruyor (`+90 532 111 22 33`), karşılaştırma ise NORMALIZE
 * değer üzerinden yapılmalı (`5321112233`). Bu yüzden düz `where('phone', ...)`
 * ya da sondan eşleyen bir LIKE deseni çalışmaz — boşluklar yüzünden hiçbir
 * formatlanmış kayıt tutmaz. Ham SQL (`REPLACE(...)`) yasak, ayrıca kolon
 * üzerinde fonksiyon uygulamak indeksi zaten kullanılamaz hâle getirirdi.
 *
 * Seçilen yol — SQL'de ÜST KÜME + PHP'de KESİN DOĞRULAMA:
 *   1. Normalize numaranın haneleri arasına joker serpiştirilerek bir LIKE
 *      deseni üretilir (bkz. `loosePhonePattern()`). Desenin anlamı: "bu 10
 *      hane bu sırayla, aralarında ne olursa olsun geçiyor". Normalize değeri
 *      bu 10 haneyle biten HER kayıt deseni MUTLAKA tutturur (haneler ham
 *      stringte aynı sırada, yalnızca ayraçlarla bölünmüş hâlde bulunur), yani
 *      desen garantili bir üst kümedir — hiçbir gerçek eşleşme kaçmaz.
 *   2. Dönen az sayıda satır PHP'de `PhoneNormalizer` ile yeniden karşılaştırılır
 *      ve yanlış-pozitifler (aynı haneleri farklı yerlerde barındıran uzun
 *      numaralar) elenir.
 *
 * Alternatif (a) "aday kümesini e-posta/isimle daralt, telefonu PHP'de bak"
 * reddedildi: e-postası da ismi de tutmayan, YALNIZCA telefonu aynı olan kayıt
 * — en sık gerçek duplicate biçimlerinden biri — hiç bulunamazdı.
 *
 * ÖLÇEKLENME SINIRI: baştaki joker yüzünden bu sorgu indeks KULLANMAZ, tablo
 * taramasıdır. Tarama veritabanı motorunda kalır (tüm tabloyu PHP'ye çekmekten
 * çok daha ucuz) ve satır başına maliyet birkaç mikrosaniyedir; on binler
 * mertebesindeki `leads`/`contacts` için tek bir duplicate kontrolünde sorun
 * çıkarmaz. Yüz binlere çıkıldığında doğru çözüm bu sınıfı değiştirmek değil,
 * ŞEMAYA `phone_normalized` (generated/stored) kolonu + indeks eklemektir;
 * o zaman 1. adım düz bir eşitlik sorgusuna dönüşür ve 2. adım gereksizleşir.
 * Bu migration bilinçli olarak Faz 6'nın dışında bırakıldı (şema sahipliği
 * başka bir katmanda).
 *
 * E-posta ve ad/soyad karşılaştırmaları SQL'de yapılır: veritabanı
 * `utf8mb4_unicode_ci` — case-insensitive — collation kullandığı için
 * `where('email', $email)` zaten büyük/küçük harf duyarsızdır ve `email`
 * indeksini kullanabilir. Firma adı karşılaştırması PHP'de yapılır (bkz.
 * `sameText()`), çünkü lead'de `company_name` string, contact'ta ise ilişkili
 * `companies.name` durur.
 *
 * BİLİNEN SINIR (F6/H8 çalışmasında ölçüldü, KAPSAM DIŞI — PHASE-AUDIT §4):
 * `utf8mb4_unicode_ci`, Türkçe `İ`~`i`'yi eşit sayıyor ama dotless `I`~`ı`'yı
 * SAYMIYOR (`'Irmak' = 'ırmak' COLLATE utf8mb4_unicode_ci` → 0). Yani
 * SADECE ad/soyad SQL sorgusuna (`where('first_name', ...)`) dayanan, e-posta
 * ya da telefonu OLMAYAN bir aday, "Irmak" / "ırmak" gibi dotless-I farklı
 * yazılmışsa SQL AŞAMASINDA hiç bulunamaz — `sameText()`/`TurkishCase::fold()`
 * bu satıra hiç sıra gelemeden devre dışı kalır. Bu, `sameText()`'in kapsadığı
 * PHP-seviyesi katlama bug'ından (H8) FARKLI bir sınırdır ve collation
 * değişmeden düzelmez; PHASE-AUDIT bilinçli olarak bu fazın dışında bıraktı
 * (bkz. `tests/Feature/Security/TurkishCaseMatchingTest.php`).
 *
 * Soft-deleted kayıtlar Eloquent'in global scope'u sayesinde hiç görünmez —
 * silinmiş bir kaydı "duplicate" diye göstermek kullanıcıyı var olmayan bir
 * çakışmayla uğraştırırdı.
 */
class DuplicateDetector
{
    /**
     * Kullanıcıya gösterilecek en fazla aday sayısı. 500 aday dönmek UI'ı
     * boğar ve kararı kolaylaştırmaz — ilk 20, skora göre en güçlü olanlardır.
     */
    public const MAX_RESULTS = 20;

    /**
     * Kural başına SQL'den çekilecek en fazla satır. Skorlama PHP'de yapıldığı
     * için her kural kendi tavanıyla çalışır: 200 tane telefon yanlış-pozitifi,
     * indeksli e-posta eşleşmesini sonuçtan itemez.
     */
    public const PER_RULE_SCAN_LIMIT = 200;

    public const SCORE_EMAIL = 100;

    public const SCORE_PHONE = 90;

    public const SCORE_NAME_COMPANY = 80;

    public const SCORE_NAME = 50;

    /**
     * Bu skordan itibaren aday `strong`, altında `possible`.
     */
    public const STRONG_THRESHOLD = 80;

    public const LEVEL_STRONG = 'strong';

    public const LEVEL_POSSIBLE = 'possible';

    public const TYPE_LEAD = 'lead';

    public const TYPE_CONTACT = 'contact';

    /**
     * @param  array{email?: ?string, phone?: ?string, first_name?: ?string, last_name?: ?string, company_name?: ?string}  $input
     * @param  int|null  $excludeLeadId  Güncelleme senaryosu: lead kendini duplicate olarak göstermesin.
     * @return Collection<int, array{type: string, id: int, name: string, email: ?string, phone: ?string, company: ?string, score: int, level: string, matched_on: list<string>}>
     */
    public function findCandidates(array $input, ?int $excludeLeadId = null): Collection
    {
        $email = $this->normalizeEmail($input['email'] ?? null);
        $phone = PhoneNormalizer::normalize($input['phone'] ?? null);
        $firstName = $this->normalizeText($input['first_name'] ?? null);
        $lastName = $this->normalizeText($input['last_name'] ?? null);
        $companyName = $this->normalizeText($input['company_name'] ?? null);

        // Ad ya da soyaddan biri eksikse isim kuralı hiç çalışmaz: tek başına
        // "Mehmet" ile eşleşme aramak yüzlerce alakasız aday üretirdi.
        $hasName = $firstName !== null && $lastName !== null;

        // Hiçbir kural çalışamıyorsa BOŞ dön. Bu erken çıkış olmasaydı filtresiz
        // sorgular tüm tabloyu döndürürdü — boş bir formda "herkes duplicate".
        if ($email === null && $phone === null && ! $hasName) {
            return collect();
        }

        /** @var Collection<string, array{model: Lead|Contact, type: string}> $records */
        $records = collect();

        foreach ($this->collectLeads($email, $phone, $firstName, $lastName, $excludeLeadId) as $lead) {
            $records->put(self::TYPE_LEAD.':'.$lead->getKey(), ['model' => $lead, 'type' => self::TYPE_LEAD]);
        }

        foreach ($this->collectContacts($email, $phone, $firstName, $lastName) as $contact) {
            $records->put(self::TYPE_CONTACT.':'.$contact->getKey(), ['model' => $contact, 'type' => self::TYPE_CONTACT]);
        }

        return $records
            ->map(fn (array $record): ?array => $this->score(
                $record['model'],
                $record['type'],
                $email,
                $phone,
                $firstName,
                $lastName,
                $companyName,
                $hasName,
            ))
            ->filter()
            ->values()
            // Skor azalan; eşitlikte tip ve id ile deterministik sıra (aynı
            // girdi her çağrıda aynı sırayı vermeli, yoksa liste titrer).
            ->sortBy([
                ['score', 'desc'],
                ['type', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->take(self::MAX_RESULTS);
    }

    /**
     * @return Collection<int, Lead>
     */
    private function collectLeads(?string $email, ?string $phone, ?string $firstName, ?string $lastName, ?int $excludeLeadId): Collection
    {
        /** @var Collection<int, Lead> $found */
        $found = collect();

        $base = function () use ($excludeLeadId): Builder {
            $query = Lead::query();

            if ($excludeLeadId !== null) {
                $query->whereKeyNot($excludeLeadId);
            }

            return $query;
        };

        if ($email !== null) {
            $found = $found->merge(
                $base()->where('email', $email)->limit(self::PER_RULE_SCAN_LIMIT)->get()
            );
        }

        if ($phone !== null) {
            $pattern = $this->loosePhonePattern($phone);

            $found = $found->merge(
                $base()->where('phone', 'like', $pattern)->limit(self::PER_RULE_SCAN_LIMIT)->get()
            );
        }

        if ($firstName !== null && $lastName !== null) {
            $found = $found->merge(
                $base()
                    ->where('first_name', $firstName)
                    ->where('last_name', $lastName)
                    ->limit(self::PER_RULE_SCAN_LIMIT)
                    ->get()
            );
        }

        return $found;
    }

    /**
     * @return Collection<int, Contact>
     */
    private function collectContacts(?string $email, ?string $phone, ?string $firstName, ?string $lastName): Collection
    {
        /** @var Collection<int, Contact> $found */
        $found = collect();

        // Firma adı hem skorlamada hem çıktıda gerekiyor; N+1 olmasın diye
        // baştan yüklenir.
        $base = fn (): Builder => Contact::query()->with('company:id,name');

        if ($email !== null) {
            $found = $found->merge(
                $base()->where('email', $email)->limit(self::PER_RULE_SCAN_LIMIT)->get()
            );
        }

        if ($phone !== null) {
            $pattern = $this->loosePhonePattern($phone);

            // `contacts` tablosunda iki numara var. `mobile` eşleşmesi de gerçek
            // bir duplicate sinyalidir — kişinin cebini lead formuna yazmak,
            // sabit hattını yazmak kadar sık.
            $found = $found->merge(
                $base()
                    ->where(function (Builder $query) use ($pattern): void {
                        $query->where('phone', 'like', $pattern)
                            ->orWhere('mobile', 'like', $pattern);
                    })
                    ->limit(self::PER_RULE_SCAN_LIMIT)
                    ->get()
            );
        }

        if ($firstName !== null && $lastName !== null) {
            $found = $found->merge(
                $base()
                    ->where('first_name', $firstName)
                    ->where('last_name', $lastName)
                    ->limit(self::PER_RULE_SCAN_LIMIT)
                    ->get()
            );
        }

        return $found;
    }

    /**
     * @param  Lead|Contact  $model
     * @return array{type: string, id: int, name: string, email: ?string, phone: ?string, company: ?string, score: int, level: string, matched_on: list<string>}|null
     */
    private function score(
        $model,
        string $type,
        ?string $email,
        ?string $phone,
        ?string $firstName,
        ?string $lastName,
        ?string $companyName,
        bool $hasName,
    ): ?array {
        /** @var array<string, int> $matches kural adı => skor */
        $matches = [];

        if ($email !== null && $this->normalizeEmail($model->email) === $email) {
            $matches['email'] = self::SCORE_EMAIL;
        }

        if ($phone !== null && $this->phoneMatches($model, $type, $phone)) {
            $matches['phone'] = self::SCORE_PHONE;
        }

        if ($hasName && $this->sameText($model->first_name, $firstName) && $this->sameText($model->last_name, $lastName)) {
            $candidateCompany = $this->companyNameOf($model, $type);

            $matches['name'] = $this->sameText($candidateCompany, $companyName)
                ? self::SCORE_NAME_COMPANY
                : self::SCORE_NAME;
        }

        if ($matches === []) {
            // SQL üst kümesinden gelip PHP doğrulamasını geçemeyen satır
            // (tipik olarak bir telefon yanlış-pozitifi).
            return null;
        }

        $score = max($matches);

        // `matched_on` güçlüden zayıfa sıralanır: kullanıcı listede önce en
        // ikna edici gerekçeyi okur.
        arsort($matches);

        return [
            'type' => $type,
            'id' => (int) $model->getKey(),
            'name' => trim($model->first_name.' '.$model->last_name),
            'email' => $model->email,
            'phone' => $model->phone,
            'company' => $this->companyNameOf($model, $type),
            'score' => $score,
            'level' => $score >= self::STRONG_THRESHOLD ? self::LEVEL_STRONG : self::LEVEL_POSSIBLE,
            'matched_on' => array_keys($matches),
        ];
    }

    /**
     * @param  Lead|Contact  $model
     */
    private function phoneMatches($model, string $type, string $phone): bool
    {
        if (PhoneNormalizer::normalize($model->phone) === $phone) {
            return true;
        }

        return $type === self::TYPE_CONTACT && PhoneNormalizer::normalize($model->mobile) === $phone;
    }

    /**
     * @param  Lead|Contact  $model
     */
    private function companyNameOf($model, string $type): ?string
    {
        if ($type === self::TYPE_LEAD) {
            return $model->company_name;
        }

        return $model->company?->name;
    }

    /**
     * Normalize numaranın haneleri arasına LIKE jokeri serpiştiren desen:
     * `5321112233` girdisi için "içinde 5,3,2,1,1,1,2,2,3,3 haneleri bu sırayla
     * geçen her string" anlamına gelen bir desen üretilir.
     *
     * Yalnızca rakam içerdiği için LIKE özel karakteri (`%`, `_`, `\`) kaçırma
     * ihtiyacı yoktur — desendeki tek özel karakter buranın kendi ürettiğidir.
     */
    private function loosePhonePattern(string $normalizedPhone): string
    {
        $wildcard = '%';

        return $wildcard.implode($wildcard, str_split($normalizedPhone)).$wildcard;
    }

    /**
     * KARAR (F6/H8): burada `TurkishCase::fold()` DEĞİL, kasıtlı olarak
     * `mb_strtolower` kullanılmaya devam ediliyor.
     *
     * E-posta local-part'ı sözleşme gereği (RFC 5321/5322) pratikte ASCII'dir;
     * gerçek bir e-posta adresinde Türkçe `İ` (U+0130) neredeyse hiç görülmez
     * — ASCII `I`/`i` zaten `mb_strtolower` ile de doğru (Türkçe'ye özgü
     * belirsizlik olmadan) küçülür, bozukluk yalnız `İ`'de var. Yani bu bug
     * gerçek e-posta verisinde pratikte HİÇ tetiklenmiyor.
     *
     * Ayrıca `TurkishCase::fold()` BİLEREK agresif (ı/i/I/İ hepsini 'i'ye
     * indiriyor) — isim/firma gibi serbest metin EŞLEŞTİRMESİ için doğru
     * tercih ama e-posta'nın anlamı TAM KİMLİKTİR: iki e-posta adresi ya
     * birebir aynı kutuya gider ya da gitmez, "yaklaşık eşleşme" burada
     * anlamsız/yanıltıcı olur (agresif katlama teorik olarak birbirinden
     * TAMAMEN farklı iki geçerli local-part'ı - örn. içinde harf yerine
     * rakam/sembol geçen edge-case'ler - gereksiz yere aynı sayabilir).
     * Bu yüzden e-posta karşılaştırması Türkçe katlamadan bağımsız, standart
     * `mb_strtolower` ile bırakıldı.
     */
    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Baş/son boşluk atılır, aradaki boşluk dizileri teke indirilir:
     * "Yılmaz  Holding " ile "Yılmaz Holding" aynı firmadır.
     */
    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * İki serbest metnin (isim / firma adı) "tam eşleşme" sayılıp sayılmadığı.
     * Her iki taraf da doluysa ve normalize edilmiş küçük harfli hâlleri
     * birebir aynıysa eşleşir. Bulanık (Levenshtein vb.) karşılaştırma bilerek
     * YOK: sözleşme "tam eşleşme" diyor; benzerlik eşiği ayarlamak yanlış
     * pozitifleri patlatır ve skor tablosunu belirsizleştirir.
     *
     * Küçültme `mb_strtolower` DEĞİL `TurkishCase::fold()` ile yapılır (F6/H8
     * düzeltmesi) — aksi halde "İhsan" ile "ihsan" birebir eşit çıkmıyordu
     * (bkz. `TurkishCase` sınıfının başındaki gerekçe). Tercih EDİLEN agresif
     * katlama burada özellikle isabetli: isim/firma serbest metindir ve
     * yanlış-pozitif (fazladan bir "possible" uyarısı) yanlış-negatiften
     * (aynı kişi ikinci kez kaydedilir) çok daha ucuzdur.
     */
    private function sameText(?string $left, ?string $right): bool
    {
        $left = $this->normalizeText($left);
        $right = $this->normalizeText($right);

        if ($left === null || $right === null) {
            return false;
        }

        return TurkishCase::fold($left) === TurkishCase::fold($right);
    }
}
