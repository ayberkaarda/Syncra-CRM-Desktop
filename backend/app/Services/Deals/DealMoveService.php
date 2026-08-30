<?php

namespace App\Services\Deals;

use App\Events\DealMoved;
use App\Exceptions\Deals\DealVersionConflictException;
use App\Http\Resources\DealCardResource;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Exchange\ExchangeRateService;
use App\Support\FractionalIndex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * =============================================================================
 * KANBAN KART TAŞIMA — projenin en zor eşzamanlılık problemi (ROADMAP R4)
 * =============================================================================
 *
 * Bir Kanban panosunda aynı kartı aynı anda birden çok kişi sürükler. Buradaki
 * her karar, "iki kullanıcı aynı saniyede ne yaparsa ne olur" sorusuna göre
 * verilmiştir.
 *
 * -----------------------------------------------------------------------------
 * İKİ KİLİT BİRDEN: SATIR KİLİDİ + VERSİYON
 * -----------------------------------------------------------------------------
 * `lockForUpdate()` (kötümser) transaction süresince satırı tutar; iki isteğin
 * hesaplarının BİRBİRİNE GİRMESİNİ engeller. Ama tek başına yetmez: ikinci
 * istek kilidi bekler, sırası gelince ÇALIŞIR ve birincinin sonucunu ezer.
 *
 * `version` (iyimser) tam olarak bunu yakalar: istemci, kartı EKRANDA GÖRDÜĞÜ
 * hâlin versiyonuyla gelir. Kilit alındıktan sonra versiyon tutmuyorsa, o
 * istemcinin gördüğü sütun/komşu/durum bayattır ve isteği 409 ile reddedilir.
 * Biri kartı "Kazanıldı"ya, diğeri "Kaybedildi"ye sürüklerse ikincisi sessizce
 * kazanmaz — kullanıcıya sorulur.
 *
 * -----------------------------------------------------------------------------
 * POZİSYON İSTEMCİDEN ALINMAZ
 * -----------------------------------------------------------------------------
 * İstemci yalnızca KOMŞULARI bildirir (`before_deal_id` / `after_deal_id`),
 * anahtarın kendisini asla. Neden: iki istemci aynı anda aynı iki kartın
 * arasına bırakırsa ikisi de aynı fractional index'i hesaplar ve aynı aşamada
 * ÇAKIŞAN iki `position` oluşur — sıralama o noktadan sonra veritabanının
 * rastgele tie-break'ine kalır. Anahtar daima sunucuda, kilit altında, taze
 * komşu değerlerinden üretilir.
 *
 * -----------------------------------------------------------------------------
 * EKSİK KOMŞU VERİTABANINDAN TAMAMLANIR
 * -----------------------------------------------------------------------------
 * İstemci yalnızca bir komşu gönderdiğinde (listenin ortasına bırakırken üst
 * komşuyu bilip alt komşuyu bilmemek olağandır) eksik taraf "liste sonu" kabul
 * EDİLMEZ; hedef aşamadaki gerçek komşu sorgulanır. Aksi hâlde kart, kullanıcı
 * onu iki kartın arasına bırakmışken sütunun en dibine düşerdi.
 */
class DealMoveService
{
    public function __construct(private readonly ExchangeRateService $rates) {}

    /**
     * @param  array{
     *     to_stage_id: int,
     *     before_deal_id?: ?int,
     *     after_deal_id?: ?int,
     *     version: int,
     *     lost_reason?: ?string,
     *     won_reason?: ?string
     * }  $payload
     *
     * @throws DealVersionConflictException 409 — kart bu arada değişti
     * @throws ValidationException 422 — geçersiz hedef aşama / komşu / eksik kayıp nedeni
     */
    public function move(Deal $deal, array $payload, User $actor): Deal
    {
        [$deal, $fromStageId] = DB::transaction(function () use ($deal, $payload): array {
            $this->lockDeal($deal);
            $this->guardVersion($deal, (int) $payload['version']);

            $stage = $this->resolveTargetStage((int) $payload['to_stage_id']);
            $fromStageId = (int) $deal->pipeline_stage_id;

            $position = $this->resolvePosition(
                $deal,
                (int) $stage->getKey(),
                $payload['before_deal_id'] ?? null,
                $payload['after_deal_id'] ?? null,
            );

            $deal->pipeline_stage_id = $stage->getKey();
            $deal->position = $position;

            $this->applyStageOutcome($deal, $stage, $payload);
            $this->applyProbability($deal, $stage, $fromStageId);

            // Optimistic lock sayacı. `version + 1` DEĞİL, `$deal->version + 1`:
            // değer az önce kilitli satırdan okundu, dolayısıyla arada kimse
            // artıramaz. Ham SQL `version = version + 1` de aynı işi görürdü
            // ama modelden okunan değeri bayat bırakırdı.
            $deal->version = (int) $deal->version + 1;

            $deal->save();

            return [$deal, $fromStageId];
        });

        // Yayın TRANSACTION DIŞINDA. İçeride dispatch edilseydi, geri alınan
        // bir taşıma da yayınlanmış olurdu: panolar kartı taşır, veritabanı
        // taşımaz. Kuyruk işçisinin commit'ten önce satırı okuma sorunu ise
        // ayrıca payload'ın düz skaler olmasıyla çözülür (bkz. DealMoved).
        broadcast(new DealMoved(DealMoved::payload($deal, $fromStageId, $actor)))->toOthers();

        return $deal;
    }

    /**
     * Satırı transaction süresince kilitler ve çağıranın model örneğini kilitli
     * satırın GERÇEK hâliyle tazeler.
     *
     * Route model binding'in hidratladığı örnek, isteğin başındaki fotoğraftır;
     * versiyon karşılaştırması onun üzerinden yapılırsa iki eşzamanlı istek de
     * "versiyon tutuyor" der ve çakışma tespiti tamamen devre dışı kalır.
     *
     * @throws ValidationException
     */
    private function lockDeal(Deal $deal): void
    {
        /** @var Deal|null $locked */
        $locked = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            // Araya giren bir soft delete.
            throw ValidationException::withMessages([
                'deal' => 'Taşınacak fırsat bulunamadı; bu arada silinmiş olabilir.',
            ]);
        }

        $deal->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * @throws DealVersionConflictException
     */
    private function guardVersion(Deal $deal, int $expected): void
    {
        $actual = (int) $deal->version;

        if ($actual === $expected) {
            return;
        }

        // Çakışma yanıtı, 200 yanıtıyla ve panonun kendisiyle AYNI kart
        // gösterimini taşır (DealCardResource): istemci çakışmayı ayrı bir kod
        // yolu olarak değil, "kartın güncel hâlini yerine oturt" olarak işler.
        // İlişkiler burada yüklenir — bu maliyet yalnızca çakışma yolunda
        // ödenir, mutlu yolda değil.
        $deal->loadMissing(['company', 'contact', 'owner', 'tags']);

        throw new DealVersionConflictException(
            (new DealCardResource($deal))->resolve(),
            $expected,
            $actual,
        );
    }

    /**
     * Hedef aşama var olmalı ve AKTİF olmalı.
     *
     * Pasif aşama, "artık kullanılmıyor" demektir (pipeline_stages'te soft
     * delete yok, pasifleştirme var). Oraya kart taşımak, kartı panodan
     * görünmez kılar: sütun render edilmez, kart hiçbir yerde çıkmaz.
     *
     * @throws ValidationException
     */
    private function resolveTargetStage(int $stageId): PipelineStage
    {
        /** @var PipelineStage|null $stage */
        $stage = PipelineStage::query()->whereKey($stageId)->first();

        if ($stage === null) {
            throw ValidationException::withMessages([
                'to_stage_id' => 'Hedef pipeline aşaması bulunamadı.',
            ]);
        }

        if (! $stage->is_active) {
            throw ValidationException::withMessages([
                'to_stage_id' => 'Pasif bir aşamaya kart taşınamaz.',
            ]);
        }

        return $stage;
    }

    /**
     * Yeni `position` — daima sunucuda, kilit altında, taze komşulardan.
     *
     * @throws ValidationException
     */
    private function resolvePosition(Deal $deal, int $stageId, ?int $beforeId, ?int $afterId): string
    {
        $before = $this->resolveNeighbour($deal, $stageId, $beforeId, 'before_deal_id');
        $after = $this->resolveNeighbour($deal, $stageId, $afterId, 'after_deal_id');

        if ($before !== null && $after !== null && $before->getKey() === $after->getKey()) {
            throw ValidationException::withMessages([
                'after_deal_id' => 'Bir kart kendisiyle komşu gösterilemez.',
            ]);
        }

        $beforePosition = $before?->position;
        $afterPosition = $after?->position;

        // Eksik tarafı veritabanından tamamla (sınıf başlığındaki not).
        if ($beforePosition !== null && $afterPosition === null) {
            $afterPosition = $this->neighbourPosition($deal, $stageId, $beforePosition, 'after');
        } elseif ($afterPosition !== null && $beforePosition === null) {
            $beforePosition = $this->neighbourPosition($deal, $stageId, $afterPosition, 'before');
        } elseif ($beforePosition === null && $afterPosition === null) {
            // Komşu verilmedi: sütunun sonuna.
            $beforePosition = $this->lastPositionInStage($deal, $stageId);
        }

        try {
            return FractionalIndex::between($beforePosition, $afterPosition);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // Ters/bozuk komşular ya da tükenmiş anahtar alanı. Sunucu hatası
            // DEĞİL: istemcinin gönderdiği komşular veritabanındaki sırayla
            // uyuşmuyor demektir, kullanıcı panoyu tazelemeli.
            throw ValidationException::withMessages([
                'before_deal_id' => 'Kartın bırakıldığı konum hesaplanamadı: '.$exception->getMessage(),
            ]);
        }
    }

    /**
     * Komşu olarak gösterilen kart: var olmalı, silinmemiş olmalı, HEDEF
     * AŞAMADA olmalı ve taşınan kartın kendisi olmamalı.
     *
     * Başka aşamanın kartını komşu göstermek tutarsız bir sıralama üretir:
     * pozisyon anahtarı yalnızca kendi sütunu içinde anlamlıdır, başka
     * sütundan alınan bir değer hedefte rastgele bir yere denk gelir.
     *
     * @throws ValidationException
     */
    private function resolveNeighbour(Deal $deal, int $stageId, ?int $neighbourId, string $field): ?Deal
    {
        if ($neighbourId === null) {
            return null;
        }

        if ($neighbourId === (int) $deal->getKey()) {
            throw ValidationException::withMessages([
                $field => 'Bir kart kendisiyle komşu gösterilemez.',
            ]);
        }

        /** @var Deal|null $neighbour */
        $neighbour = Deal::query()->whereKey($neighbourId)->first();

        if ($neighbour === null) {
            throw ValidationException::withMessages([
                $field => 'Komşu olarak gösterilen kart bulunamadı.',
            ]);
        }

        if ((int) $neighbour->pipeline_stage_id !== $stageId) {
            throw ValidationException::withMessages([
                $field => 'Komşu olarak gösterilen kart hedef aşamada değil.',
            ]);
        }

        return $neighbour;
    }

    /**
     * Hedef aşamada, verilen anahtarın hemen üstündeki/altındaki kartın
     * pozisyonu. Taşınan kartın kendisi hariç tutulur — aynı aşama içinde
     * yapılan bir taşımada kart kendi eski yerini komşu sanmamalı.
     */
    private function neighbourPosition(Deal $deal, int $stageId, string $anchor, string $direction): ?string
    {
        $query = Deal::query()
            ->where('pipeline_stage_id', $stageId)
            ->whereKeyNot($deal->getKey());

        if ($direction === 'after') {
            return $query->where('position', '>', $anchor)->orderBy('position')->value('position');
        }

        return $query->where('position', '<', $anchor)->orderByDesc('position')->value('position');
    }

    /**
     * Sütundaki en büyük anahtar.
     *
     * Soft-deleted kartlar da sayılır (`withTrashed`): silinmiş bir kart geri
     * yüklendiğinde araya giren yeni kartla aynı anahtara düşmemeli.
     */
    private function lastPositionInStage(Deal $deal, int $stageId): ?string
    {
        return Deal::withTrashed()
            ->where('pipeline_stage_id', $stageId)
            ->whereKeyNot($deal->getKey())
            ->orderByDesc('position')
            ->value('position');
    }

    /**
     * Aşama geçiş kuralları — durum, kapanış zamanı ve nedenler.
     *
     * `lost_reason` KAZANILMIŞ bir kartta değil, KAYBEDİLMİŞ bir kartta
     * ZORUNLUDUR: kayıp nedeni satış analitiğinin en değerli verisidir
     * ("fiyat" mı, "rakip" mi, "bütçe" mi?) ve opsiyonel bırakılırsa pratikte
     * hiç doldurulmaz — kart zaten sürüklenip bırakılmıştır, kimse geri dönüp
     * form açmaz. `won_reason` ise opsiyoneldir: kazanmanın nedenini bilmemek
     * canımızı yakmaz, kaybetmenin nedenini bilmemek yakar.
     *
     * Kart açık bir aşamaya geri taşındığında İKİ neden de temizlenir. Geri
     * açılan bir kartta duran eski "Fiyat yüksek bulundu" notu, kartı gören
     * herkesi yanıltır ve raporlarda hâlâ kaybedilmiş gibi görünür.
     *
     * @param  array{lost_reason?: ?string, won_reason?: ?string}  $payload
     *
     * @throws ValidationException
     */
    private function applyStageOutcome(Deal $deal, PipelineStage $stage, array $payload): void
    {
        if ($stage->is_won) {
            $deal->status = 'won';
            // `?? now()`: zaten kapalı bir kartın sütun içinde yeniden
            // sıralanması kapanış tarihini DEĞİŞTİRMEMELİ, yoksa "temmuzda
            // kazanılan işler" raporu her sürüklemede kayar.
            $deal->closed_at = $deal->closed_at ?? now();
            // Gövdede neden yoksa mevcut neden korunur: aynı sütun içinde
            // yeniden sıralamak, girilmiş nedeni silmek anlamına gelmez.
            $deal->won_reason = $this->trimmed($payload['won_reason'] ?? null) ?? $deal->won_reason;
            $deal->lost_reason = null;
            $this->freezeBaseAmount($deal);

            return;
        }

        if ($stage->is_lost) {
            $reason = $this->trimmed($payload['lost_reason'] ?? null) ?? $deal->lost_reason;

            if ($reason === null) {
                throw ValidationException::withMessages([
                    'lost_reason' => 'Kartı kayıp aşamasına taşımak için kayıp nedeni zorunludur.',
                ]);
            }

            $deal->status = 'lost';
            $deal->closed_at = $deal->closed_at ?? now();
            $deal->lost_reason = $reason;
            $deal->won_reason = null;
            $this->freezeBaseAmount($deal);

            return;
        }

        $deal->status = 'open';
        $deal->closed_at = null;
        $deal->lost_reason = null;
        $deal->won_reason = null;
        $this->clearBaseAmount($deal);
    }

    /**
     * ---------------------------------------------------------------------
     * KAPANIŞ ANI KURUNUN DONDURULMASI (Faz 14 / İz E, PHASE-INTL §2.3–§2.4)
     * ---------------------------------------------------------------------
     * NEDEN BURADA, OBSERVER'DA DEĞİL: bir fırsatın `won`/`lost` olduğu TEK
     * yer bu metottur (`$deal->status = 'won'|'lost'` başka hiçbir yerde
     * yazılmaz) ve burası zaten `lockForUpdate` ile kilitlenmiş satırın,
     * `closed_at`'in belirlendiği transaction'ın İÇİDİR. Donmuş tutarın
     * dayandığı "kapanış günü" ile `closed_at` aynı satırda, aynı kilidin
     * altında, aynı anda yazılır — ikisi ASLA sapamaz.
     *
     * Bir `saving`/`saved` observer'ı aynı garantiyi veremezdi: observer her
     * `save()`'de (yeniden sıralama, sahip atama, başlık düzenleme)
     * tetiklenir ve "bu kaydın hangi işlemin parçası olduğunu" bilmez;
     * kapanmış bir fırsatın donmuş kurunu ilgisiz bir güncellemede sessizce
     * tazeleme riski doğardı — tam olarak kaçınmak istediğimiz şey.
     *
     * BİR KEZ DONAR, TAZELENMEZ: `closed_at`'in `?? now()` kuralıyla aynı
     * gerekçe — kazanılmış bir kartı sütun içinde yeniden sıralamak ne
     * kapanış tarihini ne de kapanış kurunu değiştirmelidir.
     * (`DealPolicy::update()` zaten kapanmış fırsatta `amount` düzenlemesine
     * izin vermez, dolayısıyla donmuş tutarın kaydın kendisiyle çelişmesi
     * mümkün değildir.)
     *
     * KUR YOKSA (bilinçli karar, PHASE-INTL §2.4 "sessiz hata" yasağı):
     *   1. Kapanış gününde geçerli kur → kullanılır (doğru olan).
     *   2. Yoksa EN SON BİLİNEN kur → kullanılır (ExchangeRateService::
     *      resolveForFreeze), çünkü elde gerçek bir veri varken tahmin
     *      üretmenin anlamı yok.
     *   3. Hiç kur yoksa → ÜÇ ALAN DA null KALIR + `warning` loglanır.
     *      SIFIR YAZILMAZ: 0.00 "bu iş hiç gelir getirmedi" demektir ve
     *      raporu sessizce yanıltır; null "bilinmiyor" demektir ve rapor
     *      bunu `rate_info.unconverted_closed_count` alanıyla GÖRÜNÜR kılar
     *      (bkz. App\Services\Reports\Support\ReportCurrencyContext).
     */
    private function freezeBaseAmount(Deal $deal): void
    {
        if ($deal->base_amount !== null) {
            return;
        }

        $currency = strtoupper((string) ($deal->currency ?: $this->rates->baseCurrency()));
        $amount = (string) ($deal->amount ?? '0');
        $closedOn = CarbonImmutable::parse($deal->closed_at ?? now())->startOfDay();

        if ($this->rates->isBaseCurrency($currency)) {
            // TRY: kur tanım gereği 1.000000 — DB'de satırı yoktur, olması da
            // gerekmez (bkz. create_exchange_rates_table göç dokümanı).
            $deal->base_amount = $amount;
            $deal->base_rate = '1.000000';
            $deal->base_rate_date = $closedOn->toDateString();

            return;
        }

        $rate = $this->rates->resolveForFreeze($currency, $closedOn);
        // Dönüşüm, kur satırının KENDİ tarihiyle yapılır ($rate->rate_date):
        // kapanış gününde satır yoksa `resolveForFreeze` en son bilinen kura
        // düşer ve `toBase($amount, $currency, $closedOn)` o satırı BULAMAZ
        // (tarihi kapanıştan sonradır) — donmuş kur ile donmuş tutarın farklı
        // satırlardan gelmesi tam olarak yasaklamak istediğimiz sapmadır.
        $baseAmount = $rate === null
            ? null
            : $this->rates->toBase($amount, $currency, CarbonImmutable::parse($rate->rate_date));

        if ($rate === null || $baseAmount === null) {
            Log::warning('Fırsat kapanışında kur bulunamadı; donmuş temel tutar yazılmadı.', [
                'deal_id' => $deal->getKey(),
                'currency' => $currency,
                'closed_on' => $closedOn->toDateString(),
            ]);

            return;
        }

        $deal->base_amount = $baseAmount;
        $deal->base_rate = (string) $rate->rate;
        $deal->base_rate_date = $rate->rate_date->toDateString();
    }

    /**
     * YENİDEN AÇILMA (`won`/`lost` → `open`): donmuş alanlar TEMİZLENİR.
     *
     * `lost_reason`/`won_reason`'ın temizlenmesiyle aynı gerekçe: artık açık
     * olan bir fırsatta duran "kapanış anı TRY karşılığı", gerçekleşmemiş bir
     * geliri gerçekleşmiş gibi gösterir; kayıt kendi içinde çelişkili kalır
     * ve fırsat ikinci kez kapandığında ESKİ kurun donmuş kalmasına yol
     * açardı. Fırsat yeniden kapanırsa yeni kapanış anının kuru yazılır
     * (donma bir kez, ama HER kapanış için yeniden).
     */
    private function clearBaseAmount(Deal $deal): void
    {
        $deal->base_amount = null;
        $deal->base_rate = null;
        $deal->base_rate_date = null;
    }

    /**
     * ---------------------------------------------------------------------
     * KARAR: `probability` yalnızca BOŞSA aşamadan doldurulur, asla EZİLMEZ
     * ---------------------------------------------------------------------
     * Aşamanın `probability` değeri bir VARSAYILANDIR ("teklif gönderildi
     * aşamasındaki işlerin ~%60'ı kapanır"), kartın kendi değeri ise bir
     * YARGIDIR ("bu müşteri kararsız, ben %20 diyorum"). Sürükle-bırak bir
     * sıralama hareketidir; kullanıcının elle girdiği yargıyı sessizce silmesi
     * beklenmez. Silseydi, kullanıcı tahminini her sütun değişiminde yeniden
     * girmek zorunda kalır ve alanı kullanmayı bırakırdı.
     *
     * Kazanıldı/Kaybedildi aşamalarında bile EZMİYORUZ; ilk bakışta "kayıp
     * kartın olasılığı 0 olmalı" demek cazip ama kapanmış bir kartta olasılık
     * artık bir tahmin değil, ölü bir alandır — gerçeği `status` ve
     * `closed_at` söyler. Ağırlıklı pipeline raporları zaten yalnızca `open`
     * kartları toplar, dolayısıyla ezmemenin bir maliyeti yok; ezmenin
     * maliyeti ise kullanıcı verisinin kaybı olurdu.
     *
     * Aynı aşama içinde yapılan sıralama değişikliğinde alana hiç dokunulmaz.
     */
    private function applyProbability(Deal $deal, PipelineStage $stage, int $fromStageId): void
    {
        if ((int) $stage->getKey() === $fromStageId) {
            return;
        }

        if ($deal->probability !== null) {
            return;
        }

        $deal->probability = $stage->probability;
    }

    private function trimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
