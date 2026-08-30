<?php

namespace App\Support\ActivityLogging;

/**
 * ROADMAP R6 - keeps a single audit row from being dominated by one free-text
 * field.
 *
 * ---------------------------------------------------------------------------
 * SHAPE OF THE OUTPUT
 * ---------------------------------------------------------------------------
 * spatie writes `properties` as
 *
 *     { "attributes": { ... }, "old": { ... } }
 *
 * That shape is NOT modified. Truncation rewrites individual values in place
 * and adds ONE sibling key:
 *
 *     { "attributes": {...}, "old": {...}, "_truncated": ["description"] }
 *
 * So `Activity::changes()` (which does `properties->only(['attributes','old'])`)
 * keeps working untouched, and a reader that knows nothing about `_truncated`
 * still sees a well-formed diff - just a shorter one.
 *
 * The marker is a flat list of ATTRIBUTE NAMES rather than a per-section map:
 * a value long enough to be cut is long enough in both `old` and `attributes`
 * often enough that two lists would mostly duplicate each other, and the UI
 * only needs the answer to "should I render a 'shortened' badge next to this
 * field?".
 *
 * ---------------------------------------------------------------------------
 * IDEMPOTENCE
 * ---------------------------------------------------------------------------
 * The hook that calls this runs on `saving`, i.e. on updates of an audit row
 * as well as inserts. Cutting to exactly $limit means an already-cut value has
 * length == $limit, and the test here is strictly `>`, so a second pass is a
 * no-op and `_truncated` never accumulates duplicates.
 */
final class PropertyTruncator
{
    /** Fallback when the config key is missing or nonsensical. */
    public const DEFAULT_LIMIT = 1024;

    /** The sibling key added to `properties`. */
    public const MARKER = '_truncated';

    /**
     * Only the two diff sections are walked. Extra properties that callers of
     * `activity()->withProperties()` attach by hand are left alone - they are
     * hand-picked payloads, not machine-generated diffs, and silently mangling
     * them would be worse than a long row.
     *
     * @var array<int, string>
     */
    private const SECTIONS = ['attributes', 'old'];

    /**
     * Configured maximum, in characters.
     */
    public static function limit(): int
    {
        $limit = (int) config('activitylog.syncra_max_property_length', self::DEFAULT_LIMIT);

        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function apply(array $properties, ?int $limit = null): array
    {
        $limit ??= self::limit();

        // Preserve any marker already present (idempotent re-runs, or a caller
        // that flagged something itself).
        $flagged = array_values(array_filter(
            (array) ($properties[self::MARKER] ?? []),
            static fn ($key) => is_string($key) && $key !== ''
        ));

        foreach (self::SECTIONS as $section) {
            if (! isset($properties[$section]) || ! is_array($properties[$section])) {
                continue;
            }

            foreach ($properties[$section] as $key => $value) {
                [$newValue, $wasTruncated] = self::truncate($value, $limit);

                if (! $wasTruncated) {
                    continue;
                }

                $properties[$section][$key] = $newValue;
                $flagged[] = (string) $key;
            }
        }

        if ($flagged === []) {
            unset($properties[self::MARKER]);

            return $properties;
        }

        $properties[self::MARKER] = array_values(array_unique($flagged));

        return $properties;
    }

    /**
     * @return array{0: mixed, 1: bool} [value, wasTruncated]
     */
    private static function truncate(mixed $value, int $limit): array
    {
        if (is_string($value)) {
            return mb_strlen($value) > $limit
                ? [mb_substr($value, 0, $limit), true]
                : [$value, false];
        }

        // Arrays / JSON casts (Setting::$value, CustomField::$options, ...).
        // Measured by their encoded length, because that is what actually goes
        // into the column. A cut JSON string is no longer parseable JSON, so
        // the value is replaced by the cut STRING - `_truncated` tells the
        // reader why it is suddenly a string instead of an object.
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false || mb_strlen($encoded) <= $limit) {
                return [$value, false];
            }

            return [mb_substr($encoded, 0, $limit), true];
        }

        // int / float / bool / null cannot exceed the limit in any meaningful
        // way, and coercing them to strings would corrupt the diff.
        return [$value, false];
    }
}
