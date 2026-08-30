<?php

namespace App\Http\Resources;

use App\Repositories\LogRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Throwable;

/**
 * `subject_type` DAİMA kısa ad olarak dönülür (LogRepository::SUBJECT_TYPE_MAP
 * üzerinden) — ham sınıf adı dışarı sızdırılmaz. Beyaz listede olmayan / silinmiş
 * bir subject asla patlamaz: `subject_label` böyle durumlarda null döner.
 *
 * @property-read ActivityLog $resource
 */
class ActivityLogResource extends JsonResource
{
    /**
     * Yanıt boyutu güvenlik ağı — DB seviyesindeki kırpmadan (PropertyTruncator,
     * ROADMAP R6, alan başına 1024 karakter) BAĞIMSIZ: birden çok kırpılmış
     * alan aynı satırda birikirse yanıt yine de büyüyebilir. Tetiklenirse veri
     * KESİLMEZ (o iş zaten DB seviyesinde yapılmış), yalnızca
     * `properties._response_truncated = true` ile işaretlenir — DB'nin
     * `_truncated` (alan adı listesi) işaretinin üzerine asla yazılmaz.
     */
    protected const MAX_PROPERTIES_JSON_LENGTH = 5000;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ActivityLog $log */
        $log = $this->resource;

        $subject = $this->safeSubject($log);

        return [
            'id' => $log->id,
            'log_name' => $log->log_name,
            'description' => $log->description,
            'event' => $log->event,
            'subject_type' => LogRepository::shortNameForSubjectType($log->subject_type),
            'subject_id' => $log->subject_id,
            'subject_label' => $this->subjectLabel($subject),
            'causer' => $log->causer ? [
                'id' => $log->causer->id ?? null,
                'name' => $log->causer->name ?? null,
            ] : null,
            'properties' => $this->shapeProperties($log->properties),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * `subject` bir MorphTo ilişkisidir: kayıt silinmişse null döner, ancak
     * geçmişte kullanılmış ve artık var olmayan bir sınıfa işaret ediyorsa
     * çözümleme hata fırlatabilir. Liste asla patlamamalı.
     */
    protected function safeSubject(ActivityLog $log): ?Model
    {
        try {
            return $log->subject;
        } catch (Throwable) {
            return null;
        }
    }

    protected function subjectLabel(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        try {
            foreach (['title', 'name', 'subject'] as $attribute) {
                $value = $subject->{$attribute} ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            $first = $subject->first_name ?? null;
            $last = $subject->last_name ?? null;

            if ($first !== null || $last !== null) {
                $full = trim(($first ?? '').' '.($last ?? ''));

                if ($full !== '') {
                    return $full;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `old`/`attributes` çiftini döner. DB seviyesinde zaten kırpılmış olan
     * `_truncated` (ROADMAP R6, `PropertyTruncator`) ve çalıştırma bağlamı
     * `_context` (`ActivityLogObserver`) AYNEN geçirilir — burada asla
     * üzerine yazılmaz/yeniden yorumlanmaz.
     *
     * @return array<string, mixed>
     */
    protected function shapeProperties(mixed $properties): array
    {
        $collection = $properties instanceof Collection ? $properties : collect();

        $old = $collection->get('old', []);
        $attributes = $collection->get('attributes', []);

        $old = is_array($old) ? $old : [];
        $attributes = is_array($attributes) ? $attributes : [];

        $result = ['old' => $old, 'attributes' => $attributes];

        // DB seviyesinde kırpılan alan adları (PropertyTruncator::MARKER),
        // olduğu gibi — bir dizi, boolean DEĞİL.
        $truncatedFields = $collection->get('_truncated');

        if (is_array($truncatedFields) && $truncatedFields !== []) {
            $result['_truncated'] = array_values($truncatedFields);
        }

        // Her satıra ActivityLogObserver tarafından yazılan çalıştırma bağlamı
        // (http|console|queue|seed|system|test) — causer'sız satırlarda
        // kaynağı ayırt etmek için (bkz. ActivityFormatter::context()).
        $context = $collection->get('_context');

        if ($context !== null) {
            $result['_context'] = $context;
        }

        // Yanıt boyutu güvenlik ağı — bkz. sınıf başındaki not. Yalnızca bir
        // bayrak; `_truncated`'ın anlamıyla asla karışmaz.
        $encoded = json_encode(['old' => $old, 'attributes' => $attributes]);

        if ($encoded !== false && strlen($encoded) > self::MAX_PROPERTIES_JSON_LENGTH) {
            $result['_response_truncated'] = true;
        }

        return $result;
    }
}
