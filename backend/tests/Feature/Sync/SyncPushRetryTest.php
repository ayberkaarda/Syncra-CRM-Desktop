<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Services\Notifications\NotificationReadService;
use App\Services\Sync\ConflictDetector;
use App\Services\Sync\MutationApplier;
use App\Sync\Mutation;
use App\Sync\MutationResult;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

/**
 * Protocol §2.4/P4a + §4.3/P10b — lock contention and the partial response.
 *
 * ==========================================================================
 * WHY THE FAILURE IS INJECTED RATHER THAN PROVOKED
 * ==========================================================================
 * A real `1205 Lock wait timeout` needs two connections holding the
 * `sync_counter` row at once, which a single-process PHPUnit run cannot stage
 * deterministically - and a test that sometimes wins the race is worse than no
 * test. What actually needs locking down here is the DECISION, not MariaDB's
 * behaviour (probes C1/C2 already measured that): when contention does not
 * clear, does the server keep the results it already committed, and does it
 * refuse to mark a transient failure as terminal?
 *
 * So a MutationApplier double raises a genuine QueryException carrying driver
 * code 1205 for one specific `seq`.
 */
class SyncPushRetryTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_batch_that_hits_unclearable_contention_returns_a_partial_200(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $this->app->bind(MutationApplier::class, fn (): MutationApplier => new LockingApplier(
            app(ConflictDetector::class),
            app(NotificationReadService::class),
            failOnSeq: 2,
        ));

        $response = $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [
                $this->mutation(1, 'A Ltd'),
                $this->mutation(2, 'B Ltd'),
                $this->mutation(3, 'C Ltd'),
            ],
        ]);

        // HTTP 200: the transport succeeded and the first mutation really is
        // committed. A 5xx would throw that away and guarantee the retry meets
        // the same contention with the same work in front of it.
        $response->assertOk();

        $results = $response->json('results');

        $this->assertCount(1, $results, 'Batch, çakışmanın olduğu noktada KESİLMELİ.');
        $this->assertSame(1, $results[0]['seq']);
        $this->assertSame('applied', $results[0]['status']);

        // P10b, the binding sentence: a seq absent from `results` is
        // UNPROCESSED. It must therefore have no terminal status of any kind -
        // a `rejected` would make the client delete a perfectly good outbox row.
        $this->assertSame([1], array_column($results, 'seq'));

        $this->assertDatabaseHas('companies', ['name' => 'A Ltd']);
        $this->assertDatabaseMissing('companies', ['name' => 'B Ltd']);
        $this->assertDatabaseMissing('companies', ['name' => 'C Ltd']);
    }

    /**
     * The retry is bounded and only fires on the two transient codes. A
     * constraint violation is permanent, and retrying it three times would
     * just delay the same answer while holding a worker.
     */
    public function test_a_non_transient_query_error_is_not_retried(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $applier = new LockingApplier(
            app(ConflictDetector::class),
            app(NotificationReadService::class),
            failOnSeq: 1,
            driverCode: 1062, // duplicate entry - terminal
        );

        $this->app->bind(MutationApplier::class, fn (): MutationApplier => $applier);

        $response = $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [$this->mutation(1, 'A Ltd')],
        ]);

        $response->assertStatus(500);

        $this->assertSame(1, $applier->attempts, 'Kalıcı bir hata için yeniden deneme yapılmamalı.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mutation(int $seq, string $name): array
    {
        return [
            'seq' => $seq,
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
            'op' => 'create',
            'entity' => 'company',
            'client_id' => (string) Str::uuid(),
            'payload' => ['name' => $name],
        ];
    }
}

/**
 * Applies everything normally except one `seq`, which raises a real
 * QueryException carrying the requested driver code.
 */
class LockingApplier extends MutationApplier
{
    public int $attempts = 0;

    public function __construct(
        ConflictDetector $conflicts,
        NotificationReadService $notifications,
        private readonly int $failOnSeq = 0,
        private readonly int $driverCode = 1205,
    ) {
        parent::__construct($conflicts, $notifications);
    }

    public function apply(Mutation $mutation, User $actor, array &$clientIdMap): MutationResult
    {
        if ($mutation->seq !== $this->failOnSeq) {
            return parent::apply($mutation, $actor, $clientIdMap);
        }

        $this->attempts++;

        // A genuine QueryException, built the way the driver builds one:
        // SyncPushService reads errorInfo[1] (the DRIVER code), not the
        // SQLSTATE, because SQLSTATE lumps unrelated failures into the same
        // bucket.
        $previous = new PDOException('SQLSTATE[HY000]: General error: '.$this->driverCode);
        $previous->errorInfo = ['HY000', $this->driverCode, 'injected'];

        throw new QueryException('mysql', 'update sync_counter set value = ?', [], $previous);
    }
}
