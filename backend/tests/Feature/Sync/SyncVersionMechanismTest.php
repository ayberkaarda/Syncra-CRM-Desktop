<?php

namespace Tests\Feature\Sync;

use App\Jobs\ImportLeadsJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Repositories\ContactRepository;
use App\Repositories\DealRepository;
use App\Repositories\PriceListRepository;
use App\Repositories\QuoteRepository;
use App\Services\Chat\ChatReadState;
use App\Services\Leads\LeadConversionService;
use App\Services\Settings\PipelineStageService;
use App\Support\ImportBatch;
use App\Sync\SyncableRegistry;
use App\Sync\SyncCounter;
use Database\Seeders\CustomFieldSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Protocol §2 — the `sync_version` mechanism itself.
 *
 * These tests do not go through HTTP. They lock the invariant every endpoint
 * above them assumes: a change that a desktop client must see ALWAYS moves a
 * version it can reach with its cursor. Each write path here was verified in
 * F0 to bypass Eloquent model events in some way, which is exactly why it
 * needs a test rather than an argument.
 */
class SyncVersionMechanismTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    private function versionOf(string $table, int|string $id): int
    {
        return (int) DB::table($table)->where('id', $id)->value('sync_version');
    }

    public function test_an_ordinary_save_advances_the_version(): void
    {
        $company = Company::factory()->create();
        $before = (int) $company->sync_version;

        $company->update(['name' => 'Renamed']);

        $this->assertGreaterThan($before, (int) $company->fresh()->sync_version);
    }

    /**
     * The observer-side twin of probe T7 (protocol §2.4/P4b): a save that
     * changes nothing must not mint a version. Without the dirty check the
     * observer would MAKE the model dirty and turn every no-op save into a
     * write, a burned counter value and a phantom delta on every client.
     */
    public function test_a_no_op_save_does_not_burn_a_version(): void
    {
        $company = Company::factory()->create();
        $before = (int) $company->sync_version;
        $counter = SyncCounter::current();

        $company->save();

        $this->assertSame($before, (int) $company->fresh()->sync_version);
        $this->assertSame($counter, SyncCounter::current());
    }

    /**
     * SoftDeletes::runSoftDelete() writes through the query builder and never
     * fires `saving`, so the tombstone would otherwise keep its old version
     * and never cross a client's cursor.
     */
    public function test_a_soft_delete_advances_the_version(): void
    {
        $company = Company::factory()->create();
        $before = (int) $company->sync_version;

        $company->delete();

        $row = DB::table('companies')->where('id', $company->id)->first();

        $this->assertNotNull($row->deleted_at);
        $this->assertGreaterThan($before, (int) $row->sync_version);
    }

    /**
     * Protocol §1.4 — `->tags()->sync()` fires NO model event of any kind, so
     * a tag-only edit leaves the owner clean and would never reach a client.
     */
    public function test_a_tag_only_change_bumps_the_owner(): void
    {
        $deal = Deal::factory()->create();
        $tag = Tag::factory()->create();
        $before = (int) $deal->sync_version;

        app(DealRepository::class)->syncTags($deal, [$tag->id]);

        $this->assertGreaterThan($before, $this->versionOf('deals', $deal->id));
    }

    public function test_a_custom_field_only_change_bumps_the_owner(): void
    {
        $deal = Deal::factory()->create();

        DB::table('custom_fields')->insert([
            'entity_type' => 'deals', 'name' => 'Kaynak', 'key' => 'kaynak', 'type' => 'text',
            'is_required' => false, 'position' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(), 'sync_version' => SyncCounter::next(),
        ]);

        $before = (int) $deal->fresh()->sync_version;

        app(DealRepository::class)->syncCustomFieldValues($deal, ['kaynak' => 'fuar']);

        $this->assertGreaterThan($before, $this->versionOf('deals', $deal->id));
    }

    public function test_writing_the_same_custom_field_value_twice_does_not_burn_a_version(): void
    {
        $deal = Deal::factory()->create();

        DB::table('custom_fields')->insert([
            'entity_type' => 'deals', 'name' => 'Kaynak', 'key' => 'kaynak', 'type' => 'text',
            'is_required' => false, 'position' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(), 'sync_version' => SyncCounter::next(),
        ]);

        app(DealRepository::class)->syncCustomFieldValues($deal, ['kaynak' => 'fuar']);
        $after = $this->versionOf('deals', $deal->id);

        app(DealRepository::class)->syncCustomFieldValues($deal, ['kaynak' => 'fuar']);

        $this->assertSame($after, $this->versionOf('deals', $deal->id), 'Değişmeyen upsert sahte delta üretmemeli.');
    }

    /**
     * Protocol §2.3 #1 / K-F — quote items are EMBEDDED, so a line-item-only
     * edit leaves every `quotes` column untouched. No per-item tombstone is
     * owed (§1.5); the owner's version is.
     */
    public function test_replacing_quote_items_bumps_the_owning_quote(): void
    {
        $quote = Quote::factory()->create();
        $before = (int) $quote->sync_version;

        app(QuoteRepository::class)->replaceItems($quote, [[
            'name' => 'Danışmanlık', 'quantity' => 1, 'unit_price' => 500,
            'discount_percent' => 0, 'tax_rate' => 20, 'line_total' => 600,
        ]]);

        $this->assertGreaterThan($before, $this->versionOf('quotes', $quote->id));

        // And explicitly NOT a tombstone: quote_items never enters
        // sync_deletions, because it is not a pull table (§1.5/§2.7).
        $this->assertDatabaseMissing('sync_deletions', ['table_name' => 'quote_items']);
    }

    /**
     * Protocol §2.3 #5 — a bulk UPDATE instantiates no models.
     */
    public function test_clearing_other_primary_contacts_versions_each_demoted_row(): void
    {
        $company = Company::factory()->create();
        $keep = Contact::factory()->create(['company_id' => $company->id, 'is_primary' => true]);
        $demoted = Contact::factory()->count(3)->create(['company_id' => $company->id, 'is_primary' => true]);

        $before = $demoted->map(fn (Contact $c): int => (int) $c->sync_version)->all();

        app(ContactRepository::class)->clearOtherPrimaryContacts($company->id, $keep->id);

        $after = [];

        foreach ($demoted as $index => $contact) {
            $now = $this->versionOf('contacts', $contact->id);
            $this->assertGreaterThan($before[$index], $now);
            $after[] = $now;
        }

        $this->assertCount(3, array_unique($after), 'Satır başına TEKİL versiyon zorunlu (§2.5/K-C).');
    }

    /**
     * Protocol §2.3 #6 — Kanban column order is the one piece of
     * `pipeline_stages` an offline board cannot draw without.
     */
    public function test_reordering_pipeline_stages_versions_every_moved_stage(): void
    {
        $ids = PipelineStage::query()->orderBy('position')->pluck('id')->all();
        $before = PipelineStage::query()->pluck('sync_version', 'id')->all();

        app(PipelineStageService::class)->reorder(array_reverse($ids));

        foreach ($ids as $id) {
            $this->assertGreaterThan($before[$id], $this->versionOf('pipeline_stages', $id));
        }
    }

    /**
     * Protocol §2.3 #7.
     */
    public function test_clearing_other_default_price_lists_versions_them(): void
    {
        $keep = PriceList::factory()->create(['is_default' => true]);
        $demoted = PriceList::factory()->create(['is_default' => true]);
        $before = (int) $demoted->sync_version;

        app(PriceListRepository::class)->clearOtherDefaults($keep->id);

        $this->assertGreaterThan($before, $this->versionOf('price_lists', $demoted->id));
    }

    /**
     * KARAR P19 — `price_list_items` is the only HARD-DELETE surface on the
     * read-only side of the sync scope, so it is the only one whose removal a
     * client cannot learn about any other way. A soft-deleted row comes back
     * through the delta with `deleted_at` set; this one simply ceases to exist.
     *
     * The per-model `delete()` in PriceListRepository::removePrice() is what
     * makes the `deleting` event fire at all - a bulk `delete()` instantiates
     * no model and would leave `sync_deletions` empty.
     */
    public function test_removing_a_price_writes_a_tombstone(): void
    {
        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create();

        $item = app(PriceListRepository::class)->setPrice($priceList, $product->id, 1500.0);
        $itemId = $item->id;

        app(PriceListRepository::class)->removePrice($priceList, $product->id);

        $this->assertDatabaseMissing('price_list_items', ['id' => $itemId]);

        $tombstone = DB::table('sync_deletions')
            ->where('table_name', 'price_list_items')
            ->where('row_key', (string) $itemId)
            ->first();

        $this->assertNotNull($tombstone, 'Fiyat satırı silindi ama tombstone yazılmadı (P19).');
        $this->assertGreaterThan(0, (int) $tombstone->sync_version);
    }

    /**
     * Protocol §2.3 #3/#4 — lead conversion moves tasks and activities with a
     * bulk UPDATE that no observer can see, and moves `taggables` rows between
     * two owners that both have to be bumped (§1.4).
     */
    public function test_lead_conversion_versions_the_records_it_moves(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Admin');

        $lead = Lead::factory()->create();
        $tag = Tag::factory()->create();
        DB::table('taggables')->insert([
            'tag_id' => $tag->id, 'taggable_type' => $lead->getMorphClass(), 'taggable_id' => $lead->id,
        ]);

        $task = Task::factory()->create([
            'taskable_type' => $lead->getMorphClass(),
            'taskable_id' => $lead->id,
        ]);
        $taskVersionBefore = (int) $task->sync_version;

        $result = app(LeadConversionService::class)
            ->convert($lead, ['create_deal' => false], $actor);

        $this->assertGreaterThan(
            $taskVersionBefore,
            $this->versionOf('tasks', $task->id),
            'Dönüşümde taşınan görev versiyonlanmadı — masaüstünde hâlâ lead\'e bağlı görünürdü.'
        );

        $contact = $result['contact'];
        $this->assertGreaterThan(0, $this->versionOf('contacts', $contact->id));
    }

    /**
     * Protocol §7.3/6 — the `conversation_user` triggers, all three branches.
     */
    public function test_conversation_user_triggers_cover_insert_update_and_delete(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $conversation = Conversation::factory()->create(['created_by' => $a->id]);
        $conversation->users()->attach([$a->id, $b->id], ['joined_at' => now()]);

        $rows = DB::table('conversation_user')->where('conversation_id', $conversation->id)->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (int) $row->sync_version, 'BEFORE INSERT trigger çalışmadı.');
        }

        $this->assertCount(
            2,
            array_unique($rows->pluck('sync_version')->all()),
            'FOR EACH ROW: pivot satırları farklı versiyon almalı (§2.5/K-C).'
        );

        // A real update moves it...
        $before = (int) DB::table('conversation_user')
            ->where('conversation_id', $conversation->id)->where('user_id', $b->id)->value('sync_version');

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $a->id,
        ]);

        app(ChatReadState::class)->fanOutNewMessage($conversation->id, $a->id, $message->id);

        $this->assertGreaterThan($before, (int) DB::table('conversation_user')
            ->where('conversation_id', $conversation->id)->where('user_id', $b->id)->value('sync_version'));

        // ...and a no-op update does not (probe T7 / P4b guard).
        $counter = SyncCounter::current();

        DB::update('UPDATE conversation_user SET unread_count = unread_count WHERE conversation_id = ?', [$conversation->id]);

        $this->assertSame($counter, SyncCounter::current(), 'NULL-safe no-op guard çalışmıyor — sahte delta üretiliyor.');

        // detach() is a query-builder DELETE: the AFTER DELETE trigger is the
        // ONLY thing that can see it.
        $conversation->users()->detach($b->id);

        $this->assertDatabaseHas('sync_deletions', [
            'table_name' => 'conversation_user',
            'row_key' => $conversation->id.':'.$b->id,
        ]);
    }

    /**
     * Protocol §7.3/7 — DemoDataSeeder writes with bulk inserts, which fire no
     * model events at all. Without the backfill at the end of run(), a
     * demo-seeded system would look completely EMPTY on the desktop.
     */
    public function test_the_demo_seeder_leaves_no_scope_row_unversioned(): void
    {
        $this->seed(SettingSeeder::class);
        $this->seed(CustomFieldSeeder::class);
        $this->seed(DemoDataSeeder::class);

        // Guard against a silently skipped seeder: DemoDataSeeder returns
        // early when `companies` is already populated, and an empty database
        // would make every assertion below vacuously true.
        $this->assertGreaterThan(0, DB::table('companies')->count());
        $this->assertGreaterThan(0, DB::table('deals')->count());

        foreach (SyncableRegistry::syncVersionTables() as $table) {
            $unversioned = DB::table($table)->where('sync_version', 0)->count();

            $this->assertSame(0, $unversioned, "`{$table}` tablosunda sync_version = 0 kalan {$unversioned} satır var.");
        }
    }

    /**
     * F1 acceptance criterion: the QUEUED CSV import path stamps versions.
     *
     * The job is dispatched directly rather than through a 501-row upload:
     * what is under test is the JOB's write path (does LeadImportService still
     * go through Eloquent, so the observer fires?), not the controller's
     * threshold, which LeadImportTest already covers.
     */
    public function test_the_queued_import_path_stamps_sync_version(): void
    {
        Storage::fake('local');

        $actor = User::factory()->create();
        $actor->givePermissionTo('leads.import');

        $header = ['first_name', 'last_name', 'email', 'phone', 'company_name', 'position', 'source', 'status', 'score', 'notes'];
        $rows = [];

        for ($i = 1; $i <= 3; $i++) {
            $rows[] = "Ad{$i},Soyad{$i},kisi{$i}@example.test,,,,website,new,50,";
        }

        $content = implode("\r\n", array_merge([implode(',', $header)], $rows))."\r\n";

        Storage::disk('local')->put('imports/queued.csv', $content);

        $batchId = (string) Str::uuid();
        ImportBatch::start($batchId, $actor->id, 3);

        ImportLeadsJob::dispatchSync($batchId, 'imports/queued.csv', 'skip', $actor->id, $actor->id);

        $this->assertSame(3, Lead::query()->count());

        $versions = DB::table('leads')->pluck('sync_version')->all();

        $this->assertNotContains(0, $versions, 'Kuyruğa alınan içe aktarma yolu sync_version ATAMIYOR.');
        $this->assertCount(3, array_unique($versions), 'Satır başına tekil versiyon (§2.5/K-C).');
    }
}
