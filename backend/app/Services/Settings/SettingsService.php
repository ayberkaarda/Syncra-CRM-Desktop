<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sistem ayarları — `settings` tablosunun key/value okuma ve yazma mantığı.
 *
 * =============================================================================
 * YENİ ANAHTAR BU UÇTAN OLUŞTURULAMAZ
 * =============================================================================
 * `PATCH /api/settings` yalnızca VAR OLAN anahtarların değerini değiştirir;
 * bilinmeyen anahtar 422 döner. Bunun nedeni `type`/`group`/`is_public`
 * üçlüsüdür: bir ayarın nasıl doğrulanacağını (`type`), hangi sekmede
 * görüneceğini (`group`) ve kimlik doğrulaması olmadan sızdırılıp
 * sızdırılamayacağını (`is_public`) bu kolonlar söyler. İstemcinin serbestçe
 * anahtar açmasına izin vermek, bu üç kararı da istemciye devretmek olurdu —
 * özellikle `is_public`, yanlış verildiğinde bir yapılandırma değerini
 * herkese açan bir bayraktır.
 *
 * Ayar sözlüğünün sahibi bu yüzden `SettingSeeder`'dır (idempotent,
 * `firstOrCreate` — kullanıcının panelden yaptığı değişiklikler yeniden seed
 * edildiğinde EZİLMEZ). Yeni bir ayar bir kod değişikliğiyle gelir.
 *
 * =============================================================================
 * DEĞER HER ZAMAN STRING SAKLANIR, `type` İLE OKUNUR
 * =============================================================================
 * Kolon `text`; tip bilgisi ayrı bir kolondadır. Yazarken burada `type`'a göre
 * doğrulanıp string'e çevrilir, okurken SettingResource aynı `type`'a göre geri
 * cast eder. Böylece `"20"` istemciye `20` olarak gider ve `quote.default_tax_rate`
 * ile aritmetik yapan hiçbir yer string ile karşılaşmaz.
 */
class SettingsService
{
    /**
     * `settings.type` kolonunun kabul ettiği değerler.
     *
     * @var array<int, string>
     */
    public const TYPES = ['string', 'integer', 'boolean', 'json'];

    /**
     * Tüm ayarlar — grup, sonra anahtar sırasıyla (Ayarlar ekranındaki
     * sekme + form sırası).
     *
     * @return Collection<int, Setting>
     */
    public function all(): Collection
    {
        return Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }

    /**
     * Ekrandaki sekmeler.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        return Setting::query()
            ->select('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->map(fn ($group): string => (string) $group)
            ->all();
    }

    /**
     * `PATCH /api/settings` — düz `{ anahtar: değer }` haritası.
     *
     * TÜM anahtarlar ÖNCE doğrulanır, sonra tek transaction içinde yazılır:
     * kısmen uygulanan bir ayar değişikliği (üç alandan ikisi kaydedilmiş)
     * kullanıcıya hangi değerin geçerli olduğunu söyleyemez.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, Setting>
     *
     * @throws ValidationException 422 — bilinmeyen anahtar ya da tipe uymayan değer
     */
    public function update(array $payload): Collection
    {
        /** @var Collection<string, Setting> $existing */
        $existing = Setting::query()
            ->whereIn('key', array_keys($payload))
            ->get()
            ->keyBy('key');

        $errors = [];
        $writes = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;
            $setting = $existing->get($key);

            if ($setting === null) {
                $errors[$key] = ["Bilinmeyen ayar anahtarı: {$key}."];

                continue;
            }

            try {
                $writes[$key] = $this->normalize($value, (string) $setting->type);
            } catch (InvalidArgumentException $exception) {
                // Tip hatası, hatayı üreten anahtarın ALTINA bağlanır: 422
                // gövdesindeki `fields` haritası doğrudan ayar formundaki
                // alanla eşleşsin diye (mesajı yeniden yazan bir eşleme
                // katmanı olmadan).
                $errors[$key] = [$exception->getMessage()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($writes, $existing): void {
            foreach ($writes as $key => $value) {
                /** @var Setting $setting */
                $setting = $existing->get($key);

                // `value` DIŞINDA hiçbir kolon yazılmaz: `type`, `group` ve
                // özellikle `is_public` bu uçtan değiştirilemez (sınıf
                // dokümanı). `fill()` yerine doğrudan atama, gövdedeki fazla
                // bir anahtarın kazara mass-assign edilmesini imkânsız kılar.
                $setting->value = $value;
                $setting->save();
            }
        });

        return $this->all();
    }

    /**
     * Değeri `type`'a göre doğrular ve saklanacak string'e çevirir.
     *
     * @throws InvalidArgumentException tipe uymayan değer
     */
    protected function normalize(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => $this->normalizeInteger($value),
            'boolean' => $this->normalizeBoolean($value),
            'json' => $this->normalizeJson($value),
            default => $this->normalizeString($value),
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function normalizeInteger(mixed $value): string
    {
        if (is_bool($value) || ! is_scalar($value)) {
            $this->invalid('Bu ayar bir tam sayı bekliyor.');
        }

        $stringValue = trim((string) $value);

        if (filter_var($stringValue, FILTER_VALIDATE_INT) === false) {
            $this->invalid('Bu ayar bir tam sayı bekliyor.');
        }

        return (string) (int) $stringValue;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function normalizeBoolean(mixed $value): string
    {
        if (is_array($value)) {
            $this->invalid('Bu ayar bir doğru/yanlış değeri bekliyor.');
        }

        // FILTER_NULL_ON_FAILURE: `filter_var('abc', FILTER_VALIDATE_BOOLEAN)`
        // bayrak olmadan sessizce `false` döner — yani geçersiz bir girdi
        // "yanlış" olarak KAYDEDİLİRDİ.
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($boolean === null) {
            $this->invalid('Bu ayar bir doğru/yanlış değeri bekliyor.');
        }

        return $boolean ? '1' : '0';
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function normalizeJson(mixed $value): string
    {
        if (! is_array($value)) {
            $this->invalid('Bu ayar bir JSON nesnesi/dizisi bekliyor.');
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            $this->invalid('Bu ayarın değeri JSON olarak kodlanamadı.');
        }

        return $encoded;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function normalizeString(mixed $value): string
    {
        if (is_array($value) || is_bool($value)) {
            $this->invalid('Bu ayar metin bekliyor.');
        }

        return (string) $value;
    }

    /**
     * Hangi ANAHTARIN hatalı olduğu bu katmanda bilinmez (normalize yalnızca
     * değeri ve tipi görür), bu yüzden ValidationException DEĞİL düz bir
     * InvalidArgumentException atılır; `update()` onu doğru anahtara bağlayıp
     * tek bir toplu ValidationException'a çevirir.
     *
     * @throws InvalidArgumentException
     */
    protected function invalid(string $message): never
    {
        throw new InvalidArgumentException($message);
    }
}
