<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Repositories\LeadRepository;
use App\Support\ImportBatch;
use App\Sync\SyncCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\ActivityLogStatus;
use Spatie\Activitylog\CauserResolver;

/**
 * CSV satırlarını lead kayıtlarına dönüştüren orkestrasyon katmanı.
 * `LeadImportController` (senkron akış) ve `ImportLeadsJob` (kuyruklu akış)
 * AYNI `process()` metodunu çağırır — iki akış arasında davranış farkı
 * olmasın diye iş mantığı tek bir yerde durur.
 */
class LeadImportService
{
    /**
     * 500 satırın altı senkron işlenir, üstü kuyruğa devredilir.
     *
     * GEREKÇE: Satır başına maliyet (doğrulama + ~1-2 duplicate sorgusu +
     * bir INSERT/UPDATE) birkaç milisaniye mertebesinde; 500 satır tipik
     * olarak birkaç saniyede biter — bu, PHP-FPM/nginx'in varsayılan 30-60
     * saniyelik istek zaman aşımının güvenle altında kalır ve kullanıcı
     * sonucu ANINDA görür (ekstra bir "durumu sorgula" adımı olmadan). Daha
     * büyük dosyalarda süre bu zaman aşımını riske atar; o noktadan sonra
     * dosyayı kuyruğa atıp 202 + `batch_id` dönmek, isteği bloklamadan
     * kullanıcının `GET /leads/import/{batch}` ile ilerlemeyi izlemesine
     * izin verir.
     */
    public const SYNC_ROW_THRESHOLD = 500;

    public function __construct(
        private readonly LeadCsvParser $parser,
        private readonly LeadRepository $leads,
        private readonly DuplicateDetector $duplicates,
    ) {}

    /**
     * @param  resource  $handle
     */
    public function readHeader($handle): void
    {
        $this->parser->readHeader($handle);
    }

    /**
     * @return array<int, string>
     */
    public function missingRequiredColumns(): array
    {
        return $this->parser->missingRequiredColumns();
    }

    /**
     * @return array<int, string>
     */
    public function unknownColumns(): array
    {
        return $this->parser->unknownColumns();
    }

    /**
     * `readHeader()` sonrası kalan veri satırlarını sayar (boş satırlar
     * hariç). Senkron/kuyruk kararı bu sayıya göre verilir.
     *
     * @param  resource  $handle
     */
    public function countDataRows($handle): int
    {
        $count = 0;

        foreach ($this->parser->rows($handle) as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * `readHeader()` çağrıldıktan sonraki dosya konumundan itibaren tüm
     * satırları işler ve `$batch`'i günceller. Hem senkron istek hem de
     * `ImportLeadsJob` tarafından çağrılır.
     *
     * @param  resource  $handle
     */
    public function process(ImportBatch $batch, $handle, string $duplicateMode, ?int $ownerId, User $actor): void
    {
        $batch->markProcessing();

        /** @var ActivityLogStatus $logStatus */
        $logStatus = app(ActivityLogStatus::class);

        // AUDIT GÜRÜLTÜSÜ: 500 lead'lik bir import, Lead modelinin
        // LogsCrmActivity trait'i yüzünden 500 ayrı `activity_log` satırı
        // üretirdi (her create bir audit satırı demek). Bu, "kim ne
        // değiştirdi" sorusuna cevap vermez — tek bir kullanıcı eylemi 500
        // ayrı satıra bölünmüş olur ve Logs sayfasını doldurur. Toplu
        // import'un kendisi İZLENEBİLİR OLMALI (kim, ne zaman, kaç kayıt) ama
        // bunun doğru birimi TEK BİR ÖZET satırıdır, lead başına bir satır
        // değil. Bu yüzden model bazlı otomatik loglama import süresince
        // global olarak kapatılır (`ActivityLogStatus::disable()` —
        // spatie/laravel-activitylog'un uygulama genelinde tek anahtarı) ve
        // döngü sonunda `logSummary()` ile tek bir `leads.imported` satırı
        // yazılır. `finally` ile geri açılıyor: import sırasında bir istisna
        // fırlarsa bile sonraki isteklerin audit logu sessizce kapalı
        // kalmamalı.
        $logStatus->disable();

        // Kuyruktaki import'ta oturum yoktur: satır başına güncelleme denetim
        // satırları (updateWithAudit) causer'sız kalırdı. Import boyunca
        // causer açıkça import'u başlatan kullanıcıya bağlanır.
        /** @var CauserResolver $causer */
        $causer = app(CauserResolver::class);
        $causer->setCauser($actor);

        $createdIds = [];
        $updatedIds = [];

        try {
            $sinceFlush = 0;

            foreach ($this->parser->rows($handle) as $row) {
                $this->processRow($row, $batch, $duplicateMode, $ownerId, $actor, $createdIds, $updatedIds);

                // Her satırda Redis'e yazmak yerine 50 satırda bir — bkz.
                // ImportBatch::flush() dokümantasyonu.
                if (++$sinceFlush >= 50) {
                    $batch->flush();
                    $sinceFlush = 0;
                }
            }

            $batch->flush();
            $batch->markCompleted();
        } finally {
            $logStatus->enable();
            $causer->setCauser(null);
        }

        $this->logSummary($batch, $actor, $createdIds, $updatedIds);
    }

    /**
     * @param  array{row: int, data: ?array<string, mixed>, errors: array<int, string>, warnings: array<int, string>, raw: array<string, mixed>}  $row
     * @param  array<int, int>  $createdIds
     * @param  array<int, int>  $updatedIds
     */
    private function processRow(array $row, ImportBatch $batch, string $duplicateMode, ?int $ownerId, User $actor, array &$createdIds, array &$updatedIds): void
    {
        $batch->incrementProcessed();

        if ($row['errors'] !== []) {
            $batch->addError($row['row'], implode(' ', $row['errors']), $row['raw'], 'error');
            $batch->incrementFailed();

            return;
        }

        $data = $row['data'];
        $data['owner_id'] = $ownerId;

        foreach ($row['warnings'] as $warning) {
            $batch->addError($row['row'], $warning, $row['raw'], 'warning');
        }

        // `create` modunda duplicate'in kim olduğu davranışı etkilemez —
        // satır zaten oluşturulacak. Sorguyu atlamak, kuyruklu büyük
        // dosyalarda satır başına 1-3 gereksiz SQL sorgusundan kurtarır.
        $strong = null;

        if ($duplicateMode !== 'create') {
            $candidates = $this->duplicates->findCandidates([
                'email' => $data['email'],
                'phone' => $data['phone'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'company_name' => $data['company_name'],
            ]);

            $strong = $candidates->firstWhere('level', DuplicateDetector::LEVEL_STRONG);
        }

        if ($strong !== null && $duplicateMode === 'skip') {
            $batch->addError(
                $row['row'],
                "Güçlü duplicate bulundu ({$strong['type']} #{$strong['id']}, skor {$strong['score']}) — satır atlandı.",
                $row['raw'],
                'skipped',
            );
            $batch->incrementSkipped();

            return;
        }

        if ($strong !== null && $duplicateMode === 'update') {
            if ($strong['type'] === DuplicateDetector::TYPE_LEAD) {
                $outcome = $this->updateMatchedLead((int) $strong['id'], $data, $actor);

                if ($outcome === 'denied') {
                    $batch->addError(
                        $row['row'],
                        "Eşleşen lead #{$strong['id']} için güncelleme yetkiniz yok (sahiplik veya dönüşmüş kayıt) — satır atlandı.",
                        $row['raw'],
                        'skipped',
                    );
                    $batch->incrementSkipped();

                    return;
                }

                if ($outcome === 'updated') {
                    $updatedIds[] = (int) $strong['id'];
                    $batch->incrementUpdated();

                    return;
                }

                // Aday bulunduktan sonra silinmiş olabilir (nadir yarış
                // durumu) — bu durumda normal oluşturma akışına düş.
            } else {
                // Duplicate bir contact ise GÜNCELLEME YAPILMAZ: contact
                // zaten müşteri kaydıdır ve import formundaki alanlarla ezmek
                // (ör. bir satış temsilcisinin lead formuna yazdığı eski bir
                // telefon numarasıyla canlı bir müşteri kaydının üzerine
                // yazmak) veri kaybına yol açabilir. Lead tarafını
                // güncellemek görece güvenlidir (lead zaten "henüz
                // nitelendirilmemiş aday" kaydıdır), contact tarafı DEĞİLDİR.
                // Bu yüzden satır atlanır, kullanıcı raporda görüp isterse
                // elle işlem yapar.
                $batch->addError(
                    $row['row'],
                    "Duplicate bir kişi (contact #{$strong['id']}) — içe aktarmadan güncellenmesi güvenli değil, satır atlandı.",
                    $row['raw'],
                    'skipped',
                );
                $batch->incrementSkipped();

                return;
            }
        }

        $lead = $this->leads->create($data);
        $createdIds[] = $lead->id;
        $batch->incrementCreated();
    }

    /**
     * Eşleşen lead'i KİLİTLİ okuyup karar verir ve aynı transaction'da yazar.
     *
     * Import izni bir güncelleme izni DEĞİLDİR: satır, web'deki PATCH ile aynı
     * LeadPolicy::update() kapısından geçer (`leads.update` + sahiplik +
     * dönüşmüş lead kilidi). Karar kilitsiz bir anlık görüntüye göre verilseydi
     * araya giren bir dönüşüm (LeadConversionService de satırı kilitler) ya da
     * devretme sonrasında `converted` durum `new` ile ezilir veya artık
     * başkasının olan lead yazılırdı. Kilit sırası SyncCounter'daki kuralı
     * izler: önce sayaç, sonra satır.
     *
     * @param  array<string, mixed>  $data
     * @return 'updated'|'denied'|'missing'
     */
    private function updateMatchedLead(int $leadId, array $data, User $actor): string
    {
        return DB::transaction(function () use ($leadId, $data, $actor): string {
            SyncCounter::lock();

            /** @var Lead|null $lead */
            $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();

            // Aday bulunduktan sonra silinmiş olabilir: çağıran normal
            // oluşturma akışına düşer.
            if ($lead === null) {
                return 'missing';
            }

            if (Gate::forUser($actor)->denies('update', $lead)) {
                return 'denied';
            }

            // Sahip güncellemede DEĞİŞMEZ: web'de de devretme ayrı bir uçtur
            // (UpdateLeadRequest `owner_id` => missing). Import'un `owner_id`'si
            // yalnızca YENİ satırların sahibidir.
            unset($data['owner_id']);

            $this->updateWithAudit($lead, $data);

            return 'updated';
        });
    }

    /**
     * Mevcut bir kaydın üzerine yazmak, yeni kayıt açmaktan farklıdır: eski
     * değerler yalnızca alan düzeyindeki denetim satırında kalır. Toplu
     * özet (`leads.imported`) yalnızca id taşır, ezilen değeri geri getiremez.
     * Bu yüzden import boyunca kapatılan model loglaması, GÜNCELLEME için
     * satır başına geçici olarak açılır; oluşturmalar özet satırında kalır.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateWithAudit(Lead $lead, array $data): void
    {
        /** @var ActivityLogStatus $logStatus */
        $logStatus = app(ActivityLogStatus::class);

        $logStatus->enable();

        try {
            $this->leads->update($lead, $data);
        } finally {
            $logStatus->disable();
        }
    }

    /**
     * @param  array<int, int>  $createdIds
     * @param  array<int, int>  $updatedIds
     */
    private function logSummary(ImportBatch $batch, User $actor, array $createdIds, array $updatedIds): void
    {
        if ($createdIds === [] && $updatedIds === []) {
            return;
        }

        activity('crm')
            ->causedBy($actor)
            ->withProperties([
                'batch_id' => $batch->id(),
                'created_count' => count($createdIds),
                'updated_count' => count($updatedIds),
                // Teşhis için ilk 100 id yeterli; tamamı zaten `leads`
                // tablosunda sorgulanabilir (`owner_id` + zaman aralığı).
                'created_lead_ids' => array_slice($createdIds, 0, 100),
                'updated_lead_ids' => array_slice($updatedIds, 0, 100),
            ])
            ->log('leads.imported');
    }
}
