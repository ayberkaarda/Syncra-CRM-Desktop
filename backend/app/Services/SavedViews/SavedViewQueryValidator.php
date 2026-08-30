<?php

namespace App\Services\SavedViews;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler, `query_json` yeniden doğrulaması
 * (docs/PHASE-AUDIT.md §5.4 — BAĞLAYICI):
 *
 * "`query_json` sunucuda yeniden doğrulanmalı, ham filtre olarak sorguya gömülmemeli
 * ... izin verilen alan adları, operatörler ve sıralama sütunları modül başına BEYAZ
 * LİSTE olmalı ... Bilinmeyen anahtar → reddet (sessizce atma; sessiz atma kullanıcıya
 * 'filtrem kayıtlı' yalanı söyler)."
 *
 * TEK çekirdek fonksiyon (`inspect()`) İKİ modda kullanılır:
 *   - `validateStrict()` — YAZMA (create/update): bilinmeyen/geçersiz alan varsa
 *     422 (`ValidationException`) fırlatır. Kullanıcı ne kaydettiğini TAM bilir, sessiz
 *     düşme YOK.
 *   - `sanitizeForRead()` — OKUMA (`SavedViewResource`): aynı beyaz listeyle tekrar
 *     süzer ama HATA FIRLATMAZ, geçersiz/tanınmayan alanları sessizce DÜŞÜRÜR. Bu, "okurken
 *     de doğrula" gereksinimini karşılar — şema zamanla değişirse (ör. bir filtre alanı
 *     kaldırılırsa) eski bir kayıttaki artık geçersiz bir anahtar hiçbir zaman istemciye
 *     çıkmaz ve asla ham/doğrulanmamış olarak dışarı sızmaz. Bu, YAZMA anındaki "reddet"
 *     kuralıyla ÇELİŞMEZ: o kural kullanıcının o AN ne yazdığını bilmesi için var; burada
 *     iş zaten geçmişte biten bir kayda dair, kullanıcıya "işte tam yazdığın" YALANINI
 *     söylemek yerine güvenli bir alt küme göstermek doğru olan.
 *
 * ÖNEMLİ — bu sınıf hiçbir zaman `query_json`'ı OLDUĞU GİBİ bir Eloquent sorgusuna
 * gömmez (`whereRaw`/`DB::raw` yok, ham anahtar->değer geçişi yok): yalnızca hangi
 * anahtarların/değerlerin kabul edilebilir olduğuna karar verir ve normalize edilmiş bir
 * dizi döner. Bu dizinin GERÇEK bir sorguya dönüşmesi bu sınıfın işi DEĞİLDİR — frontend
 * onu URL parametrelerine yazar, gerçek veri her zaman ilgili modülün KENDİ `Index*Request`
 * doğrulamasından geçen normal liste ucundan (DealController::index() vb.) çekilir. Bu,
 * PHASE-AUDIT §5.4'ün "confused deputy" kısıtının mimari karşılığıdır: bu sınıf ASLA veri
 * döndürmez, yalnızca hangi filtre PARAMETRELERİNİN taşınabileceğine karar verir.
 */
final class SavedViewQueryValidator
{
    /**
     * @var list<string>
     */
    private const TOP_LEVEL_KEYS = ['q', 'sort', 'per_page', 'filter'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateStrict(string $module, array $input): array
    {
        [$normalized, $rejected] = self::inspect($module, $input);

        if ($rejected !== []) {
            throw ValidationException::withMessages([
                'query_json' => [__('errors.saved_view.invalid_query', ['fields' => implode(', ', $rejected)])],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function sanitizeForRead(string $module, array $stored): array
    {
        [$normalized] = self::inspect($module, $stored);

        return $normalized;
    }

    /**
     * Ortak çekirdek: bilinmeyen üst-seviye/`filter.*` anahtarlarını ve tip/format olarak
     * geçersiz değerleri tespit eder, TÜMÜNÜ normalize edilmiş sonuçtan DÜŞÜRÜR, ayrıca
     * hangi alanların reddedildiğini (çağıran karar versin: fırlat mı, sessizce süz mü) döner.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function inspect(string $module, array $input): array
    {
        $rejected = [];

        foreach (array_keys($input) as $key) {
            if (! in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $rejected[] = (string) $key;
            }
        }

        $filterRules = SavedViewQuerySchema::filterRules($module);
        $rawFilter = is_array($input['filter'] ?? null) ? $input['filter'] : [];

        $knownFilter = [];
        foreach ($rawFilter as $key => $value) {
            if (! array_key_exists($key, $filterRules)) {
                $rejected[] = "filter.{$key}";

                continue;
            }
            $knownFilter[$key] = $value;
        }

        $sort = $input['sort'] ?? null;
        if ($sort !== null && $sort !== '') {
            $column = ltrim((string) $sort, '-');
            if (! in_array($column, SavedViewQuerySchema::sortColumns($module), true)) {
                $rejected[] = 'sort';
                $sort = null;
            }
        } else {
            $sort = null;
        }

        // Bilinen alanları TİP/FORMAT için Laravel Validator'dan geçir (ör. `filter.owner_id`
        // sayısal olmalı, `filter.status` sabit bir listede olmalı). Başarısız olan HER alan
        // da reddedilenler listesine eklenir ve normalize edilmiş sonuçtan düşürülür — bir
        // saldırganın yalnızca "tanınmayan anahtar" testini geçip geçersiz DEĞER göndermesi
        // aynı şekilde yakalanır.
        $rules = [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
        foreach ($knownFilter as $key => $value) {
            $rules["filter.{$key}"] = $filterRules[$key];
        }

        $validator = ValidatorFacade::make(
            ['q' => $input['q'] ?? null, 'per_page' => $input['per_page'] ?? null, 'filter' => $knownFilter],
            $rules
        );

        $cleanFilter = $knownFilter;
        $q = $input['q'] ?? null;
        $perPage = $input['per_page'] ?? null;

        if ($validator->fails()) {
            foreach ($validator->errors()->keys() as $field) {
                $rejected[] = $field;

                if ($field === 'q') {
                    $q = null;
                } elseif ($field === 'per_page') {
                    $perPage = null;
                } elseif (str_starts_with($field, 'filter.')) {
                    unset($cleanFilter[substr($field, strlen('filter.'))]);
                }
            }
        }

        $normalized = [
            'q' => $q !== null && $q !== '' ? (string) $q : null,
            'sort' => $sort,
            'per_page' => $perPage !== null ? (int) $perPage : null,
            'filter' => $cleanFilter,
        ];

        return [$normalized, array_values(array_unique($rejected))];
    }
}
