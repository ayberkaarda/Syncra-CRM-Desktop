<?php

namespace App\Http\Resources;

use App\Services\SavedViews\SavedViewQueryValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Faz 14 / İz F — C2 Kayıtlı Görünümler (docs/PHASE-INTL.md §3).
 *
 * `query_json` OLDUĞU GİBİ dönülmez: `SavedViewQueryValidator::sanitizeForRead()`'den
 * geçer (docs/PHASE-AUDIT.md §5.4 — "okurken de doğrula"). Bu, depoda (ör. bir şema
 * değişikliği ya da doğrudan DB müdahalesi sonrası) artık geçersiz/tanınmayan bir anahtar
 * kalmışsa istemciye HİÇBİR ZAMAN ham/doğrulanmamış olarak sızmamasını garanti eder.
 *
 * `is_mine`: istemcinin "düzenle/sil" düğmelerini göstermesi için — gerçek yetki kararı
 * YİNE DE sunucuda `SavedViewPolicy::update()`/`delete()` ile verilir, bu alan yalnızca UI
 * kolaylığıdır (görev tanımı: "kendi görünümünü görür/düzenler/siler").
 */
class SavedViewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'name' => $this->name,
            'query_json' => SavedViewQueryValidator::sanitizeForRead($this->module, $this->query_json ?? []),
            'is_shared' => $this->is_shared,
            'is_mine' => $request->user() !== null && $this->user_id === $request->user()->id,
            'owner_name' => $this->whenLoaded('user', fn () => $this->user->name),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
