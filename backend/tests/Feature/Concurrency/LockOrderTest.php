<?php

namespace Tests\Feature\Concurrency;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Companies\CompanyService;
use App\Services\Contacts\ContactService;
use App\Services\Products\ProductService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lock order against the sync counter, raced for real: two processes, two
 * connections, statements lined up by LockRace so that the old order closes a
 * cycle. The rule under test (SyncCounter): a writer that will draw a version
 * takes the counter before any row or child-row lock, because an Eloquent save
 * and a desktop update (MutationApplier) both take the counter first.
 *
 * The workers need committed rows, so nothing here runs in a test transaction.
 * The schema is rebuilt before and after every test.
 */
class LockOrderTest extends TestCase
{
    private const COUNTER_DRAW = '/^update\s+`?sync_counter`?/i';

    private const COUNTER_LOCK = '/from `sync_counter`.*for update$/is';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    /**
     * @param  array{a: array<string, mixed>, b: array<string, mixed>}  $results
     */
    private function assertBothCommitted(array $results): void
    {
        $this->assertSame(['a' => null, 'b' => null], LockRace::lockErrors($results), json_encode($results));
        $this->assertTrue($results['a']['ok'] && $results['b']['ok'], json_encode($results));
    }

    // ------------------------------------------------------------- users

    public function test_a_password_reset_and_a_deactivation_of_one_user_do_not_deadlock(): void
    {
        $user = User::factory()->create();
        $user->createToken('desktop-1', ['desktop']);
        $user->createToken('desktop-2', ['desktop']);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::resetPassword',
                'args' => [$user->id],
                'after' => ['/^delete from `personal_access_tokens`/i' => 'a_tokens'],
                'before' => [self::COUNTER_DRAW => 'b_user'],
            ],
            [
                'call' => LockScenarios::class.'::deactivateUser',
                'args' => [$user->id],
                'after' => ['/^update `users`/i' => 'b_user'],
                'before' => ['/^delete from `personal_access_tokens`/i' => 'a_tokens'],
            ],
        );

        $this->assertBothCommitted($results);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_a_user_delete_and_a_deactivation_of_one_user_do_not_deadlock(): void
    {
        $user = User::factory()->create();
        $user->createToken('desktop-1', ['desktop']);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::deleteUser',
                'args' => [$user->id],
                'after' => ['/^delete from `personal_access_tokens`/i' => 'a_tokens'],
                'before' => [self::COUNTER_DRAW => 'b_user'],
            ],
            [
                'call' => LockScenarios::class.'::deactivateUser',
                'args' => [$user->id],
                'after' => ['/^update `users`/i' => 'b_user'],
                'before' => ['/^delete from `personal_access_tokens`/i' => 'a_tokens'],
            ],
        );

        $this->assertBothCommitted($results);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_a_role_change_and_the_deactivation_of_another_super_admin_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $target = $this->superAdmin();
        $other = $this->superAdmin();

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::renameAndChangeRole',
                'args' => [$target->id, 'Admin'],
                'actor' => $actor->id,
                'after' => ['/^select .*from `users`.*for update$/is' => 'a_admins'],
                'before' => [self::COUNTER_DRAW => 'b_counter'],
            ],
            [
                'call' => LockScenarios::class.'::deactivateUser',
                'args' => [$other->id],
                'after' => [self::COUNTER_DRAW => 'b_counter'],
                'before' => ['/^update `users`/i' => 'a_admins'],
            ],
        );

        $this->assertBothCommitted($results);
        $this->assertTrue($target->fresh()->hasRole('Admin'));
        $this->assertFalse($other->fresh()->is_active);
    }

    // ---------------------------------------------------- pipeline stages

    public function test_a_stage_reorder_and_a_stage_deactivation_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        PipelineStage::query()->delete();
        $stages = collect(range(1, 3))->map(fn (int $i) => PipelineStage::factory()->create([
            'position' => $i, 'is_won' => false, 'is_lost' => false, 'is_active' => true,
        ]));
        $target = $stages[1];
        $order = [$target->id, $stages[0]->id, $stages[2]->id];

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::reorderStages',
                'args' => [$order],
                'after' => ['/^update `pipeline_stages`/i' => 'a_stage'],
                'before' => [self::COUNTER_DRAW => 'b_counter'],
            ],
            [
                'call' => LockScenarios::class.'::deactivateStage',
                'args' => [$target->id],
                'actor' => $actor->id,
                'after' => [self::COUNTER_LOCK => 'b_counter'],
                'before' => ['/^update `pipeline_stages`/i' => 'a_stage'],
            ],
        );

        $this->assertBothCommitted($results);
        $this->assertFalse((bool) $target->fresh()->is_active);
        $this->assertSame(1, (int) $target->fresh()->position);
    }

    // ---------------------------------------------------- role matrix

    /**
     * Two administrators save the matrix of one role at once. Each audit row
     * must describe the change from the state the other one committed: replayed
     * in order from the starting set, the rows have to land on the final set.
     * The first writer reads the current permissions and is held there until the
     * second one is done.
     */
    public function test_concurrent_matrix_saves_of_one_role_leave_a_consistent_audit(): void
    {
        $actor = $this->superAdmin();
        $role = Role::create(['name' => 'Race Role', 'guard_name' => 'web']);
        $role->givePermissionTo(['leads.view', 'deals.view']);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::syncRolePermissions',
                'args' => [$role->id, ['leads.view', 'tickets.view']],
                'actor' => $actor->id,
                'after' => ['/inner join `role_has_permissions`/i' => 'a_read'],
                'before' => ['/^(delete from|insert into) `role_has_permissions`/i' => 'never'],
            ],
            [
                'call' => LockScenarios::class.'::syncRolePermissions',
                'args' => [$role->id, ['deals.view', 'companies.view']],
                'actor' => $actor->id,
                'before' => ['/from `permissions`/i' => 'a_read'],
            ],
        );

        $this->assertBothCommitted($results);

        $state = ['deals.view', 'leads.view'];
        $rows = Activity::query()->where('description', 'roles.permissions_changed')
            ->where('subject_id', $role->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            foreach ($row->properties['revoked'] as $name) {
                $this->assertContains($name, $state, 'revoked a permission the role did not have: '.json_encode($rows));
            }
            foreach ($row->properties['granted'] as $name) {
                $this->assertNotContains($name, $state, 'granted a permission the role already had: '.json_encode($rows));
            }
            $state = array_values(array_unique(array_merge(array_diff($state, $row->properties['revoked']), $row->properties['granted'])));
        }

        $final = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        sort($state);
        $this->assertSame($final, $state);
    }

    // ------------------------------------------------- embedded children

    public function test_a_tag_only_edit_and_a_desktop_tag_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        [$t1, $t2, $t3] = Tag::factory()->count(3)->create()->all();
        $company = Company::factory()->create();
        $company->tags()->attach($t1->id);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webCompanyTags',
                'args' => [$company->id, [$t2->id]],
                'actor' => $actor->id,
                'after' => ['/^delete from `taggables`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_row'],
            ],
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['company', 'companies', $company->id, ['tag_ids' => [$t3->id]]],
                'actor' => $actor->id,
                'after' => ['/from `companies`.*for update$/is' => 'b_row'],
                'before' => ['/^delete from `taggables`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    public function test_a_lead_tag_only_edit_and_a_desktop_tag_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        [$t1, $t2, $t3] = Tag::factory()->count(3)->create()->all();
        $lead = Lead::factory()->create();
        $lead->tags()->attach($t1->id);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webLeadTags',
                'args' => [$lead->id, [$t2->id]],
                'actor' => $actor->id,
                'after' => ['/^delete from `taggables`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_row'],
            ],
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['lead', 'leads', $lead->id, ['tag_ids' => [$t3->id]]],
                'actor' => $actor->id,
                'after' => ['/from `leads`.*for update$/is' => 'b_row'],
                'before' => ['/^delete from `taggables`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    /**
     * Demoting the other primary contacts is a bulk statement that locks their
     * rows and then draws one version per row. A clean owner save takes no
     * counter before it.
     */
    public function test_demoting_other_primary_contacts_and_a_desktop_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $company = Company::factory()->create();
        $kept = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);
        $demoted = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webMakePrimaryContact',
                'args' => [$kept->id],
                'actor' => $actor->id,
                'after' => ['/^update `contacts`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_counter'],
            ],
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['contact', 'contacts', $demoted->id, ['position' => 'Desktop']],
                'actor' => $actor->id,
                'after' => [self::COUNTER_LOCK => 'b_counter'],
                'before' => ['/from `contacts`.*for update$/is' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
        $this->assertFalse((bool) $demoted->fresh()->is_primary);
    }

    public function test_a_custom_field_only_deal_edit_and_a_desktop_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $deal = Deal::factory()->create();
        $field = CustomField::factory()->create(['entity_type' => 'deals', 'type' => 'text', 'key' => 'segment']);
        CustomFieldValue::query()->create([
            'custom_field_id' => $field->id,
            'customizable_type' => Deal::class,
            'customizable_id' => $deal->id,
            'value' => 'v1',
        ]);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webDealCustomFields',
                'args' => [$deal->id, ['segment' => 'v2']],
                'actor' => $actor->id,
                'after' => ['/^update `custom_field_values`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_row'],
            ],
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['deal', 'deals', $deal->id, ['custom_fields' => ['segment' => 'v3']]],
                'actor' => $actor->id,
                'after' => ['/from `deals`.*for update$/is' => 'b_row'],
                'before' => ['/^update `custom_field_values`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    public function test_a_custom_field_only_ticket_edit_and_a_desktop_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $ticket = Ticket::factory()->create(['status' => 'open']);
        $field = CustomField::factory()->create(['entity_type' => 'tickets', 'type' => 'text', 'key' => 'channel']);
        CustomFieldValue::query()->create([
            'custom_field_id' => $field->id,
            'customizable_type' => Ticket::class,
            'customizable_id' => $ticket->id,
            'value' => 'v1',
        ]);

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webTicketCustomFields',
                'args' => [$ticket->id, ['channel' => 'v2']],
                'actor' => $actor->id,
                'after' => ['/^update `custom_field_values`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_row'],
            ],
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['ticket', 'tickets', $ticket->id, ['custom_fields' => ['channel' => 'v3']]],
                'actor' => $actor->id,
                'after' => ['/from `tickets`.*for update$/is' => 'b_row'],
                'before' => ['/^update `custom_field_values`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function seedCustomField(string $model, int $id, string $entityType, string $key): void
    {
        $field = CustomField::factory()->create(['entity_type' => $entityType, 'type' => 'text', 'key' => $key]);
        CustomFieldValue::query()->create([
            'custom_field_id' => $field->id,
            'customizable_type' => $model,
            'customizable_id' => $id,
            'value' => 'v1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function webCustomFieldSide(string $service, string $model, int $id, array $customFields, int $actorId): array
    {
        return [
            'call' => LockScenarios::class.'::webCustomFields',
            'args' => [$service, $model, $id, $customFields],
            'actor' => $actorId,
            'after' => ['/^update `custom_field_values`/i' => 'a_child'],
            'before' => [self::COUNTER_DRAW => 'b_row'],
        ];
    }

    public function test_a_custom_field_only_contact_edit_and_a_desktop_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $contact = Contact::factory()->create();
        $this->seedCustomField(Contact::class, $contact->id, 'contacts', 'segment');

        $results = LockRace::run(
            $this->webCustomFieldSide(ContactService::class, Contact::class, $contact->id, ['segment' => 'v2'], $actor->id),
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['contact', 'contacts', $contact->id, ['custom_fields' => ['segment' => 'v3']]],
                'actor' => $actor->id,
                'after' => ['/from `contacts`.*for update$/is' => 'b_row'],
                'before' => ['/^update `custom_field_values`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    public function test_a_custom_field_only_company_edit_and_a_desktop_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $company = Company::factory()->create();
        $this->seedCustomField(Company::class, $company->id, 'companies', 'segment');

        $results = LockRace::run(
            $this->webCustomFieldSide(CompanyService::class, Company::class, $company->id, ['segment' => 'v2'], $actor->id),
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['company', 'companies', $company->id, ['custom_fields' => ['segment' => 'v3']]],
                'actor' => $actor->id,
                'after' => ['/from `companies`.*for update$/is' => 'b_row'],
                'before' => ['/^update `custom_field_values`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    /**
     * Products have no desktop update; the other writer is a web edit that
     * changes a column (its save draws a version, holding the counter) and then
     * the same custom field.
     */
    public function test_a_custom_field_only_product_edit_and_a_full_product_edit_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $product = Product::factory()->create();
        $this->seedCustomField(Product::class, $product->id, 'products', 'segment');

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webCustomFields',
                'args' => [ProductService::class, Product::class, $product->id, ['segment' => 'v2']],
                'actor' => $actor->id,
                'after' => ['/^update `custom_field_values`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_counter'],
            ],
            [
                'call' => LockScenarios::class.'::webProductEdit',
                'args' => [$product->id, ['name' => 'Renamed', 'custom_fields' => ['segment' => 'v3']]],
                'actor' => $actor->id,
                'after' => [self::COUNTER_DRAW => 'b_counter'],
                'before' => ['/^update `custom_field_values`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }

    public function test_an_items_only_quote_edit_and_a_desktop_items_update_do_not_deadlock(): void
    {
        $actor = $this->superAdmin();
        $quote = Quote::factory()->create(['status' => 'draft', 'created_by' => $actor->id]);
        QuoteItem::factory()->create(['quote_id' => $quote->id, 'position' => 1]);
        $item = fn (string $name, int $price) => [
            'name' => $name, 'quantity' => 1, 'unit_price' => $price, 'discount_percent' => 0, 'tax_rate' => 20,
        ];

        $results = LockRace::run(
            [
                'call' => LockScenarios::class.'::webQuoteItems',
                'args' => [$quote->id, [$item('web', 100)]],
                'actor' => $actor->id,
                'after' => ['/^delete from `quote_items`/i' => 'a_child'],
                'before' => [self::COUNTER_DRAW => 'b_row'],
            ],
            [
                'call' => LockScenarios::class.'::syncUpdate',
                'args' => ['quote', 'quotes', $quote->id, ['items' => [$item('desktop', 200)]]],
                'actor' => $actor->id,
                'after' => ['/from `quotes`.*for update$/is' => 'b_row'],
                'before' => ['/^delete from `quote_items`/i' => 'a_child'],
            ],
        );

        $this->assertBothCommitted($results);
    }
}
