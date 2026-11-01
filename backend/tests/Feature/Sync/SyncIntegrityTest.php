<?php

namespace Tests\Feature\Sync;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PriceList;
use App\Models\Quote;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\ContactRepository;
use App\Repositories\DealRepository;
use App\Repositories\LeadRepository;
use App\Services\Activities\ActivityService;
use App\Services\Companies\CompanyService;
use App\Services\Contacts\ContactService;
use App\Services\Deals\DealMoveService;
use App\Services\Deals\DealService;
use App\Services\Leads\LeadConversionService;
use App\Services\Leads\LeadService;
use App\Services\Products\PriceListService;
use App\Services\Quotes\QuoteService;
use App\Services\Quotes\QuoteStatusMachine;
use App\Services\Sync\SyncPullService;
use App\Services\Tasks\TaskService;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketStatusMachine;
use App\Sync\SyncCounter;
use App\Sync\SyncVersionBumper;
use App\Sync\WriteTrail;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The two invariants a mirror depends on (protocol §4.2, §5):
 *
 *   - a stale-base edit to a field somebody else changed is a conflict,
 *     whatever the client's clock says;
 *   - `next_cursor` never passes something the client has not received.
 */
class SyncIntegrityTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    private function push(string $token, array $mutation): TestResponse
    {
        return $this->withToken($token)->postJson('/api/sync/push', [
            'batch_id' => (string) Str::uuid(),
            'mutations' => [array_merge(
                ['seq' => 1, 'idempotency_key' => (string) Str::uuid(), 'op' => 'update'],
                $mutation,
            )],
        ]);
    }

    /** What `now_iso()` puts on the wire. */
    private function clientNow(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Write as `$user`, through the service, so the audit trail is the real one. */
    private function editAs($user, Company $company, array $data): void
    {
        $this->actingAs($user);
        app(CompanyService::class)->update($company->fresh(), $data);
        $this->app['auth']->forgetGuards();
    }

    /**
     * Follow `next_cursor` exactly as the client does.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: int} rows, tombstones, rounds
     */
    private function drain(string $token, string $table, int $limit, ?int $window = null, array $otherCursors = []): array
    {
        $rows = [];
        $deletions = [];
        $cursor = 0;

        for ($round = 1; $round <= 50; $round++) {
            $body = ['cursors' => array_merge($otherCursors, [$table => $cursor]), 'limit' => $limit];

            if ($window !== null) {
                $body['window_days'] = $window;
            }

            $page = $this->withToken($token)->postJson('/api/sync/pull', $body)->json('tables.'.$table);

            $rows = array_merge($rows, $page['rows']);
            $deletions = array_merge($deletions, $page['deletions']);
            $cursor = (int) $page['next_cursor'];

            if (! $page['has_more']) {
                return [$rows, $deletions, $round];
            }
        }

        $this->fail('The pull never finished.');
    }

    // ---------------------------------------------------- conflict detection

    public function test_a_stale_edit_made_after_the_other_write_is_still_a_conflict(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create(['amount' => 1000, 'owner_id' => $bob->id]);
        $base = (int) $deal->sync_version;

        $this->travel(1)->hours();
        $this->actingAs($bob);
        $deal->fresh()->update(['amount' => 2000]);
        $this->app['auth']->forgetGuards();

        // Alice has not seen it, and edits offline an hour AFTER Bob saved.
        $this->travel(1)->hours();
        $response = $this->push($alice, [
            'entity' => 'deal', 'server_id' => $deal->id, 'base_sync_version' => $base,
            'occurred_at' => $this->clientNow(),
            'changed_fields' => ['amount'], 'payload' => ['amount' => 1500],
        ]);

        $response->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.conflicting_fields', ['amount']);
        $this->assertEquals(2000, $deal->fresh()->amount, 'Bob\'s amount was silently overwritten.');
    }

    public function test_the_client_clock_cannot_turn_a_conflict_into_an_apply(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        foreach (['2099-01-01T00:00:00.000Z', '2001-01-01T00:00:00.000Z', null] as $claimed) {
            $company = Company::factory()->create(['notes' => 'BASE']);
            $base = (int) $company->sync_version;

            $this->travel(1)->minutes();
            $this->editAs($bob, $company, ['notes' => 'BOB']);

            $this->travel(1)->minutes();
            $this->push($alice, [
                'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
                'occurred_at' => $claimed,
                'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
            ])->assertJsonPath('results.0.status', 'conflict');

            $this->assertSame('BOB', $company->fresh()->notes);
        }
    }

    public function test_the_client_clock_cannot_turn_a_disjoint_edit_into_a_conflict(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['name' => 'Base', 'city' => 'Ankara']);
        $base = (int) $company->sync_version;

        $this->travel(1)->minutes();
        $this->editAs($bob, $company, ['city' => 'İzmir']);

        // A clock years behind used to sweep the record's own `created` row in.
        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'occurred_at' => '2001-01-01T00:00:00.000Z',
            'changed_fields' => ['name'], 'payload' => ['name' => 'Offline name'],
        ])->assertJsonPath('results.0.status', 'applied');

        $this->assertSame('Offline name', $company->fresh()->name);
        $this->assertSame('İzmir', $company->fresh()->city);
    }

    public function test_a_write_the_client_already_saw_is_not_a_conflict(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE', 'city' => 'Ankara']);

        $this->travel(1)->minutes();
        $this->editAs($bob, $company, ['notes' => 'BOB']);
        $base = (int) $company->fresh()->sync_version;

        $this->travel(1)->minutes();
        $this->editAs($bob, $company, ['city' => 'İzmir']);

        // Base already includes Bob's `notes`; only `city` moved since.
        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'occurred_at' => '2001-01-01T00:00:00.000Z',
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'applied');

        $this->assertSame('ALICE', $company->fresh()->notes);
    }

    public function test_a_current_base_applies_without_looking_at_the_trail(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);
        $this->editAs($bob, $company, ['notes' => 'BOB']);

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id,
            'base_sync_version' => (int) $company->fresh()->sync_version,
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'applied');
    }

    public function test_an_audit_row_without_a_recorded_version_counts_against_the_client(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);
        $base = (int) $company->sync_version;
        $this->editAs($bob, $company, ['notes' => 'BOB']);

        // What a row older than the column, on a subject the backfill could
        // not reach, looks like.
        DB::table('activity_log')->where('subject_id', $company->id)->update(['subject_sync_version' => null]);

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'conflict');
    }

    /**
     * A bulk statement moves the value and the version but writes no audit
     * row, so the trail cannot name the field. The version did move, though.
     */
    public function test_a_field_changed_by_a_bulk_statement_is_still_a_conflict(): void
    {
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create();
        $primary = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);
        $other = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => false]);
        $base = (int) $primary->sync_version;

        app(ContactRepository::class)->clearOtherPrimaryContacts($company->id, $other->id);
        $this->assertFalse((bool) $primary->fresh()->is_primary);

        $this->push($alice, [
            'entity' => 'contact', 'server_id' => $primary->id, 'base_sync_version' => $base,
            'occurred_at' => $this->clientNow(),
            'changed_fields' => ['is_primary'], 'payload' => ['is_primary' => true],
        ])->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.conflicting_fields', ['is_primary']);
    }

    public function test_a_field_the_audit_trail_never_records_is_still_a_conflict(): void
    {
        [, $alice] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create();
        $base = (int) $deal->sync_version;

        app(DealRepository::class)->syncTags($deal, [Tag::factory()->create()->id]);

        $this->push($alice, [
            'entity' => 'deal', 'server_id' => $deal->id, 'base_sync_version' => $base,
            'occurred_at' => $this->clientNow(),
            'changed_fields' => ['tag_ids'], 'payload' => ['tag_ids' => []],
        ])->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.conflicting_fields', ['tag_ids']);
    }

    public function test_a_tag_change_does_not_conflict_with_an_edit_to_a_plain_field(): void
    {
        [, $alice] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create();
        $base = (int) $deal->sync_version;

        app(DealRepository::class)->syncTags($deal, [Tag::factory()->create()->id]);

        $this->push($alice, [
            'entity' => 'deal', 'server_id' => $deal->id, 'base_sync_version' => $base,
            'occurred_at' => $this->clientNow(),
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'Plain field'],
        ])->assertJsonPath('results.0.status', 'applied');
    }

    public function test_every_audit_row_records_the_version_its_write_drew(): void
    {
        $company = Company::factory()->create();
        $company->update(['city' => 'Trabzon']);

        $row = DB::table('activity_log')
            ->where('subject_id', $company->id)
            ->where('subject_type', $company->getMorphClass())
            ->orderByDesc('id')
            ->first();

        $this->assertSame((int) $company->fresh()->sync_version, (int) $row->subject_sync_version);
    }

    // ---------------------------------------- conflict window: old-base edits

    /**
     * The backfill ties every old audit row to the subject's current version,
     * the record's own `created` row included, and that row names every field.
     */
    public function test_a_backfilled_created_row_does_not_conflict_with_an_old_base(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['name' => 'Base', 'city' => 'Ankara']);
        $base = (int) $company->sync_version;
        $this->editAs($bob, $company, ['city' => 'İzmir']);

        DB::table('activity_log')->where('subject_id', $company->id)
            ->where('subject_type', $company->getMorphClass())
            ->update(['subject_sync_version' => (int) $company->fresh()->sync_version]);

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['name'], 'payload' => ['name' => 'Offline name'],
        ])->assertJsonPath('results.0.status', 'applied');

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['city'], 'payload' => ['city' => 'Offline city'],
        ])->assertJsonPath('results.0.status', 'conflict');
    }

    public function test_an_embedded_field_nobody_touched_does_not_conflict(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['city' => 'Ankara']);
        $base = (int) $company->sync_version;
        $tag = Tag::factory()->create();
        $this->editAs($bob, $company, ['city' => 'İzmir']);

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['tag_ids'], 'payload' => ['tag_ids' => [$tag->id]],
        ])->assertJsonPath('results.0.status', 'applied');

        $this->assertSame([$tag->id], $company->fresh()->tags->pluck('id')->all());
    }

    public function test_a_primary_flag_nobody_touched_does_not_conflict(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $contact = Contact::factory()->create(['is_primary' => false, 'notes' => 'BASE']);
        $base = (int) $contact->sync_version;

        $this->actingAs($bob);
        $contact->fresh()->update(['notes' => 'BOB']);
        $this->app['auth']->forgetGuards();

        $this->push($alice, [
            'entity' => 'contact', 'server_id' => $contact->id, 'base_sync_version' => $base,
            'changed_fields' => ['is_primary'], 'payload' => ['is_primary' => true],
        ])->assertJsonPath('results.0.status', 'applied');
    }

    // ------------------------------- conflict window: moves the trail can't name

    public function test_a_version_moved_by_a_raw_write_with_no_trail_fails_safe(): void
    {
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);
        $base = (int) $company->sync_version;

        DB::table('companies')->where('id', $company->id)
            ->update(['notes' => 'RAW', 'sync_version' => SyncCounter::next()]);

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.conflicting_fields', ['notes']);

        $this->assertSame('RAW', $company->fresh()->notes);
    }

    public function test_a_write_made_with_logging_switched_off_fails_safe(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);
        $base = (int) $company->sync_version;

        activity()->withoutLogs(fn () => $this->editAs($bob, $company, ['notes' => 'UNLOGGED']));

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'conflict');
    }

    public function test_a_pruned_trail_fails_safe(): void
    {
        [$bob] = $this->deviceUser('Admin');
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);
        $base = (int) $company->sync_version;
        $this->editAs($bob, $company, ['notes' => 'BOB']);

        DB::table('activity_log')->where('subject_id', $company->id)
            ->where('subject_type', $company->getMorphClass())->delete();

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'conflict');
    }

    /** The record vanished and came back under the client: that is worth a look. */
    public function test_a_restore_fails_safe(): void
    {
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);
        $base = (int) $company->sync_version;

        $company->delete();
        $company->restore();

        $this->push($alice, [
            'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
            'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
        ])->assertJsonPath('results.0.status', 'conflict');
    }

    /** A client that cannot state a base has no claim to anything being disjoint. */
    public function test_an_update_with_no_base_still_conflicts(): void
    {
        [, $alice] = $this->deviceUser('Admin');

        $company = Company::factory()->create(['notes' => 'BASE']);

        foreach ([null, 0] as $base) {
            $this->push($alice, [
                'entity' => 'company', 'server_id' => $company->id, 'base_sync_version' => $base,
                'changed_fields' => ['notes'], 'payload' => ['notes' => 'ALICE'],
            ])->assertJsonPath('results.0.status', 'conflict');
        }
    }

    // ---------------------------------------------- commits racing with a pull

    /**
     * Rows short-page at v10, a tag commits v11, another tag's tombstone
     * commits v12. Both queries are honest, and the second one's v12 still
     * carried the cursor over a v11 the first never saw.
     */
    public function test_a_commit_between_the_two_reads_cannot_carry_the_cursor_past_it(): void
    {
        [, $token] = $this->deviceUser('Admin');

        Tag::factory()->create();
        $doomed = Tag::factory()->create();
        $late = null;
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$late, &$injected, $doomed): void {
            if (! $injected && str_starts_with($query->sql, 'select * from `tags`')) {
                $injected = true;
                $late = Tag::factory()->create();
                $doomed->delete();
            }
        });

        $first = $this->withToken($token)->postJson('/api/sync/pull', ['cursors' => [], 'limit' => 500])
            ->json('tables.tags');

        $this->assertNotNull($late, 'The commit was never injected between the reads.');

        $second = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['tags' => (int) $first['next_cursor']], 'limit' => 500,
        ])->json('tables.tags');

        $this->assertContains(
            $late->id,
            array_merge(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')),
            'The cursor passed a row the client never received.'
        );
        $this->assertContains(
            (string) $doomed->id,
            array_merge(array_column($first['deletions'], 'row_key'), array_column($second['deletions'], 'row_key')),
        );
    }

    /**
     * The observer writes the version, then soft-deletes. Outside a transaction
     * a pull between the two takes the row as live at that version, and a
     * device at that cursor can never learn it was deleted.
     */
    public function test_a_web_company_delete_is_one_transaction(): void
    {
        $company = Company::factory()->create();
        $levels = [];

        Company::deleting(function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        $outer = DB::transactionLevel();
        app(CompanyService::class)->delete($company->fresh());

        $this->assertCount(1, $levels);
        $this->assertGreaterThan($outer, $levels[0]);
        $this->assertSoftDeleted($company);
    }

    /** @return array<int, int> ids of `$table` rows `sync_untrailed_writes` still records */
    private function untrailedIds(string $table): array
    {
        return DB::table(WriteTrail::TABLE)->where('subject_table', $table)
            ->orderBy('subject_id')->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_physically_deleted_row_takes_its_untrailed_record_with_it(): void
    {
        [$gone, $soft, $kept] = Company::factory()->count(3)->create()->all();

        foreach ([$gone, $soft, $kept] as $company) {
            SyncVersionBumper::bump($company);
        }

        $gone->forceDelete();
        $soft->delete();

        $this->assertSame([$soft->id, $kept->id], $this->untrailedIds('companies'));
    }

    public function test_a_prune_sweeps_records_of_rows_deleted_where_no_observer_saw_it(): void
    {
        [$gone, $kept] = Company::factory()->count(2)->create()->all();
        SyncVersionBumper::bump($gone);
        SyncVersionBumper::bump($kept);
        DB::table('companies')->where('id', $gone->id)->delete();
        DB::table(WriteTrail::TABLE)->insert(['subject_table' => 'dropped_table', 'subject_id' => 1, 'sync_version' => 1]);

        $this->assertSame(2, WriteTrail::prune());
        $this->assertSame([$kept->id], $this->untrailedIds('companies'));
        $this->assertSame([], $this->untrailedIds('dropped_table'));
        $this->assertSame(1, DB::table(WriteTrail::TABLE)->where('subject_table', WriteTrail::BASELINE)->count());
    }

    /**
     * The transaction level at every version draw `$write` makes. A draw at the
     * caller's own level committed on its own, ahead of the row it was for.
     *
     * @return array<int, int>
     */
    private function drawLevels(callable $write): array
    {
        $levels = [];
        $recording = true;

        DB::listen(function (QueryExecuted $query) use (&$levels, &$recording): void {
            if ($recording && str_starts_with($query->sql, 'UPDATE sync_counter')) {
                $levels[] = DB::transactionLevel();
            }
        });

        try {
            $write();
        } finally {
            $recording = false;
        }

        return $levels;
    }

    /** The web paths that drew a version outside any transaction. */
    public function test_every_web_write_draws_its_version_inside_the_transaction_that_writes_the_row(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create();
        $activity = Activity::factory()->create();
        $lead = Lead::factory()->create();
        $contact = Contact::factory()->create();
        $deal = Deal::factory()->create();
        $doomedLead = Lead::factory()->create();
        $ticket = Ticket::factory()->create();
        $quote = Quote::factory()->create();
        $priceList = PriceList::factory()->create(['is_default' => false]);
        $imported = Lead::factory()->raw();

        $writes = [
            'task create' => fn () => app(TaskService::class)->create(['title' => 'Call back'], $user->id),
            'task update' => fn () => app(TaskService::class)->update($task->fresh(), ['title' => 'Renamed']),
            'task delete' => fn () => app(TaskService::class)->delete($task->fresh()),
            'activity update' => fn () => app(ActivityService::class)->update($activity->fresh(), ['subject' => 'Renamed']),
            'activity delete' => fn () => app(ActivityService::class)->delete($activity->fresh()),
            'contact delete' => fn () => app(ContactService::class)->delete($contact->fresh()),
            'deal delete' => fn () => app(DealService::class)->delete($deal->fresh()),
            'lead delete' => fn () => app(LeadService::class)->delete($doomedLead->fresh()),
            'ticket delete' => fn () => app(TicketService::class)->delete($ticket->fresh()),
            'quote delete' => fn () => app(QuoteService::class)->delete($quote->fresh()),
            'price list delete' => fn () => app(PriceListService::class)->delete($priceList->fresh()),
            // LeadImportService writes through the repository directly.
            'lead import create' => fn () => app(LeadRepository::class)->create($imported),
            'lead import update' => fn () => app(LeadRepository::class)->update($lead->fresh(), ['first_name' => 'Imported']),
        ];

        $outer = DB::transactionLevel();
        $outside = [];

        foreach ($writes as $name => $write) {
            $levels = $this->drawLevels($write);

            if ($levels === [] || min($levels) <= $outer) {
                $outside[$name] = $levels;
            }
        }

        $this->assertSame([], $outside, 'Drawn outside a transaction (levels at each draw; the caller is at '.$outer.').');
    }

    /**
     * Every writer takes the counter before the entity row (MutationApplier
     * does). One that locks the row first deadlocks against a sync update.
     */
    public function test_a_service_that_locks_its_row_takes_the_counter_first(): void
    {
        $actor = User::factory()->create();
        $deal = Deal::factory()->create();
        $stage = $deal->pipeline_stage_id;

        $services = [
            'deals' => fn () => app(DealMoveService::class)->move($deal->fresh(), ['to_stage_id' => $stage, 'version' => (int) $deal->fresh()->version], $actor),
            'tickets' => fn () => app(TicketStatusMachine::class)->transition(Ticket::factory()->create(['status' => 'open']), 'in_progress'),
            'quotes' => fn () => app(QuoteStatusMachine::class)->transition(Quote::factory()->create(['status' => 'sent']), 'rejected'),
            'leads' => fn () => app(LeadConversionService::class)->convert(Lead::factory()->create(), ['create_deal' => false], $actor),
        ];

        $order = [];
        $recording = null;

        DB::listen(function (QueryExecuted $query) use (&$order, &$recording): void {
            if ($recording === null || ! str_ends_with($query->sql, 'for update')) {
                return;
            }

            if (str_contains($query->sql, '`sync_counter`')) {
                $order[$recording][] = 'counter';
            } elseif (str_contains($query->sql, 'from `'.$recording.'`')) {
                $order[$recording][] = 'row';
            }
        });

        foreach ($services as $table => $write) {
            $recording = $table;

            try {
                $write();
            } catch (\Throwable) {
                // Only the order of the first two locks is under test.
            }

            $recording = null;
        }

        $this->assertSame(
            array_fill_keys(array_keys($services), 'counter'),
            array_map(fn (array $locks) => $locks[0], $order + array_fill_keys(array_keys($services), ['none'])),
        );
    }

    // ------------------------------------------- cursor: rows and tombstones

    public function test_a_tombstone_above_the_page_does_not_jump_the_cursor(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $live = Tag::factory()->count(2)->create()->pluck('id')->all();
        $gone = Tag::factory()->create();
        $goneId = $gone->id;
        $gone->delete();

        [$rows, $deletions] = $this->drain($token, 'tags', 1);

        $this->assertEqualsCanonicalizing($live, array_column($rows, 'id'));
        $this->assertSame([(string) $goneId], array_column($deletions, 'row_key'));
    }

    public function test_no_row_is_lost_at_the_real_page_size(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $live = Tag::factory()->count(530)->create()->pluck('id')->all();
        $gone = Tag::factory()->create();
        $gone->delete();

        [$rows, $deletions] = $this->drain($token, 'tags', 500);

        $this->assertEqualsCanonicalizing($live, array_column($rows, 'id'));
        $this->assertCount(1, $deletions);
    }

    public function test_a_full_tombstone_page_does_not_lose_a_later_row(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $gone = Tag::factory()->count(3)->create();
        $goneIds = $gone->pluck('id')->map(fn ($id) => (string) $id)->all();
        $gone->each->delete();
        $live = Tag::factory()->count(2)->create()->pluck('id')->all();

        // Tombstones sit BELOW the rows here: the opposite order to the above.
        [$rows, $deletions] = $this->drain($token, 'tags', 2);

        $this->assertEqualsCanonicalizing($live, array_column($rows, 'id'));
        $this->assertEqualsCanonicalizing($goneIds, array_column($deletions, 'row_key'));
    }

    public function test_interleaved_rows_and_tombstones_arrive_exactly_once(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $live = [];
        $goneIds = [];

        for ($i = 0; $i < 9; $i++) {
            $tag = Tag::factory()->create();

            if ($i % 3 === 2) {
                $goneIds[] = (string) $tag->id;
                $tag->delete();
            } else {
                $live[] = $tag->id;
            }
        }

        foreach ([1, 2, 3, 4] as $limit) {
            [$rows, $deletions] = $this->drain($token, 'tags', $limit);

            $this->assertSame($live, array_column($rows, 'id'), "limit {$limit}");
            $this->assertSame($goneIds, array_column($deletions, 'row_key'), "limit {$limit}");
        }
    }

    /**
     * A page the byte budget cuts used to drop every tombstone, including the
     * ones BELOW the last row it sent, and the cursor then moved past them.
     */
    public function test_a_page_cut_by_the_byte_budget_keeps_the_tombstones_below_the_cut(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        $note = fn (string $body) => DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'fixture',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => ['title' => 'x', 'body' => $body],
        ]);

        $first = $note('short');
        $gone = $note('short');
        $goneId = (string) $gone->id;
        $gone->delete();

        $filler = str_repeat('x', 60 * 1024);

        for ($i = 0; $i < 100; $i++) {
            $note($filler);
        }

        [$rows, $deletions, $rounds] = $this->drain($token, 'notifications', 500);

        $this->assertGreaterThan(1, $rounds, 'The budget never cut the page.');
        $this->assertCount(101, $rows);
        $this->assertContains($first->id, array_column($rows, 'id'));
        $this->assertSame([$goneId], array_column($deletions, 'row_key'));
    }

    /**
     * The byte budget can run out exactly between two tables, with nothing cut
     * and every `has_more` false, so the tables it never reached are absent
     * and nothing else in the body says they are still owed.
     */
    public function test_a_table_the_budget_never_reached_keeps_the_client_pulling(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $size = fn (): int => array_sum(array_map(
            fn (array $row): int => strlen((string) json_encode($row)),
            $this->withToken($token)->postJson('/api/sync/pull', ['cursors' => [], 'limit' => 500])
                ->json('tables.companies.rows'),
        ));

        for ($i = 0; $i < 84; $i++) {
            Company::factory()->create(['notes' => str_repeat('x', 60 * 1024)]);
        }

        // Fill `companies` to 500 bytes under the ceiling...
        $last = Company::factory()->create(['notes' => '']);
        $room = SyncPullService::MAX_BYTES - $size() - 500;
        $this->assertGreaterThan(0, $room);
        $this->assertLessThan(65000, $room);
        $last->update(['notes' => str_repeat('y', $room)]);

        // ...so the single `contacts` row overflows it through the one-row
        // floor, uncut, and `leads` is never visited.
        Contact::factory()->create(['company_id' => null, 'notes' => str_repeat('z', 2000)]);
        $lead = Lead::factory()->create();

        $tables = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => [], 'limit' => 500,
        ])->json('tables');

        $this->assertArrayHasKey('contacts', $tables);
        $this->assertArrayNotHasKey('leads', $tables);
        $this->assertTrue(
            collect($tables)->contains(fn (array $table): bool => $table['has_more']),
            'Nothing tells the client that `leads` is still owed.'
        );

        $next = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => array_map(fn (array $table): int => (int) $table['next_cursor'], $tables),
            'limit' => 500,
        ])->json('tables');

        $this->assertContains($lead->id, array_column($next['leads']['rows'], 'id'));
    }

    // -------------------------------------------------- cursor: company path

    public function test_a_closure_company_does_not_jump_the_cursor(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $companies = Company::factory()->count(3)->create();
        Contact::factory()->create(['company_id' => $companies[2]->id]);

        [$rows] = $this->drain($token, 'companies', 1, 30, ['contacts' => 0]);

        $this->assertEqualsCanonicalizing(
            $companies->pluck('id')->all(),
            array_values(array_unique(array_column($rows, 'id')))
        );
    }

    public function test_no_company_is_lost_at_the_real_page_size(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $companies = Company::factory()->count(620)->create();
        Contact::factory()->count(40)
            ->sequence(fn ($s) => ['company_id' => $companies[580 + $s->index]->id])
            ->create();

        [$rows] = $this->drain($token, 'companies', 500, 30, ['contacts' => 0]);

        $this->assertEqualsCanonicalizing(
            $companies->pluck('id')->all(),
            array_values(array_unique(array_column($rows, 'id')))
        );
    }

    /**
     * Once the client sends every cursor, `companies` is non-zero from page 2
     * on, and a contact arriving there can still point at a company the window
     * left out on page 1.
     */
    public function test_a_later_page_still_ships_the_company_it_references(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $ancient = Company::factory()->create();
        DB::table('companies')->where('id', $ancient->id)->update(['updated_at' => now()->subDays(90)]);

        Company::factory()->create();
        Contact::factory()->count(2)->create(['company_id' => null]);
        $late = Contact::factory()->create(['company_id' => $ancient->id]);

        $cursors = ['companies' => 0, 'contacts' => 0];
        $companies = [];
        $contacts = [];

        for ($round = 0; $round < 10; $round++) {
            $tables = $this->withToken($token)->postJson('/api/sync/pull', [
                'cursors' => $cursors, 'limit' => 2, 'window_days' => 30,
            ])->json('tables');

            $companies = array_merge($companies, array_column($tables['companies']['rows'], 'id'));
            $contacts = array_merge($contacts, array_column($tables['contacts']['rows'], 'id'));
            $cursors = [
                'companies' => (int) $tables['companies']['next_cursor'],
                'contacts' => (int) $tables['contacts']['next_cursor'],
            ];

            if (! $tables['companies']['has_more'] && ! $tables['contacts']['has_more']) {
                break;
            }
        }

        $this->assertContains($late->id, $contacts);
        $this->assertContains($ancient->id, $companies, 'The relation renders empty on the client.');
    }

    public function test_later_pages_do_not_resend_companies_the_client_already_has(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create();
        Contact::factory()->count(3)->create(['company_id' => $company->id]);

        $first = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0, 'contacts' => 0], 'limit' => 2, 'window_days' => 30,
        ])->json('tables');

        $this->assertCount(1, $first['companies']['rows']);

        $second = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => [
                'companies' => $first['companies']['next_cursor'],
                'contacts' => $first['contacts']['next_cursor'],
            ],
            'limit' => 2, 'window_days' => 30,
        ])->json('tables');

        $this->assertNotEmpty($second['contacts']['rows']);
        $this->assertSame([], $second['companies']['rows']);
    }
}
