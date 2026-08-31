<?php

namespace Tests\Feature\Sync;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tag;
use App\Notifications\DealAssignedNotification;
use App\Repositories\DealRepository;
use App\Repositories\PriceListRepository;
use App\Repositories\QuoteRepository;
use App\Sync\SyncableRegistry;
use App\Sync\SyncCounter;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SYNCDESKTOP §4.6/3 + protocol §7.3/4 — the delta reader.
 *
 * The keyset-stability test is the important one. A single-scalar cursor
 * (K-C) is only correct while `sync_version` is unique per row; if it ever is
 * not, a LIMIT boundary that lands inside a tie drops a record permanently and
 * NOTHING else in the system would notice. 600 rows over a 500 page is the
 * cheapest configuration that actually crosses such a boundary.
 */
class SyncPullTest extends TestCase
{
    use InteractsWithDeviceTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PipelineStageSeeder::class);
    }

    public function test_delta_returns_only_rows_above_the_cursor(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $old = Company::factory()->create();
        $cursor = SyncCounter::current();
        $fresh = Company::factory()->create();

        $response = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => $cursor],
            'limit' => 100,
        ]);

        $ids = array_column($response->json('tables.companies.rows'), 'id');

        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertSame((int) $fresh->fresh()->sync_version, $response->json('tables.companies.next_cursor'));
    }

    /**
     * Protocol §7.3/4 — 600 rows, page size 500: every row exactly once.
     */
    public function test_keyset_paging_over_a_limit_boundary_neither_repeats_nor_skips(): void
    {
        [, $token] = $this->deviceUser('Admin');

        Contact::factory()->count(600)->create();

        $seen = [];
        $cursor = 0;

        for ($page = 0; $page < 5; $page++) {
            $body = $this->withToken($token)->postJson('/api/sync/pull', [
                'cursors' => ['contacts' => $cursor],
                'limit' => 500,
            ])->json('tables.contacts');

            foreach ($body['rows'] as $row) {
                $seen[] = (int) $row['id'];
            }

            $cursor = (int) $body['next_cursor'];

            if (! $body['has_more']) {
                break;
            }
        }

        $this->assertCount(600, $seen, 'Sayfalama satır atladı ya da tekrarladı.');
        $this->assertCount(600, array_unique($seen), 'Aynı satır iki kez döndü.');
    }

    /**
     * Protocol §2.5/K-C, stated as a schema invariant rather than a paging
     * accident: no two rows of a table may share a version.
     */
    public function test_every_row_gets_a_distinct_sync_version(): void
    {
        Contact::factory()->count(50)->create();

        $versions = DB::table('contacts')->pluck('sync_version')->all();

        $this->assertCount(count($versions), array_unique($versions));
        $this->assertNotContains(0, $versions);
    }

    public function test_soft_deleted_rows_come_back_as_tombstones_with_a_new_version(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create();
        $cursor = SyncCounter::current();

        $company->delete();

        $rows = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => $cursor],
        ])->json('tables.companies.rows');

        $this->assertCount(1, $rows);
        $this->assertSame($company->id, (int) $rows[0]['id']);
        $this->assertNotNull($rows[0]['deleted_at'], 'Soft delete satırın KENDİSİ tombstone olarak dönmeli.');
        $this->assertGreaterThan($cursor, (int) $rows[0]['sync_version']);
    }

    public function test_hard_deleted_tags_arrive_through_sync_deletions(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $tag = Tag::factory()->create();
        $cursor = SyncCounter::current();

        $tag->delete();

        $deletions = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['tags' => $cursor],
        ])->json('tables.tags.deletions');

        $this->assertCount(1, $deletions);
        $this->assertSame((string) $tag->id, $deletions[0]['row_key']);
    }

    /**
     * KARAR P19 — the tombstone has to reach the client through the SAME delta
     * the rows travel on, otherwise writing it changes nothing.
     */
    public function test_a_removed_price_arrives_as_a_deletion_in_the_delta(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $priceList = PriceList::factory()->create();
        $product = Product::factory()->create();

        $repository = app(PriceListRepository::class);
        $item = $repository->setPrice($priceList, $product->id, 990.0);
        $itemId = $item->id;

        $cursor = SyncCounter::current();

        $repository->removePrice($priceList, $product->id);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['price_list_items' => $cursor],
        ])->json('tables.price_list_items');

        $this->assertSame(
            [(string) $itemId],
            array_column($body['deletions'], 'row_key'),
            'Silinen fiyat satırı deltada dönmüyor — istemcinin aynası küçülemez.'
        );

        $this->assertGreaterThan($cursor, (int) $body['next_cursor']);
    }

    /**
     * Protocol §1.4/§1.5 — the three embedded children travel inside the
     * owner's row, and the owner's version moves when only they change.
     */
    public function test_rows_carry_their_embedded_tags_custom_fields_and_items(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $tag = Tag::factory()->create();
        $deal = Deal::factory()->create();
        app(DealRepository::class)->syncTags($deal, [$tag->id]);

        $quote = Quote::factory()->create();
        app(QuoteRepository::class)->replaceItems($quote, [[
            'name' => 'Line',
            'quantity' => 2,
            'unit_price' => 100,
            'discount_percent' => 0,
            'tax_rate' => 20,
            'line_total' => 240,
        ]]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['deals' => 0, 'quotes' => 0],
        ])->json('tables');

        $dealRow = collect($body['deals']['rows'])->firstWhere('id', $deal->id);
        $this->assertSame([$tag->id], $dealRow['tags']);
        $this->assertArrayHasKey('custom_fields', $dealRow);

        $quoteRow = collect($body['quotes']['rows'])->firstWhere('id', $quote->id);
        $this->assertCount(1, $quoteRow['items']);
        $this->assertSame('Line', $quoteRow['items'][0]['name']);
    }

    public function test_bootstrap_window_excludes_rows_older_than_window_days(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $recent = Company::factory()->create();
        $ancient = Company::factory()->create();

        // Moved with a raw statement so the observer does not re-version the
        // row and drag it back into the window through a fresh timestamp.
        DB::table('companies')->where('id', $ancient->id)->update(['updated_at' => now()->subDays(90)]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0],
            'window_days' => 30,
        ])->json('tables.companies');

        $ids = array_column($body['rows'], 'id');

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($ancient->id, $ids, 'Bootstrap penceresi dışındaki kayıt GELMEMELİ (K12).');
    }

    public function test_the_window_is_not_applied_to_a_delta_pull(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create();
        DB::table('companies')->where('id', $company->id)->update(['updated_at' => now()->subDays(90)]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 1],
            'window_days' => 30,
        ])->json('tables.companies');

        $this->assertContains(
            $company->id,
            array_column($body['rows'], 'id'),
            'Delta çekiminde `window_days` uygulanmaz — eski ama BUGÜN değişmiş kayıt kaçarsa veri kaybıdır.'
        );
    }

    public function test_the_users_projection_never_leaks_a_credential_column(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $rows = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['users' => 0],
        ])->json('tables.users.rows');

        $this->assertNotEmpty($rows);

        foreach (['password', 'remember_token', 'must_change_password', 'last_login_at', 'email_verified_at'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $rows[0], "`users` projeksiyonu `{$forbidden}` sızdırıyor.");
        }

        $this->assertSame(
            SyncableRegistry::USER_PROJECTION,
            array_keys($rows[0]),
            '`users` projeksiyonu bir BEYAZ LİSTEDİR; yeni bir kolon sessizce eklenemez.'
        );
    }

    /**
     * SYNCDESKTOP §4.4 — the 5 MB response ceiling.
     *
     * 100 companies with ~60 KB of notes each is ~6 MB, so the page has to be
     * cut. (60 KB, not more: `notes` is a TEXT column and 64 KB is its hard
     * ceiling.) The two assertions that matter are that it IS cut and that
     * `next_cursor` stops at the LAST ROW ACTUALLY SENT - a cursor that ran
     * past a dropped row would lose it forever.
     */
    public function test_a_response_over_the_byte_ceiling_is_cut_and_reports_has_more(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $filler = str_repeat('x', 60 * 1024);

        for ($i = 0; $i < 100; $i++) {
            Company::factory()->create(['notes' => $filler]);
        }

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0],
            'limit' => 500,
        ])->json('tables.companies');

        $this->assertTrue($body['has_more'], '5 MB tavanını aşan yanıt kesilmedi.');
        $this->assertLessThan(100, count($body['rows']));
        $this->assertGreaterThan(0, count($body['rows']));

        $lastSent = (int) end($body['rows'])['sync_version'];

        $this->assertSame(
            $lastSent,
            (int) $body['next_cursor'],
            'İmleç gönderilmeyen bir satırın ötesine geçti — o kayıt bir daha ASLA dönmez.'
        );

        // And the client can finish the job: continuing from the reported
        // cursor returns the remainder with no gap.
        $rest = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => $body['next_cursor']],
            'limit' => 500,
        ])->json('tables.companies.rows');

        $this->assertSame(100, count($body['rows']) + count($rest));
    }

    /**
     * DESKTOP-ARCHITECTURE.md EK 3, "BACKEND'E DEVREDİLEN İKİ GERÇEK BOŞLUK" #1:
     * a KEY-mode notification (`data.title_key`/`data.body_key`/`data.params`,
     * protocol §5.1) carries no sentence at all - only NotificationResource's
     * render path (Laravel's PHP translation catalogue) can turn a key into
     * one, and the desktop has no such catalogue. The pull row must therefore
     * carry a server-rendered `title`/`body` too, produced through the SAME
     * `NotificationText::resolve()` NotificationResource uses (K7).
     *
     * `title_key`/`body_key`/`params` must survive UNCHANGED alongside them so
     * a future client can still render for itself.
     */
    public function test_a_key_mode_notification_carries_a_server_rendered_title_and_body(): void
    {
        [$user, $token] = $this->deviceUser('Admin', ['locale' => 'en']);

        $deal = Deal::factory()->create(['owner_id' => $user->id]);

        $user->notify(DealAssignedNotification::make($deal, null));

        $row = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['notifications' => 0],
        ])->json('tables.notifications.rows.0');

        $data = json_decode((string) $row['data'], true);

        $this->assertSame(
            'A deal was assigned to you',
            $data['title'] ?? null,
            'Anahtar modundaki bildirim pull satırında render EDİLMİŞ title taşımıyor.'
        );
        $this->assertNotNull($data['body'] ?? null);
        $this->assertStringContainsString($deal->title, (string) $data['body']);

        // Additive, not destructive: title_key/body_key/params kalmalı.
        $this->assertSame('notifications.deal_assigned.title', $data['title_key'] ?? null);
        $this->assertSame('notifications.deal_assigned.body', $data['body_key'] ?? null);
        $this->assertArrayHasKey('params', $data);
    }

    /**
     * The recipient's locale, not the puller's request locale, decides the
     * render - and SyncScope already scopes the `notifications` table to
     * `notifiable_id = $user`, so the puller IS the recipient.
     */
    public function test_notification_text_renders_in_the_recipients_locale(): void
    {
        [$user, $token] = $this->deviceUser('Admin', ['locale' => 'tr']);

        $deal = Deal::factory()->create(['owner_id' => $user->id]);

        $user->notify(DealAssignedNotification::make($deal, null));

        $row = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['notifications' => 0],
        ])->json('tables.notifications.rows.0');

        $data = json_decode((string) $row['data'], true);

        $this->assertSame('Size bir fırsat atandı', $data['title'] ?? null);
    }

    /**
     * Backward compatibility (protocol §5.1's fallback path, NotificationText
     * doc block): a pre-Phase-14 plain-text row has no `title_key` and must
     * pass through with its stored title/body untouched, not blanked.
     */
    public function test_a_plain_text_legacy_notification_keeps_its_stored_title_and_body(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Legacy',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['type' => 'legacy', 'title' => 'Eski başlık', 'body' => 'Eski gövde']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'sync_version' => SyncCounter::next(),
        ]);

        $row = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['notifications' => 0],
        ])->json('tables.notifications.rows.0');

        $data = json_decode((string) $row['data'], true);

        $this->assertSame('Eski başlık', $data['title'] ?? null);
        $this->assertSame('Eski gövde', $data['body'] ?? null);
    }

    public function test_an_unpermitted_table_is_absent_from_the_pull_response(): void
    {
        [, $token] = $this->deviceUser('Destek Temsilcisi');

        Deal::factory()->create();

        $tables = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['deals' => 0, 'tickets' => 0],
        ])->json('tables');

        $this->assertArrayHasKey('tickets', $tables);
        $this->assertArrayNotHasKey('deals', $tables, 'İzinsiz tablo istenmiş olsa bile yanıtta yer almamalı.');
    }
}
