<?php

namespace App\Sync;

use Closure;

/**
 * Marks the audit rows a push batch produces (SYNCDESKTOP §4.4).
 *
 * "Every applied mutation stamps `properties.channel = 'desktop'` and the
 * `batch_id`" is a real operational requirement: when a desktop client
 * replays fifty offline edits, the Logs page must be able to show them as ONE
 * batch from ONE origin instead of fifty indistinguishable manual edits.
 *
 * ------------------------------------------------------------------------
 * WHY A SCOPED STATIC AND NOT A PARAMETER
 * ------------------------------------------------------------------------
 * Audit rows are not written by the sync layer. They are written deep inside
 * the existing services, by spatie's LogsActivity trait, during ordinary model
 * saves - which is exactly what K7 demands (the sync path must go through the
 * same code the HTTP path uses, not a parallel copy). There is no argument to
 * thread through: the only shared channel between "the applier knows this is
 * batch X" and "the trait is about to insert a row" is request-scoped state.
 *
 * ActivityLogObserver::saving() - already the single choke point every audit
 * row passes through, for the reasons documented on that class - reads it.
 *
 * The state is always set through within(), never assigned: the `finally`
 * guarantees a thrown mutation cannot leak its batch id onto the next one, and
 * the previous value is restored rather than cleared so nesting stays honest.
 */
final class SyncActivityContext
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $context = null;

    /**
     * @template T
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function within(array $context, Closure $callback): mixed
    {
        $previous = self::$context;
        self::$context = $context;

        try {
            return $callback();
        } finally {
            self::$context = $previous;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return self::$context;
    }
}
