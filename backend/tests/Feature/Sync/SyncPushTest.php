<?php

namespace Tests\Feature\Sync;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Quote;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Companies\CompanyService;
use App\Services\Sync\SyncPushService;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SYNCDESKTOP §4.6/4 — the push endpoint.
 *
 * The theme running through this file is K7: nothing here asserts that the
 * sync layer implements a rule, it asserts that the sync layer REACHES the
 * rule that already exists. QUOTE_LOCKED, the status machines, the policies
 * and the `changed_fields` contract are all proved by observing the SAME
 * refusal the HTTP surface produces.
 */
class SyncPushTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mutations
     */
    private function push(string $token, array $mutations): TestResponse
    {
        return $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => $mutations,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mutation(array $overrides): array
    {
        return array_merge([
            'seq' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_create_stores_the_client_id_and_returns_the_server_id(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $clientId = (string) Str::uuid();

        $response = $this->push($token, [$this->mutation([
            'op' => 'create',
            'entity' => 'company',
            'client_id' => $clientId,
            'payload' => ['name' => 'Offline Ltd'],
        ])]);

        $response->assertOk()->assertJsonPath('results.0.status', 'applied');

        $serverId = $response->json('results.0.server_id');

        $this->assertDatabaseHas('companies', ['id' => $serverId, 'name' => 'Offline Ltd', 'client_id' => $clientId]);
        $this->assertSame((int) Company::find($serverId)->sync_version, $response->json('results.0.sync_version'));
    }

    /**
     * The offline case that has no online equivalent: a contact created for a
     * company that also only exists offline. The reference is resolved from
     * the ids assigned earlier in the SAME batch.
     */
    public function test_a_reference_to_a_record_created_earlier_in_the_same_batch_resolves(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $companyClientId = (string) Str::uuid();

        $response = $this->push($token, [
            $this->mutation([
                'seq' => 1,
                'op' => 'create',
                'entity' => 'company',
                'client_id' => $companyClientId,
                'payload' => ['name' => 'Parent A.Ş.'],
            ]),
            $this->mutation([
                'seq' => 2,
                'op' => 'create',
                'entity' => 'contact',
                'client_id' => (string) Str::uuid(),
                'payload' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'company_client_id' => $companyClientId,
                ],
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        $companyId = $response->json('results.0.server_id');
        $contact = Contact::find($response->json('results.1.server_id'));

        $this->assertSame((int) $companyId, (int) $contact->company_id);
    }

    public function test_an_unresolvable_reference_is_rejected_rather_than_dropped(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $response = $this->push($token, [$this->mutation([
            'op' => 'create',
            'entity' => 'contact',
            'client_id' => (string) Str::uuid(),
            'payload' => [
                'first_name' => 'Orphan',
                'last_name' => 'Record',
                'company_client_id' => (string) Str::uuid(),
            ],
        ])]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'UNRESOLVED_REFERENCE');

        // The point of rejecting: a contact saved WITHOUT its company would be
        // silently wrong and impossible to detect later.
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_replaying_the_same_idempotency_key_returns_duplicate_and_creates_nothing(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $mutation = $this->mutation([
            'op' => 'create',
            'entity' => 'company',
            'client_id' => (string) Str::uuid(),
            'payload' => ['name' => 'Replayed Ltd'],
        ]);

        $first = $this->push($token, [$mutation]);
        $second = $this->push($token, [$mutation]);

        $first->assertJsonPath('results.0.status', 'applied');
        $second->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.server_id', $first->json('results.0.server_id'));

        $this->assertDatabaseCount('companies', 1);
    }

    public function test_update_writes_only_the_changed_fields(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['name' => 'Before', 'city' => 'Ankara']);

        $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'company',
            'server_id' => $company->id,
            'base_sync_version' => $company->sync_version,
            'changed_fields' => ['name'],
            // `city` is in the payload but NOT in changed_fields: an offline
            // client holds the whole record, and writing the whole record back
            // would blindly overwrite whatever somebody else edited meanwhile.
            'payload' => ['name' => 'After', 'city' => 'İstanbul'],
        ])])->assertJsonPath('results.0.status', 'applied');

        $company->refresh();

        $this->assertSame('After', $company->name);
        $this->assertSame('Ankara', $company->city, 'changed_fields DIŞINDAKİ alan yazıldı — sessiz üzerine yazma.');
    }

    public function test_changed_fields_must_be_a_subset_of_payload(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create();

        $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'company',
            'server_id' => $company->id,
            'base_sync_version' => $company->sync_version,
            'changed_fields' => ['name', 'city'],
            'payload' => ['name' => 'Only name'],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_MUTATION');
    }

    /**
     * Field-level LWW (SYNCDESKTOP §4.4 / protocol §5): a concurrent edit to a
     * DIFFERENT field is not a conflict, an edit to the SAME field is.
     */
    public function test_a_concurrent_edit_to_another_field_is_not_a_conflict(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['name' => 'Base', 'city' => 'Ankara']);
        $base = (int) $company->sync_version;

        /*
         * The timeline has to be REAL, not simulated with a backdated
         * occurred_at: the detector unions the fields of every activity_log
         * row created after `occurred_at`, and the record's own `created` row
         * carries EVERY fillable attribute. Backdating the offline edit to
         * before creation would sweep that row in and turn any edit into a
         * conflict - which is a property of the test, not of the code.
         */
        $this->travel(10)->minutes();
        $occurredAt = now()->toIso8601String();

        $this->travel(10)->minutes();
        $this->actingAs($user);
        app(CompanyService::class)->update($company, ['city' => 'İzmir']);
        $this->app['auth']->forgetGuards();

        $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'company',
            'server_id' => $company->id,
            'base_sync_version' => $base,
            'occurred_at' => $occurredAt,
            'changed_fields' => ['name'],
            'payload' => ['name' => 'Offline name'],
        ])])->assertJsonPath('results.0.status', 'applied');

        $this->assertSame('Offline name', $company->fresh()->name);
        $this->assertSame('İzmir', $company->fresh()->city);
    }

    public function test_a_concurrent_edit_to_the_same_field_is_a_field_conflict(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['name' => 'Base']);
        $base = (int) $company->sync_version;

        $this->travel(10)->minutes();
        $occurredAt = now()->toIso8601String();

        $this->travel(10)->minutes();
        $this->actingAs($user);
        app(CompanyService::class)->update($company, ['name' => 'Server name']);
        $this->app['auth']->forgetGuards();

        $response = $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'company',
            'server_id' => $company->id,
            'base_sync_version' => $base,
            'occurred_at' => $occurredAt,
            'changed_fields' => ['name'],
            'payload' => ['name' => 'Offline name'],
        ])]);

        $response->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.code', 'FIELD_CONFLICT')
            ->assertJsonPath('results.0.conflicting_fields', ['name']);

        $this->assertSame('Server name', $company->fresh()->name, 'Çakışan yazma UYGULANMAMALI.');
        $this->assertSame('Server name', $response->json('results.0.server_row.name'));
    }

    public function test_deal_move_reaches_the_optimistic_lock(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create();
        $target = PipelineStage::query()->where('id', '!=', $deal->pipeline_stage_id)->first();

        $response = $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'deal',
            'server_id' => $deal->id,
            'action' => 'deal.move',
            /*
             * Field names come from the EXISTING MoveDealRequest contract
             * (`to_stage_id`, not `pipeline_stage_id`) - K7: the sync path
             * reuses that request rather than defining a second vocabulary.
             */
            'payload' => [
                'to_stage_id' => $target->id,
                // Stale on purpose: DealMoveService must refuse it.
                'version' => $deal->version + 5,
                'after_deal_id' => null,
                'before_deal_id' => null,
            ],
        ])]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'DEAL_VERSION_CONFLICT');

        $this->assertSame($deal->pipeline_stage_id, $deal->fresh()->pipeline_stage_id);
    }

    public function test_deal_move_with_the_current_version_applies(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create();
        $target = PipelineStage::query()
            ->where('id', '!=', $deal->pipeline_stage_id)
            ->where('is_won', false)->where('is_lost', false)
            ->first();

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'deal',
            'server_id' => $deal->id,
            'action' => 'deal.move',
            'payload' => [
                'to_stage_id' => $target->id,
                'version' => $deal->version,
                'after_deal_id' => null,
                'before_deal_id' => null,
            ],
        ])])->assertJsonPath('results.0.status', 'applied');

        $this->assertSame($target->id, $deal->fresh()->pipeline_stage_id);
    }

    public function test_editing_a_sent_quote_hits_quote_locked(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $quote = Quote::factory()->create(['status' => 'sent']);

        $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'quote',
            'server_id' => $quote->id,
            'base_sync_version' => $quote->sync_version,
            // QuoteService::AMOUNT_LOCKED_FIELDS - the document's AMOUNT is
            // what freezes once it reaches the customer, not its title.
            'changed_fields' => ['discount_value'],
            'payload' => ['discount_value' => 10],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'QUOTE_LOCKED');
    }

    public function test_an_illegal_ticket_transition_hits_the_status_machine(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $ticket = Ticket::factory()->create(['status' => 'open']);

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'ticket',
            'server_id' => $ticket->id,
            'action' => 'ticket.status',
            'payload' => ['status' => 'closed'],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_online_only_actions_are_refused_with_their_own_code(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $lead = Lead::factory()->create();

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'lead',
            'server_id' => $lead->id,
            'action' => 'lead.convert',
            'payload' => [],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'ONLINE_ONLY');
    }

    public function test_an_action_outside_the_whitelist_is_refused(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create();

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'deal',
            'server_id' => $deal->id,
            'action' => 'deal.obliterate',
            'payload' => [],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_MUTATION');
    }

    public function test_deleting_a_won_deal_is_refused_by_the_policy(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $wonStage = PipelineStage::query()->where('is_won', true)->first();
        $deal = Deal::factory()->create(['status' => 'won', 'pipeline_stage_id' => $wonStage->id]);

        $this->push($token, [$this->mutation([
            'op' => 'delete',
            'entity' => 'deal',
            'server_id' => $deal->id,
            'base_sync_version' => $deal->sync_version,
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'FORBIDDEN');

        $this->assertNotSoftDeleted($deal);
    }

    public function test_updating_an_already_deleted_record_reports_record_deleted(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $task = Task::factory()->create();
        $id = $task->id;
        $task->forceDelete();

        $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'task',
            'server_id' => $id,
            'base_sync_version' => 1,
            'changed_fields' => ['title'],
            'payload' => ['title' => 'Ghost'],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'RECORD_DELETED');
    }

    /**
     * WIRE RULE 1 — `op=delete` carries NEITHER `occurred_at` NOR `payload`.
     *
     * SYNCDESKTOP §4.4's own example sends only seq / idempotency_key / op /
     * entity / server_id / base_sync_version. `occurred_at` exists so
     * ConflictDetector can compare it against `activity_log.created_at`, and
     * that is an `op=update` question: a delete decides on
     * `base_sync_version` alone. If validation demanded either field, every
     * delete the client sends would come back 422 and offline deletions would
     * never reach the server.
     */
    public function test_a_delete_without_occurred_at_or_payload_is_accepted(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $task = Task::factory()->create();

        $response = $this->push($token, [[
            'seq' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'op' => 'delete',
            'entity' => 'task',
            'server_id' => $task->id,
            'base_sync_version' => $task->sync_version,
        ]]);

        $response->assertOk()->assertJsonPath('results.0.status', 'applied');

        $this->assertSoftDeleted($task);
    }

    /**
     * WIRE RULE 2 — a mutation may address its target by `client_id`.
     *
     * This is the sequence that has no online equivalent and is the reason the
     * rule exists: a task created and completed in the SAME offline session.
     * The client sorts actions after their entity's create (SYNCDESKTOP §5.4),
     * so when the action is applied the server id exists only in this batch's
     * running map - it cannot be in the request, because it did not exist when
     * the request was written.
     */
    public function test_an_action_can_target_a_record_created_earlier_in_the_same_batch(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $taskClientId = (string) Str::uuid();

        $response = $this->push($token, [
            $this->mutation([
                'seq' => 1,
                'op' => 'create',
                'entity' => 'task',
                'client_id' => $taskClientId,
                'payload' => ['title' => 'Offline görev'],
            ]),
            $this->mutation([
                'seq' => 2,
                'op' => 'action',
                'entity' => 'task',
                // No server_id: it does not exist yet on the client.
                'client_id' => $taskClientId,
                'action' => 'task.complete',
                'payload' => ['completed' => true],
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        $task = Task::find($response->json('results.0.server_id'));

        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_an_update_can_target_a_record_by_client_id_from_an_earlier_batch(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $clientId = (string) Str::uuid();

        $created = $this->push($token, [$this->mutation([
            'op' => 'create',
            'entity' => 'company',
            'client_id' => $clientId,
            'payload' => ['name' => 'Batch One'],
        ])]);

        $serverId = $created->json('results.0.server_id');

        // A SEPARATE batch: the client still has not adopted the server id
        // (its response may have been lost), so it addresses by client_id.
        $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'company',
            'client_id' => $clientId,
            'base_sync_version' => $created->json('results.0.sync_version'),
            'changed_fields' => ['name'],
            'payload' => ['name' => 'Batch Two'],
        ])])->assertJsonPath('results.0.status', 'applied');

        $this->assertSame('Batch Two', Company::find($serverId)->name);
    }

    /**
     * WIRE RULE 2, the failure half. A `client_id` nobody has ever seen means
     * its create is still queued behind this mutation - TRANSIENT, so the
     * client must keep the outbox row and retry. Reporting it as
     * RECORD_DELETED would throw away work the user did offline.
     */
    public function test_an_unresolvable_client_id_target_is_unresolved_reference(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'task',
            'client_id' => (string) Str::uuid(),
            'action' => 'task.complete',
            'payload' => ['completed' => true],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'UNRESOLVED_REFERENCE');

        $this->push($token, [$this->mutation([
            'op' => 'delete',
            'entity' => 'task',
            'client_id' => (string) Str::uuid(),
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'UNRESOLVED_REFERENCE');
    }

    /**
     * A `server_id` whose row is gone is the OTHER answer, and the difference
     * matters: this one is terminal, so the client stops retrying.
     */
    public function test_a_missing_server_id_target_is_record_deleted_not_unresolved(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $task = Task::factory()->create();
        $id = $task->id;
        $task->forceDelete();

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'task',
            'server_id' => $id,
            'action' => 'task.complete',
            'payload' => ['completed' => true],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'RECORD_DELETED');
    }

    /**
     * A notification is addressed by `client_id` BECAUSE its primary key is
     * already a server-minted UUID - there is no separate client_id column
     * and none is needed (protocol §6.1/D10).
     */
    public function test_a_notification_action_is_addressed_by_its_uuid_client_id(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        $uuid = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $uuid,
            'type' => 'App\\Notifications\\Dummy',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => '{}',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'sync_version' => 0,
        ]);

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'notification',
            'client_id' => $uuid,
            'action' => 'notification.read',
        ])])->assertJsonPath('results.0.status', 'applied');

        $this->assertNotNull(DB::table('notifications')->where('id', $uuid)->value('read_at'));
    }

    /**
     * The horizontal boundary (Faz 13, Model C) must not have a sync-shaped
     * hole: a rep without `deals.assign` cannot create a deal in somebody
     * else's name, exactly as on the web (ForcesRecordOwnerOnCreate).
     */
    public function test_owner_forgery_is_silently_pinned_to_the_caller(): void
    {
        [$user, $token] = $this->deviceUser('Satış Temsilcisi');
        $victim = User::factory()->create();

        $response = $this->push($token, [$this->mutation([
            'op' => 'create',
            'entity' => 'deal',
            'client_id' => (string) Str::uuid(),
            'payload' => ['title' => 'Not yours', 'owner_id' => $victim->id],
        ])]);

        $response->assertJsonPath('results.0.status', 'applied');

        $this->assertSame(
            (int) $user->id,
            (int) Deal::find($response->json('results.0.server_id'))->owner_id,
            'Sahip sahteciliği masaüstü yolundan geçiyor — yatay sınır delinmiş.'
        );
    }

    public function test_a_role_without_create_permission_is_refused(): void
    {
        [, $token] = $this->deviceUser('İzleyici');

        $this->push($token, [$this->mutation([
            'op' => 'create',
            'entity' => 'deal',
            'client_id' => (string) Str::uuid(),
            'payload' => ['title' => 'Nope'],
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'FORBIDDEN');
    }

    public function test_a_batch_over_the_limit_is_refused_as_a_whole(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $mutations = [];

        for ($i = 0; $i <= SyncPushService::MAX_BATCH; $i++) {
            $mutations[] = $this->mutation([
                'seq' => $i,
                'op' => 'create',
                'entity' => 'company',
                'client_id' => (string) Str::uuid(),
                'payload' => ['name' => 'Bulk '.$i],
            ]);
        }

        $this->push($token, $mutations)
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'PUSH_BATCH_TOO_LARGE');

        $this->assertDatabaseCount('companies', 0);
    }

    /**
     * Protocol §7.3/3 — `notification.read_all` is the one user-scoped action,
     * and every row it touches must end up with its OWN version (§2.5/K-C).
     */
    public function test_notification_read_all_is_user_scoped_and_versions_every_row(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        for ($i = 0; $i < 5; $i++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\Dummy',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => '{}',
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'sync_version' => 0,
            ]);
        }

        $response = $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'notification',
            'action' => 'notification.read_all',
            'scope' => 'user',
        ])]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.affected', 5);

        $versions = DB::table('notifications')->pluck('sync_version')->all();

        $this->assertNotContains(0, $versions);
        $this->assertCount(5, array_unique($versions), 'read_all satır başına TEKİL versiyon üretmeli (§2.5/K-C).');
    }

    public function test_read_all_without_the_user_scope_is_refused(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $this->push($token, [$this->mutation([
            'op' => 'action',
            'entity' => 'notification',
            'action' => 'notification.read_all',
        ])])->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'INVALID_MUTATION');
    }

    /**
     * SYNCDESKTOP §4.4: every applied mutation stamps its origin and batch on
     * the audit trail, so the Logs page can show fifty replayed offline edits
     * as one desktop batch instead of fifty manual ones.
     */
    public function test_applied_mutations_stamp_the_channel_and_batch_id_on_the_audit_trail(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $batchId = (string) Str::uuid();

        $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => $batchId,
            'mutations' => [$this->mutation([
                'op' => 'create',
                'entity' => 'company',
                'client_id' => (string) Str::uuid(),
                'payload' => ['name' => 'Audited Ltd'],
            ])],
        ])->assertJsonPath('results.0.status', 'applied');

        $properties = DB::table(config('activitylog.table_name', 'activity_log'))
            ->where('subject_type', Company::class)
            ->orderByDesc('id')
            ->value('properties');

        $decoded = json_decode((string) $properties, true);

        $this->assertSame('desktop', $decoded['channel'] ?? null);
        $this->assertSame($batchId, $decoded['batch_id'] ?? null);
    }

    /**
     * DESKTOP-ARCHITECTURE.md EK 3, "DOĞRULANMASI GEREKEN VARSAYIM": the desktop
     * client's outbox carries `tag_ids` (the REST field `StoreCompanyRequest`/
     * `UpdateCompanyRequest` validates) AND `tags` (the mirror key the SAME
     * row's payload carries on the way DOWN, protocol §1.4/§1.5's embed). The
     * claim under test is that a FormRequest with no `tags` rule simply drops
     * the unvalidated key rather than 422-ing the whole mutation - Laravel's
     * default `Validator` behaviour, but never previously exercised here.
     *
     * If this goes RED, the assumption is false and every offline company
     * create/update with a tag on it would 422 in production.
     */
    public function test_create_tolerates_the_tags_mirror_key_alongside_tag_ids(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $tag = Tag::factory()->create();

        $response = $this->push($token, [$this->mutation([
            'op' => 'create',
            'entity' => 'company',
            'client_id' => (string) Str::uuid(),
            'payload' => [
                'name' => 'Tagged Ltd',
                // The REST field the FormRequest validates:
                'tag_ids' => [$tag->id],
                // The mirror column echoed back on pull (protocol §1.4) - NOT a
                // rule in StoreCompanyRequest, sent anyway because the client's
                // outbox does not strip it.
                'tags' => [$tag->id],
            ],
        ])]);

        $response->assertOk()->assertJsonPath('results.0.status', 'applied');

        $company = Company::find($response->json('results.0.server_id'));

        $this->assertSame(
            [$tag->id],
            $company->tags()->pluck('tags.id')->all(),
            'tag_ids bağlanmadı — tags anahtarı fazladan gönderilince push kırıldı.'
        );
    }

    public function test_update_tolerates_the_tags_mirror_key_alongside_tag_ids(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create();
        $tag = Tag::factory()->create();

        $response = $this->push($token, [$this->mutation([
            'op' => 'update',
            'entity' => 'company',
            'server_id' => $company->id,
            'base_sync_version' => $company->sync_version,
            // The client's outbox treats `tag_ids` and its mirror `tags` as one
            // logical change, so both travel in `changed_fields` too.
            'changed_fields' => ['tag_ids', 'tags'],
            'payload' => [
                'tag_ids' => [$tag->id],
                'tags' => [$tag->id],
            ],
        ])]);

        $response->assertOk()->assertJsonPath('results.0.status', 'applied');

        $this->assertSame(
            [$tag->id],
            $company->fresh()->tags()->pluck('tags.id')->all(),
            'tag_ids bağlanmadı — tags anahtarı fazladan gönderilince push kırıldı.'
        );
    }
}
