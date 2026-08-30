<?php

namespace App\Support\ActivityLogging;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The single place where "what does an auditable CRM model log?" is answered.
 *
 * Every business model gets `use LogsCrmActivity;` and nothing else - no
 * per-model `getActivitylogOptions()` copy-paste, so a new global exclusion
 * (say, a future `api_token` column) is a one-line change here instead of
 * fifteen chances to forget one.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS LOGGED - AND WHAT DELIBERATELY IS NOT
 * ---------------------------------------------------------------------------
 * Logged (business records people argue about):
 *   Company, Contact, Lead, Deal, Task, Activity, Ticket, Product, Quote,
 *   QuoteItem, PipelineStage, CustomField, Setting, Tag, User
 *
 * NOT logged, on purpose:
 *   PageVisitLog, SessionLog - they ARE logs. Auditing a log table means every
 *       page view writes two rows and the audit trail becomes a mirror of the
 *       traffic log rather than a record of decisions.
 *   Message, Conversation - chat is the highest-volume table in the product by
 *       an order of magnitude. Mirroring it into `activity_log` would bury the
 *       twenty rows that matter ("who dropped this deal's amount by 40%")
 *       under thousands of "sent a message" rows, and the conversation history
 *       is already fully preserved, in order, in its own table with its own
 *       soft deletes and `edited_at`. Nothing is lost by leaving it out.
 *   Attachment - a file row is created/deleted as a side effect of the record
 *       it hangs off; the parent record's own audit row is the meaningful
 *       entry. Logging both doubles rows for one user action.
 *   CustomFieldValue - a value row per field per record. The owning record is
 *       what a reviewer looks at; the definition (CustomField) IS audited,
 *       which is where the risky changes live.
 *
 * ---------------------------------------------------------------------------
 * EVENT NAMING: ENGLISH IN THE DATABASE, TURKISH IN THE UI
 * ---------------------------------------------------------------------------
 * `event` / `description` stay at spatie's `created | updated | deleted |
 * restored`. Turkish is a rendering concern:
 *
 *  - The column is a filter facet ("show me deletions"). Storing display text
 *    makes every query depend on the wording, so a copy tweak silently breaks
 *    saved filters and any index-friendly `where('event', ...)`.
 *  - Turkish has no single natural past-participle form here ("oluşturuldu" vs
 *    "eklendi"); picking one at write time freezes it into historical rows,
 *    while a UI map can be changed for the whole history at once.
 *  - Multi-language later costs a translation file, not a data migration.
 *
 * ---------------------------------------------------------------------------
 * EXTENDING PER MODEL
 * ---------------------------------------------------------------------------
 * A model that needs a different rule overrides `getActivitylogOptions()` and
 * builds on the shared default so the security-relevant exclusions survive:
 *
 *     public function getActivitylogOptions(): LogOptions
 *     {
 *         return $this->defaultCrmLogOptions()->logExcept([
 *             ...self::GLOBALLY_EXCLUDED_ATTRIBUTES,
 *             'some_noisy_column',
 *         ]);
 *     }
 *
 * ---------------------------------------------------------------------------
 * NOTE ON `activities()`
 * ---------------------------------------------------------------------------
 * spatie's trait ships an `activities()` MorphMany. Company, Contact, Deal,
 * Lead and Ticket already declare their own `activities()` (the CRM
 * call/meeting/e-mail timeline, App\Models\Activity). A class method always
 * wins over a trait method in PHP, so those relations keep their existing
 * meaning and nothing about them changes. Audit rows for a record are read
 * through the Activity log model directly, never through `$deal->activities`.
 */
trait LogsCrmActivity
{
    use LogsActivity;

    /**
     * Model audit rows live under their own log name so the Logs page can
     * separate "someone changed a record" from hand-written application logs
     * with one indexed predicate.
     */
    public const AUDIT_LOG_NAME = 'crm';

    /**
     * Never written to `activity_log`, for any model, ever.
     *
     * Two different reasons in one list:
     *
     *   SECRETS - `password` (a bcrypt hash is still credential material and
     *   an offline-crackable one; an audit table is read by more people, and
     *   exported to CSV more often, than the users table),
     *   `remember_token` (a live authentication credential - logging it would
     *   let anyone with `logs.view` impersonate the user),
     *   `email_verified_at` / `must_change_password` (account-lifecycle
     *   plumbing that says nothing about a business decision).
     *
     *   NOISE - `created_at`, `updated_at`, `deleted_at`. `updated_at` changes
     *   on EVERY save, so without this exclusion `logOnlyDirty()` would find a
     *   dirty attribute every single time and `dontSubmitEmptyLogs()` would
     *   never fire: the table would fill with rows whose only content is "the
     *   timestamp moved". The audit row's own `created_at` already carries the
     *   when.
     *
     * @var array<int, string>
     */
    public const GLOBALLY_EXCLUDED_ATTRIBUTES = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
        'deleted_at',
        'email_verified_at',
        'must_change_password',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return $this->defaultCrmLogOptions();
    }

    /**
     * The shared default. Override `getActivitylogOptions()`, not this.
     */
    protected function defaultCrmLogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG_NAME)
            // logFillable(), NOT logAll(): `logAll()` is `logOnly(['*'])`,
            // which reads `array_keys($this->getAttributes())` and therefore
            // picks up every column the model happens to have hydrated -
            // including ones added by a future migration that nobody thought
            // about in audit terms. `$fillable` is a list somebody wrote on
            // purpose; a new sensitive column is not audited until it is
            // explicitly made mass-assignable, and even then the exclusion
            // list below still has to be passed.
            ->logFillable()
            ->logExcept(self::GLOBALLY_EXCLUDED_ATTRIBUTES)
            // Only the fields that actually moved: an audit row should answer
            // "what changed", not restate the whole record.
            ->logOnlyDirty()
            // ...and if nothing moved, write nothing at all. A no-op save()
            // from a controller that re-assigns identical values must not
            // manufacture history.
            ->dontSubmitEmptyLogs();
    }
}
