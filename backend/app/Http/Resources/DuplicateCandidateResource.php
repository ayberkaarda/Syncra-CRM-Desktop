<?php

namespace App\Http\Resources;

use App\Services\Leads\DuplicateDetector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `DuplicateDetector::findCandidates()` çıktısındaki TEK bir adayın API
 * gösterimi.
 *
 * Sarmalanan şey bir model değil, detector'ın ürettiği düz dizidir — skor ve
 * `matched_on` hiçbir tabloda durmuyor, karşılaştırma anında hesaplanıyor.
 * Resource yine de araya giriyor çünkü:
 *
 *  - Yanıt şekli SÖZLEŞMEDİR (alan adları, `score` int, `matched_on` liste).
 *    Detector'ın iç dizisini doğrudan `response()->json()` ile dökmek, servisin
 *    iç yapısındaki her değişikliği sessizce API'ye sızdırırdı.
 *  - `type` yalnızca `lead` / `contact` kısa adlarını taşır; ham model sınıf
 *    adı (`App\Models\Contact`) hiçbir zaman dışarı çıkmaz — projedeki mevcut
 *    kural (bkz. ActivityLogResource).
 *  - `matched_on` `array_values()` ile yeniden indekslenir: PHP'de anahtarları
 *    boşluklu bir dizi JSON'a nesne olarak serileşir ve frontend'de `.map()`
 *    patlar.
 *
 * Kullanımı: `DuplicateCandidateResource::collection($detector->findCandidates(...))`
 */
class DuplicateCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $candidate */
        $candidate = (array) $this->resource;

        return [
            'type' => (string) ($candidate['type'] ?? DuplicateDetector::TYPE_LEAD),
            'id' => (int) ($candidate['id'] ?? 0),
            'name' => (string) ($candidate['name'] ?? ''),
            'email' => $candidate['email'] ?? null,
            'phone' => $candidate['phone'] ?? null,
            'company' => $candidate['company'] ?? null,
            'score' => (int) ($candidate['score'] ?? 0),
            'level' => (string) ($candidate['level'] ?? DuplicateDetector::LEVEL_POSSIBLE),
            'matched_on' => array_values((array) ($candidate['matched_on'] ?? [])),
        ];
    }
}
