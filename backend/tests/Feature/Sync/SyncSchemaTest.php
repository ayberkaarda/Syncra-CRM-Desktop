<?php

namespace Tests\Feature\Sync;

use App\Models\Conversation;
use App\Models\CustomField;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Sync\SyncableRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Protocol §2.8 / KARAR P16 — the FK CASCADE blind spot, locked in TWO layers.
 *
 * ==========================================================================
 * THE PROBLEM
 * ==========================================================================
 * Probe D3 proved on MariaDB 10.4.32 that a child row deleted by an FK
 * CASCADE fires NEITHER the child's AFTER DELETE trigger NOR any Eloquent
 * event. A `taggables` row vanished and `sync_deletions` stayed empty. There
 * is no mechanism - observer, trigger or otherwise - that can see it.
 *
 * Every cascade chain in this schema is currently DORMANT, but only because
 * every parent happens to use soft deletes or has no delete route at all.
 * That is a coincidence of today's code, not a design guarantee: one
 * `forceDelete()`, one GDPR purge or one real tag-delete endpoint turns it
 * into silent, unrecoverable divergence on every desktop client.
 *
 * Converting the constraints to RESTRICT was REJECTED (§10.1): it changes the
 * web product's schema semantics and is a scope extension. This test is the
 * agreed alternative - it turns today's coincidence into a contract that
 * fails loudly when somebody changes either half of it.
 *
 * LAYER 1 pins the DELETE_RULE of every FK in the sync scope.
 * LAYER 2 pins the absence of a hard-delete path to the cascade parents.
 * Either one alone is insufficient: a new cascade with no delete path is
 * harmless today and dangerous tomorrow, and a new delete path on an existing
 * cascade is dangerous immediately.
 */
class SyncSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every foreign key on a sync-scope table, with its DELETE_RULE as of F1.
     *
     * Adding a row here is a deliberate act. If a migration introduces a new
     * CASCADE onto a scope table, this test goes red and the author has to
     * answer the question it encodes: how will a desktop client learn that the
     * child row is gone?
     *
     * @var array<string, string>
     */
    private const EXPECTED_DELETE_RULES = [
        'activities.user_id' => 'SET NULL',
        'companies.owner_id' => 'SET NULL',
        'contacts.company_id' => 'SET NULL',
        'contacts.owner_id' => 'SET NULL',
        'conversation_user.conversation_id' => 'CASCADE',
        'conversation_user.last_delivered_message_id' => 'SET NULL',
        'conversation_user.last_read_message_id' => 'SET NULL',
        'conversation_user.user_id' => 'CASCADE',
        'conversations.created_by' => 'SET NULL',
        'custom_field_values.custom_field_id' => 'CASCADE',
        'deals.company_id' => 'SET NULL',
        'deals.contact_id' => 'SET NULL',
        'deals.owner_id' => 'SET NULL',
        'deals.pipeline_stage_id' => 'RESTRICT',
        'exchange_rates.entered_by' => 'SET NULL',
        'leads.converted_company_id' => 'SET NULL',
        'leads.converted_contact_id' => 'SET NULL',
        'leads.converted_deal_id' => 'SET NULL',
        'leads.owner_id' => 'SET NULL',
        'messages.attachment_id' => 'SET NULL',
        'messages.conversation_id' => 'CASCADE',
        'messages.user_id' => 'SET NULL',
        'price_list_items.price_list_id' => 'CASCADE',
        'price_list_items.product_id' => 'CASCADE',
        'quote_items.product_id' => 'SET NULL',
        'quote_items.quote_id' => 'CASCADE',
        'quotes.company_id' => 'SET NULL',
        'quotes.contact_id' => 'SET NULL',
        'quotes.created_by' => 'SET NULL',
        'quotes.deal_id' => 'SET NULL',
        'quotes.parent_quote_id' => 'SET NULL',
        'saved_views.user_id' => 'CASCADE',
        'taggables.tag_id' => 'CASCADE',
        'tasks.assigned_to' => 'SET NULL',
        'tasks.created_by' => 'SET NULL',
        'tickets.assigned_to' => 'SET NULL',
        'tickets.company_id' => 'SET NULL',
        'tickets.contact_id' => 'SET NULL',
        'tickets.created_by' => 'SET NULL',
    ];

    public function test_layer_one_the_delete_rules_of_the_sync_scope_are_unchanged(): void
    {
        $scope = array_merge(
            SyncableRegistry::syncVersionTables(),
            // The three embedded children are not sync tables, but they are
            // exactly where the cascade risk lives (§1.4/§1.5), so their
            // constraints are pinned too.
            ['taggables', 'quote_items', 'custom_field_values'],
        );

        $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as rc')
            ->join('information_schema.KEY_COLUMN_USAGE as k', function ($join): void {
                $join->on('k.CONSTRAINT_NAME', '=', 'rc.CONSTRAINT_NAME')
                    ->on('k.CONSTRAINT_SCHEMA', '=', 'rc.CONSTRAINT_SCHEMA');
            })
            ->where('rc.CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('rc.TABLE_NAME', $scope)
            ->get(['rc.TABLE_NAME', 'rc.DELETE_RULE', 'k.COLUMN_NAME']);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->TABLE_NAME.'.'.$row->COLUMN_NAME] = $row->DELETE_RULE;
        }

        ksort($actual);

        $expected = self::EXPECTED_DELETE_RULES;
        ksort($expected);

        $this->assertSame(
            $expected,
            $actual,
            'Senkron kapsamındaki bir yabancı anahtarın DELETE_RULE değeri değişti. '.
            'Yeni bir CASCADE eklendiyse: FK cascade ile silinen çocuk satır NE trigger NE observer '.
            'tetikler (probe D3) — o satırın silindiği hiçbir masaüstü istemciye ULAŞMAZ.'
        );
    }

    /**
     * LAYER 2 — the cascade parents have no hard-delete route.
     */
    public function test_layer_two_no_cascade_parent_can_be_hard_deleted(): void
    {
        // Soft-delete parents: the cascade never fires, because the parent row
        // is never actually removed.
        foreach ([
            Conversation::class,
            User::class,
            PriceList::class,
            Product::class,
            Quote::class,
        ] as $model) {
            $this->assertContains(
                SoftDeletes::class,
                class_uses_recursive($model),
                "{$model} artık soft delete kullanmıyor — bağlı CASCADE zinciri UYANDI (§2.8)."
            );
        }

        // `tags` has no soft delete, so its cascade onto `taggables` is dormant
        // only because no endpoint deletes a tag (routes/api.php:254-255 is
        // index + store).
        $tagRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/tags'))
            ->flatMap(fn ($route): array => $route->methods())
            ->unique()
            ->values()
            ->all();

        $this->assertNotContains(
            'DELETE',
            $tagRoutes,
            'Bir etiket silme ucu eklenmiş: `taggables.tag_id` CASCADE zinciri artık ölü değil ve '.
            'silinen pivot satırları hiçbir istemciye iletilmez.'
        );

        // No production code force-deletes anything in the sync scope. The one
        // legitimate forceDelete in the codebase is PruneOrphanAttachments,
        // and `attachments` is outside the sync scope entirely (§1.3).
        $offenders = [];

        foreach (['app/Services', 'app/Repositories', 'app/Http', 'app/Jobs', 'app/Listeners', 'app/Observers'] as $dir) {
            foreach ($this->phpFiles(base_path($dir)) as $file) {
                if (str_contains((string) file_get_contents($file), '->forceDelete(')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'İş katmanında forceDelete() belirdi — §2.8 cascade kör noktası uyanmış olabilir.'
        );
    }

    /**
     * `custom_fields` is the remaining cascade parent with no soft delete. Its
     * DELETE endpoint deliberately DEACTIVATES instead of deleting; this
     * asserts the behaviour rather than trusting the method name.
     */
    public function test_deleting_a_custom_field_deactivates_it_instead(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        $field = CustomField::factory()->create(['entity_type' => 'deals', 'is_active' => true]);

        $this->actingAs($admin)
            ->deleteJson("/api/settings/custom-fields/{$field->id}")
            ->assertOk();

        $this->assertDatabaseHas('custom_fields', ['id' => $field->id, 'is_active' => 0]);
    }

    /**
     * The registry and the migrations are two lists of the same thing; this
     * keeps them from drifting.
     */
    public function test_every_registered_table_has_its_sync_columns(): void
    {
        foreach (SyncableRegistry::syncVersionTables() as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'sync_version'), "`{$table}` tablosunda sync_version yok.");
        }

        foreach (SyncableRegistry::clientIdTables() as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'client_id'), "`{$table}` tablosunda client_id yok.");
        }

        // Protocol §6.1/D10: notifications must NOT get one - its uuid primary
        // key already is the client id.
        $this->assertFalse(Schema::hasColumn('notifications', 'client_id'));

        // Protocol §1.4/§1.5: the three embedded children own no version.
        foreach (['taggables', 'quote_items', 'custom_field_values'] as $embedded) {
            $this->assertFalse(
                Schema::hasColumn($embedded, 'sync_version'),
                "`{$embedded}` gömülü bir çocuktur, kendi versiyonunu ALMAMALI (§1.5)."
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
