<?php

namespace App\Observers;

use App\Events\ActivityLogged;
use App\Support\ActivityLogging\ActivityFormatter;
use App\Support\ActivityLogging\PropertyTruncator;
use App\Sync\SyncActivityContext;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * The one choke point every audit row passes through.
 *
 * ---------------------------------------------------------------------------
 * WHY AN OBSERVER ON THE ACTIVITY MODEL, AND NOT tapActivity() / A LOG PIPE
 * ---------------------------------------------------------------------------
 * spatie offers three hooks. They are not equivalent here:
 *
 *  1. `tapActivity()` on the SUBJECT model. ActivityLogger::log() calls it, so
 *     it works - but only for rows that have a subject implementing it. It
 *     would have to be added to the shared trait AND would still miss every
 *     hand-written `activity()->log()` call (the login / session / import
 *     entries other modules write, which have no subject at all). Truncation
 *     that covers "most" rows is not a size guarantee.
 *
 *  2. `LoggablePipe` via `Model::addLogChange()`. `LogsActivity::$changesPipes`
 *     is a static declared IN THE TRAIT, which in PHP means one independent
 *     copy per using class - registering the pipe would mean fifteen
 *     registrations that must be repeated for every model added later, and it
 *     again never sees subject-less logs.
 *
 *  3. This: an Eloquent observer on the Activity model itself. Every audit row
 *     is an Eloquent insert on that model, whatever wrote it. One
 *     registration, no per-model bookkeeping, and it cannot be bypassed by a
 *     module that logs without going through the trait.
 *
 * `saving` rather than `creating`, so an audit row edited later (a backfill, a
 * redaction) is re-checked too. PropertyTruncator is idempotent, so the extra
 * passes are free.
 */
class ActivityLogObserver
{
    /**
     * Cut oversized values (ROADMAP R6) and stamp the execution context.
     */
    public function saving(Activity $activity): void
    {
        $properties = $activity->properties?->toArray() ?? [];

        $properties = PropertyTruncator::apply($properties);

        // Written on every row, not only causer-less ones, so the Logs page
        // can filter by origin ("hide everything the importer did") instead of
        // inferring it from a null causer.
        $properties['_context'] = ActivityFormatter::context();

        /*
         * Faz F1 (SYNCDESKTOP §4.4): a mutation replayed from the desktop
         * outbox must be identifiable as such, and as part of ONE batch.
         * Stamped here for the same reason everything else is stamped here -
         * this is the only place every audit row passes through, and the rows
         * themselves are written by the existing services, not by the sync
         * layer. Absent for web traffic, so no existing row changes shape.
         */
        $syncContext = SyncActivityContext::current();

        if ($syncContext !== null) {
            $properties['channel'] = 'desktop';
            $properties['batch_id'] = $syncContext['batch_id'] ?? null;
        }

        $activity->properties = $properties;
    }

    /**
     * Fan the new row out to the live Logs page.
     */
    public function created(Activity $activity): void
    {
        try {
            ActivityLogged::dispatch(ActivityFormatter::broadcastPayload($activity));
        } catch (Throwable $e) {
            // The audit row is the product; the broadcast is a convenience.
            // A dead queue connection must never turn "the deal was saved" into
            // a 500 - and must never roll back an audit write either.
            report($e);
        }
    }
}
