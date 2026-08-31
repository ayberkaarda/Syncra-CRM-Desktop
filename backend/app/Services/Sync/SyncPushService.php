<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Sync\Mutation;
use App\Sync\MutationResult;
use App\Sync\SyncActivityContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /api/sync/push` — the batch driver (SYNCDESKTOP §4.4, protocol §4.3).
 *
 * ==========================================================================
 * ONE TRANSACTION PER MUTATION, NOT ONE PER BATCH
 * ==========================================================================
 * A batch is a transport convenience, not a unit of work. Fifty offline edits
 * are fifty independent business decisions, and one rejected by a policy must
 * not roll back the forty-nine that were fine - the user would watch a whole
 * day of offline work bounce because one deal had been closed meanwhile.
 *
 * ==========================================================================
 * LOCK CONTENTION IS EXPECTED, AND IS NOT A FAILURE (protocol §2.4/P4a)
 * ==========================================================================
 * Every mutation advances `sync_counter`, whose single row is a global write
 * mutex (K-B, accepted deliberately: it is what makes commit order equal
 * version order). Probes C1/C2 measured concurrent transactions dying with
 * `1205 Lock wait timeout`. So contention is designed for, not hoped against:
 *
 *   - retried on 1205 and 1213 only - a deadlock or a lock timeout is
 *     transient by definition, whereas a constraint violation is not and must
 *     surface immediately;
 *   - three attempts, 100/400/900 ms with +-25% jitter. The jitter matters:
 *     two clients that collided once will collide again on a fixed schedule;
 *   - on exhaustion the mutation is NOT marked `rejected`. A temporary
 *     condition may never take a terminal status - the client would delete a
 *     perfectly good outbox row. The batch stops there instead and the
 *     response is partial.
 *
 * ==========================================================================
 * PARTIAL RESPONSE (protocol §4.3/P10b - frozen wire contract)
 * ==========================================================================
 * HTTP 200 with the results produced so far. The binding sentence:
 *
 *   "any mutation whose seq is absent from `results` is considered
 *    UNPROCESSED; it stays `queued` on the client and is re-sent next round."
 *
 * No new status and no new error code, because `idempotency_key` already makes
 * re-sending free. The alternative - a 5xx for the whole batch - would throw
 * away every result already committed and guarantee the next attempt hits the
 * same contention with the same work in front of it.
 */
class SyncPushService
{
    public const MAX_BATCH = 200;

    public const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_ATTEMPTS = 3;

    /**
     * Base backoff per attempt, in milliseconds (protocol §2.4/P4a).
     *
     * @var array<int, int>
     */
    private const BACKOFF_MS = [100, 400, 900];

    /**
     * MariaDB error codes that mean "try again", and nothing else.
     * 1205 = lock wait timeout, 1213 = deadlock.
     *
     * @var array<int, int>
     */
    private const RETRYABLE = [1205, 1213];

    public function __construct(private readonly MutationApplier $applier) {}

    /**
     * @param  array<int, array<string, mixed>>  $rawMutations
     * @return array<string, mixed>
     */
    public function push(User $user, string $batchId, array $rawMutations): array
    {
        $mutations = array_map(static fn (array $raw): Mutation => Mutation::fromArray($raw), $rawMutations);

        // `seq` is the client's causal order: a create must land before the
        // update that refers to it. Sorting here means a client that batches
        // out of order still gets correct behaviour rather than a mysterious
        // UNRESOLVED_REFERENCE.
        usort($mutations, static fn (Mutation $a, Mutation $b): int => $a->seq <=> $b->seq);

        $results = [];
        $clientIdMap = [];

        foreach ($mutations as $mutation) {
            $replay = $this->replayOf($user, $mutation);

            if ($replay !== null) {
                $results[] = $replay->toArray();

                continue;
            }

            $result = $this->applyWithRetry($mutation, $user, $clientIdMap, $batchId);

            if ($result === null) {
                // Contention did not clear. Everything from here on is left
                // unprocessed and therefore absent from `results` - see P10b.
                Log::warning('Sync push batch truncated by lock contention.', [
                    'batch_id' => $batchId,
                    'user_id' => $user->getKey(),
                    'stopped_at_seq' => $mutation->seq,
                    'completed' => count($results),
                ]);

                break;
            }

            $this->remember($user, $mutation, $result);

            $results[] = $result->toArray();
        }

        return [
            'batch_id' => $batchId,
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, int>  $clientIdMap
     * @return MutationResult|null null = retries exhausted, stop the batch
     */
    private function applyWithRetry(Mutation $mutation, User $user, array &$clientIdMap, string $batchId): ?MutationResult
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                return SyncActivityContext::within(['batch_id' => $batchId], fn (): MutationResult => DB::transaction(
                    fn (): MutationResult => $this->applier->apply($mutation, $user, $clientIdMap)
                ));
            } catch (QueryException $e) {
                if (! $this->isRetryable($e)) {
                    throw $e;
                }

                if ($attempt === self::MAX_ATTEMPTS - 1) {
                    return null;
                }

                usleep($this->backoffMicroseconds($attempt));
            }
        }

        return null;
    }

    private function isRetryable(QueryException $e): bool
    {
        // errorInfo[1] is the DRIVER code (1205/1213); errorInfo[0] is the
        // SQLSTATE, which lumps both under the same 'HY000'/'40001' buckets and
        // would therefore also match unrelated failures.
        $driverCode = $e->errorInfo[1] ?? null;

        return is_int($driverCode) && in_array($driverCode, self::RETRYABLE, true);
    }

    private function backoffMicroseconds(int $attempt): int
    {
        $base = self::BACKOFF_MS[$attempt] ?? self::BACKOFF_MS[count(self::BACKOFF_MS) - 1];

        // +-25% jitter. Without it, two clients that collided once retry in
        // lockstep and collide again at exactly the same moment.
        $jitter = random_int(-25, 25) / 100;

        return (int) round($base * (1 + $jitter) * 1000);
    }

    /**
     * Has this exact mutation already been applied?
     *
     * The ledger is keyed by `idempotency_key` AND scoped to the user, so a key
     * captured from one account can never replay a result into another.
     */
    private function replayOf(User $user, Mutation $mutation): ?MutationResult
    {
        $row = DB::table('sync_idempotency')
            ->where('idempotency_key', $mutation->idempotencyKey)
            ->where('user_id', $user->getKey())
            ->value('result_json');

        if ($row === null) {
            return null;
        }

        $stored = json_decode((string) $row, true);

        if (! is_array($stored)) {
            return null;
        }

        $stored['seq'] = $mutation->seq;

        return MutationResult::fromStored($stored);
    }

    private function remember(User $user, Mutation $mutation, MutationResult $result): void
    {
        /*
         * Only successful outcomes are remembered. A `rejected` or `conflict`
         * is a decision about the CURRENT server state, and that state changes:
         * a deal that was locked may be reopened, a conflict may be resolved.
         * Freezing those answers would make the client's retry permanently
         * wrong instead of eventually right.
         */
        if (! in_array($result->status, ['applied', 'duplicate'], true)) {
            return;
        }

        try {
            DB::table('sync_idempotency')->insert([
                'idempotency_key' => $mutation->idempotencyKey,
                'user_id' => $user->getKey(),
                'result_json' => (string) json_encode($result->toArray()),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // A duplicate key here means a concurrent request for the same
            // mutation already recorded it - which is exactly the state we
            // wanted. Anything else is still not worth failing an applied
            // mutation over: the worst case is that a retry re-applies work
            // that the UNIQUE `client_id` index already makes idempotent.
            Log::info('sync_idempotency write skipped.', [
                'idempotency_key' => $mutation->idempotencyKey,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
