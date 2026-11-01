<?php

namespace Tests\Feature\Sync;

use App\Events\Chat\MessageCreated;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sync\MutationApplier;
use App\Sync\Mutation;
use App\Sync\SyncCounter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MutationAuthorizationTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function mutation(array $data): array
    {
        return array_merge([
            'seq' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'op' => 'update',
            'base_sync_version' => 0,
            'occurred_at' => now()->subDay()->toIso8601String(),
        ], $data);
    }

    private function push(string $token, array $mutations): array
    {
        return $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => $mutations,
        ])->assertOk()->json('results');
    }

    private function assertNoRecordData(array $result): void
    {
        foreach (['server_row', 'sync_version', 'conflicting_fields'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
    }

    public function test_k10_real_applier_retries_nested_driver_errors_and_keeps_partial_results(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $company = Company::factory()->create(['name' => 'Locked row']);
        $attempts = 0;
        $driverCode = 1205;
        Company::updating(function (Company $row) use ($company, &$attempts, &$driverCode): void {
            if ($row->id === $company->id && $row->isDirty('name')) {
                $attempts++;
                // A real driver exception inside CompanyService's nested
                // transaction, rather than a MutationApplier double. SIGNAL
                // deterministically exercises the same wrapping as contention.
                DB::statement("SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = {$driverCode}, MESSAGE_TEXT = 'Lock wait timeout exceeded; try restarting transaction'");
            }
        });

        foreach ([1205, 1213] as $code) {
            $driverCode = $code;
            $attempts = 0;
            $firstId = (string) Str::uuid();
            $blocked = $this->mutation([
                'seq' => 2, 'entity' => 'company', 'server_id' => $company->id,
                'base_sync_version' => (int) $company->fresh()->sync_version,
                'changed_fields' => ['name'], 'payload' => ['name' => 'Blocked'],
            ]);
            $results = $this->push($token, [
                $this->mutation([
                    'op' => 'create', 'entity' => 'company', 'client_id' => $firstId,
                    'payload' => ['name' => 'Before lock'],
                ]),
                $blocked,
                $this->mutation([
                    'seq' => 3, 'op' => 'create', 'entity' => 'company',
                    'client_id' => (string) Str::uuid(), 'payload' => ['name' => 'After lock'],
                ]),
            ]);
            $this->assertSame(3, $attempts);
            $this->assertSame([1], array_column($results, 'seq'));
            $this->assertSame('applied', $results[0]['status']);
            $this->assertDatabaseHas('companies', ['client_id' => $firstId]);
            $this->assertDatabaseMissing('companies', ['name' => 'After lock']);
            $this->assertDatabaseMissing('sync_idempotency', ['idempotency_key' => $blocked['idempotency_key']]);
            $this->assertSame('Locked row', $company->fresh()->name);
        }
    }

    public function test_k11_create_permission_does_not_reveal_another_authors_row_or_private_conversation(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $company = Company::factory()->create(['client_id' => (string) Str::uuid()]);
        $actor->syncRoles([]);
        $actor->givePermissionTo(['companies.create', 'chat.use']);
        $conversation = Conversation::factory()->withMembers([User::factory()->create()])
            ->create(['client_id' => (string) Str::uuid()]);

        foreach ([['company', $company], ['conversation', $conversation]] as [$entity, $row]) {
            $map = [];
            $result = app(MutationApplier::class)->apply(Mutation::fromArray($this->mutation([
                'op' => 'create', 'entity' => $entity, 'client_id' => $row->client_id,
                'payload' => ['name' => 'Probe'],
            ])), $actor, $map)->toArray();
            $this->assertSame('FORBIDDEN', $result['code']);
            $this->assertArrayNotHasKey('server_id', $result);
            $this->assertNoRecordData($result);
            $this->assertSame([], $map);
        }

        // Default Admin also cannot replay a conversation without membership.
        $actor->assignRole('Admin');
        $this->app['auth']->forgetGuards();
        $result = $this->push($token, [$this->mutation([
            'op' => 'create', 'entity' => 'conversation', 'client_id' => $conversation->client_id,
        ])])[0];
        $this->assertSame('FORBIDDEN', $result['code']);
        $this->assertArrayNotHasKey('server_id', $result);
        $this->assertNoRecordData($result);
    }

    public function test_k12_array_message_parent_is_rejected_without_aborting_the_batch(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $results = $this->push($token, [
            $this->mutation([
                'op' => 'create', 'entity' => 'message', 'client_id' => (string) Str::uuid(),
                'payload' => ['conversation_id' => [1, 2], 'body' => 'Invalid parent'],
            ]),
            $this->mutation([
                'seq' => 2, 'op' => 'create', 'entity' => 'company', 'client_id' => (string) Str::uuid(),
                'payload' => ['name' => 'After invalid parent'],
            ]),
        ]);
        $this->assertSame('FORBIDDEN', $results[0]['code']);
        $this->assertNoRecordData($results[0]);
        $this->assertSame('applied', $results[1]['status']);
        $this->assertDatabaseMissing('messages', ['body' => 'Invalid parent']);
    }

    public function test_k13_create_only_actor_can_reference_their_company_in_the_same_batch(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $actor->syncRoles([]);
        $actor->givePermissionTo(['companies.create', 'contacts.create']);
        $foreign = Company::factory()->create(['client_id' => (string) Str::uuid()]);
        $companyId = (string) Str::uuid();
        $results = $this->push($token, [
            $this->mutation([
                'op' => 'create', 'entity' => 'company', 'client_id' => $companyId,
                'payload' => ['name' => 'Own new company'],
            ]),
            $this->mutation([
                'seq' => 2, 'op' => 'create', 'entity' => 'contact', 'client_id' => (string) Str::uuid(),
                'payload' => ['first_name' => 'New', 'last_name' => 'Contact', 'company_client_id' => $companyId],
            ]),
        ]);
        $this->assertSame(['applied', 'applied'], array_column($results, 'status'));
        $this->assertDatabaseHas('contacts', ['id' => $results[1]['server_id'], 'company_id' => $results[0]['server_id']]);
        $result = $this->push($token, [$this->mutation([
            'op' => 'create', 'entity' => 'contact', 'client_id' => (string) Str::uuid(),
            'payload' => ['first_name' => 'Hidden', 'last_name' => 'Parent', 'company_client_id' => $foreign->client_id],
        ])])[0];
        $this->assertSame('UNRESOLVED_REFERENCE', $result['code']);
        $this->assertNoRecordData($result);
    }

    public function test_k14_terminal_state_denials_without_view_are_forbidden_and_keep_ownership_checks(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $actor->syncRoles([]);
        $actor->givePermissionTo(['deals.delete', 'tickets.delete', 'leads.update']);
        $deal = Deal::factory()->create(['status' => 'won']);
        $ticket = Ticket::factory()->create(['status' => 'closed']);
        $lead = Lead::factory()->create(['status' => 'converted', 'owner_id' => $actor->id]);
        foreach ([['deal', $deal, 'delete'], ['ticket', $ticket, 'delete'], ['lead', $lead, 'update']] as [$entity, $row, $op]) {
            foreach ([['server_id' => $row->id], ['client_id' => (string) Str::uuid()]] as $address) {
                if (isset($address['client_id'])) {
                    DB::table($row->getTable())->where('id', $row->id)->update(['client_id' => $address['client_id']]);
                }
                $result = $this->push($token, [$this->mutation(array_merge([
                    'op' => $op, 'entity' => $entity, 'changed_fields' => ['name'], 'payload' => ['name' => 'Denied'],
                ], $address))])[0];
                $this->assertSame('FORBIDDEN', $result['code']);
                $this->assertNoRecordData($result);
                $this->assertNotNull($row->fresh());
            }
        }
        $lead->update(['owner_id' => User::factory()->create()->id]);
        $result = $this->push($token, [$this->mutation([
            'entity' => 'lead', 'server_id' => $lead->id,
            'changed_fields' => ['name'], 'payload' => ['name' => 'Hidden'],
        ])])[0];
        $this->assertSame('RECORD_DELETED', $result['code']);
        $this->assertNoRecordData($result);
    }

    public function test_y3_conflict_uses_the_locked_reread_instead_of_the_initial_model(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $conversation = Conversation::factory()->withMembers([$actor])->create();
        $message = Message::factory()->inConversation($conversation)->fromUser($actor)->create(['body' => 'Initial']);
        $base = (int) $message->fresh()->sync_version;
        $interleaved = false;
        $newVersion = null;
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        Message::retrieved(function (Message $row) use ($message, &$interleaved, &$newVersion): void {
            if ($row->id === $message->id && ! $interleaved) {
                $interleaved = true;
                // Deterministic interleaving: leave the first hydrated model
                // stale, but advance stored data before the locking reread.
                // This does not claim to stage a second connection's commit.
                $newVersion = SyncCounter::next();
                DB::table('messages')->where('id', $row->id)->update([
                    'body' => 'Interleaved writer', 'sync_version' => $newVersion,
                ]);
            }
        });
        $result = $this->push($token, [$this->mutation([
            'entity' => 'message', 'server_id' => $message->id, 'base_sync_version' => $base,
            'changed_fields' => ['body'], 'payload' => ['body' => 'Stale overwrite'],
        ])])[0];
        $this->assertTrue($interleaved);
        $this->assertSame('FIELD_CONFLICT', $result['code']);
        $this->assertSame($newVersion, $result['sync_version']);
        $this->assertSame('Interleaved writer', $result['server_row']['body']);
        $this->assertSame('Interleaved writer', $message->fresh()->body);
        $locks = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'for update')));
        $this->assertStringContainsString('sync_counter', $locks[0]);
        $this->assertNotEmpty(array_filter($locks, fn (string $sql): bool => str_contains($sql, 'messages')));
    }

    public function test_module_and_private_message_conflict_attacks_fail_in_one_batch(): void
    {
        [$attacker, $token] = $this->deviceUser('İzleyici');
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->withMembers([$owner])->create();
        $message = Message::factory()->inConversation($conversation)->fromUser($owner)->create(['body' => 'Private body']);
        $deal = Deal::factory()->create(['title' => 'Private deal']);
        $attacker->syncRoles([]);
        $results = $this->push($token, [
            $this->mutation(['entity' => 'message', 'server_id' => $message->id, 'changed_fields' => ['body'], 'payload' => ['body' => 'overwrite']]),
            $this->mutation(['seq' => 2, 'entity' => 'deal', 'server_id' => $deal->id, 'changed_fields' => ['title'], 'payload' => ['title' => 'overwrite']]),
        ]);

        foreach ($results as $result) {
            $this->assertSame('rejected', $result['status']);
            $this->assertSame('RECORD_DELETED', $result['code']);
            $this->assertNoRecordData($result);
            $this->assertArrayNotHasKey('server_id', $result);
        }
        $this->assertSame('Private body', $message->fresh()->body);
        $this->assertSame('Private deal', $deal->fresh()->title);
    }

    public function test_chat_permission_does_not_allow_non_member_conflict_or_delete_or_cursor(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->withMembers([$owner])->create();
        $message = Message::factory()->inConversation($conversation)->fromUser($owner)->create();
        foreach (['update', 'delete'] as $op) {
            $existing = $this->push($token, [$this->mutation([
                'op' => $op, 'entity' => 'message', 'server_id' => $message->id,
                'changed_fields' => ['body'], 'payload' => ['body' => 'overwrite'],
            ])])[0];
            $missing = $this->push($token, [$this->mutation([
                'op' => $op, 'entity' => 'message', 'server_id' => $message->id + 10000,
                'changed_fields' => ['body'], 'payload' => ['body' => 'overwrite'],
            ])])[0];
            $this->assertNoRecordData($existing);
            unset($existing['server_id'], $missing['server_id']);
            $this->assertSame($missing, $existing);
        }
        $result = $this->push($token, [$this->mutation([
            'op' => 'action', 'entity' => 'conversation', 'server_id' => $conversation->id, 'action' => 'read',
        ])])[0];
        $this->assertSame('RECORD_DELETED', $result['code']);
        $this->assertNoRecordData($result);
        $this->assertNotNull($message->fresh());
    }

    public function test_authorized_author_still_receives_a_legitimate_conflict(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $conversation = Conversation::factory()->withMembers([$actor])->create();
        $message = Message::factory()->inConversation($conversation)->fromUser($actor)->create(['body' => 'Server body']);
        $result = $this->push($token, [$this->mutation([
            'entity' => 'message', 'server_id' => $message->id,
            'changed_fields' => ['body'], 'payload' => ['body' => 'Offline body'],
        ])])[0];

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('FIELD_CONFLICT', $result['code']);
        $this->assertSame(['body'], $result['conflicting_fields']);
        $this->assertSame('Server body', $result['server_row']['body']);
        $this->assertSame((int) $message->fresh()->sync_version, $result['sync_version']);
    }

    public function test_view_permission_alone_does_not_allow_update_conflict_payload(): void
    {
        [$actor, $token] = $this->deviceUser('Satış Temsilcisi');
        $deal = Deal::factory()->create(['owner_id' => User::factory()->create()->id]);
        $this->assertTrue($actor->can('view', $deal));
        $result = $this->push($token, [$this->mutation([
            'entity' => 'deal', 'server_id' => $deal->id,
            'changed_fields' => ['title'], 'payload' => ['title' => 'overwrite'],
        ])])[0];
        $this->assertSame('FORBIDDEN', $result['code']);
        $this->assertNoRecordData($result);
    }

    public function test_create_replay_checks_visibility_before_exposing_id_or_populating_batch_map(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('chat.use');
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->withMembers([$owner])->create();
        $clientId = (string) Str::uuid();
        $message = Message::factory()->inConversation($conversation)->fromUser($owner)->create(['client_id' => $clientId]);
        $map = [];
        $result = app(MutationApplier::class)->apply(Mutation::fromArray($this->mutation([
            'op' => 'create', 'entity' => 'message', 'client_id' => $clientId,
            'payload' => ['conversation_id' => $conversation->id, 'body' => 'overwrite'],
        ])), $actor, $map)->toArray();

        $this->assertSame('FORBIDDEN', $result['code']);
        $this->assertArrayNotHasKey('server_id', $result);
        $this->assertNoRecordData($result);
        $this->assertSame([], $map);
        $this->assertNotNull($message->fresh());
    }

    public function test_hidden_client_id_targets_and_batch_map_targets_are_not_an_authorization_bypass(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('chat.use');
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->withMembers([$owner])->create();
        $clientId = (string) Str::uuid();
        $message = Message::factory()->inConversation($conversation)->fromUser($owner)->create(['client_id' => $clientId]);
        foreach ([[], [$clientId => $message->id]] as $map) {
            $result = app(MutationApplier::class)->apply(Mutation::fromArray($this->mutation([
                'entity' => 'message', 'client_id' => $clientId,
                'changed_fields' => ['body'], 'payload' => ['body' => 'overwrite'],
            ])), $actor, $map)->toArray();
            $this->assertSame('UNRESOLVED_REFERENCE', $result['code']);
            $this->assertNoRecordData($result);
            $this->assertArrayNotHasKey('server_id', $result);
        }
    }

    public function test_authorized_create_replay_still_returns_duplicate(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $clientId = (string) Str::uuid();
        $company = Company::factory()->create(['client_id' => $clientId]);
        $result = $this->push($token, [$this->mutation([
            'op' => 'create', 'entity' => 'company', 'client_id' => $clientId, 'payload' => ['name' => 'Replay'],
        ])])[0];
        $this->assertSame('duplicate', $result['status']);
        $this->assertSame($company->id, $result['server_id']);
        $this->assertSame((int) $company->fresh()->sync_version, $result['sync_version']);
    }

    public function test_authorized_move_keeps_its_service_conflict_payload(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $deal = Deal::factory()->create();
        $target = PipelineStage::factory()->create(['is_won' => false, 'is_lost' => false]);
        $result = $this->push($token, [$this->mutation([
            'op' => 'action', 'entity' => 'deal', 'server_id' => $deal->id, 'action' => 'move',
            'payload' => [
                'to_stage_id' => $target->id, 'version' => $deal->version + 5,
                'after_deal_id' => null, 'before_deal_id' => null,
            ],
        ])])[0];
        $this->assertSame('DEAL_VERSION_CONFLICT', $result['code']);
        $this->assertSame($deal->id, $result['server_row']['id']);
        $this->assertSame($deal->version, $result['server_row']['version']);
        $this->assertSame($deal->pipeline_stage_id, $deal->fresh()->pipeline_stage_id);
    }

    public function test_foreign_notifications_are_hidden_on_update_delete_and_read(): void
    {
        [, $token] = $this->deviceUser('Admin');
        $owner = User::factory()->create();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\Dummy',
            'notifiable_type' => $owner->getMorphClass(), 'notifiable_id' => $owner->id,
            'data' => '{"secret":"private"}', 'created_at' => now(), 'updated_at' => now(), 'sync_version' => 1,
        ]);
        foreach (['update', 'delete', 'action'] as $op) {
            $result = $this->push($token, [$this->mutation([
                'op' => $op, 'entity' => 'notification', 'client_id' => $id, 'action' => 'read',
                'changed_fields' => ['data'], 'payload' => ['data' => '{}'],
            ])])[0];
            $this->assertSame('UNRESOLVED_REFERENCE', $result['code']);
            $this->assertNoRecordData($result);
            $this->assertArrayNotHasKey('server_id', $result);
        }
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    public function test_hidden_and_unknown_client_references_have_the_same_result(): void
    {
        [, $token] = $this->deviceUser('İzleyici');
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->withMembers([$owner])->create(['client_id' => (string) Str::uuid()]);
        $results = [];
        foreach ([$conversation->client_id, (string) Str::uuid()] as $reference) {
            $results[] = $this->push($token, [$this->mutation([
                'op' => 'create', 'entity' => 'message', 'client_id' => (string) Str::uuid(),
                'payload' => ['conversation_client_id' => $reference, 'body' => 'probe'],
            ])])[0];
        }
        $this->assertSame($results[0], $results[1]);
        $this->assertSame('UNRESOLVED_REFERENCE', $results[0]['code']);
    }

    public function test_k2_post_write_validation_rolls_back_rows_audit_and_batch_map(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        // Fail after the real insert and audit, inside the mutation transaction.
        Task::created(function (Task $task): void {
            if ($task->title === 'Must roll back') {
                throw ValidationException::withMessages(['title' => ['Post-write validation failed.']]);
            }
        });
        $beforeTasks = DB::table('tasks')->count();
        $beforeAudit = DB::table('activity_log')->count();
        $clientId = (string) Str::uuid();
        $mutation = $this->mutation([
            'op' => 'create', 'entity' => 'task', 'client_id' => $clientId,
            'payload' => ['title' => 'Must roll back'],
        ]);
        $map = ['existing' => 123];
        $result = app(MutationApplier::class)->apply(Mutation::fromArray($mutation), $actor, $map)->toArray();
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('INVALID_MUTATION', $result['code']);
        $this->assertSame(['existing' => 123], $map);
        foreach ([1, 2] as $attempt) {
            $key = (string) Str::uuid();
            $result = $this->push($token, [array_replace($mutation, [
                'idempotency_key' => $key,
            ])])[0];
            $this->assertSame('rejected', $result['status']);
            $this->assertSame('INVALID_MUTATION', $result['code']);
            $this->assertArrayNotHasKey('server_id', $result);
            $this->assertDatabaseMissing('sync_idempotency', ['idempotency_key' => $key]);
        }
        $this->assertSame($beforeTasks, DB::table('tasks')->count());
        $this->assertSame($beforeAudit, DB::table('activity_log')->count());
        $this->assertDatabaseMissing('tasks', ['client_id' => $clientId]);
    }

    public function test_k2_create_only_permission_still_allows_creation_and_replay(): void
    {
        [$actor, $token] = $this->deviceUser('İzleyici');
        $actor->syncRoles([]);
        $actor->givePermissionTo(['companies.create', 'tasks.create', 'tickets.create']);
        foreach ([
            ['company', 'companies', ['name' => 'Create only']],
            ['task', 'tasks', ['title' => 'Create only']],
            ['ticket', 'tickets', ['subject' => 'Create only', 'description' => 'Create only']],
        ] as [$entity, $table, $payload]) {
            $this->assertFalse($actor->can($table.'.view'));
            $clientId = (string) Str::uuid();
            $mutation = $this->mutation([
                'op' => 'create', 'entity' => $entity, 'client_id' => $clientId, 'payload' => $payload,
            ]);
            $first = $this->push($token, [$mutation])[0];
            $this->assertSame('applied', $first['status']);
            $second = $this->push($token, [array_replace($mutation, ['idempotency_key' => (string) Str::uuid()])])[0];
            $this->assertSame('duplicate', $second['status']);
            $this->assertSame($first['server_id'], $second['server_id']);
            $this->assertSame(1, DB::table($table)->where('client_id', $clientId)->count());
        }
    }

    public function test_k5_soft_deleted_create_replay_checks_authority_and_stays_duplicate(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $clientId = (string) Str::uuid();
        $company = Company::factory()->create(['client_id' => $clientId]);
        $company->delete();
        $result = $this->push($token, [$this->mutation([
            'op' => 'create', 'entity' => 'company', 'client_id' => $clientId,
            'payload' => ['name' => 'Replay'],
        ])])[0];
        $this->assertSame('duplicate', $result['status']);
        $this->assertSame($company->id, $result['server_id']);
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
        $actor->syncRoles([]);
        $this->app['auth']->forgetGuards();
        $result = $this->push($token, [$this->mutation([
            'op' => 'create', 'entity' => 'company', 'client_id' => $clientId,
            'payload' => ['name' => 'Forbidden replay'],
        ])])[0];
        $this->assertSame('FORBIDDEN', $result['code']);
        $this->assertArrayNotHasKey('server_id', $result);
    }

    public function test_k6_operation_permission_without_view_allows_update_delete_and_action(): void
    {
        [$actor, $token] = $this->deviceUser('İzleyici');
        $actor->syncRoles([]);
        $actor->givePermissionTo(['deals.update', 'deals.delete', 'tasks.update']);
        $deal = Deal::factory()->create(['owner_id' => $actor->id]);
        $task = Task::factory()->create(['assigned_to' => $actor->id, 'status' => 'pending']);
        $this->assertFalse($actor->can('view', $deal));
        $result = $this->push($token, [$this->mutation([
            'entity' => 'deal', 'server_id' => $deal->id,
            'base_sync_version' => (int) $deal->fresh()->sync_version,
            'changed_fields' => ['title'], 'payload' => ['title' => 'Allowed update'],
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertSame('Allowed update', $deal->fresh()->title);
        $result = $this->push($token, [$this->mutation([
            'op' => 'action', 'entity' => 'task',
            'server_id' => $task->id, 'action' => 'complete', 'payload' => ['completed' => true],
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertSame('completed', $task->fresh()->status);
        $result = $this->push($token, [$this->mutation([
            'op' => 'delete', 'entity' => 'deal', 'server_id' => $deal->id,
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
    }

    public function test_k6_client_id_writes_without_view_preserve_ownership_boundaries(): void
    {
        [$actor, $token] = $this->deviceUser('İzleyici');
        $actor->syncRoles([]);
        $actor->givePermissionTo(['deals.update', 'tasks.update']);
        $clientId = (string) Str::uuid();
        $deal = Deal::factory()->create(['owner_id' => $actor->id, 'client_id' => $clientId]);
        $result = $this->push($token, [$this->mutation([
            'entity' => 'deal', 'client_id' => $clientId,
            'base_sync_version' => (int) $deal->fresh()->sync_version,
            'changed_fields' => ['title'], 'payload' => ['title' => 'Client address update'],
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertSame('Client address update', $deal->fresh()->title);
        $taskId = (string) Str::uuid();
        $task = Task::factory()->create(['assigned_to' => $actor->id, 'client_id' => $taskId, 'status' => 'pending']);
        $result = $this->push($token, [$this->mutation([
            'op' => 'action', 'entity' => 'task', 'client_id' => $taskId,
            'action' => 'complete', 'payload' => ['completed' => true],
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertSame('completed', $task->fresh()->status);
        $owner = User::factory()->create();
        $deal->update(['owner_id' => $owner->id]);
        $result = $this->push($token, [$this->mutation([
            'entity' => 'deal', 'client_id' => $clientId,
            'changed_fields' => ['title'], 'payload' => ['title' => 'Forbidden edit'],
        ])])[0];
        $this->assertSame('UNRESOLVED_REFERENCE', $result['code']);
        $this->assertNoRecordData($result);
        $this->assertSame('Client address update', $deal->fresh()->title);
    }

    public function test_k7_hidden_and_missing_message_parents_return_record_deleted(): void
    {
        Event::fake([MessageCreated::class]);
        [$actor, $token] = $this->deviceUser('Admin');
        $owner = User::factory()->create();
        $conversation = Conversation::factory()->withMembers([$owner, $actor])->create();
        $conversation->users()->detach($actor->id);
        $before = DB::table('messages')->count();
        $results = [];
        foreach ([$conversation->id, $conversation->id + 10000] as $id) {
            $results[] = $this->push($token, [$this->mutation([
                'op' => 'create', 'entity' => 'message', 'client_id' => (string) Str::uuid(),
                'payload' => ['conversation_id' => $id, 'body' => 'Must not send'],
            ])])[0];
        }
        $this->assertSame(['seq' => 1, 'status' => 'rejected', 'code' => 'RECORD_DELETED'], $results[0]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($before, DB::table('messages')->count());
        $visible = Conversation::factory()->withMembers([$actor])->create();
        $result = $this->push($token, [$this->mutation([
            'op' => 'create', 'entity' => 'message', 'client_id' => (string) Str::uuid(),
            'payload' => ['conversation_id' => $visible->id, 'body' => 'Allowed message'],
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertDatabaseHas('messages', ['id' => $result['server_id'], 'body' => 'Allowed message']);
    }

    public function test_k9_own_notification_read_still_works_after_target_resolution(): void
    {
        [$actor, $token] = $this->deviceUser('Admin');
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\Dummy',
            'notifiable_type' => $actor->getMorphClass(), 'notifiable_id' => $actor->id,
            'data' => '{}', 'created_at' => now(), 'updated_at' => now(), 'sync_version' => 1,
        ]);
        $result = $this->push($token, [$this->mutation([
            'op' => 'action', 'entity' => 'notification', 'client_id' => $id, 'action' => 'read',
        ])])[0];
        $this->assertSame('applied', $result['status']);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }
}
