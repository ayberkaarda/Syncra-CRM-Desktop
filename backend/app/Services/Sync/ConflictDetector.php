<?php

namespace App\Services\Sync;

use App\Sync\SyncableRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
 *   2. Collect `activity_log` entries for (subject_type, subject_id) created
 *      after `occurred_at`, and union the keys of their
 *      `properties.attributes`. That is the set of fields other actors wrote.
 *   3. Intersect with `changed_fields`. Empty -> field-disjoint, apply.
 *      Non-empty -> FIELD_CONFLICT, and the intersection IS
 *      `conflicting_fields`.
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
     * @param  array<int, string>  $changedFields
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

        $touched = $this->fieldsTouchedSince($model, $occurredAt);

        return array_values(array_intersect($changedFields, $touched));
    }

    /**
     * Field names written by anyone since the client made its edit.
     *
     * @return array<int, string>
     */
    private function fieldsTouchedSince(Model $model, ?string $occurredAt): array
    {
        $query = DB::table(config('activitylog.table_name', 'activity_log'))
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey());

        if ($occurredAt !== null) {
            $query->where('created_at', '>', $occurredAt);
        }

        $fields = [];

        foreach ($query->pluck('properties') as $properties) {
            $decoded = json_decode((string) $properties, true);

            if (! is_array($decoded) || ! isset($decoded['attributes']) || ! is_array($decoded['attributes'])) {
                continue;
            }

            foreach (array_keys($decoded['attributes']) as $field) {
                $fields[(string) $field] = true;
            }
        }

        return array_keys($fields);
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
