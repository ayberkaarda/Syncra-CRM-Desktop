<?php

namespace App\Services\Settings;

use App\Events\DealMoved;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\FractionalIndex;
use App\Sync\SyncVersionBumper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * PIPELINE AŞAMA YÖNETİMİ — MEVCUT KANBAN'I KIRMADAN
 * =============================================================================
 *
 * Ayarlar ekranı, Faz 7'de yazılmış çalışan bir panonun ALTINDAKİ sütun
 * tanımlarını değiştirir. Buradaki her karar, "bu değişiklik yapıldığında açık
 * duran panolarda ve o panolardaki kartlarda ne olur" sorusuna göre verildi.
 *
 * -----------------------------------------------------------------------------
 * AŞAMALAR SİLİNMEZ
 * -----------------------------------------------------------------------------
 * `pipeline_stages` tablosunda `softDeletes` YOKTUR (migration'daki not) ve
 * `deals.pipeline_stage_id` yabancı anahtarı `restrictOnDelete` ile bağlıdır.
 * Yani silme ya veritabanı tarafından reddedilir ya da (kartlar önce
 * taşınsaydı) tarihsel raporlardan bir sütunu tamamen yok ederdi. Silmenin
 * yerine PASİFLEŞTİRME vardır: aşama kalır, panoda çizilmez.
 *
 * -----------------------------------------------------------------------------
 * PASİFLEŞTİRME: SESSİZ KAYBOLMA YASAK
 * -----------------------------------------------------------------------------
 * Bir aşamayı pasifleştirmek, içindeki kartları panodan siler — kayıtlar durur
 * ama kimse onları göremez. Bu yüzden AÇIK fırsatı olan bir aşama
 * `move_to_stage_id` OLMADAN pasifleştirilemez (422 `STAGE_HAS_OPEN_DEALS`).
 * Hata gövdesi kaç fırsat olduğunu ve hangi aşamaların hedef olabileceğini de
 * taşır: kullanıcı ikinci bir istek atmadan karar verebilsin diye.
 *
 * KAPALI (won/lost) kartlar taşınmaz. Onlar bir SONUCUN kaydıdır; "Kaybedildi"
 * sütunundaki bir kartı başka bir sütuna taşımak geçmişi yeniden yazardı.
 * Zaten panoda da sonuç sütunlarında dururlar, pasifleşen sütunda değil.
 *
 * -----------------------------------------------------------------------------
 * TAŞIMA, KANBAN'IN KENDİ KURALLARIYLA YAPILIR
 * -----------------------------------------------------------------------------
 * Toplu taşıma, `deals` tablosuna doğrudan `UPDATE` atmakla YAPILMAZ. Üç şey
 * korunur:
 *
 *  1. `position` — App\Support\FractionalIndex ile üretilir, elle değil.
 *     Alfabesi yalnızca küçük harfli base36'dır ve bu KRİTİK bir karardır:
 *     kolonun collation'ı `utf8mb4_unicode_ci` (büyük/küçük harf duyarsız), o
 *     yüzden PHP'nin `strcmp()` sıralamasıyla MySQL'in `ORDER BY` sıralaması
 *     ancak bu alfabeyle birebir aynı olur. Kendi üreteci yazan bir kod, aynı
 *     kolona farklı bir alfabeyle değer basıp sıralamayı sessizce bozardı.
 *  2. `version` — optimistic locking sayacı +1. Uzaktaki bir panonun elindeki
 *     kart artık bayattır; sürüklenirse 409 alması GEREKİR, sessizce
 *     kazanmamalıdır.
 *  3. `DealMoved` yayını — her taşıma için, mevcut olay ve mevcut payload
 *     biçimiyle. Açık panolar kartı doğru sütundan alıp doğru sütuna koyar.
 *
 * Yayın TRANSACTION'DAN SONRA yapılır: geri alınan bir taşıma yayınlanırsa
 * panolar kartı taşır, veritabanı taşımaz (DealMoveService'teki aynı karar).
 *
 * `->toOthers()` KULLANILMAZ — DealMoveService'ten kasıtlı fark. Orada olayı
 * tetikleyen kullanıcı kartı zaten optimistic update ile yerine koymuştur ve
 * yankı kartı zıplatır. Burada tetikleyici Ayarlar ekranındadır; panoyu
 * gösteren hiçbir istemci (kendi ikinci sekmesi dahil) bu taşımadan haberdar
 * değildir, dolayısıyla herkesin haberi olmalıdır.
 */
class PipelineStageService
{
    use DeniesSettingsChange;

    /**
     * `pipeline_stages.color` hex DEĞİL, tasarım sistemi token adı tutar
     * (PipelineStageSeeder ve frontend'deki tokenBadgeVariant.ts ile aynı
     * sözlük).
     *
     * @var array<int, string>
     */
    public const COLORS = ['primary', 'success', 'danger', 'warning', 'neutral', 'info'];

    /**
     * @return Collection<int, PipelineStage>
     */
    public function list(bool $includeInactive = true): Collection
    {
        $query = PipelineStage::query()->withCount('deals')->orderBy('position');

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * `POST /api/settings/pipeline-stages` — yeni aşama listenin SONUNA.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PipelineStage
    {
        $isWon = (bool) ($data['is_won'] ?? false);
        $isLost = (bool) ($data['is_lost'] ?? false);

        if ($isWon && $isLost) {
            $this->deny(
                'Bir aşama aynı anda hem kazanıldı hem kaybedildi olamaz.',
                'STAGE_FLAG_CONFLICT',
                ['is_won' => ['Bir aşama aynı anda hem kazanıldı hem kaybedildi olamaz.']],
            );
        }

        // Sonuç sütunu TEK olmalı. İki "Kazanıldı" sütunu veri kaybettirmez
        // ama huniyi anlamsız kılar: dönüşüm oranı, kazanılan ciro ve
        // ağırlıklı pipeline raporlarının hepsi "kazanılan aşama" diye TEK
        // bir sütun varsayar ve ikincisindeki kartları sessizce dışarıda
        // bırakırdı.
        if ($isWon && PipelineStage::query()->where('is_won', true)->exists()) {
            $this->deny(
                'Kazanıldı aşaması zaten tanımlı; ikinci bir kazanıldı aşaması oluşturulamaz.',
                'STAGE_FLAG_ALREADY_EXISTS',
                ['is_won' => ['Kazanıldı aşaması zaten tanımlı.']],
            );
        }

        if ($isLost && PipelineStage::query()->where('is_lost', true)->exists()) {
            $this->deny(
                'Kaybedildi aşaması zaten tanımlı; ikinci bir kaybedildi aşaması oluşturulamaz.',
                'STAGE_FLAG_ALREADY_EXISTS',
                ['is_lost' => ['Kaybedildi aşaması zaten tanımlı.']],
            );
        }

        return DB::transaction(function () use ($data, $isWon, $isLost): PipelineStage {
            $stage = PipelineStage::query()->create([
                'name' => (string) $data['name'],
                'slug' => isset($data['slug'])
                    ? (string) $data['slug']
                    : $this->uniqueSlug((string) $data['name']),
                'position' => ((int) PipelineStage::query()->max('position')) + 1,
                'probability' => (int) ($data['probability'] ?? match (true) {
                    $isWon => 100,
                    $isLost => 0,
                    default => 0,
                }),
                'color' => $data['color'] ?? null,
                'is_won' => $isWon,
                'is_lost' => $isLost,
                'is_active' => true,
            ]);

            return $stage->loadCount('deals');
        });
    }

    /**
     * `PATCH /api/settings/pipeline-stages/{stage}`.
     *
     * Pasifleştirme (`is_active: false`) diğer alanlardan ÖNCE ve kendi
     * transaction'ı içinde işlenir; reddedilirse (422) isimde/renkte hiçbir
     * değişiklik kaydedilmez.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PipelineStage $stage, array $data, User $actor): PipelineStage
    {
        $moveToStageId = array_key_exists('move_to_stage_id', $data) && $data['move_to_stage_id'] !== null
            ? (int) $data['move_to_stage_id']
            : null;

        $requestedActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : null;

        unset($data['is_active'], $data['move_to_stage_id']);

        if ($requestedActive === false && $stage->is_active) {
            $this->deactivate($stage, $moveToStageId, $actor);
        }

        $attributes = array_intersect_key($data, array_flip(['name', 'slug', 'probability', 'color']));

        if ($requestedActive === true) {
            $attributes['is_active'] = true;
        }

        // `name_key` temizliği — YALNIZCA `name` GERÇEKTEN değiştiğinde (`color`/`probability`/
        // `position` güncellemesi bunu BOZMAZ). Aşama seed edilmiş taksonomimizden biriyse
        // (`name_key` dolu) ve admin ismini burada değiştiriyorsa, isim bu andan itibaren
        // MÜŞTERİ VERİSİDİR: bir daha `enums.json` çevirisiyle ezilmemesi için anahtar
        // NULL'lanır (bkz. migration 2026_08_25_960001 ve frontend `stageLabel()`).
        if (array_key_exists('name', $attributes) && $attributes['name'] !== $stage->name) {
            $attributes['name_key'] = null;
        }

        if ($attributes !== []) {
            $stage->fill($attributes)->save();
        }

        return $stage->refresh()->loadCount('deals');
    }

    /**
     * `POST /api/settings/pipeline-stages/reorder`.
     *
     * `pipeline_stages.position` (sütun sırası) yeniden yazılır;
     * `deals.position` (kart sırası) HİÇ ELLENMEZ — iki farklı kolondur
     * (bkz. ReorderPipelineStagesRequest).
     *
     * @param  array<int, int>  $orderedIds
     * @return Collection<int, PipelineStage>
     */
    public function reorder(array $orderedIds): Collection
    {
        $allIds = PipelineStage::query()->orderBy('position')->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $missing = array_values(array_diff($allIds, $orderedIds));

        if ($missing !== [] || count($orderedIds) !== count($allIds)) {
            $this->deny(
                'Sıralama listesi TÜM aşamaları içermelidir; eksik liste sırayı belirsiz bırakır.',
                'STAGE_REORDER_INCOMPLETE',
                ['ordered_ids' => ['Sıralama listesi tüm aşamaları içermelidir.']],
                [
                    'expected_count' => count($allIds),
                    'received_count' => count($orderedIds),
                    'missing_stage_ids' => $missing,
                ],
            );
        }

        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                PipelineStage::query()->whereKey($id)->update(['position' => $index + 1]);

                /*
                 * Protocol §2.3 #6 - a query-builder update instantiates no
                 * model, so SyncVersionObserver never fires. Column order is
                 * the one piece of `pipeline_stages` an offline Kanban board
                 * cannot render without: a stale order draws the board wrong,
                 * silently. The statement above is left untouched (it is the
                 * keyed single-row update the reorder contract is built on);
                 * only the version is added.
                 */
                SyncVersionBumper::bumpRows('pipeline_stages', [$id]);
            }
        });

        return $this->list();
    }

    /**
     * Pasifleştirme — bu modülün asıl işi.
     *
     * TEK transaction: ya bütün açık fırsatlar taşınır VE aşama pasifleşir, ya
     * da hiçbiri olmaz. Aksi halde "kartları taşındı ama hâlâ aktif" ya da
     * (çok daha kötüsü) "pasifleşti ama kartlar içeride kaldı" gibi bir ara
     * durum kalırdı.
     */
    protected function deactivate(PipelineStage $stage, ?int $moveToStageId, User $actor): void
    {
        // Sonuç aşamaları pasifleştirilemez: DealMoveService kazanç/kayıp
        // durumunu bu bayraklara bakarak yazar, dolayısıyla sütun kaybolursa
        // bir kartı kazanılmış olarak işaretlemenin yolu kalmaz.
        if ($stage->is_won || $stage->is_lost) {
            $this->deny(
                'Kazanıldı/Kaybedildi aşamaları pasifleştirilemez; huninin sonucunu bu sütunlar tanımlar.',
                'STAGE_IS_SYSTEM',
                ['is_active' => ['Sistem aşaması pasifleştirilemez.']],
            );
        }

        /** @var array<int, array<string, mixed>> $broadcasts */
        $broadcasts = DB::transaction(function () use ($stage, $moveToStageId, $actor): array {
            // Kilit, "kaç açık fırsat var" cevabının transaction boyunca
            // geçerli kalmasını sağlar: kilitsizken, sayımı yaptıktan sonra
            // biri panodan bu sütuna yeni bir kart sürükleyebilir ve kart
            // pasif sütunda görünmez kalırdı.
            $openDeals = Deal::query()
                ->where('pipeline_stage_id', $stage->getKey())
                ->where('status', 'open')
                ->orderBy('position')
                ->with('owner')
                ->lockForUpdate()
                ->get();

            if ($openDeals->isEmpty()) {
                $stage->is_active = false;
                $stage->save();

                return [];
            }

            if ($moveToStageId === null) {
                $this->denyOpenDeals($stage, $openDeals->count());
            }

            $target = $this->resolveMoveTarget($stage, $moveToStageId);

            // Hedefin SONUNA eklenecek ilk anahtarın dayanağı. `withTrashed`:
            // soft-deleted bir kart geri yüklendiğinde araya giren yeni kartla
            // aynı anahtara düşmemeli (DealMoveService ile aynı gerekçe).
            $lastPosition = Deal::withTrashed()
                ->where('pipeline_stage_id', $target->getKey())
                ->orderByDesc('position')
                ->value('position');

            $broadcasts = [];

            foreach ($openDeals as $deal) {
                $fromStageId = (int) $deal->pipeline_stage_id;

                $lastPosition = FractionalIndex::last($lastPosition);

                $deal->pipeline_stage_id = $target->getKey();
                $deal->position = $lastPosition;
                $deal->version = (int) $deal->version + 1;

                // DealMoveService::applyProbability ile AYNI kural: aşamanın
                // olasılığı bir VARSAYILANDIR, kartın kendi değeri bir
                // YARGIDIR. Toplu taşıma kullanıcının elle girdiği tahmini
                // silmez; yalnızca hiç girilmemişse doldurur.
                if ($deal->probability === null) {
                    $deal->probability = $target->probability;
                }

                $deal->save();

                $broadcasts[] = DealMoved::payload($deal, $fromStageId, $actor);
            }

            $stage->is_active = false;
            $stage->save();

            return $broadcasts;
        });

        foreach ($broadcasts as $payload) {
            broadcast(new DealMoved($payload));
        }
    }

    /**
     * 422 `STAGE_HAS_OPEN_DEALS` — kullanıcının kararını verebilmesi için
     * gereken HER ŞEY gövdede: kaç kart var ve nereye taşınabilir.
     */
    protected function denyOpenDeals(PipelineStage $stage, int $openDealsCount): never
    {
        $this->deny(
            "Bu aşamada {$openDealsCount} açık fırsat var. Aşamayı pasifleştirmek için "
                .'fırsatların taşınacağı aşamayı (move_to_stage_id) seçin.',
            'STAGE_HAS_OPEN_DEALS',
            ['move_to_stage_id' => ['Açık fırsatların taşınacağı aşama seçilmelidir.']],
            [
                'open_deals_count' => $openDealsCount,
                'available_stages' => $this->availableTargets($stage),
            ],
        );
    }

    /**
     * Hedef aşama doğrulaması. `exists:pipeline_stages,id` (FormRequest)
     * YETMEZ — üç kural daha var ve üçü de pasif/anlamsız bir hedefe kart
     * taşımayı engeller:
     *
     *   - AKTİF olmalı: pasif bir aşamaya taşımak kartları yine görünmez
     *     kılardı, yani sorunu bir sütun öteye taşımak olurdu.
     *   - KAYNAKTAN FARKLI olmalı: kendi içine taşımak pasifleşen aşamada
     *     kart bırakırdı.
     *   - SONUÇ aşaması OLMAMALI: açık bir fırsatı "Kazanıldı"ya taşımak,
     *     kullanıcının vermediği bir satış kararını sunucunun vermesi olurdu
     *     (ve DealMoveService kayıp aşamasında `lost_reason` şart koşar —
     *     toplu taşımada girilebilecek bir neden yoktur).
     */
    protected function resolveMoveTarget(PipelineStage $stage, int $moveToStageId): PipelineStage
    {
        /** @var PipelineStage|null $target */
        $target = PipelineStage::query()->whereKey($moveToStageId)->first();

        $reason = match (true) {
            $target === null => 'Fırsatların taşınacağı aşama bulunamadı.',
            (int) $target->getKey() === (int) $stage->getKey() => 'Fırsatlar pasifleştirilen aşamanın kendisine taşınamaz.',
            ! $target->is_active => 'Pasif bir aşamaya fırsat taşınamaz.',
            $target->is_won || $target->is_lost => 'Açık fırsatlar Kazanıldı/Kaybedildi aşamasına toplu taşınamaz.',
            default => null,
        };

        if ($reason !== null) {
            $this->deny(
                $reason,
                'STAGE_INVALID_MOVE_TARGET',
                ['move_to_stage_id' => [$reason]],
                ['available_stages' => $this->availableTargets($stage)],
            );
        }

        return $target;
    }

    /**
     * Taşıma hedefi olabilecek aşamalar: aktif, kaynaktan farklı, sonuç
     * aşaması değil.
     *
     * `name_key` de taşınır — bkz. PipelineStageResource: DOLUYSA arayüz
     * `enums:pipelineStage.<name_key>`yi çevirir, NULL'sa (admin verisi) ham `name` basılır.
     *
     * @return array<int, array{id: int, name: string, name_key: ?string}>
     */
    protected function availableTargets(PipelineStage $stage): array
    {
        return PipelineStage::query()
            ->where('is_active', true)
            ->whereKeyNot($stage->getKey())
            ->where('is_won', false)
            ->where('is_lost', false)
            ->orderBy('position')
            ->get(['id', 'name', 'name_key'])
            ->map(fn (PipelineStage $candidate): array => [
                'id' => (int) $candidate->getKey(),
                'name' => (string) $candidate->name,
                'name_key' => $candidate->name_key,
            ])
            ->all();
    }

    /**
     * İsimden slug — çakışırsa `-2`, `-3` ... eklenir.
     *
     * `Str::slug()` Türkçe karakterleri ASCII'ye indirger
     * ("Teklif Gönderildi" -> "teklif-gonderildi"), yani mevcut
     * PipelineStageSeeder biçimiyle aynı sonucu verir.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'asama';
        }

        $slug = $base;
        $suffix = 1;

        while (PipelineStage::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
