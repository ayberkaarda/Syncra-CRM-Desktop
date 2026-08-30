<?php

use Spatie\Activitylog\Models\Activity;

return [

    /*
     * If set to false, no activities will be saved to the database.
     *
     * Syncra note: this is the switch a data importer / seeder should flip (or
     * better: `activity()->withoutLogs(fn () => ...)`, which flips it for the
     * duration of a closure only). See `syncra_max_property_length` below and
     * docs in App\Support\ActivityLogging\LogsCrmActivity.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     */
    'delete_records_older_than_days' => 365,

    /*
     * If no log name is passed to the activity() helper we use this default
     * log name.
     *
     * Model audit rows do NOT use it - LogsCrmActivity pins them to `crm`
     * (see LogsCrmActivity::AUDIT_LOG_NAME) so the audit trail can always be
     * separated from hand-written `activity()->log()` calls with a single
     * indexed `log_name` predicate.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     *
     * Syncra: deliberately TRUE. Every CRM entity soft-deletes, so the very row
     * an audit entry describes ("deleted Deal #42") is soft-deleted by the
     * time anyone reads the log. With the package default (false) the morphTo
     * would resolve to null and the Logs page would show a deletion with no
     * subject - exactly the record you most want to be able to inspect.
     */
    'subject_returns_soft_deleted_models' => true,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),

    /*
     |--------------------------------------------------------------------------
     | Syncra additions (not part of the upstream package config)
     |--------------------------------------------------------------------------
     */

    /*
     * ROADMAP R6 - diff truncation.
     *
     * Maximum length (in CHARACTERS, not bytes - mb_strlen) of a single value
     * inside `properties.attributes` / `properties.old`. Anything longer is cut
     * to this length and its key is listed under `properties._truncated`.
     *
     * Why it exists: `description`, `notes`, `body` and `terms` are free text.
     * A single edited quote-terms field can push one audit row past 8 KB, and
     * the Logs page reads hundreds of rows at a time. Truncating at write time
     * keeps the table (and the JSON parsing on the read side) bounded; the full
     * value always remains on the record itself.
     *
     * Applied by App\Observers\ActivityLogObserver via PropertyTruncator.
     */
    'syncra_max_property_length' => (int) env('ACTIVITY_LOGGER_MAX_PROPERTY_LENGTH', 1024),
];
