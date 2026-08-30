<?php

namespace App\Services\Settings;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Collection;

/**
 * Özel alan TANIMLARININ yönetimi (değerlerin değil).
 *
 * =============================================================================
 * SİLME YOK, PASİFLEŞTİRME VAR — VE `custom_field_values` HİÇ ELLENMEZ
 * =============================================================================
 * `DELETE /api/settings/custom-fields/{customField}` alanı `is_active=false`
 * yapar; tek bir değer satırı bile silinmez.
 *
 * Gerçek silme iki şeyi birden yok ederdi: (1) o alana yıllardır girilmiş
 * VERİYİ — "Bütçe" alanı kaldırıldığında 4.000 lead'in bütçesi de gider ve
 * geri getirilemez; (2) DENETİM İZİNİN anlamını — `activity_log` satırları
 * artık var olmayan bir alanın eski/yeni değerlerini gösterir ve kimse neyin
 * değiştiğini okuyamaz.
 *
 * Pasifleştirilen alan formlarda ve `GET /api/custom-fields` şemasında
 * görünmez (o uç `active()` süzer), ama değerleri yerinde durur: alan yeniden
 * aktifleştirildiğinde eski veri OLDUĞU GİBİ geri gelir. Yanlışlıkla
 * kaldırma bu sayede geri alınabilir bir işlemdir.
 */
class CustomFieldService
{
    use DeniesSettingsChange;

    /**
     * `custom_fields.type` sözlüğü (migration'daki yorumla aynı).
     *
     * @var array<int, string>
     */
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'boolean'];

    /**
     * `options` kolonunun anlamlı olduğu tipler.
     *
     * @var array<int, string>
     */
    public const OPTION_TYPES = ['select', 'multiselect'];

    /**
     * Ayarlar ekranının listesi: PASİF alanlar da döner (yönetilen şey
     * onların aktifliğidir) ve `entity_type` süzgeci OPSİYONELDİR — ekran
     * tüm kayıt tiplerini tek tabloda gösterir.
     *
     * `GET /api/custom-fields` (form şeması ucu) ile kasıtlı fark: orada
     * `entity_type` zorunludur ve yalnızca aktif alanlar döner.
     *
     * @return Collection<int, CustomField>
     */
    public function list(?string $entityType = null, bool $includeInactive = true): Collection
    {
        $query = CustomField::query()
            ->orderBy('entity_type')
            ->orderBy('position')
            ->orderBy('id');

        if ($entityType !== null) {
            $query->forEntity($entityType);
        }

        if (! $includeInactive) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CustomField
    {
        $type = (string) $data['type'];
        $entityType = (string) $data['entity_type'];

        return CustomField::query()->create([
            'entity_type' => $entityType,
            'name' => (string) $data['name'],
            'key' => (string) $data['key'],
            'type' => $type,
            'options' => $this->normalizeOptions($type, $data['options'] ?? null),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'position' => array_key_exists('position', $data)
                ? (int) $data['position']
                : ((int) CustomField::query()->where('entity_type', $entityType)->max('position')) + 1,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomField $field, array $data): CustomField
    {
        $this->assertImmutable($field, $data);

        $type = array_key_exists('type', $data) ? (string) $data['type'] : (string) $field->type;

        $attributes = array_intersect_key($data, array_flip(['name', 'is_required', 'position', 'is_active']));

        if (array_key_exists('type', $data)) {
            $attributes['type'] = $type;
        }

        // `options`, `type` ile birlikte düşünülür: tip seçim dışına
        // çevrildiğinde eski seçenek listesi TEMİZLENİR. Kalsaydı, alan
        // sonradan tekrar `select` yapıldığında artık geçerli olmayan bir
        // listeyle geri gelirdi — ve arada girilmiş serbest metin değerleri
        // o listeye uymazdı.
        if (array_key_exists('options', $data) || array_key_exists('type', $data)) {
            $attributes['options'] = $this->normalizeOptions(
                $type,
                array_key_exists('options', $data) ? $data['options'] : $field->options,
            );
        }

        if ($attributes !== []) {
            $field->fill($attributes)->save();
        }

        return $field->refresh();
    }

    /**
     * `DELETE /api/settings/custom-fields/{customField}` — SİLMEZ,
     * pasifleştirir (sınıf dokümanı). Zaten pasifse hiçbir şey yapmaz
     * (idempotent).
     */
    public function deactivate(CustomField $field): CustomField
    {
        if ($field->is_active) {
            $field->is_active = false;
            $field->save();
        }

        return $field->refresh();
    }

    /**
     * `key` ve `entity_type` gövdede BULUNABİLİR (form tüm nesneyi geri
     * gönderir) ama DEĞİŞEMEZ — gerekçe UpdateCustomFieldRequest'te.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertImmutable(CustomField $field, array $data): void
    {
        if (array_key_exists('key', $data) && (string) $data['key'] !== (string) $field->key) {
            $this->deny(
                'Alan anahtarı (key) oluşturulduktan sonra değiştirilemez.',
                'CUSTOM_FIELD_KEY_IMMUTABLE',
                ['key' => ['Alan anahtarı oluşturulduktan sonra değiştirilemez.']],
                ['current_key' => (string) $field->key],
            );
        }

        if (array_key_exists('entity_type', $data) && (string) $data['entity_type'] !== (string) $field->entity_type) {
            $this->deny(
                'Alanın bağlı olduğu kayıt tipi (entity_type) değiştirilemez.',
                'CUSTOM_FIELD_ENTITY_TYPE_IMMUTABLE',
                ['entity_type' => ['Alanın bağlı olduğu kayıt tipi değiştirilemez.']],
                ['current_entity_type' => (string) $field->entity_type],
            );
        }
    }

    /**
     * @param  mixed  $options
     * @return array<int, string>|null
     */
    protected function normalizeOptions(string $type, $options): ?array
    {
        if (! in_array($type, self::OPTION_TYPES, true)) {
            return null;
        }

        if (! is_array($options)) {
            return null;
        }

        return array_values(array_map('strval', $options));
    }
}
