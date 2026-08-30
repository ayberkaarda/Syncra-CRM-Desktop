<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bir sistem ayarı.
 *
 * `value` HAM STRING OLARAK DEĞİL, `type`'a göre CAST EDİLMİŞ olarak döner
 * (`Setting::get()` ile aynı dönüşüm). Gerekçe: aksi halde her istemcinin
 * `type` alanına bakıp `"1"` → `true`, `"20"` → `20` dönüşümünü kendi
 * yapması gerekirdi — yani cast mantığı ikinci kez, JavaScript'te
 * uygulanırdı. `type` yine de yanıtta kalır: ayar formundaki alanın hangi
 * girdi bileşeniyle (checkbox / number / textarea) çizileceğini o belirler.
 *
 * @property-read Setting $resource
 */
class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Setting $setting */
        $setting = $this->resource;

        return [
            'key' => $setting->key,
            'value' => self::cast($setting->value, (string) $setting->type),
            'type' => $setting->type,
            'group' => $setting->group,
            'is_public' => (bool) $setting->is_public,
            'description' => $setting->description,
        ];
    }

    /**
     * `Setting::castValue()` ile AYNI dönüşüm; o metot `protected` olduğu
     * için burada tekrarlanır (model API'sini yalnızca bu Resource uğruna
     * genişletmemek için).
     */
    public static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
