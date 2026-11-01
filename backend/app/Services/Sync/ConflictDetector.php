<?php

namespace App\Services\Sync;

use App\Sync\SyncableRegistry;
use App\Sync\WriteTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether an offline `update` may land (SYNCDESKTOP §4.4, protocol §5).
 *
 * The cheap answer - "the row's version moved, so it is a conflict" - is
 * wrong most of the time. Two people editing DIFFERENT fields of the same
 * record are not in conflict, and treating them as one would push routine
 * offline work into the Conflict Inbox until users stopped trusting it.
 *
 * So the question asked is narrower: since the client's `base_sync_version`,
 * did anyone change any of the fields THIS mutation changes?
 *
 *   1. `server.sync_version <= base_sync_version` -> nothing moved. Apply.
 *   2. Collect `activity_log` entries for (subject_type, subject_id) whose
 *      `subject_sync_version` is above the base, and union the keys of their
 *      `properties.attributes`. That is the set of fields written since.
 *   3. Add the embedded and bulk fields (below) if such a write happened since.
 *   4. If nothing on record accounts for the row's current version, every
 *      field of the mutation is in conflict (below).
 *   5. Intersect with `changed_fields`. Empty -> field-disjoint, apply.
 *      Non-empty -> FIELD_CONFLICT, and the intersection IS
 *      `conflicting_fields`.
 *
 * The window is the version, never a timestamp. `occurred_at` is the CLIENT's
 * clock at the moment of the edit, and an offline edit is routinely made after
 * a save it has not heard about yet: a time window leaves that save out, which
 * is the exact overwrite this class exists to stop. `MutationApplier` still
 * passes `occurred_at`; it is ignored.
 *
 * An audit row with no recorded version counts against the client. A needless
 * conflict is visible and one click to settle; an overwrite is neither.
 *
 * A `created` row never does, once the client states a base: no one can hold a
 * base older than the row, and the backfill gave old `created` rows the row's
 * current version, so reading them would conflict with every field they list.
 * It still accounts for that version in step 4. A client that states no base
 * has made no claim about what it saw, and gets the full record.
 *
 * ------------------------------------------------------------------------
 * FIELDS THE TRAIL CANNOT NAME
 * ------------------------------------------------------------------------
 * Embedded children and bulk statements move a row's version and write no audit
 * row (protocol §1.4, §1.5, §2.3), so step 2 can never report them. They go
 * through SyncVersionBumper, which leaves the version in `sync_untrailed_writes`
 * (WriteTrail). If that version is above the base, all of those fields conflict
 * together: the record says such a write happened, not which of them it touched.
 *
 * ------------------------------------------------------------------------
 * A VERSION NOTHING ACCOUNTS FOR
 * ------------------------------------------------------------------------
 * The trail is not complete, and "no audit row" is not evidence of "no change":
 * a raw statement, a save with logging switched off and a pruned audit row all
 * leave a moved version behind and no fields to name. The version the row is at
 * now has to be explained by an audit row or by WriteTrail; if it is not, we do
 * not know what moved, and the mutation's fields are the values at risk.
 *
 * This looks only at the CURRENT version, because the row's history of versions
 * is not kept. An unexplained move with an explained one on top of it stays
 * invisible (protocol §5).
 *
 * ------------------------------------------------------------------------
 * ENTITIES WITHOUT AN AUDIT TRAIL (protocol §5.1/P11)
 * ------------------------------------------------------------------------
 * `Conversation`, `Message`, `CustomFieldValue`, `Attachment`, `PageVisitLog`
 * and `SessionLog` keep no activity_log rows, and that is a documented
 * decision in LogsCrmActivity, not an oversight - writing an audit row per
 * chat message is a cost this project rejected on purpose.
 *
 * For those, step 2 has no input, so the detector falls back to RECORD level:
 * any version movement is a conflict. Adding LogsActivity to them was rejected
 * (P11); the practical cost is near zero because concurrent field edits on a
 * chat message are vanishingly rare, and `notifications` only ever receives
 * `read`/`delete` actions, which do not pass through here at all.
 */
class ConflictDetector
{
    /**
     * Embedded in the owner's row, so audited on no row at all.
     */
    private const EMBEDDED_FIELDS = ['tags', 'tag_ids', 'custom_fields', 'items'];

    /**
     * Written by bulk statements that deliberately leave no audit row:
     * ContactRepository::clearOtherPrimaryContacts() and
     * LeadConversionService::moveRelatedRecords(). A new one belongs here.
     *
     * @var array<string, array<int, string>>
     */
    private const BULK_WRITTEN_FIELDS = [
        'contact' => ['is_primary'],
        'task' => ['taskable_type', 'taskable_id'],
        'activity' => ['activityable_type', 'activityable_id'],
    ];

    /**
     * @param  array<int, string>  $changedFields
     * @param  string|null  $occurredAt  ignored, see the class docblock
     * @return array<int, string> the conflicting fields; empty means "apply"
     */
    public function detect(Model $model, string $entity, array $changedFields, ?int $baseSyncVersion, ?string $occurredAt): array
    {
        $serverVersion = (int) ($model->getAttribute('sync_version') ?? 0);

        if ($baseSyncVersion !== null && $serverVersion <= $baseSyncVersion) {
            return [];
        }

        if (! $this->keepsActivityLog($entity)) {
            // Record-level: we cannot tell WHICH field moved, so we must not
            // guess. Reporting the client's own changed fields is the honest
            // answer - those are the values at risk.
            return $changedFields;
        }

        if (! WriteTrail::ready()) {
            return $changedFields;
        }

        $since = $baseSyncVersion ?? 0;

        [$touched, $newestAudited] = $this->auditedAfter($model, $since);
        [$untrailed, $baseline] = WriteTrail::untrailed($model);

        if (max($newestAudited, $untrailed) < $serverVersion) {
            return $changedFields;
        }

        if (max($untrailed, $baseline) > $since) {
            $touched = array_merge($touched, self::EMBEDDED_FIELDS, self::BULK_WRITTEN_FIELDS[$entity] ?? []);
        }

        return array_values(array_intersect($changedFields, $touched));
    }

    /**
     * What the audit trail knows above the client's base.
     *
     * @return array{0: array<int, string>, 1: int} field names written, and the newest version recorded
     */
    private function auditedAfter(Model $model, int $baseSyncVersion): array
    {
        // Where the audit rows are written: `ACTIVITY_LOGGER_DB_CONNECTION` may
        // put them in a database the default connection cannot see.
        $rows = WriteTrail::auditConnection()->table(config('activitylog.table_name', 'activity_log'))
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey())
            ->where(function ($query) use ($baseSyncVersion): void {
                $query->where(WriteTrail::COLUMN, '>', $baseSyncVersion)
                    ->orWhereNull(WriteTrail::COLUMN);
            })
            ->get(['event', 'properties', WriteTrail::COLUMN]);

        $fields = [];
        $newest = 0;

        foreach ($rows as $row) {
            $newest = max($newest, (int) $row->{WriteTrail::COLUMN});

            if ($row->event === 'created' && $baseSyncVersion > 0) {
                continue;
            }

            $decoded = json_decode((string) $row->properties, true);

            if (! is_array($decoded) || ! isset($decoded['attributes']) || ! is_array($decoded['attributes'])) {
                continue;
            }

            foreach (array_keys($decoded['attributes']) as $field) {
                $fields[(string) $field] = true;
            }
        }

        return [array_keys($fields), $newest];
    }

    private function keepsActivityLog(string $entity): bool
    {
        // Mirrors the LogsCrmActivity docblock's deliberate exclusions
        // (protocol §5.1). Kept as an explicit list rather than a trait probe:
        // the trait may be present on a parent class that does not actually log
        // the fields we would need, and a wrong "true" here silently downgrades
        // a real conflict into an apply.
        return ! in_array($entity, ['conversation', 'message', 'notification'], true)
            && SyncableRegistry::entity($entity) !== null;
    }
}
