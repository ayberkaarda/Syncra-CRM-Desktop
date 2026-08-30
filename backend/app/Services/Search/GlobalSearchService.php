<?php

namespace App\Services\Search;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Search\SearchResult;
use App\Support\TurkishCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Global komut paleti araması (`GET /api/search?q=`) — Faz 14 / İz F / C1.
 * Sözleşme: `docs/PHASE-INTL.md` §3 (İz F), güvenlik kısıtları
 * `docs/PHASE-AUDIT.md` §5.4 (C1 maddesi).
 *
 * ==========================================================================
 * NEDEN AYRI BİR "GLOBAL ARAMA" KATMANI — REPOSITORY'LER NEDEN DEĞİŞTİRİLMEDİ
 * ==========================================================================
 * Bu servis 7 modülün kendi `*Repository::applyFilters()` metodundaki `q`
 * arama semantiğini (AYNI sütunlar, AYNI `LIKE` davranışı) BİREBİR tekrar
 * eder — ikinci bir arama anlayışı İCAT EDİLMEDİ (bkz. her `search*()`
 * metodunun üstündeki "kaynak" yorumu). Repository'lerin `paginate()`'i
 * KULLANILMADI çünkü: (1) o metotlar sayfalama/sıralama/onlarca ek filtre
 * taşıyor, burada sadece "ilk N eşleşme" gerekiyor; (2) repository'lerin
 * `with()` seti liste ekranı için tasarlanmış (etiketler, custom field
 * değerleri, sayaçlar) — komut paleti sonucu yalnızca başlık+alt satır
 * gösterir, bu ekstra ilişkileri çekmek saf israf olurdu.
 *
 * ==========================================================================
 * KARAR — İZİNSİZ MODÜLÜN ANAHTARI YANITTA HİÇ OLMAZ (boş dizi bile değil)
 * ==========================================================================
 * PHASE-AUDIT §5.4: "izinsiz modül sonuç kümesinde HİÇ görünmemeli (sayı/
 * varlık sızıntısı dahil)". `search()` bunu şöyle uygular: döngü her modül
 * için ÖNCE `Gate::allows('viewAny', ModelClass)` sorar; izin yoksa o
 * modülün anahtarı `$results` dizisine HİÇ EKLENMEZ (ne boş dizi, ne
 * `null`, ne de bir "count" alanı). Neden "boş dizi dönsek daha tutarlı
 * olur" YAPILMADI: bir istemci `deals: []` görürse bile "bu sistemde
 * `deals` diye bir modül var ve ben göremiyorum" bilgisini edinir — bu da
 * bir varlık sızıntısıdır (Destek Temsilcisi'nin deals/quotes/leads
 * modüllerinin var olduğunu, sadece kendisinin göremediğini öğrenmesi gibi).
 * Anahtarın TAMAMEN yokluğu, izinsiz kullanıcı için modülün API
 * sözleşmesinde hiç var olmadığı yanılsamasını korur. Bunu kilitleyen test:
 * `tests/Feature/Security/SearchAuthorizationTest.php`.
 *
 * Yetkilendirme her modül için `Gate::allows('viewAny', ...)` ile yapılır —
 * bu, ilgili Policy'nin (DealPolicy/LeadPolicy/.../UserPolicy) `viewAny()`
 * metoduna, dolayısıyla `user->can('<modül>.view')` iznine yönlenir. Kendi
 * izin/rol mantığı İCAT EDİLMEDİ; mevcut Policy katmanına devredildi.
 *
 * ==========================================================================
 * YATAY OKUMA (Faz 13, Model C) — SAHİPLİK FİLTRESİ BİLEREK YOK
 * ==========================================================================
 * `DealPolicy`/`LeadPolicy`/`TicketPolicy` gibi Policy'lerin `view()` metodu
 * BİLEREK düzdür (bkz. `ChecksRecordOwnership` dokümanı ve PHASE-AUDIT §1
 * "Model C" notu): modül `.view` izni olan bir kullanıcı o modüldeki TÜM
 * kayıtları okuyabilir, sahiplik aranmaz. Bu servis o kararı OLDUĞU GİBİ
 * miras alır — aşağıdaki `search*()` sorgularının hiçbirinde `owner_id`/
 * `assigned_to` filtresi YOKTUR. Bu bilinçli bir tutarlılıktır, unutulmuş
 * bir filtre değildir.
 *
 * ==========================================================================
 * PERFORMANS — N+1 YOK, HAM SQL YOK, BÜTÇELİ SONUÇ SETİ
 * ==========================================================================
 * Her modül İÇİN TEK sorgu (`limit()` + gerekiyorsa TEK `with()`); modül
 * sayısı arttıkça toplam sorgu sayısı DOĞRUSAL büyür (7 modül = en fazla 7
 * sorgu + izinli olmayanlar hiç çalışmaz), kayıt sayısına göre DEĞİL. Ham
 * SQL (`whereRaw`/`DB::raw`) hiç kullanılmadı; tüm `LIKE` koşulları
 * parametreli `where(...)`'dır (bkz. `likePattern()`).
 */
class GlobalSearchService
{
    /**
     * Modül başına en fazla sonuç. Bu bir komut paleti/hızlı-atlama
     * arayüzüdür (Attio "Ctrl-K" eşdeğeri) — bir liste ekranı DEĞİL, bu
     * yüzden sayfalama YOK (görev tanımı gereği). 5, bir açılır listede
     * kaydırma gerektirmeden tek bakışta taranabilecek, yine de "bu terim
     * için kaç aday var" hissini veren makul bir üst sınırdır (Attio/
     * Linear/Notion komut paletlerinin tipik "kategori başına ilk birkaç
     * sonuç" deseniyle aynı büyüklük mertebesi).
     */
    private const PER_MODULE_LIMIT = 5;

    /**
     * Toplam sonuç tavanı. Bugün MATEMATİKSEL OLARAK devre dışıdır:
     * 7 modül x 5 (PER_MODULE_LIMIT) = 35 = bu sabit — yani bu görevde
     * TAM da yeterli, hiçbir modülü aç açık kısıtlamıyor (bir kullanıcının
     * izinli olduğu HER modül daima kendi 5 sonucunu alabiliyor). Yine de
     * BİLEREK ayrı bir sabit olarak tutuluyor: modül listesi ileride
     * genişlerse (Faz 14 sonrası yeni bir modül eklenirse) ya da
     * PER_MODULE_LIMIT büyütülürse, yanıt boyutu yine de bu tavanla
     * sınırlı kalır — "toplam sınır" gereksinimi (görev tanımı) bir
     * unutulmuş TODO değil, kasıtlı bir ileriye dönük güvenlik/performans
     * bariyeri olarak burada duruyor. `search()` bütçeyi modül sırasına
     * göre PAYLAŞTIRIR: bir modülün eşleşmesi PER_MODULE_LIMIT'in altında
     * kalırsa (ör. yalnızca 1 eşleşen deal), kullanılmayan pay bir SONRAKİ
     * modüle devreder — bu yüzden pratikte hiçbir izinli modül "bütçe
     * tükendi" yüzünden aç kalmaz (bkz. `search()` içindeki `$remaining`).
     */
    private const TOTAL_LIMIT = 35;

    /**
     * Aranacak modüllerin KISA AD -> Model FQCN beyaz listesi.
     *
     * `App\Support\MorphTargets` İLE AYNI DESEN (sabit dizi lookup,
     * `class_exists()` yok, string birleştirme yok) ama AYRI bir sabit:
     * MorphTargets yalnızca `taskable_type`/`activityable_type` morph
     * kolonlarının hedeflediği 5 modeli listeler (Quote ve User bunlardan
     * BİRİ DEĞİL — ikisi de morph edilebilir bir ilişkinin hedefi değil).
     * MorphTargets'a Quote/User eklemek onun kapsamını (morph whitelist)
     * bu servisin kapsamıyla (arama whitelist) gereksiz yere birbirine
     * bağlardı; iki whitelist farklı sorunlar çözüyor, ayrı kalmalı.
     *
     * FQCN dışarı (response gövdesine) SIZMAZ: `search*()` metotlarının
     * hepsi `SearchResult::$type` alanına bu dizinin KISA ADINI yazar,
     * `::class` değerini değil (bkz. her metodun `type:` argümanı).
     *
     * SIRA görev tanımındaki modül önceliğiyle (deal, lead, contact,
     * company, quote, ticket, user) birebir aynı — hem `TOTAL_LIMIT`
     * bütçe paylaşımının sırası hem de yanıttaki anahtar sırası bunu
     * yansıtır.
     *
     * @var array<string, class-string>
     */
    private const MODULES = [
        'deal' => Deal::class,
        'lead' => Lead::class,
        'contact' => Contact::class,
        'company' => Company::class,
        'quote' => Quote::class,
        'ticket' => Ticket::class,
        'user' => User::class,
    ];

    /**
     * Kısa ad -> yanıttaki çoğul anahtar. Mevcut modül isimlendirmesiyle
     * (izin adları `deals.view`, rota segmentleri `/deals`, ...) tutarlı.
     *
     * @var array<string, string>
     */
    private const RESPONSE_KEYS = [
        'deal' => 'deals',
        'lead' => 'leads',
        'contact' => 'contacts',
        'company' => 'companies',
        'quote' => 'quotes',
        'ticket' => 'tickets',
        'user' => 'users',
    ];

    /**
     * @return array<string, array<int, SearchResult>>
     */
    public function search(User $actor, string $rawTerm): array
    {
        $pattern = $this->likePattern($this->normalize($rawTerm));

        $results = [];
        $remaining = self::TOTAL_LIMIT;

        foreach (self::MODULES as $shortName => $modelClass) {
            // KARAR: izin yoksa modül döngüden TAMAMEN atlanır -> anahtar
            // yanıtta hiç oluşmaz (gerekçe: sınıf dokümanındaki "İZİNSİZ
            // MODÜLÜN ANAHTARI..." başlığı).
            if (! Gate::forUser($actor)->allows('viewAny', $modelClass)) {
                continue;
            }

            $take = min(self::PER_MODULE_LIMIT, max(0, $remaining));

            $items = $take > 0
                ? $this->searchModule($shortName, $pattern, $take)
                : [];

            $results[self::RESPONSE_KEYS[$shortName]] = $items;

            // Kullanılmayan pay bir sonraki (izinli) modüle devreder —
            // bkz. TOTAL_LIMIT dokümanı.
            $remaining -= count($items);
        }

        return $results;
    }

    /**
     * @return array<int, SearchResult>
     */
    private function searchModule(string $shortName, string $pattern, int $limit): array
    {
        return match ($shortName) {
            'deal' => $this->searchDeals($pattern, $limit),
            'lead' => $this->searchLeads($pattern, $limit),
            'contact' => $this->searchContacts($pattern, $limit),
            'company' => $this->searchCompanies($pattern, $limit),
            'quote' => $this->searchQuotes($pattern, $limit),
            'ticket' => $this->searchTickets($pattern, $limit),
            'user' => $this->searchUsers($pattern, $limit),
        };
    }

    /**
     * Kaynak: `App\Repositories\DealRepository::applyFilters()` — AYNI iki
     * alan (`title`, `company.name`), AYNI OR gruplaması.
     *
     * @return array<int, SearchResult>
     */
    private function searchDeals(string $pattern, int $limit): array
    {
        $rows = Deal::query()
            ->select(['id', 'title', 'company_id'])
            ->with(['company:id,name'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('title', 'like', $pattern)
                    ->orWhereHas('company', function (Builder $query) use ($pattern): void {
                        $query->where('name', 'like', $pattern);
                    });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Deal $deal) => new SearchResult(
            type: 'deal',
            id: $deal->id,
            title: $deal->title,
            subtitle: $deal->company?->name,
            link: "/deals/{$deal->id}",
        ))->all();
    }

    /**
     * Kaynak: `App\Repositories\LeadRepository::baseQuery()` — AYNI dört
     * alan (`first_name`, `last_name`, `email`, `company_name`).
     *
     * @return array<int, SearchResult>
     */
    private function searchLeads(string $pattern, int $limit): array
    {
        $rows = Lead::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'company_name'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('first_name', 'like', $pattern)
                    ->orWhere('last_name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('company_name', 'like', $pattern);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(function (Lead $lead) {
            $fullName = trim("{$lead->first_name} {$lead->last_name}");

            return new SearchResult(
                type: 'lead',
                id: $lead->id,
                title: $fullName !== '' ? $fullName : $lead->email,
                subtitle: $lead->company_name ?? $lead->email,
                link: "/leads/{$lead->id}",
            );
        })->all();
    }

    /**
     * Kaynak: `App\Repositories\ContactRepository::paginate()` — AYNI altı
     * alan (`first_name`, `last_name`, `email`, `phone`, `mobile`, `position`).
     *
     * @return array<int, SearchResult>
     */
    private function searchContacts(string $pattern, int $limit): array
    {
        $rows = Contact::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'position', 'company_id'])
            ->with(['company:id,name'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('first_name', 'like', $pattern)
                    ->orWhere('last_name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('phone', 'like', $pattern)
                    ->orWhere('mobile', 'like', $pattern)
                    ->orWhere('position', 'like', $pattern);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Contact $contact) => new SearchResult(
            type: 'contact',
            id: $contact->id,
            title: $contact->full_name,
            subtitle: $contact->company?->name ?? $contact->email,
            link: "/contacts/{$contact->id}",
        ))->all();
    }

    /**
     * Kaynak: `App\Repositories\CompanyRepository::paginate()` — AYNI dört
     * alan (`name`, `email`, `website`, `industry`).
     *
     * @return array<int, SearchResult>
     */
    private function searchCompanies(string $pattern, int $limit): array
    {
        $rows = Company::query()
            ->select(['id', 'name', 'email', 'website', 'industry'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('website', 'like', $pattern)
                    ->orWhere('industry', 'like', $pattern);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Company $company) => new SearchResult(
            type: 'company',
            id: $company->id,
            title: $company->name,
            subtitle: $company->industry ?? $company->email,
            link: "/companies/{$company->id}",
        ))->all();
    }

    /**
     * Kaynak: `App\Repositories\QuoteRepository::applyFilters()` — AYNI iki
     * alan (`quote_number`, `title`).
     *
     * @return array<int, SearchResult>
     */
    private function searchQuotes(string $pattern, int $limit): array
    {
        $rows = Quote::query()
            ->select(['id', 'quote_number', 'title'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('quote_number', 'like', $pattern)
                    ->orWhere('title', 'like', $pattern);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Quote $quote) => new SearchResult(
            type: 'quote',
            id: $quote->id,
            title: $quote->title,
            // quote_number HER ZAMAN dolu ve benzersizdir (bkz. QuoteRepository
            // üretim mantığı) — company adı yerine bunu ikincil satır olarak
            // seçmek, arama TAM DA numaradan yapıldığında eşleşen değeri
            // kullanıcıya geri gösterir.
            subtitle: $quote->quote_number,
            link: "/quotes/{$quote->id}",
        ))->all();
    }

    /**
     * Kaynak: `App\Repositories\TicketRepository::applyFilters()` — AYNI üç
     * alan (`ticket_number`, `subject`, `description`).
     *
     * @return array<int, SearchResult>
     */
    private function searchTickets(string $pattern, int $limit): array
    {
        $rows = Ticket::query()
            ->select(['id', 'ticket_number', 'subject', 'description'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('ticket_number', 'like', $pattern)
                    ->orWhere('subject', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Ticket $ticket) => new SearchResult(
            type: 'ticket',
            id: $ticket->id,
            title: $ticket->subject,
            subtitle: $ticket->ticket_number,
            link: "/tickets/{$ticket->id}",
        ))->all();
    }

    /**
     * Kaynak: `App\Repositories\UserRepository::paginate()` — AYNI iki alan
     * (`name`, `email`).
     *
     * LİNK NOTU: `frontend/src/router.tsx` bir `users/:id` DETAY rotası
     * TAŞIMIYOR (Kullanıcılar ekranı liste/modal tabanlı — bkz. router
     * dosyası, `UsersPage` tek başına, alt rotası yok). Bu yüzden diğer
     * altı modülün aksine `link` bir kayıt detayına değil, listeye
     * (`/users`) gider; frontend şeridi (bu görevin dışında) isterse bu
     * yolu bir `?highlight=` gibi bir sorgu parametresiyle
     * zenginleştirebilir — sözleşme bunu YASAKLAMAZ, yalnızca temel yolu
     * sabitler.
     *
     * @return array<int, SearchResult>
     */
    private function searchUsers(string $pattern, int $limit): array
    {
        $rows = User::query()
            ->select(['id', 'name', 'email'])
            ->where(function (Builder $query) use ($pattern): void {
                $query->where('name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (User $user) => new SearchResult(
            type: 'user',
            id: $user->id,
            title: $user->name,
            subtitle: $user->email,
            link: '/users',
        ))->all();
    }

    /**
     * ==========================================================================
     * TÜRKÇE ARAMA — TurkishCase::fold() KULLANIMI VE BİLİNEN SINIR
     * ==========================================================================
     * Arama terimi `TurkishCase::fold()` ile katlanır (İ/I/ı/i hepsi tek bir
     * `i`'ye, kalan her şey `mb_strtolower`). Bu, `DuplicateDetector` ve chat
     * mention aramasıyla AYNI mekanizma (Faz 13 / F6 / H8) — ikinci bir
     * eşleştirme kuralı İCAT EDİLMEDİ.
     *
     * Repository'lerin kendi `q` aramalarının aksine (onlar terimi OLDUĞU
     * GİBİ `LIKE`'a verir, tamamen `utf8mb4_unicode_ci` collation'ın
     * case-insensitivity'sine güvenir) burada terim ÖNCE PHP'de katlanıyor.
     * Bunun nedeni collation'ın Türkçe kuralını UYGULAMAMASI: `İ`=`i` sayar
     * ama `I`=`ı` SAYMAZ (bkz. `TurkishCase` sınıf dokümanı, PHASE-AUDIT
     * §4 F6). PHP tarafında katlama, en azından SORGU TERİMİNİN dört
     * biçiminin (İ/I/ı/i) hepsini AYNI ASCII `i`'ye indirger.
     *
     * BİLİNEN SINIR (bu fazın kapsamı DIŞINDA, PHASE-AUDIT §4 F6'da
     * belgeli): katlama yalnızca SORGU TERİMİNE uygulanıyor, SÜTUN
     * DEĞERİNE değil (sütuna `LOWER()`/özel bir ifade uygulamak bu projenin
     * "ham SQL yok" ilkesini ve mevcut repository `q` semantiğini ihlal
     * ederdi). Sonuç: kullanıcı "ırmak" yazıp DB'de dotless büyük `I` ile
     * yazılmış "Irmak" satırını ararsa, collation bu ikisini eşit SAYMADIĞI
     * için (yukarıdaki bilinen sınır) SQL ön-filtresi satırı hiç
     * DÖNDÜRMEYEBİLİR — PHP katlaması burada devreye giremez çünkü satır
     * veritabanından PHP'ye hiç ULAŞMAZ. Bu, isim eşleştirmedeki aynı
     * bilinen sınırın arama uç noktasına yansımasıdır; kapatma yolu (SQL'de
     * garantili üst küme + PHP'de kesin doğrulama) Faz 15 adayı olarak
     * kayıtlı (PROGRESS "Bir Sonraki Adım") — burada TEKRARLANMADI.
     */
    private function normalize(string $term): string
    {
        return trim(TurkishCase::fold($term) ?? '');
    }

    /**
     * ==========================================================================
     * JOKER KARAKTER KAÇIŞI — `%` / `_` kullanıcı girdisinde OLABİLİR
     * ==========================================================================
     * Kullanıcı `%` ya da `_` yazarsa (MySQL LIKE'ın kendi joker
     * karakterleri) ve bunlar kaçırılmadan doğrudan gömülürse, arama
     * kullanıcının niyetinden çok daha geniş bir kümeyle eşleşir (ör. `_`
     * "herhangi bir karakter" anlamına gelir — pratikte tüm tabloyu
     * döndürebilir). SIRA ÖNEMLİDİR: önce ters eğik çizgi (`\`) kaçırılır
     * (MySQL LIKE'ın VARSAYILAN kaçış karakteri budur), SONRA `%`/`_`
     * kaçırılır — sıra tersine çevrilirse `%`/`_` kaçışının eklediği `\`
     * karakterleri bir daha kaçırılıp çift kaçışa (`\\%`) yol açar.
     * `ESCAPE` cümlesi elle yazılmıyor çünkü MySQL'in LIKE VARSAYILANI zaten
     * ters eğik çizgidir — bu da `whereRaw` gerektirmeden (ham SQL yok
     * garantisi korunarak) standart `where(..., 'like', ...)` ile çalışır.
     */
    private function likePattern(string $term): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return "%{$escaped}%";
    }
}
