<?php

namespace Tests\Feature\Sync;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sync\SyncPushService;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Consumer 2 of `wire-fixtures/`: THE LIVE SERVER.
 *
 * ==========================================================================
 * WHY THIS FILE IS NOT "MORE PUSH TESTS"
 * ==========================================================================
 * `SyncPushTest` already proves the push endpoint behaves. It does so with
 * bodies IT writes, and that is precisely the hole: in B1 the backend
 * fixtures were written in the server's own `'deal.move'` dialect, so PHPUnit
 * was green about a body no client has ever sent, while twelve verbs were
 * dead on the wire. The suite tested the server against the server.
 *
 * Every mutation below is read out of `wire-fixtures/push/*.json` - the SAME
 * files that
 *
 *   - `desktop/crates/syncra-sync/tests/wire_fixtures.rs` compares
 *     `OutboxRow::to_wire()` against, field by field, and
 *   - `desktop/src/platform/data/wire-fixtures.test.ts` compares the
 *     TypeScript `DataSource` composers against.
 *
 * No copy of a body exists in this file, and that is the whole mechanism: a
 * side that changes its own shape without touching the fixture goes red on
 * its own consumer; a side that edits the fixture instead turns the OTHER
 * consumers red. There is no arrangement in which all three are green and the
 * wire is broken.
 *
 * ==========================================================================
 * WHAT IS SUBSTITUTED, AND WHAT MAY NEVER BE
 * ==========================================================================
 * A fixture cannot carry a live primary key: `RefreshDatabase` mints new ids
 * every run. So each fixture declares a `server.bind` map of
 * `<dot path in the body> => <token from the seeded scenario>`, and
 * `bindIdentities()` rewrites those VALUES.
 *
 * KEYS ARE NEVER TOUCHED. Not one field name, not one nesting level, not the
 * `op`/`entity`/`action` triple - the shape that reaches `/api/sync/push` is
 * the fixture's shape verbatim. Substituting a value is binding a record;
 * substituting a key would be re-writing the contract mid-test, which is the
 * failure this whole directory exists to make impossible. `bindIdentities()`
 * asserts the path already exists before writing to it, so a renamed field is
 * a loud failure rather than a silently invented key.
 *
 * ==========================================================================
 * FIXTURES RECORD TODAY'S WIRE
 * ==========================================================================
 * A disagreement between a fixture and this server is a FINDING, to be
 * reported and decided. It is never a reason to edit the fixture until the
 * test passes - that converts a real defect into a documented one.
 */
class WireFixtureTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    // ------------------------------------------------------------------ io

    /**
     * The repository's `wire-fixtures/` directory.
     *
     * `base_path()` is `backend/`, so the shared directory is one level up.
     * Static because the data providers run before the application boots.
     */
    private static function fixtureDir(string $kind): string
    {
        $dir = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'wire-fixtures'.DIRECTORY_SEPARATOR.$kind;
        $real = realpath($dir);

        if ($real === false) {
            throw new \RuntimeException("wire-fixtures/{$kind} not found at {$dir}");
        }

        return $real;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readFixture(string $kind, string $name): array
    {
        $path = self::fixtureDir($kind).DIRECTORY_SEPARATOR.$name.'.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("{$path} is not valid JSON");
        }

        return $decoded;
    }

    /**
     * Every fixture name under one directory, as a PHPUnit data set.
     *
     * Named data sets so a failure says `deal.action.move`, not `#4`; the
     * whole point of a per-fixture case is that the report names the verb.
     *
     * @return array<string, array<int, string>>
     */
    private static function namesIn(string $kind): array
    {
        $files = glob(self::fixtureDir($kind).DIRECTORY_SEPARATOR.'*.json');
        sort($files);

        $sets = [];

        foreach ($files as $file) {
            $name = basename($file, '.json');
            $sets[$name] = [$name];
        }

        if ($sets === []) {
            throw new \RuntimeException("wire-fixtures/{$kind} is empty");
        }

        return $sets;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function pushFixtures(): array
    {
        return self::namesIn('push');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function pullRowFixtures(): array
    {
        return self::namesIn('pull');
    }

    // ------------------------------------------------------------- binding

    /**
     * Replace the identity VALUES a fixture cannot know with the seeded ones.
     *
     * @param  array<string, mixed>  $body  the fixture's `wire` object
     * @param  array<string, string>  $bind  dot path => scenario token
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>
     */
    private function bindIdentities(array $body, array $bind, array $tokens): array
    {
        foreach ($bind as $path => $token) {
            $this->assertArrayHasKey(
                $token,
                $tokens,
                "Fixture binds `{$path}` to unknown token `{$token}`."
            );

            $segments = explode('.', $path);

            // The path must already exist. A bind that CREATES a key would
            // paper over exactly the drift this suite is here to catch: the
            // client renames `owner_id`, the fixture keeps binding it, and the
            // test invents the old key back for the server to accept.
            $probe = $body;

            foreach ($segments as $segment) {
                $this->assertIsArray($probe, "Fixture path `{$path}` runs through a scalar.");
                $this->assertArrayHasKey(
                    $segment,
                    $probe,
                    "Fixture binds `{$path}`, but the wire body has no such field. "
                    .'Either the body was renamed and the bind was not, or the bind is stale.'
                );
                $probe = $probe[$segment];
            }

            $cursor = &$body;

            foreach ($segments as $segment) {
                $cursor = &$cursor[$segment];
            }

            $cursor = $tokens[$token];
            unset($cursor);
        }

        return $body;
    }

    /**
     * Build the records a fixture's `server.scenario` names.
     *
     * One method rather than one per scenario: every branch produces the same
     * thing - a flat `token => value` map - and splitting that into nine
     * private methods would hide the one fact worth seeing, which is how
     * little setup each canonical body actually needs.
     *
     * @return array<string, mixed>
     */
    private function scenario(string $name, User $user): array
    {
        return match ($name) {
            'none' => [],

            'company' => (function () use ($user) {
                $company = \App\Models\Company::factory()->create();

                return [
                    'company.id' => (int) $company->id,
                    // Bound into `base_sync_version` so the update/delete
                    // fixtures are based on the CURRENT server state. A stale
                    // base would answer `conflict`, which is a correct answer
                    // to a different question than the one this suite asks.
                    'company.sync_version' => (int) $company->sync_version,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'deal' => (function () use ($user) {
                $stages = PipelineStage::query()->where('is_won', false)->where('is_lost', false)
                    ->orderBy('position')->take(2)->get();

                $this->assertCount(2, $stages, 'The move fixture needs two open stages to move between.');

                $deal = Deal::factory()->create([
                    'pipeline_stage_id' => $stages[0]->id,
                    'owner_id' => $user->getKey(),
                    'status' => 'open',
                ]);

                return [
                    'deal.id' => (int) $deal->id,
                    // `version` is the deal's OWN optimistic lock, not the
                    // delta cursor. Protocol §4.3: the two counters travel in
                    // the same body and neither substitutes for the other.
                    'deal.version' => (int) $deal->version,
                    'deal.sync_version' => (int) $deal->sync_version,
                    'stage_b.id' => (int) $stages[1]->id,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'task' => (function () use ($user) {
                $task = Task::factory()->create([
                    'status' => 'pending',
                    'completed_at' => null,
                    'created_by' => $user->getKey(),
                ]);

                return [
                    'task.id' => (int) $task->id,
                    'task.sync_version' => (int) $task->sync_version,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'ticket' => (function () use ($user) {
                $ticket = Ticket::factory()->create([
                    'status' => 'open',
                    'resolved_at' => null,
                    'closed_at' => null,
                ]);

                return [
                    'ticket.id' => (int) $ticket->id,
                    'ticket.sync_version' => (int) $ticket->sync_version,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'lead' => (function () use ($user) {
                $lead = Lead::factory()->create(['owner_id' => $user->getKey()]);

                return [
                    'lead.id' => (int) $lead->id,
                    'lead.sync_version' => (int) $lead->sync_version,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'quote' => (function () use ($user) {
                // `sent` on purpose: QuoteStatusMachine only allows
                // accepted/rejected/expired FROM `sent`, and the fixture's
                // canonical body is the accept.
                $quote = Quote::factory()->create(['status' => 'sent']);

                return [
                    'quote.id' => (int) $quote->id,
                    'quote.sync_version' => (int) $quote->sync_version,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'conversation' => (function () use ($user) {
                $conversation = Conversation::factory()->create(['created_by' => $user->getKey()]);
                $conversation->users()->attach([$user->getKey()], ['joined_at' => now()]);

                $message = Message::factory()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->getKey(),
                ]);

                return [
                    'conversation.id' => (int) $conversation->id,
                    'message.id' => (int) $message->id,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            'notification' => (function () use ($user) {
                $uuid = (string) Str::uuid();

                DB::table('notifications')->insert([
                    'id' => $uuid,
                    'type' => 'App\\Notifications\\Dummy',
                    'notifiable_type' => $user->getMorphClass(),
                    'notifiable_id' => $user->getKey(),
                    'data' => '{}',
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'sync_version' => 0,
                ]);

                return [
                    'notification.id' => $uuid,
                    'user.id' => (int) $user->getKey(),
                ];
            })(),

            default => throw new \RuntimeException("Unknown fixture scenario: {$name}"),
        };
    }

    // ---------------------------------------------------------------- push

    /**
     * The canonical body, POSTed at the real endpoint against a real MariaDB.
     */
    #[DataProvider('pushFixtures')]
    public function test_the_server_applies_the_canonical_push_body(string $name): void
    {
        $fixture = self::readFixture('push', $name);

        [$user, $token] = $this->deviceUser('Admin');

        $tokens = $this->scenario((string) $fixture['server']['scenario'], $user);

        $body = $this->bindIdentities(
            $fixture['wire'],
            $fixture['server']['bind'] ?? [],
            $tokens
        );

        $response = $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [$body],
        ]);

        $response->assertOk();

        $status = $response->json('results.0.status');
        $code = $response->json('results.0.code');

        $this->assertSame(
            $fixture['server']['expect'],
            $status,
            "Fixture `{$name}` is the body the desktop client really sends, and the server "
            ."answered `{$status}`".($code === null ? '' : " ({$code})").". "
            .'The fixture records CURRENT behaviour, so a red here is a wire mismatch to '
            .'report, not a fixture to edit. '.($fixture['why'] ?? '')
        );
    }

    /**
     * Coverage, asserted rather than assumed.
     *
     * `MutationApplier::ALLOWED_ACTIONS` is the server's own list. An entry
     * with no fixture is a verb no consumer is comparing, which is the exact
     * state all twelve were in before B1 was found. The crate makes the same
     * assertion against `protocol::ACTION_WHITELIST`, so the two whitelists
     * are transitively held equal through the fixture set - which is the
     * check neither side could make alone.
     */
    public function test_every_whitelisted_action_has_a_wire_fixture(): void
    {
        $covered = [];

        foreach (array_keys(self::pushFixtures()) as $name) {
            $wire = self::readFixture('push', $name)['wire'];

            if (isset($wire['action'])) {
                $covered[] = $wire['entity'].'.'.$wire['action'];
            }
        }

        $missing = array_values(array_diff(\App\Services\Sync\MutationApplier::ALLOWED_ACTIONS, $covered));

        $this->assertSame(
            [],
            $missing,
            'These whitelisted actions have no canonical wire body: '.implode(', ', $missing)
        );

        $stray = array_values(array_diff($covered, \App\Services\Sync\MutationApplier::ALLOWED_ACTIONS));

        $this->assertSame(
            [],
            $stray,
            'These fixtures describe actions the server does not whitelist: '.implode(', ', $stray)
        );
    }

    /**
     * O45, from the server's side.
     *
     * `applyAction()` refuses an entity-qualified verb outright. The fixtures
     * are checked for the dotted dialect by the crate; this asserts the server
     * still refuses it, so the two halves of the decision cannot drift apart.
     */
    public function test_the_server_refuses_the_entity_qualified_dialect_of_a_fixture(): void
    {
        $fixture = self::readFixture('push', 'deal.action.move');

        [$user, $token] = $this->deviceUser('Admin');
        $tokens = $this->scenario('deal', $user);

        $body = $this->bindIdentities($fixture['wire'], $fixture['server']['bind'], $tokens);
        // The ONE change: the dialect B1 was about.
        $body['action'] = 'deal.move';

        $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [$body],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_MUTATION');
    }

    // ---------------------------------------------------------------- pull

    /**
     * The pull row really carries every key the fixture names.
     *
     * Containment, not equality: every `wire-fixtures/pull/*.row.json` fixture
     * declares itself a SUBSET (`completeness: "subset"`), so this asserts the
     * server sends at least those keys. The crate asserts the mirror has a
     * column for each of them - which is the pair that would have caught the
     * SLA fields (and now the flattened attachment fields, KARAR A29) going
     * missing, because on their own each side looked fine.
     *
     * One data-provided test per fixture file rather than one method per
     * table: every branch needs different seeding (a ticket vs. a message
     * with an attachment, in a conversation the caller belongs to), so the
     * seeding is dispatched on `fixture.table` instead of duplicating the
     * HTTP call and key-containment assertion per table.
     */
    #[DataProvider('pullRowFixtures')]
    public function test_the_pull_row_carries_every_key_the_fixture_names(string $name): void
    {
        $fixture = self::readFixture('pull', $name);
        $table = (string) $fixture['table'];

        [$user, $token] = $this->deviceUser('Admin');

        $id = match ($table) {
            'tickets' => $this->seedTicketForPullFixture(),
            'messages' => $this->seedMessageForPullFixture($user),
            default => throw new \RuntimeException("No pull-fixture seeding wired for table `{$table}`."),
        };

        $rows = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => [$table => 0],
        ])->assertOk()->json("tables.{$table}.rows");

        $row = collect($rows)->firstWhere('id', $id);

        $this->assertNotNull($row, "The seeded {$fixture['entity']} is not in the pull response at all.");

        foreach (array_keys($fixture['row']) as $key) {
            $this->assertArrayHasKey(
                $key,
                $row,
                "The pull row has no `{$key}`. The fixture says the desktop mirror is built "
                .'from that key, and a key the server stops sending is a column the client '
                .'silently stops filling.'
            );
        }
    }

    private function seedTicketForPullFixture(): int
    {
        $ticket = Ticket::factory()->create([
            'priority' => 'high',
            'status' => 'open',
            'sla_due_at' => now()->addHours(20),
            'sla_paused_at' => null,
            'sla_paused_seconds' => 0,
            'resolved_at' => null,
        ]);

        return (int) $ticket->id;
    }

    /**
     * A `messages` row carrying an attachment, in a conversation the caller
     * (`$user`) is a member of - `SyncScope::applyRowScope()` gates
     * `messages` on `conversation_user` membership, so a message in a
     * conversation the caller does not belong to would simply not come back.
     */
    private function seedMessageForPullFixture(User $user): int
    {
        $conversation = Conversation::factory()->dm()->createdBy($user)->withMembers([$user])->create();
        $attachment = Attachment::factory()->image()->create();

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        return (int) $message->id;
    }

    // -------------------------------------------------------------- errors

    /**
     * The refusal envelope is the one the fixture records.
     *
     * Case 2 of the four: the code is nested under `errors`, one level down.
     * The crate parses the same fixture body with `ApiErrorBody::from_value`;
     * this proves the server still produces it.
     */
    public function test_the_refusal_envelope_matches_the_fixture(): void
    {
        $fixture = self::readFixture('errors', 'push_batch_too_large');

        [, $token] = $this->deviceUser('Admin');

        $mutations = [];

        for ($i = 0; $i <= SyncPushService::MAX_BATCH; $i++) {
            $mutations[] = [
                'seq' => $i,
                'idempotency_key' => (string) Str::uuid(),
                'occurred_at' => now()->toIso8601String(),
                'op' => 'create',
                'entity' => 'company',
                'client_id' => (string) Str::uuid(),
                'payload' => ['name' => 'Bulk '.$i],
            ];
        }

        $response = $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => $mutations,
        ]);

        $response->assertStatus((int) $fixture['http_status']);

        // The shape, key by key - a flat `{"code": ...}` would be a different
        // envelope and is what the client used to (wrongly) expect.
        $this->assertIsArray($response->json('errors'), 'The envelope must nest under `errors`.');
        $this->assertSame($fixture['expect']['code'], $response->json('errors.code'));
        $this->assertNotNull($response->json('errors.message'), 'A refusal without a message logs an empty line.');
    }
}
