<?php

namespace App\Support\ActivityLogging;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns a raw `activity_log` row into the small, human-facing shape the Logs
 * page and the `private-logs` broadcast need.
 */
final class ActivityFormatter
{
    /**
     * Length cap for a human label. Long enough for a real deal title, short
     * enough that a websocket frame stays a frame.
     */
    private const LABEL_LENGTH = 120;

    /**
     * First match wins. Ordered from "most specific human name" to "least".
     * `email` is last because a row that has a real name should show it.
     *
     * @var array<int, string>
     */
    private const LABEL_KEYS = [
        'title',
        'name',
        'subject',
        'ticket_number',
        'quote_number',
        'sku',
        'key',
        'email',
    ];

    /**
     * Where the write happened. Used by the UI to render a causer-less row as
     * "Sistem" / "Konsol" / "Kuyruk" instead of an empty cell.
     *
     * A console or queued write has NO causer and that is correct - inventing
     * one (say, the first Super Admin) would be a lie recorded in an audit
     * trail. The context string is the honest alternative: nobody did it, this
     * is where it came from.
     */
    public static function context(): string
    {
        if (! app()->runningInConsole()) {
            return 'http';
        }

        if (app()->runningUnitTests()) {
            return 'test';
        }

        // argv[1] is the artisan command name. Read defensively: a console
        // application can be booted without one.
        $command = (string) (($_SERVER['argv'][1] ?? '') ?: '');

        if ($command === '') {
            return 'system';
        }

        if (str_starts_with($command, 'queue:') || str_starts_with($command, 'horizon')) {
            return 'queue';
        }

        if ($command === 'db:seed' || str_starts_with($command, 'migrate')) {
            return 'seed';
        }

        return 'console';
    }

    /**
     * A human-readable name for the audited record.
     *
     * Read from the diff FIRST and from the database only as a fallback:
     *
     *  - `created` and `deleted` rows carry every fillable attribute in
     *    `properties`, so the label is already there - free, and still correct
     *    for a record that has since been hard-deleted.
     *  - `updated` rows carry only the dirty fields (logOnlyDirty), so a deal
     *    whose amount changed has no `title` in its diff; that is the case
     *    that costs one primary-key lookup.
     */
    public static function subjectLabel(Activity $activity): ?string
    {
        $bag = self::propertyBag($activity);

        if (($label = self::labelFrom($bag)) !== null) {
            return $label;
        }

        $subject = $activity->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        return self::labelFrom($subject->getAttributes());
    }

    /**
     * Display name of whoever caused the entry, or null for system writes.
     */
    public static function causerName(Activity $activity): ?string
    {
        if ($activity->causer_id === null) {
            return null;
        }

        // The overwhelmingly common case: the causer is the user making the
        // current request, already hydrated. Skip the morphTo round-trip.
        $current = auth()->user();

        if ($current !== null
            && (int) $current->getKey() === (int) $activity->causer_id
            && $current->getMorphClass() === $activity->causer_type) {
            return self::nameOf($current);
        }

        $causer = $activity->causer;

        return $causer instanceof Model ? self::nameOf($causer) : null;
    }

    /**
     * The payload published on `private-logs`.
     *
     * Deliberately WITHOUT the diff. `properties` can be several kilobytes
     * even after truncation, it is fanned out to every subscriber with
     * `logs.view`, and the live stream only renders a one-line row. A viewer
     * who opens a row fetches the detail over HTTP, where it is paged, cached
     * and authorized per record.
     *
     * @return array<string, mixed>
     */
    public static function broadcastPayload(Activity $activity): array
    {
        return [
            'id' => (int) $activity->getKey(),
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id !== null ? (int) $activity->subject_id : null,
            'subject_label' => self::subjectLabel($activity),
            'causer_id' => $activity->causer_id !== null ? (int) $activity->causer_id : null,
            'causer_name' => self::causerName($activity),
            // Lets the UI print "Sistem" for a causer-less row without having
            // to guess why the causer is missing.
            'context' => (string) (data_get($activity->properties, '_context') ?: 'system'),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    /**
     * `attributes` merged over `old`, so a `deleted` row (which only has
     * `old`) and a `created` row (which only has `attributes`) are searched
     * the same way.
     *
     * @return array<string, mixed>
     */
    private static function propertyBag(Activity $activity): array
    {
        $properties = $activity->properties?->toArray() ?? [];

        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];

        return array_merge($old, $new);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function labelFrom(array $attributes): ?string
    {
        // Contact / Lead / User-shaped records: a person is "Ad Soyad", never
        // just one half of it.
        $person = trim(
            trim((string) ($attributes['first_name'] ?? ''))
            .' '
            .trim((string) ($attributes['last_name'] ?? ''))
        );

        if ($person !== '') {
            return Str::limit($person, self::LABEL_LENGTH);
        }

        foreach (self::LABEL_KEYS as $key) {
            $value = $attributes[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), self::LABEL_LENGTH);
            }
        }

        return null;
    }

    private static function nameOf(Model $model): ?string
    {
        foreach (['name', 'email'] as $key) {
            $value = $model->getAttribute($key);

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), self::LABEL_LENGTH);
            }
        }

        return null;
    }
}
