<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Quotes\QuoteCalculator;
use App\Sync\SyncVersionBackfill;
use Carbon\CarbonImmutable;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Geliştirme/demo ortamı için gerçekçi ve kendi içinde tutarlı örnek veri.
 *
 * Tasarım notları:
 *  - Üretimde ASLA çalışmaz: DatabaseSeeder ortam kontrolü yapar, burada da
 *    ikinci bir güvenlik kontrolü vardır.
 *  - Performans için factory yerine toplu `insert()` kullanılır. Tüm iş
 *    kuralları (aşama/durum uyumu, teklif matematiği, SLA, okunmamış mesaj
 *    sayacı, morph geçerliliği) burada merkezî olarak kurulur ve sonunda
 *    `assertConsistency()` ile doğrulanır; tutarsızlık varsa exception fırlar
 *    ve transaction tamamen geri alınır.
 *  - Faker sabit seed ile çalışır, böylece demo veri tekrar üretilebilirdir.
 */
class DemoDataSeeder extends Seeder
{
    /** Tüm demo hesapların ortak şifresi. */
    public const PASSWORD = 'Demo!2026Syncra';

    private const FAKER_SEED = 20260823;

    /** @var list<string> */
    private const FIRST_NAMES_MALE = [
        'Ahmet', 'Mehmet', 'Mustafa', 'Ali', 'Hüseyin', 'Hasan', 'İbrahim', 'Osman', 'Yusuf', 'Murat',
        'Emre', 'Burak', 'Kerem', 'Cem', 'Onur', 'Serkan', 'Barış', 'Tolga', 'Umut', 'Volkan',
    ];

    /** @var list<string> */
    private const FIRST_NAMES_FEMALE = [
        'Ayşe', 'Fatma', 'Emine', 'Hatice', 'Zeynep', 'Elif', 'Merve', 'Büşra', 'Selin', 'Ceren',
        'Gizem', 'İrem', 'Pınar', 'Seda', 'Şeyma', 'Tuğçe', 'Yasemin', 'Esra', 'Melis', 'Nazlı',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'Yılmaz', 'Kaya', 'Demir', 'Şahin', 'Çelik', 'Yıldız', 'Yıldırım', 'Öztürk', 'Aydın', 'Özdemir',
        'Arslan', 'Doğan', 'Kılıç', 'Aslan', 'Çetin', 'Kara', 'Koç', 'Kurt', 'Özkan', 'Şimşek',
        'Polat', 'Korkmaz', 'Erdoğan', 'Aksoy', 'Turan', 'Bulut', 'Güneş', 'Tekin', 'Sarı', 'Avcı',
    ];

    /** @var list<string> */
    private const COMPANY_NAMES = [
        'Anadolu Bilişim Sistemleri A.Ş.', 'Marmara Lojistik Ltd. Şti.', 'Ege Tekstil Sanayi A.Ş.',
        'Toros Enerji A.Ş.', 'Boğaziçi Yazılım Ltd. Şti.', 'Kapadokya Turizm A.Ş.',
        'Trakya Gıda Sanayi A.Ş.', 'Selçuk İnşaat Taahhüt Ltd. Şti.', 'Karadeniz Otomotiv A.Ş.',
        'Akdeniz Sağlık Hizmetleri A.Ş.', 'İç Anadolu Makine Ltd. Şti.', 'Bosphorus Finans Danışmanlık A.Ş.',
        'Doğu Kimya Sanayi A.Ş.', 'Yıldız Perakende Grubu A.Ş.', 'Nova Eğitim Kurumları Ltd. Şti.',
        'Atlas Sigorta Aracılık A.Ş.', 'Pamukkale Seramik A.Ş.', 'Efes Ambalaj Ltd. Şti.',
        'Zirve Mühendislik A.Ş.', 'Vega Telekomünikasyon A.Ş.', 'Optimum Danışmanlık Ltd. Şti.',
        'Meridyen Medikal A.Ş.', 'Safir Mobilya Sanayi Ltd. Şti.', 'Kuzey Denizcilik A.Ş.',
        'Prizma Reklam Ajansı Ltd. Şti.',
    ];

    /** @var list<string> */
    private const CITIES = [
        'İstanbul', 'Ankara', 'İzmir', 'Bursa', 'Antalya', 'Adana', 'Konya', 'Gaziantep', 'Kayseri', 'Denizli',
    ];

    /** @var list<string> */
    private const INDUSTRIES = [
        'Bilişim', 'İnşaat', 'Tekstil', 'Otomotiv', 'Gıda', 'Sağlık', 'Lojistik', 'Enerji',
        'Turizm', 'Eğitim', 'Finans', 'Perakende',
    ];

    /** @var list<string> */
    private const POSITIONS = [
        'Genel Müdür', 'Satın Alma Müdürü', 'Proje Yöneticisi', 'İnsan Kaynakları Uzmanı',
        'Bilgi İşlem Müdürü', 'Muhasebe Müdürü', 'Pazarlama Uzmanı', 'Operasyon Müdürü',
        'Teknik Destek Uzmanı', 'Yönetim Kurulu Üyesi',
    ];

    /** @var list<string> */
    private const DEAL_TITLES = [
        'CRM Kurulum Projesi', 'Yıllık Lisans Yenileme', 'Donanım Tedariki', 'Bakım Anlaşması',
        'Danışmanlık Hizmeti', 'Yazılım Geliştirme Projesi', 'Bulut Migrasyonu', 'Eğitim Paketi',
        'Entegrasyon Çalışması', 'Altyapı Modernizasyonu',
    ];

    /** @var list<string> */
    private const PRODUCT_NAMES = [
        'CRM Kullanıcı Lisansı', 'Sunucu Bakım Paketi', 'Bulut Depolama 1 TB', 'Yerinde Kurulum Hizmeti',
        'Kullanıcı Eğitimi (Günlük)', 'Mobil Uygulama Modülü', 'Entegrasyon Danışmanlığı',
        'SMS Paketi 10.000', 'E-Fatura Entegrasyonu', 'Yedekleme Hizmeti',
        'Raporlama Modülü', 'Yetkilendirme Modülü', 'API Erişim Paketi', 'Öncelikli Destek Paketi',
        'Veri Aktarım Hizmeti', 'Özel Geliştirme (Adam/Gün)', 'Sanal Sunucu (Aylık)',
        'Güvenlik Denetimi', 'Performans Optimizasyonu', 'Çağrı Merkezi Modülü',
    ];

    /**
     * Aktivite tipleri — bkz. `StoreActivityRequest::rules()` (`'type' => Rule::in([...])`,
     * aynı küme `UpdateActivityRequest`/`IndexActivityRequest`'te de tekrarlanır; repo genelinde
     * `ActivityType` için ortak bir enum/sabit YOK, üç request sınıfında ayrı ayrı literaldir).
     * Seed verisinin backend validasyonunun kabul ETMEDİĞİ bir değer üretmesi başlı başına bir
     * hatadır — Faz 14 denetiminde `'visit'` bu şekilde DB'ye sızmış ve `ActivityTypeBadge`'i
     * çökertmişti (backend `'visit'`i zaten reddediyor, yalnız seed bulk-insert'i doğrulamayı
     * bypass ettiği için mümkün oldu). Bu sabit `ActivityApiTest`teki regresyon testi tarafından
     * doğrudan okunur — kilidi burada tut, `$types` içine literal EKLEME.
     *
     * @var list<string>
     */
    private const ACTIVITY_TYPES = ['call', 'email', 'meeting', 'note'];

    private Generator $faker;

    private CarbonImmutable $now;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> Satış rolündeki kullanıcılar (müdürler + temsilciler). */
    private array $salesUserIds = [];

    private int $supportUserId = 0;

    private int $adminUserId = 0;

    /** @var list<int> */
    private array $companyIds = [];

    /** @var array<int, CarbonImmutable> */
    private array $companyCreatedAt = [];

    /** @var array<int, list<int>> companyId => contactIds */
    private array $companyContacts = [];

    /** @var list<int> */
    private array $contactIds = [];

    /** @var array<int, CarbonImmutable> */
    private array $contactCreatedAt = [];

    /** @var array<int, array<string, mixed>> */
    private array $contactInfo = [];

    /** @var array<string, array<string, mixed>> slug => stage */
    private array $stages = [];

    /** @var list<int> */
    private array $dealIds = [];

    /** @var array<int, CarbonImmutable> */
    private array $dealCreatedAt = [];

    /** @var array<int, array<string, mixed>> */
    private array $dealInfo = [];

    /** @var list<int> */
    private array $leadIds = [];

    /** @var array<int, CarbonImmutable> */
    private array $leadCreatedAt = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var array<int, array<string, mixed>> */
    private array $productInfo = [];

    /** @var list<int> */
    private array $quoteIds = [];

    /** @var list<int> */
    private array $ticketIds = [];

    /** @var array<int, CarbonImmutable> */
    private array $ticketCreatedAt = [];

    /** @var list<int> */
    private array $tagIds = [];

    /** @var list<int> attachable'ı boş bırakılmış, mesajlara iliştirilecek ekler. */
    private array $freeAttachmentIds = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DemoDataSeeder üretim ortamında çalıştırılamaz — atlandı.');

            return;
        }

        if (Company::withTrashed()->exists()) {
            $this->command?->warn('companies tablosu dolu — demo veri zaten üretilmiş, DemoDataSeeder atlandı.');

            return;
        }

        $this->faker = FakerFactory::create('tr_TR');
        $this->faker->seed(self::FAKER_SEED);
        $this->now = CarbonImmutable::now();

        $started = microtime(true);

        // Audit trail susturması: demo veri üretimi ~600 modeli create ettiği için
        // spatie/laravel-activitylog her biri için bir `created` satırı yazar ve
        // Loglar sayfası daha ilk açılışta çöple dolardı. Bu kayıtlar gerçek bir
        // kullanıcı eylemini temsil etmediği için susturuluyor.
        //
        // SIRALAMA — `withoutLogs` EN DIŞTA, `DB::transaction` onun İÇİNDE:
        //  1) Log durumu bir PHP bellek bayrağıdır, veritabanı durumu değil; bir
        //     rollback onu geri almaz. Geri açma işini en dıştaki `finally`ye
        //     bırakmak, transaction başarısız olsa bile bayrağın kesinlikle
        //     eski haline dönmesini garanti eder.
        //  2) `DB::transaction` deadlock'ta closure'ı yeniden dener; dıştaki
        //     sarmalayıcı her denemeyi kapsar, içerideki olsaydı her denemede
        //     bayrak açılıp kapanırdı.
        //  3) Commit anında tetiklenen `afterCommit` model event'leri
        //     transaction closure'ının DIŞINDA çalışır; `withoutLogs` içeride
        //     olsaydı bu event'lerden doğan audit satırları kaçardı.
        // Çekirdek seeder'lar (roller, süper admin, pipeline, ayarlar, özel
        // alanlar) bilerek sarmalanmadı — ürettikleri birkaç satır kurulum izi
        // olarak faydalıdır.
        activity()->withoutLogs(function (): void {
            DB::transaction(function (): void {
                $this->loadStages();
                $this->seedUsers();
                $this->seedCompanies();
                $this->seedContacts();
                $this->seedDeals();
                $this->seedLeads();
                $this->seedProducts();
                $this->seedQuotes();
                $this->seedTickets();
                $this->seedTasks();
                $this->seedActivities();
                $this->seedTags();
                $this->seedAttachments();
                $this->seedConversations();
                $this->seedCustomFieldValues();
                $this->seedLogs();

                /*
                 * Faz F1 — masaüstü senkron sürüm damgası (protokol §2.2/§2.6).
                 *
                 * Bu seeder BİLEREK `bulkInsert()` kullanıyor (bkz. :31 —
                 * performans için factory yerine toplu insert). Toplu insert
                 * HİÇBİR Eloquent model event'i üretmez, dolayısıyla
                 * SyncVersionObserver bu ~600 satırın hiçbirini görmez ve
                 * hepsi `sync_version = 0` ile kalır. Sıfır, bootstrap
                 * cursor'ının kendisidir ve pull sorgusu `> cursor` olduğu için
                 * o satırlar bir masaüstü istemciye ASLA gitmezdi: demo veriyle
                 * kurulmuş bir sistem, masaüstünde bomboş görünürdü.
                 *
                 * Seeder'ı Eloquent'e çevirmek REDDEDİLDİ (protokol §2.2):
                 * bu dosyanın kendi tasarım kararını bozar. Tek seferlik
                 * backfill hem daha ucuz hem de sonradan eklenen bir tablo
                 * için unutulması imkânsız.
                 *
                 * Transaction'ın İÇİNDE: yarım versiyonlanmış bir demo veri
                 * seti, hiç versiyonlanmamış olandan daha kötüdür.
                 */
                SyncVersionBackfill::run();

                $this->assertConsistency();
            });
        });

        $this->summary(microtime(true) - $started);
    }

    private function loadStages(): void
    {
        $stages = DB::table('pipeline_stages')->orderBy('position')->get();

        if ($stages->isEmpty()) {
            throw new RuntimeException('PipelineStageSeeder çalışmadan DemoDataSeeder çalıştırılamaz.');
        }

        foreach ($stages as $stage) {
            $this->stages[$stage->slug] = [
                'id' => (int) $stage->id,
                'probability' => (int) $stage->probability,
                'is_won' => (bool) $stage->is_won,
                'is_lost' => (bool) $stage->is_lost,
            ];
        }
    }

    /**
     * 8 demo kullanıcı (Super Admin hariç). Şifre tek bir bcrypt hash'inden
     * türetilir; `must_change_password=false` olduğu için demo hesaplarla
     * doğrudan giriş test edilebilir.
     */
    private function seedUsers(): void
    {
        $hash = Hash::make(self::PASSWORD);

        $definitions = [
            ['Admin', 'Deniz Aksoy', 'deniz.aksoy@syncra.local', 'Yönetim'],
            ['Satış Müdürü', 'Elif Yıldırım', 'elif.yildirim@syncra.local', 'Satış'],
            ['Satış Müdürü', 'Mert Korkmaz', 'mert.korkmaz@syncra.local', 'Satış'],
            ['Satış Temsilcisi', 'Zeynep Demir', 'zeynep.demir@syncra.local', 'Satış'],
            ['Satış Temsilcisi', 'Burak Şahin', 'burak.sahin@syncra.local', 'Satış'],
            ['Satış Temsilcisi', 'Ayşe Kaya', 'ayse.kaya@syncra.local', 'Satış'],
            ['Destek Temsilcisi', 'Emre Çelik', 'emre.celik@syncra.local', 'Destek'],
            ['İzleyici', 'Selin Arslan', 'selin.arslan@syncra.local', 'Yönetim'],
        ];

        foreach ($definitions as $index => [$role, $name, $email, $department]) {
            $createdAt = $this->now->subMonths(9)->addDays($index * 3);

            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $hash,
                'department' => $department,
                'is_active' => true,
                'must_change_password' => false,
                'email_verified_at' => $createdAt,
                'last_login_at' => $this->now->subDays($this->faker->numberBetween(0, 12)),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $user->timestamps = false;
            $user->save();
            $user->timestamps = true;

            $user->assignRole($role);

            $this->userIds[] = (int) $user->id;

            if ($index === 0) {
                $this->adminUserId = (int) $user->id;
            } elseif ($index === 6) {
                $this->supportUserId = (int) $user->id;
            } elseif ($index <= 5) {
                $this->salesUserIds[] = (int) $user->id;
            }
        }
    }

    private function seedCompanies(): void
    {
        $rows = [];
        $createdDates = [];

        foreach (self::COMPANY_NAMES as $index => $name) {
            $createdAt = $this->now->subDays($this->faker->numberBetween(30, 180));
            $city = self::CITIES[$index % count(self::CITIES)];
            $domain = Str::slug(Str::of($name)->before(' ')->toString()).($index + 1).'.com.tr';

            $rows[] = [
                'name' => $name,
                'email' => 'info@'.$domain,
                'phone' => $this->phone(),
                'website' => 'https://www.'.$domain,
                'industry' => self::INDUSTRIES[$index % count(self::INDUSTRIES)],
                'address' => $this->faker->streetAddress().', '.$city,
                'city' => $city,
                'country' => 'Türkiye',
                'employee_count' => $this->faker->numberBetween(5, 4500),
                'annual_revenue' => $this->faker->numberBetween(2_500_000, 250_000_000),
                'owner_id' => $this->salesUserIds[$index % count($this->salesUserIds)],
                'notes' => $index % 4 === 0 ? 'Kurumsal müşteri adayı, yıllık sözleşme potansiyeli yüksek.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $createdDates[] = $createdAt;
        }

        $this->bulkInsert('companies', $rows);
        $this->companyIds = array_map('intval', DB::table('companies')->orderBy('id')->pluck('id')->all());

        foreach ($this->companyIds as $index => $id) {
            $this->companyCreatedAt[$id] = $createdDates[$index];
            $this->companyContacts[$id] = [];
        }
    }

    /**
     * 60 kişi: ilk 50'si bir firmaya bağlı (firma başına 2 kişi), son 10'u
     * bağımsız. Her firmanın YALNIZCA ilk kişisi is_primary=true olur.
     */
    private function seedContacts(): void
    {
        $rows = [];
        $meta = [];
        $companyCount = count($this->companyIds);

        for ($i = 0; $i < 60; $i++) {
            $companyId = $i < 50 ? $this->companyIds[$i % $companyCount] : null;
            $isPrimary = $i < $companyCount;
            $first = $this->firstName($i);
            $last = self::LAST_NAMES[($i * 7) % count(self::LAST_NAMES)];

            $createdAt = $companyId !== null
                ? $this->between($this->companyCreatedAt[$companyId], $this->now->subDays(2))
                : $this->now->subDays($this->faker->numberBetween(5, 170));

            $city = self::CITIES[$i % count(self::CITIES)];
            $domain = $companyId !== null ? 'firma'.$companyId.'.com.tr' : 'ornekmail.com';
            $email = Str::slug($first).'.'.Str::slug($last).($i + 1).'@'.$domain;
            $phone = $this->phone();

            $rows[] = [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $phone,
                'mobile' => $this->mobile(),
                'position' => self::POSITIONS[$i % count(self::POSITIONS)],
                'company_id' => $companyId,
                'owner_id' => $this->salesUserIds[$i % count($this->salesUserIds)],
                'is_primary' => $isPrimary,
                'address' => $this->faker->streetAddress().', '.$city,
                'city' => $city,
                'country' => 'Türkiye',
                'notes' => $i % 5 === 0 ? 'Telefonla ulaşmak e-postadan daha hızlı sonuç veriyor.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $meta[] = [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'phone' => $phone,
                'company_id' => $companyId,
                'created_at' => $createdAt,
            ];
        }

        $this->bulkInsert('contacts', $rows);
        $this->contactIds = array_map('intval', DB::table('contacts')->orderBy('id')->pluck('id')->all());

        foreach ($this->contactIds as $index => $id) {
            $this->contactCreatedAt[$id] = $meta[$index]['created_at'];
            $this->contactInfo[$id] = $meta[$index];

            if ($meta[$index]['company_id'] !== null) {
                $this->companyContacts[$meta[$index]['company_id']][] = $id;
            }
        }
    }

    /**
     * 50 fırsat: 12 kazanılmış, 8 kaybedilmiş, 30 açık.
     *
     * Sözleşme: status='won' => is_won aşaması + closed_at + won_reason;
     * status='lost' => is_lost aşaması + closed_at + lost_reason;
     * status='open' => won/lost olmayan aşama + closed_at null + iki reason da null.
     * `position` aşama içinde benzersiz, sabit genişlikte base36 fractional index.
     */
    private function seedDeals(): void
    {
        $openSlugs = ['yeni-firsat', 'iletisim-kuruldu', 'teklif-hazirlaniyor', 'teklif-gonderildi', 'muzakere'];
        $wonReasons = [
            'Fiyat ve teslim süresi rakiplerden iyiydi',
            'Mevcut müşteri referansı belirleyici oldu',
            'Teknik ekip demosu güven verdi',
            'Entegrasyon kapsamı ihtiyacı tam karşıladı',
        ];
        $lostReasons = [
            'Fiyat yüksek bulundu',
            'Rakip firma tercih edildi',
            'Bütçe onaylanmadı',
            'Proje ertelendi',
            'İhtiyaç ortadan kalktı',
        ];

        $plan = array_merge(
            array_fill(0, 12, 'won'),
            array_fill(0, 8, 'lost'),
            array_fill(0, 30, 'open')
        );

        $positionCounters = [];
        $rows = [];
        $meta = [];

        foreach ($plan as $i => $status) {
            $companyId = $this->companyIds[$i % count($this->companyIds)];
            $contacts = $this->companyContacts[$companyId];
            $contactId = $contacts !== [] ? $contacts[$i % count($contacts)] : null;
            $ownerId = $this->salesUserIds[$i % count($this->salesUserIds)];

            $createdAt = $this->between($this->companyCreatedAt[$companyId], $this->now->subDays(10));

            if ($status === 'won') {
                $stage = $this->stages['kazanildi'];
                $closedAt = $this->between($createdAt->addDays(2), $this->now->subDay());
                $probability = 100;
                $wonReason = $wonReasons[$i % count($wonReasons)];
                $lostReason = null;
                $expected = $closedAt->toDateString();
            } elseif ($status === 'lost') {
                $stage = $this->stages['kaybedildi'];
                $closedAt = $this->between($createdAt->addDays(2), $this->now->subDay());
                $probability = 0;
                $wonReason = null;
                $lostReason = $lostReasons[$i % count($lostReasons)];
                $expected = $closedAt->toDateString();
            } else {
                $stage = $this->stages[$openSlugs[$i % count($openSlugs)]];
                $closedAt = null;
                $probability = $stage['probability'];
                $wonReason = null;
                $lostReason = null;
                $expected = $this->now->addDays($this->faker->numberBetween(5, 100))->toDateString();
            }

            $stageId = $stage['id'];
            $positionCounters[$stageId] = ($positionCounters[$stageId] ?? 0) + 1;

            $amount = $this->faker->numberBetween(5_000, 500_000);

            $rows[] = [
                'title' => self::DEAL_TITLES[$i % count(self::DEAL_TITLES)].' — '.Str::of(self::COMPANY_NAMES[$i % count(self::COMPANY_NAMES)])->before(' ')->toString(),
                'description' => 'Müşterinin mevcut altyapısına uygun kapsam belirlendi; devreye alma takvimi görüşülüyor.',
                'amount' => $amount,
                'currency' => 'TRY',
                'pipeline_stage_id' => $stageId,
                'position' => $this->fractionalKey($positionCounters[$stageId]),
                'version' => 1,
                'probability' => $probability,
                'expected_close_date' => $expected,
                'closed_at' => $closedAt,
                'status' => $status,
                'lost_reason' => $lostReason,
                'won_reason' => $wonReason,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'owner_id' => $ownerId,
                // Faz 14 / İz E (docs/PHASE-INTL.md §2.3): kapanışta donan TRY
                // tutarı. Göç yalnız MEVCUT satırları geriye dönük doldurur —
                // seeder yeniden çalıştırılırsa kapanmış fırsatlar bu alanlar
                // olmadan yazılır ve raporlar "0 gelir" gösterir. Tüm demo veri
                // TRY olduğu için kur 1 kesin doğru; açık fırsatlarda üçü de null.
                'base_amount' => $closedAt !== null ? $amount : null,
                'base_rate' => $closedAt !== null ? '1.000000' : null,
                'base_rate_date' => $closedAt?->toDateString(),
                'created_at' => $createdAt,
                'updated_at' => $closedAt ?? $createdAt,
            ];

            $meta[] = [
                'created_at' => $createdAt,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'owner_id' => $ownerId,
                'status' => $status,
            ];
        }

        $this->bulkInsert('deals', $rows);
        $this->dealIds = array_map('intval', DB::table('deals')->orderBy('id')->pluck('id')->all());

        foreach ($this->dealIds as $index => $id) {
            $this->dealCreatedAt[$id] = $meta[$index]['created_at'];
            $this->dealInfo[$id] = $meta[$index];
        }
    }

    /**
     * 40 lead: 10 dönüşmüş (gerçek kişi/firma/fırsat kayıtlarına bağlanır),
     * 26 normal ve 4 adet KASITLI DUPLICATE.
     *
     * Duplicate'lar Faz 6'daki duplicate detector için test verisidir:
     * 2 lead mevcut bir kişiyle aynı e-postaya, 2 lead aynı ad+soyad+telefona sahiptir.
     */
    private function seedLeads(): void
    {
        $sources = ['web', 'referral', 'phone', 'email', 'event', 'social', 'other'];
        $openStatuses = ['new', 'contacted', 'qualified', 'unqualified', 'lost'];
        $rows = [];
        $meta = [];

        // 1) Dönüşmüş leadler — converted_* alanları gerçek kayıtlara işaret eder.
        for ($i = 0; $i < 10; $i++) {
            $contactId = $this->contactIds[$i * 3];
            $info = $this->contactInfo[$contactId];
            $companyId = $info['company_id'];
            $createdAt = $this->between($this->now->subDays(200), $this->contactCreatedAt[$contactId]);
            $convertedAt = $this->between($createdAt, $this->contactCreatedAt[$contactId]->addDays(2));
            $dealId = $i < 5 ? $this->dealIds[$i * 4] : null;

            $rows[] = [
                'first_name' => $info['first_name'],
                'last_name' => $info['last_name'],
                'email' => $info['email'],
                'phone' => $info['phone'],
                'company_name' => $companyId !== null ? self::COMPANY_NAMES[array_search($companyId, $this->companyIds, true) ?: 0] : null,
                'position' => self::POSITIONS[$i % count(self::POSITIONS)],
                'source' => $sources[$i % count($sources)],
                'status' => 'converted',
                'score' => $this->faker->numberBetween(80, 100),
                'owner_id' => $this->salesUserIds[$i % count($this->salesUserIds)],
                'converted_at' => $convertedAt,
                'converted_contact_id' => $contactId,
                'converted_company_id' => $companyId,
                'converted_deal_id' => $dealId,
                'notes' => 'Fuar görüşmesi sonrası müşteriye dönüştürüldü.',
                'created_at' => $createdAt,
                'updated_at' => $convertedAt,
            ];
            $meta[] = ['created_at' => $createdAt];
        }

        // 2) Henüz dönüşmemiş leadler — converted_* alanlarının HEPSİ null.
        for ($i = 0; $i < 26; $i++) {
            $first = $this->firstName($i + 13);
            $last = self::LAST_NAMES[($i * 11) % count(self::LAST_NAMES)];
            $createdAt = $this->now->subDays($this->faker->numberBetween(1, 180));

            $rows[] = [
                'first_name' => $first,
                'last_name' => $last,
                'email' => Str::slug($first).'.'.Str::slug($last).'.lead'.($i + 1).'@ornekmail.com',
                'phone' => $this->phone(),
                'company_name' => $this->faker->company(),
                'position' => self::POSITIONS[$i % count(self::POSITIONS)],
                'source' => $sources[$i % count($sources)],
                'status' => $openStatuses[$i % count($openStatuses)],
                'score' => $this->faker->numberBetween(0, 75),
                'owner_id' => $this->salesUserIds[$i % count($this->salesUserIds)],
                'converted_at' => null,
                'converted_contact_id' => null,
                'converted_company_id' => null,
                'converted_deal_id' => null,
                'notes' => $i % 3 === 0 ? 'Geri arama talebi var, öğleden sonra müsait.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $meta[] = ['created_at' => $createdAt];
        }

        // 3) KASITLI DUPLICATE'LER (Faz 6 duplicate detector test verisi).
        //    İlk ikisi mevcut bir kişiyle AYNI E-POSTA, sonraki ikisi AYNI AD+SOYAD+TELEFON.
        $duplicateSources = [
            ['contact_index' => 2, 'mode' => 'email'],
            ['contact_index' => 5, 'mode' => 'email'],
            ['contact_index' => 8, 'mode' => 'name_phone'],
            ['contact_index' => 11, 'mode' => 'name_phone'],
        ];

        foreach ($duplicateSources as $d => $definition) {
            $contactId = $this->contactIds[$definition['contact_index']];
            $info = $this->contactInfo[$contactId];
            $createdAt = $this->now->subDays($this->faker->numberBetween(1, 25));

            $isEmailDuplicate = $definition['mode'] === 'email';

            $rows[] = [
                'first_name' => $isEmailDuplicate ? $this->firstName($d + 41) : $info['first_name'],
                'last_name' => $isEmailDuplicate ? self::LAST_NAMES[($d * 5) % count(self::LAST_NAMES)] : $info['last_name'],
                'email' => $isEmailDuplicate ? $info['email'] : 'yeni.kayit'.($d + 1).'@ornekmail.com',
                'phone' => $info['phone'],
                'company_name' => $this->faker->company(),
                'position' => self::POSITIONS[$d % count(self::POSITIONS)],
                'source' => 'web',
                'status' => 'new',
                'score' => $this->faker->numberBetween(10, 60),
                'owner_id' => $this->salesUserIds[$d % count($this->salesUserIds)],
                'converted_at' => null,
                'converted_contact_id' => null,
                'converted_company_id' => null,
                'converted_deal_id' => null,
                'notes' => 'DEMO: mevcut bir kişiyle çakışan kayıt — duplicate tespiti testi için bilerek eklendi.',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            $meta[] = ['created_at' => $createdAt];
        }

        $this->bulkInsert('leads', $rows);
        $this->leadIds = array_map('intval', DB::table('leads')->orderBy('id')->pluck('id')->all());

        foreach ($this->leadIds as $index => $id) {
            $this->leadCreatedAt[$id] = $meta[$index]['created_at'];
        }
    }

    private function seedProducts(): void
    {
        $categories = ['Yazılım', 'Donanım', 'Hizmet', 'Lisans', 'Eğitim', 'Destek'];
        $units = ['adet', 'ay', 'yıl', 'saat', 'paket'];
        $rows = [];
        $meta = [];

        foreach (self::PRODUCT_NAMES as $index => $name) {
            $createdAt = $this->now->subDays($this->faker->numberBetween(120, 240));
            $unitPrice = $this->faker->numberBetween(250, 75_000);
            $taxRate = $index % 7 === 0 ? 10.00 : 20.00;

            $rows[] = [
                'name' => $name,
                'sku' => 'SKU-'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
                'description' => $name.' — standart kapsam ve koşullarla sunulur.',
                'category' => $categories[$index % count($categories)],
                'unit_price' => $unitPrice,
                'currency' => 'TRY',
                'tax_rate' => $taxRate,
                'unit' => $units[$index % count($units)],
                'stock_quantity' => $index % 3 === 0 ? null : $this->faker->numberBetween(0, 500),
                'is_active' => $index % 10 !== 9,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $meta[] = ['name' => $name, 'unit_price' => $unitPrice, 'tax_rate' => $taxRate];
        }

        $this->bulkInsert('products', $rows);
        $this->productIds = array_map('intval', DB::table('products')->orderBy('id')->pluck('id')->all());

        foreach ($this->productIds as $index => $id) {
            $this->productInfo[$id] = $meta[$index];
        }
    }

    /**
     * 15 teklif + kalemleri.
     *
     * HESAP BURADA YAPILMAZ: seeder yalnızca kalem girdilerini üretir, tutarları
     * `App\Services\Quotes\QuoteCalculator` hesaplar. Formülün ikinci bir
     * kopyası burada yaşasaydı iki yol ancak biri diğerini yanlışlarken
     * ayrışırdı ve o an hangisinin doğru olduğu belli olmazdı — docs/
     * QUOTE-FINANCIALS.md'nin TEK uygulaması calculator'dır.
     *
     * Calculator KDV'yi İNDİRİM SONRASI matrahtan hesaplar (KDVK md. 25/a) ve
     * teklif geneli indirimi KDV oranı gruplarına ciro payıyla dağıtır
     * (largest-remainder). Ürünlerdeki karışık KDV dağılımı (%10 / %20)
     * bilerek korunur: demo veride çok-oranlı dağıtım yolunu canlı tutar.
     *
     * `quote_items.name` ilgili ürünün o anki adının SNAPSHOT'ıdır;
     * `quote_items.line_total` da calculator tarafından doldurulur.
     */
    private function seedQuotes(): void
    {
        $validityDays = (int) (Setting::get('quote.validity_days') ?? 30);
        $terms = (string) (Setting::get('quote.terms') ?? '');

        $statusPlan = [
            'draft', 'draft', 'draft',
            'sent', 'sent', 'sent', 'sent', 'sent',
            'accepted', 'accepted', 'accepted', 'accepted',
            'rejected', 'rejected',
            'expired',
        ];

        $quoteRows = [];
        $itemsByQuoteIndex = [];

        foreach ($statusPlan as $i => $status) {
            $dealId = $this->dealIds[$i * 3];
            $deal = $this->dealInfo[$dealId];

            $createdAt = $status === 'expired'
                ? $this->now->subDays(120)
                : $this->between($this->dealCreatedAt[$dealId], $this->now->subDays(5));

            // --- Kalemler: yalnızca girdi alanları; tutarları calculator doldurur ---
            $itemCount = $this->faker->numberBetween(2, 5);
            $chosen = $this->faker->randomElements($this->productIds, $itemCount);
            $items = [];

            foreach ($chosen as $position => $productId) {
                $product = $this->productInfo[$productId];

                $items[] = [
                    'product_id' => $productId,
                    'name' => $product['name'], // snapshot
                    'description' => $product['name'].' kapsamı standart sözleşme koşullarına tabidir.',
                    'quantity' => (float) $this->faker->numberBetween(1, 20),
                    'unit_price' => (float) $product['unit_price'],
                    'discount_percent' => (float) $this->faker->randomElement([0, 0, 0, 5, 10, 15]),
                    'tax_rate' => (float) $product['tax_rate'],
                    'position' => $position + 1,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            // Her 4. teklif %5 teklif geneli indirimli (QTE-000001/5/9/13),
            // diğerlerinde indirim yok. Demo veride revizyon zinciri YOKTUR.
            $isDiscounted = $i % 4 === 0;
            $discountType = $isDiscounted ? QuoteCalculator::DISCOUNT_PERCENT : QuoteCalculator::DISCOUNT_AMOUNT;
            $discountValue = $isDiscounted ? 5.00 : 0.00;

            // Calculator kalemleri `line_total` ile zenginleştirip geri verir;
            // tanımadığı anahtarlara (name, position, created_at, ...) dokunmaz.
            $calculated = QuoteCalculator::calculate($items, $discountValue, $discountType);
            $items = $calculated['items'];

            $sentAt = $status === 'draft' ? null : $this->between($createdAt, $createdAt->addDays(4));
            $acceptedAt = $status === 'accepted' ? $this->between($sentAt->addDay(), $sentAt->addDays(15)->min($this->now)) : null;
            $rejectedAt = $status === 'rejected' ? $this->between($sentAt->addDay(), $sentAt->addDays(15)->min($this->now)) : null;

            $quoteRows[] = [
                'quote_number' => 'QTE-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'title' => 'Teklif — '.self::DEAL_TITLES[$i % count(self::DEAL_TITLES)],
                'deal_id' => $dealId,
                'company_id' => $deal['company_id'],
                'contact_id' => $deal['contact_id'],
                'status' => $status,
                'valid_until' => $createdAt->addDays($validityDays)->toDateString(),
                'subtotal' => $calculated['subtotal'],
                'discount_type' => $calculated['discount_type'],
                'discount_value' => $calculated['discount_value'],
                'discount_amount' => $calculated['discount_amount'],
                'tax_amount' => $calculated['tax_amount'],
                'total' => $calculated['total'],
                'currency' => 'TRY',
                'parent_quote_id' => null,
                'revision' => 1,
                'notes' => 'Fiyatlar teklif tarihindeki kur ve stok durumuna göre hazırlanmıştır.',
                'terms' => $terms,
                'sent_at' => $sentAt,
                'accepted_at' => $acceptedAt,
                'rejected_at' => $rejectedAt,
                'created_by' => $deal['owner_id'],
                'created_at' => $createdAt,
                'updated_at' => $acceptedAt ?? $rejectedAt ?? $sentAt ?? $createdAt,
            ];

            $itemsByQuoteIndex[$i] = $items;
        }

        $this->bulkInsert('quotes', $quoteRows);
        $this->quoteIds = array_map('intval', DB::table('quotes')->orderBy('id')->pluck('id')->all());

        $itemRows = [];
        foreach ($this->quoteIds as $index => $quoteId) {
            foreach ($itemsByQuoteIndex[$index] as $item) {
                $itemRows[] = array_merge(['quote_id' => $quoteId], $item);
            }
        }

        $this->bulkInsert('quote_items', $itemRows);
    }

    /**
     * 30 destek talebi. `sla_due_at` = created_at + settings'teki öncelik saati.
     * 8 tanesi bilerek ihlal edilmiş durumda (sla_due_at geçmişte, resolved_at null)
     * ki SLA sayacı test edilebilsin.
     */
    private function seedTickets(): void
    {
        $slaHours = [
            'low' => (int) (Setting::get('ticket.sla_hours_low') ?? 72),
            'normal' => (int) (Setting::get('ticket.sla_hours_normal') ?? 48),
            'high' => (int) (Setting::get('ticket.sla_hours_high') ?? 24),
            'urgent' => (int) (Setting::get('ticket.sla_hours_urgent') ?? 4),
        ];
        $priorities = ['low', 'normal', 'high', 'urgent'];
        $categories = ['Teknik Destek', 'Faturalandırma', 'Ürün Bilgisi', 'Şikayet', 'Kurulum', 'Eğitim Talebi'];
        $subjects = [
            'Sisteme giriş yapılamıyor',
            'Fatura tutarı hatalı görünüyor',
            'Rapor ekranı çok yavaş açılıyor',
            'Yeni kullanıcı tanımlanamıyor',
            'E-posta bildirimleri gelmiyor',
            'Mobil uygulamada senkronizasyon sorunu',
            'Veri aktarımı yarıda kesiliyor',
            'Eğitim tarihi değişikliği talebi',
        ];

        $plan = array_merge(
            array_fill(0, 8, 'breached'),
            array_fill(0, 7, 'open'),
            array_fill(0, 8, 'resolved'),
            array_fill(0, 7, 'closed')
        );

        $rows = [];
        $meta = [];

        foreach ($plan as $i => $kind) {
            $priority = $priorities[$i % count($priorities)];
            $hours = $slaHours[$priority];
            $companyId = $this->companyIds[$i % count($this->companyIds)];
            $contacts = $this->companyContacts[$companyId];
            $contactId = $contacts !== [] ? $contacts[0] : null;

            if ($kind === 'breached') {
                $createdAt = $this->now->subDays($this->faker->numberBetween(5, 60));
                $status = 'open';
                $firstResponseAt = $i % 2 === 0 ? $createdAt->addMinutes($this->faker->numberBetween(20, 240)) : null;
                $resolvedAt = null;
                $closedAt = null;
            } elseif ($kind === 'open') {
                // SLA penceresi içinde: created_at, sla süresinin yarısından yeni.
                $createdAt = $this->now->subMinutes($this->faker->numberBetween(10, max(15, (int) ($hours * 60 / 2))));
                $status = 'open';
                $firstResponseAt = null;
                $resolvedAt = null;
                $closedAt = null;
            } else {
                $createdAt = $this->now->subDays($this->faker->numberBetween(10, 170));
                $status = $kind; // resolved | closed
                $firstResponseAt = $createdAt->addMinutes($this->faker->numberBetween(15, max(30, $hours * 60)));
                $resolvedAt = $this->between($firstResponseAt->addHours(1), $firstResponseAt->addHours(72));
                $closedAt = $kind === 'closed'
                    ? $this->between($resolvedAt->addHours(1), $resolvedAt->addHours(48))
                    : null;
            }

            $rows[] = [
                'ticket_number' => 'TKT-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'subject' => $subjects[$i % count($subjects)],
                'description' => 'Müşteri sorunu detaylandırdı; ekran görüntüleri talep edildi ve inceleme başlatıldı.',
                'priority' => $priority,
                'status' => $status,
                'category' => $categories[$i % count($categories)],
                'contact_id' => $contactId,
                'company_id' => $companyId,
                'assigned_to' => $i % 10 === 9 ? null : $this->supportUserId,
                'created_by' => $this->salesUserIds[$i % count($this->salesUserIds)],
                'sla_due_at' => $createdAt->addHours($hours),
                'first_response_at' => $firstResponseAt,
                'resolved_at' => $resolvedAt,
                'closed_at' => $closedAt,
                'created_at' => $createdAt,
                'updated_at' => $closedAt ?? $resolvedAt ?? $createdAt,
            ];

            $meta[] = ['created_at' => $createdAt];
        }

        $this->bulkInsert('tickets', $rows);
        $this->ticketIds = array_map('intval', DB::table('tickets')->orderBy('id')->pluck('id')->all());

        foreach ($this->ticketIds as $index => $id) {
            $this->ticketCreatedAt[$id] = $meta[$index]['created_at'];
        }
    }

    /**
     * @return list<array{type: class-string, id: int, created_at: CarbonImmutable}>
     */
    private function morphPool(): array
    {
        $pool = [];

        foreach ($this->dealIds as $id) {
            $pool[] = ['type' => Deal::class, 'id' => $id, 'created_at' => $this->dealCreatedAt[$id]];
        }
        foreach ($this->contactIds as $id) {
            $pool[] = ['type' => Contact::class, 'id' => $id, 'created_at' => $this->contactCreatedAt[$id]];
        }
        foreach ($this->companyIds as $id) {
            $pool[] = ['type' => Company::class, 'id' => $id, 'created_at' => $this->companyCreatedAt[$id]];
        }
        foreach ($this->leadIds as $id) {
            $pool[] = ['type' => Lead::class, 'id' => $id, 'created_at' => $this->leadCreatedAt[$id]];
        }
        foreach ($this->ticketIds as $id) {
            $pool[] = ['type' => Ticket::class, 'id' => $id, 'created_at' => $this->ticketCreatedAt[$id]];
        }

        return $pool;
    }

    /**
     * 80 görev: 20 gecikmiş, 25 açık/gelecek, 30 tamamlanmış, 5 iptal.
     * created_at her zaman bağlı olduğu kaydın created_at'inden SONRADIR.
     */
    private function seedTasks(): void
    {
        $titles = [
            'Müşteriyi ara', 'Teklif hazırla', 'Toplantı planla', 'Sözleşme taslağını gönder',
            'Demo sunumu yap', 'Fatura takibi', 'Referans kontrolü', 'Fiyat revizyonu',
            'Teknik gereksinim listesini iste', 'Kurulum takvimini netleştir',
        ];
        $pool = $this->morphPool();
        $plan = array_merge(
            array_fill(0, 20, 'overdue'),
            array_fill(0, 25, 'pending'),
            array_fill(0, 30, 'completed'),
            array_fill(0, 5, 'cancelled')
        );

        $rows = [];

        foreach ($plan as $i => $kind) {
            $hasParent = $i % 8 !== 7; // ~10 görev bağımsız
            $parent = $pool[($i * 13) % count($pool)];

            $parentCreatedAt = $hasParent ? $parent['created_at'] : $this->now->subDays(90);
            $createdAt = $this->between($parentCreatedAt, $this->now->subDay());

            $priority = ['low', 'normal', 'high', 'urgent'][$i % 4];

            if ($kind === 'overdue') {
                $dueAt = $this->between($createdAt, $this->now->subHours(6));
                $status = 'pending';
                $completedAt = null;
            } elseif ($kind === 'pending') {
                $dueAt = $this->now->addDays($this->faker->numberBetween(1, 45));
                $status = 'pending';
                $completedAt = null;
            } elseif ($kind === 'completed') {
                $dueAt = $this->between($createdAt, $this->now);
                $status = 'completed';
                $completedAt = $this->between($dueAt, $this->now);
            } else {
                $dueAt = $this->between($createdAt, $this->now->addDays(20));
                $status = 'cancelled';
                $completedAt = null;
            }

            $isTicket = $hasParent && $parent['type'] === Ticket::class;

            $rows[] = [
                'title' => $titles[$i % count($titles)],
                'description' => $i % 3 === 0 ? 'Görüşme notlarını CRM üzerine işlemeyi unutma.' : null,
                'due_at' => $dueAt,
                'reminder_at' => $dueAt->subHours(2),
                'priority' => $priority,
                'status' => $status,
                'completed_at' => $completedAt,
                'assigned_to' => $isTicket ? $this->supportUserId : $this->salesUserIds[$i % count($this->salesUserIds)],
                'created_by' => $this->salesUserIds[($i + 1) % count($this->salesUserIds)],
                'taskable_type' => $hasParent ? $parent['type'] : null,
                'taskable_id' => $hasParent ? $parent['id'] : null,
                'created_at' => $createdAt,
                'updated_at' => $completedAt ?? $createdAt,
            ];
        }

        $this->bulkInsert('tasks', $rows);
    }

    /**
     * 120 aktivite. occurred_at bağlı kaydın created_at'inden sonradır.
     */
    private function seedActivities(): void
    {
        $types = self::ACTIVITY_TYPES;
        $subjects = [
            'call' => ['Tanışma görüşmesi', 'Teklif sonrası takip araması', 'Fiyat müzakeresi'],
            'email' => ['Teklif dosyası gönderildi', 'Ürün broşürü paylaşıldı', 'Toplantı özeti iletildi'],
            // Eskiden ayrı bir 'visit' tipi vardı (backend'in kabul etmediği bir değer — bkz.
            // ACTIVITY_TYPES dokümantasyonu); yerinde/fabrika/ofis ziyaretleri anlamca zaten
            // yüz yüze birer TOPLANTI olduğu için konuları buraya taşındı, ayrı tip icat edilmedi.
            'meeting' => [
                'Kick-off toplantısı', 'İhtiyaç analizi toplantısı', 'Teknik değerlendirme',
                'Yerinde ziyaret', 'Fabrika ziyareti', 'Ofis ziyareti',
            ],
            'note' => ['Müşteri notu', 'İç değerlendirme notu', 'Rakip analizi notu'],
        ];
        $outcomes = ['successful', 'no_answer', 'rescheduled', 'follow_up', 'not_interested'];
        $pool = $this->morphPool();

        $rows = [];

        for ($i = 0; $i < 120; $i++) {
            $parent = $pool[($i * 7) % count($pool)];
            $type = $types[$i % count($types)];
            $occurredAt = $this->between($parent['created_at'], $this->now);
            $isTicket = $parent['type'] === Ticket::class;

            $rows[] = [
                'type' => $type,
                'subject' => $subjects[$type][$i % count($subjects[$type])],
                'body' => 'Görüşmede kapsam, bütçe ve zaman planı ele alındı; sonraki adım kararlaştırıldı.',
                'occurred_at' => $occurredAt,
                'duration_minutes' => in_array($type, ['call', 'meeting'], true)
                    ? $this->faker->numberBetween(5, 120)
                    : null,
                'outcome' => $i % 3 === 0 ? $outcomes[$i % count($outcomes)] : null,
                'user_id' => $isTicket ? $this->supportUserId : $this->salesUserIds[$i % count($this->salesUserIds)],
                'activityable_type' => $parent['type'],
                'activityable_id' => $parent['id'],
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ];
        }

        $this->bulkInsert('activities', $rows);
    }

    private function seedTags(): void
    {
        $tags = [
            ['Öncelikli', 'danger'], ['VIP Müşteri', 'warning'], ['Yeni Müşteri', 'success'],
            ['Fuar Bağlantısı', 'info'], ['Web Sitesi', 'info'], ['Tavsiye', 'success'],
            ['Sıcak Fırsat', 'danger'], ['Soğuk Fırsat', 'neutral'], ['Yenileme', 'primary'],
            ['Kurumsal', 'primary'], ['KOBİ', 'neutral'], ['Kamu', 'warning'],
        ];

        $rows = [];
        foreach ($tags as $index => [$name, $color]) {
            $createdAt = $this->now->subDays(200 - $index);
            $rows[] = [
                'name' => $name,
                'slug' => Str::slug($name),
                'color' => $color,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $this->bulkInsert('tags', $rows);
        $this->tagIds = array_map('intval', DB::table('tags')->orderBy('id')->pluck('id')->all());

        // taggables: yalnızca gerçekten var olan kayıtlara bağlanır, (tag, type, id) benzersizdir.
        $targets = array_merge(
            array_map(fn (int $id) => [Company::class, $id], array_slice($this->companyIds, 0, 20)),
            array_map(fn (int $id) => [Contact::class, $id], array_slice($this->contactIds, 0, 20)),
            array_map(fn (int $id) => [Deal::class, $id], array_slice($this->dealIds, 0, 20)),
            array_map(fn (int $id) => [Lead::class, $id], array_slice($this->leadIds, 0, 15)),
        );

        $pivot = [];
        $seen = [];

        foreach ($targets as $index => [$type, $id]) {
            $count = $this->faker->numberBetween(1, 3);
            for ($k = 0; $k < $count; $k++) {
                $tagId = $this->tagIds[($index * 5 + $k * 3) % count($this->tagIds)];
                $key = $tagId.'|'.$type.'|'.$id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $pivot[] = ['tag_id' => $tagId, 'taggable_type' => $type, 'taggable_id' => $id];
            }
        }

        $this->bulkInsert('taggables', $pivot);
    }

    /**
     * Ekler yalnızca DB satırıdır — DEMO SATIRLARI, DİSKTE KARŞILIĞI YOKTUR.
     * `path` değerleri `attachments/demo/...` biçimindedir.
     */
    private function seedAttachments(): void
    {
        $documents = [
            ['Teklif_Formu.pdf', 'application/pdf'],
            ['Sozlesme_Taslagi.pdf', 'application/pdf'],
            ['Urun_Katalogu.pdf', 'application/pdf'],
            ['Fiyat_Listesi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['Ekran_Goruntusu.png', 'image/png'],
            ['Toplanti_Notlari.txt', 'text/plain'],
        ];

        $attachTargets = array_merge(
            array_map(fn (int $id) => [Deal::class, $id], array_slice($this->dealIds, 0, 5)),
            array_map(fn (int $id) => [Ticket::class, $id], array_slice($this->ticketIds, 0, 4)),
            array_map(fn (int $id) => [Quote::class, $id], array_slice($this->quoteIds, 0, 3)),
        );

        $rows = [];

        foreach ($attachTargets as $index => [$type, $id]) {
            [$original, $mime] = $documents[$index % count($documents)];
            $filename = $this->faker->uuid().'-'.$index.'.'.pathinfo($original, PATHINFO_EXTENSION);
            $createdAt = $this->now->subDays($this->faker->numberBetween(1, 100));

            $rows[] = [
                'filename' => $filename,
                'original_name' => $original,
                'mime_type' => $mime,
                'size' => $this->faker->numberBetween(10_240, 8_388_608),
                'disk' => 'local',
                'path' => 'attachments/demo/'.$filename,
                'attachable_type' => $type,
                'attachable_id' => $id,
                'uploaded_by' => $this->salesUserIds[$index % count($this->salesUserIds)],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $attachedCount = count($rows);

        // Mesajlara iliştirilecek serbest ekler (attachable boş).
        for ($i = 0; $i < 6; $i++) {
            [$original, $mime] = $documents[$i % count($documents)];
            $filename = $this->faker->uuid().'-msg'.$i.'.'.pathinfo($original, PATHINFO_EXTENSION);
            $createdAt = $this->now->subDays($this->faker->numberBetween(1, 40));

            $rows[] = [
                'filename' => $filename,
                'original_name' => $original,
                'mime_type' => $mime,
                'size' => $this->faker->numberBetween(10_240, 4_194_304),
                'disk' => 'local',
                'path' => 'attachments/demo/'.$filename,
                'attachable_type' => null,
                'attachable_id' => null,
                'uploaded_by' => $this->userIds[$i % count($this->userIds)],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $this->bulkInsert('attachments', $rows);

        $allIds = array_map('intval', DB::table('attachments')->orderBy('id')->pluck('id')->all());
        $this->freeAttachmentIds = array_slice($allIds, $attachedCount);
    }

    /**
     * 8 konuşma + 120 mesaj.
     *  - type='dm'     => TAM 2 katılımcı
     *  - type='group'  => 3+ katılımcı
     *  - type='record' => bir Deal/Ticket kaydına (conversable) bağlı
     * `last_message_at` konuşmanın son mesajının created_at'idir.
     * Pivot'ta `unread_count`, `last_read_message_id`'den SONRAKİ mesaj sayısına eşittir.
     */
    private function seedConversations(): void
    {
        $lines = [
            'Merhaba, teklifi müşteriye ilettim.',
            'Fiyat konusunda küçük bir revizyon istiyorlar.',
            'Yarın 14:00 için toplantı ayarladım.',
            'Sözleşme taslağını hukuka gönderdim.',
            'Teknik ekip demoyu hazırladı.',
            'Müşteri bütçe onayını bekliyor.',
            'Kurulum takvimini netleştirelim mi?',
            'Referans müşteri görüşmesi olumlu geçti.',
            'Faturayı muhasebeye ilettim.',
            'Destek talebini yükselttim, önceliklendirildi.',
            'Rakip firma da teklif vermiş, dikkatli olalım.',
            'Bugünkü görüşme notlarını CRM’e işledim.',
        ];

        $definitions = [
            ['type' => 'dm', 'name' => null, 'participants' => 2, 'messages' => 18, 'conversable' => null],
            ['type' => 'dm', 'name' => null, 'participants' => 2, 'messages' => 14, 'conversable' => null],
            ['type' => 'dm', 'name' => null, 'participants' => 2, 'messages' => 12, 'conversable' => null],
            ['type' => 'group', 'name' => 'Satış Ekibi', 'participants' => 5, 'messages' => 20, 'conversable' => null],
            ['type' => 'group', 'name' => 'Destek Vardiyası', 'participants' => 3, 'messages' => 16, 'conversable' => null],
            ['type' => 'group', 'name' => 'Yönetim Koordinasyon', 'participants' => 4, 'messages' => 15, 'conversable' => null],
            ['type' => 'record', 'name' => null, 'participants' => 3, 'messages' => 13, 'conversable' => 'deal'],
            ['type' => 'record', 'name' => null, 'participants' => 3, 'messages' => 12, 'conversable' => 'ticket'],
        ];

        $conversationRows = [];
        $plans = [];

        foreach ($definitions as $index => $definition) {
            $createdAt = $this->now->subDays($this->faker->numberBetween(20, 90));
            $participants = array_slice($this->rotate($this->userIds, $index), 0, $definition['participants']);

            $conversableType = null;
            $conversableId = null;
            if ($definition['conversable'] === 'deal') {
                $conversableType = Deal::class;
                $conversableId = $this->dealIds[0];
            } elseif ($definition['conversable'] === 'ticket') {
                $conversableType = Ticket::class;
                $conversableId = $this->ticketIds[0];
            }

            $conversationRows[] = [
                'type' => $definition['type'],
                'name' => $definition['name'],
                'conversable_type' => $conversableType,
                'conversable_id' => $conversableId,
                'created_by' => $participants[0],
                'last_message_at' => null, // mesajlar yazıldıktan sonra güncellenir
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $plans[] = [
                'created_at' => $createdAt,
                'participants' => $participants,
                'messages' => $definition['messages'],
            ];
        }

        $this->bulkInsert('conversations', $conversationRows);
        $conversationIds = array_map('intval', DB::table('conversations')->orderBy('id')->pluck('id')->all());

        // --- Mesajlar ---
        $messageRows = [];
        $messageCounts = [];

        foreach ($conversationIds as $index => $conversationId) {
            $plan = $plans[$index];
            $cursor = $plan['created_at'];
            $participants = $plan['participants'];
            $freeIndex = 0;

            for ($m = 0; $m < $plan['messages']; $m++) {
                $cursor = $cursor->addMinutes($this->faker->numberBetween(5, 900));
                if ($cursor->greaterThan($this->now)) {
                    $cursor = $this->now->subMinutes($plan['messages'] - $m);
                }

                $attachmentId = null;
                if ($m === 3 && isset($this->freeAttachmentIds[$index % count($this->freeAttachmentIds)])) {
                    $attachmentId = $this->freeAttachmentIds[($index + $freeIndex) % count($this->freeAttachmentIds)];
                }

                $messageRows[] = [
                    'conversation_id' => $conversationId,
                    'user_id' => $participants[$m % count($participants)],
                    'body' => $lines[($index * 3 + $m) % count($lines)],
                    'attachment_id' => $attachmentId,
                    'type' => $attachmentId !== null ? 'file' : 'text',
                    'edited_at' => $m === 5 ? $cursor->addMinutes(3) : null,
                    'created_at' => $cursor,
                    'updated_at' => $cursor,
                ];
            }

            $messageCounts[$conversationId] = $plan['messages'];
        }

        $this->bulkInsert('messages', $messageRows);

        // --- last_message_at + pivot ---
        $messagesByConversation = [];
        foreach (DB::table('messages')->orderBy('id')->get(['id', 'conversation_id', 'created_at']) as $message) {
            $messagesByConversation[(int) $message->conversation_id][] = [
                'id' => (int) $message->id,
                'created_at' => $message->created_at,
            ];
        }

        $pivotRows = [];

        foreach ($conversationIds as $index => $conversationId) {
            $messages = $messagesByConversation[$conversationId] ?? [];
            $total = count($messages);

            if ($total > 0) {
                DB::table('conversations')
                    ->where('id', $conversationId)
                    ->update(['last_message_at' => max(array_column($messages, 'created_at'))]);
            }

            foreach ($plans[$index]['participants'] as $slot => $userId) {
                $readCount = $slot === 0 ? $total : $this->faker->numberBetween(max(0, $total - 5), $total);
                $lastReadId = $readCount > 0 ? $messages[$readCount - 1]['id'] : null;

                $pivotRows[] = [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'last_read_message_id' => $lastReadId,
                    'unread_count' => $total - $readCount,
                    'joined_at' => $plans[$index]['created_at'],
                    'is_muted' => false,
                    'created_at' => $plans[$index]['created_at'],
                    'updated_at' => $plans[$index]['created_at'],
                ];
            }
        }

        $this->bulkInsert('conversation_user', $pivotRows);
    }

    private function seedCustomFieldValues(): void
    {
        $fields = CustomField::query()->get()->keyBy(fn (CustomField $field) => $field->entity_type.'.'.$field->key);
        $rows = [];

        $budget = $fields->get('leads.butce');
        $product = $fields->get('leads.ilgilendigi_urun');
        $taxOffice = $fields->get('companies.vergi_dairesi');
        $competitor = $fields->get('deals.rakip_firma');

        $productOptions = ['CRM Lisansı', 'Destek Paketi', 'Danışmanlık', 'Eğitim', 'Entegrasyon'];
        $taxOffices = ['Beşiktaş VD', 'Kadıköy VD', 'Çankaya VD', 'Konak VD', 'Osmangazi VD'];
        $competitors = ['Rakip A Yazılım', 'Rakip B Teknoloji', 'Rakip C Bilişim'];

        foreach (array_slice($this->leadIds, 0, 10) as $index => $leadId) {
            $createdAt = $this->leadCreatedAt[$leadId];

            if ($budget !== null) {
                $rows[] = $this->customValueRow($budget->id, Lead::class, $leadId, (string) ($this->faker->numberBetween(10, 500) * 1000), $createdAt);
            }
            if ($product !== null) {
                $rows[] = $this->customValueRow($product->id, Lead::class, $leadId, $productOptions[$index % count($productOptions)], $createdAt);
            }
        }

        if ($taxOffice !== null) {
            foreach (array_slice($this->companyIds, 0, 10) as $index => $companyId) {
                $rows[] = $this->customValueRow($taxOffice->id, Company::class, $companyId, $taxOffices[$index % count($taxOffices)], $this->companyCreatedAt[$companyId]);
            }
        }

        if ($competitor !== null) {
            foreach (array_slice($this->dealIds, 0, 8) as $index => $dealId) {
                $rows[] = $this->customValueRow($competitor->id, Deal::class, $dealId, $competitors[$index % count($competitors)], $this->dealCreatedAt[$dealId]);
            }
        }

        $this->bulkInsert('custom_field_values', $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function customValueRow(int $fieldId, string $type, int $id, string $value, CarbonImmutable $createdAt): array
    {
        return [
            'custom_field_id' => $fieldId,
            'customizable_type' => $type,
            'customizable_id' => $id,
            'value' => $value,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    private function seedLogs(): void
    {
        // --- Bildirimler ---
        $notificationTypes = [
            'App\\Notifications\\DealAssigned',
            'App\\Notifications\\TaskDueSoon',
            'App\\Notifications\\TicketSlaBreached',
        ];
        $notifications = [];

        for ($i = 0; $i < 6; $i++) {
            $createdAt = $this->now->subDays($this->faker->numberBetween(0, 20));
            $notifications[] = [
                'id' => (string) Str::uuid(),
                'type' => $notificationTypes[$i % count($notificationTypes)],
                'notifiable_type' => User::class,
                'notifiable_id' => $this->userIds[$i % count($this->userIds)],
                'data' => json_encode([
                    'title' => 'Yeni bildirim',
                    'message' => 'Üzerinize atanan bir kayıt güncellendi.',
                    'url' => '/dashboard',
                ], JSON_UNESCAPED_UNICODE),
                'read_at' => $i % 3 === 0 ? $createdAt->addHours(2) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $this->bulkInsert('notifications', $notifications);

        // --- Sayfa ziyaret logları ---
        $pages = [
            ['dashboard', '/dashboard', 'Kontrol Paneli'],
            ['leads.index', '/leads', 'Aday Müşteriler'],
            ['deals.board', '/deals/board', 'Fırsat Panosu'],
            ['companies.index', '/companies', 'Firmalar'],
            ['contacts.index', '/contacts', 'Kişiler'],
            ['tickets.index', '/tickets', 'Destek Talepleri'],
            ['quotes.index', '/quotes', 'Teklifler'],
        ];
        $visits = [];

        for ($i = 0; $i < 20; $i++) {
            [$route, $path, $title] = $pages[$i % count($pages)];
            $enteredAt = $this->now->subDays($this->faker->numberBetween(0, 25))->subMinutes($this->faker->numberBetween(0, 600));
            $duration = $this->faker->numberBetween(15, 2400);

            $visits[] = [
                'user_id' => $this->userIds[$i % count($this->userIds)],
                'route' => $route,
                'path' => $path,
                'title' => $title,
                'entered_at' => $enteredAt,
                'last_heartbeat_at' => $enteredAt->addSeconds($duration),
                'duration_seconds' => $duration,
                'ip_address' => $this->faker->ipv4(),
                'session_id' => (string) Str::uuid(),
                'created_at' => $enteredAt,
                'updated_at' => $enteredAt->addSeconds($duration),
            ];
        }

        $this->bulkInsert('page_visit_logs', $visits);

        // --- Oturum logları ---
        $browsers = ['Chrome', 'Firefox', 'Edge', 'Safari'];
        $platforms = ['Windows', 'macOS', 'Linux', 'iOS', 'Android'];
        $devices = ['desktop', 'mobile', 'tablet'];
        $sessions = [];

        for ($i = 0; $i < 15; $i++) {
            $isFailed = $i % 5 === 4;
            $userId = $this->userIds[$i % count($this->userIds)];
            $loggedInAt = $this->now->subDays($this->faker->numberBetween(0, 25));
            $duration = $this->faker->numberBetween(300, 28800);
            $isLogout = ! $isFailed && $i % 2 === 1;

            $sessions[] = [
                'user_id' => $isFailed ? null : $userId,
                'email' => $isFailed ? 'bilinmeyen'.$i.'@ornekmail.com' : DB::table('users')->where('id', $userId)->value('email'),
                'event' => $isFailed ? 'failed_login' : ($isLogout ? 'logout' : 'login'),
                'ip_address' => $this->faker->ipv4(),
                'user_agent' => 'Mozilla/5.0 (compatible; Syncra demo)',
                'device' => $devices[$i % count($devices)],
                'browser' => $browsers[$i % count($browsers)],
                'platform' => $platforms[$i % count($platforms)],
                'session_id' => (string) Str::uuid(),
                'logged_in_at' => $isFailed ? null : $loggedInAt,
                'logged_out_at' => $isLogout ? $loggedInAt->addSeconds($duration) : null,
                'duration_seconds' => $isLogout ? $duration : null,
                'created_at' => $loggedInAt,
                'updated_at' => $loggedInAt,
            ];
        }

        $this->bulkInsert('session_logs', $sessions);
    }

    /**
     * Demo verinin iş kurallarına uyduğunu doğrular. Herhangi bir ihlal
     * exception fırlatır ve transaction'ın tamamı geri alınır.
     */
    private function assertConsistency(): void
    {
        $checks = [
            'won deal, is_won olmayan aşamada' => 'SELECT COUNT(*) AS c FROM deals d JOIN pipeline_stages s ON s.id = d.pipeline_stage_id WHERE d.status = \'won\' AND s.is_won = 0',
            'lost deal, is_lost olmayan aşamada' => 'SELECT COUNT(*) AS c FROM deals d JOIN pipeline_stages s ON s.id = d.pipeline_stage_id WHERE d.status = \'lost\' AND s.is_lost = 0',
            'open deal, kapanış aşamasında' => 'SELECT COUNT(*) AS c FROM deals d JOIN pipeline_stages s ON s.id = d.pipeline_stage_id WHERE d.status = \'open\' AND (s.is_won = 1 OR s.is_lost = 1)',
            'won/lost deal closed_at boş' => 'SELECT COUNT(*) AS c FROM deals WHERE status IN (\'won\', \'lost\') AND closed_at IS NULL',
            'open deal closed_at dolu' => 'SELECT COUNT(*) AS c FROM deals WHERE status = \'open\' AND closed_at IS NOT NULL',
            'aynı aşamada tekrarlanan position' => 'SELECT COUNT(*) AS c FROM (SELECT pipeline_stage_id, position FROM deals GROUP BY pipeline_stage_id, position HAVING COUNT(*) > 1) x',
            'converted lead, converted_at boş' => 'SELECT COUNT(*) AS c FROM leads WHERE status = \'converted\' AND converted_at IS NULL',
            'converted olmayan lead, converted_contact_id dolu' => 'SELECT COUNT(*) AS c FROM leads WHERE status <> \'converted\' AND converted_contact_id IS NOT NULL',
            'dm konuşmada katılımcı sayısı 2 değil' => 'SELECT COUNT(*) AS c FROM (SELECT c.id FROM conversations c JOIN conversation_user cu ON cu.conversation_id = c.id WHERE c.type = \'dm\' GROUP BY c.id HAVING COUNT(*) <> 2) x',
            'last_message_at son mesajla uyuşmuyor' => 'SELECT COUNT(*) AS c FROM conversations c LEFT JOIN (SELECT conversation_id, MAX(created_at) AS m FROM messages GROUP BY conversation_id) mm ON mm.conversation_id = c.id WHERE (c.last_message_at IS NULL AND mm.m IS NOT NULL) OR (c.last_message_at IS NOT NULL AND (mm.m IS NULL OR c.last_message_at <> mm.m))',
            'unread_count tutarsız' => 'SELECT COUNT(*) AS c FROM conversation_user cu WHERE cu.unread_count <> (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = cu.conversation_id AND (cu.last_read_message_id IS NULL OR m.id > cu.last_read_message_id))',
            'primary kişisi 1\'den fazla olan firma' => 'SELECT COUNT(*) AS c FROM (SELECT company_id FROM contacts WHERE is_primary = 1 AND company_id IS NOT NULL GROUP BY company_id HAVING COUNT(*) > 1) x',
        ];

        foreach ($checks as $label => $sql) {
            $count = (int) DB::selectOne($sql)->c;

            if ($count > 0) {
                throw new RuntimeException("DemoDataSeeder tutarlılık ihlali [{$label}]: {$count} kayıt.");
            }
        }

        $this->assertQuoteTotals();

        $this->assertMorphIntegrity('tasks', 'taskable');
        $this->assertMorphIntegrity('activities', 'activityable');
        $this->assertMorphIntegrity('attachments', 'attachable');
        $this->assertMorphIntegrity('taggables', 'taggable');
        $this->assertMorphIntegrity('custom_field_values', 'customizable');
        $this->assertMorphIntegrity('conversations', 'conversable');
    }

    /**
     * Teklif tutarlarını PHP tarafında yeniden hesaplayarak doğrular.
     *
     * Neden SQL DEĞİL: teklif geneli indirimi KDV oranı gruplarına dağıtan
     * largest-remainder algoritması (kesir → net → oran şeklinde üç kademeli
     * tie-break) tek bir SQL ifadesine çevrilemez. Eski
     * `ABS(total - (sub - discount + tax)) > 0.01` kontrolü KDV'yi indirim
     * ÖNCESİ matrahtan doğruluyordu; yeni modelde yanlış pozitif üretirdi.
     *
     * TOLERANS YOK: hesap deterministik olduğu için karşılaştırma tam
     * eşitliktir. Kuruş düzeyinde string karşılaştırması yapılır ki DB'den
     * gelen decimal(15,2) ile calculator'ın float çıktısı arasında float
     * karşılaştırması hiç devreye girmesin.
     */
    private function assertQuoteTotals(): void
    {
        $itemsByQuote = DB::table('quote_items')
            ->orderBy('quote_id')
            ->orderBy('position')
            ->get(['quote_id', 'quantity', 'unit_price', 'discount_percent', 'tax_rate', 'line_total'])
            ->groupBy('quote_id');

        $quotes = DB::table('quotes')
            ->orderBy('id')
            ->get(['id', 'quote_number', 'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'tax_amount', 'total']);

        foreach ($quotes as $quote) {
            $rows = $itemsByQuote[$quote->id] ?? collect();

            if ($rows->isEmpty()) {
                throw new RuntimeException("DemoDataSeeder teklif ihlali [{$quote->quote_number}]: kalemi yok.");
            }

            $expected = QuoteCalculator::calculate(
                $rows->map(fn ($row): array => [
                    'quantity' => $row->quantity,
                    'unit_price' => $row->unit_price,
                    'discount_percent' => $row->discount_percent,
                    'tax_rate' => $row->tax_rate,
                ])->all(),
                $quote->discount_value,
                $quote->discount_type,
            );

            foreach (['subtotal', 'discount_amount', 'tax_amount', 'total'] as $field) {
                $stored = $this->kurus($quote->{$field});
                $computed = $this->kurus($expected[$field]);

                if ($stored !== $computed) {
                    throw new RuntimeException(sprintf(
                        'DemoDataSeeder teklif ihlali [%s.%s]: kayıtlı %s, hesaplanan %s.',
                        $quote->quote_number,
                        $field,
                        $stored,
                        $computed
                    ));
                }
            }

            foreach ($rows->values() as $index => $row) {
                $stored = $this->kurus($row->line_total);
                $computed = $this->kurus($expected['items'][$index]['line_total']);

                if ($stored !== $computed) {
                    throw new RuntimeException(sprintf(
                        'DemoDataSeeder teklif ihlali [%s kalem #%d.line_total]: kayıtlı %s, hesaplanan %s.',
                        $quote->quote_number,
                        $index + 1,
                        $stored,
                        $computed
                    ));
                }
            }
        }
    }

    /**
     * Tutarı sabit biçimli kuruş string'ine çevirir; tam eşitlik
     * karşılaştırması float'a hiç dokunmadan yapılabilsin diye.
     */
    private function kurus(int|float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Morph alanlarının gerçekten var olan kayıtlara işaret ettiğini doğrular.
     */
    private function assertMorphIntegrity(string $table, string $morph): void
    {
        $types = DB::table($table)
            ->whereNotNull($morph.'_type')
            ->distinct()
            ->pluck($morph.'_type');

        foreach ($types as $type) {
            /** @var class-string<Model> $type */
            $related = (new $type)->getTable();

            $orphans = DB::table($table.' as t')
                ->leftJoin($related.' as r', 'r.id', '=', 't.'.$morph.'_id')
                ->where('t.'.$morph.'_type', $type)
                ->whereNull('r.id')
                ->count();

            if ($orphans > 0) {
                throw new RuntimeException("DemoDataSeeder morph ihlali [{$table}.{$morph} -> {$type}]: {$orphans} kayıt.");
            }
        }
    }

    private function summary(float $seconds): void
    {
        $tables = [
            'users', 'companies', 'contacts', 'pipeline_stages', 'deals', 'leads',
            'tasks', 'activities', 'tickets', 'products', 'quotes', 'quote_items',
            'attachments', 'conversations', 'conversation_user', 'messages',
            'notifications', 'page_visit_logs', 'session_logs', 'custom_fields',
            'custom_field_values', 'tags', 'taggables', 'settings',
        ];

        $this->command?->newLine();
        $this->command?->info('Demo veri üretildi ('.number_format($seconds, 2).' sn):');

        $rows = [];
        foreach ($tables as $table) {
            $rows[] = [$table, DB::table($table)->count()];
        }

        $this->command?->table(['Tablo', 'Kayıt'], $rows);
        $this->command?->info('Demo kullanıcı şifresi: '.self::PASSWORD.' (örn. elif.yildirim@syncra.local)');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function bulkInsert(string $table, array $rows, int $chunkSize = 200): void
    {
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function between(CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        if ($start->greaterThanOrEqualTo($end)) {
            return $end;
        }

        return $start->addSeconds($this->faker->numberBetween(0, $end->getTimestamp() - $start->getTimestamp()));
    }

    /**
     * Kanban için fractional index: sabit genişlikte base36 → sözlük sırası
     * sayısal sırayla aynıdır ve araya kart eklemek için boşluk bırakır.
     */
    private function fractionalKey(int $sequence): string
    {
        return 'a'.str_pad(base_convert((string) ($sequence * 32), 10, 36), 4, '0', STR_PAD_LEFT);
    }

    private function firstName(int $index): string
    {
        return $index % 2 === 0
            ? self::FIRST_NAMES_MALE[($index * 3) % count(self::FIRST_NAMES_MALE)]
            : self::FIRST_NAMES_FEMALE[($index * 3) % count(self::FIRST_NAMES_FEMALE)];
    }

    private function phone(): string
    {
        return '+90 '.$this->faker->numberBetween(212, 462).' '.$this->faker->numerify('### ## ##');
    }

    private function mobile(): string
    {
        return '+90 5'.$this->faker->numerify('##').' '.$this->faker->numerify('### ## ##');
    }

    /**
     * @param  list<int>  $items
     * @return list<int>
     */
    private function rotate(array $items, int $offset): array
    {
        $count = count($items);
        $offset %= $count;

        return array_merge(array_slice($items, $offset), array_slice($items, 0, $offset));
    }
}
