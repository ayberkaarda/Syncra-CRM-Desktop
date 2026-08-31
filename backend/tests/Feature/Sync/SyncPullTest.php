<?php

namespace Tests\Feature\Sync;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\ExchangeRate;
use App\Models\PipelineStage;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SavedView;
use App\Models\Setting;
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
use Illuminate\Notifications\DatabaseNotification;
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

    /**
     * FIX-BE U9 — depth-1 relational closure. A company outside the window
     * that an IN-window deal still points at must ship anyway, or the
     * client's `deals` screen renders every one of them with `company: —`.
     */
    public function test_bootstrap_window_still_ships_a_company_referenced_by_an_in_window_deal(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $ancient = Company::factory()->create();
        DB::table('companies')->where('id', $ancient->id)->update(['updated_at' => now()->subDays(90)]);

        $deal = Deal::factory()->create(['company_id' => $ancient->id]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0, 'deals' => 0],
            'window_days' => 30,
        ])->json('tables');

        $this->assertContains(
            $deal->id,
            array_column($body['deals']['rows'], 'id'),
            'Fırsat pencerede olmalı (bugün oluşturuldu).'
        );
        $this->assertContains(
            $ancient->id,
            array_column($body['companies']['rows'], 'id'),
            'İlişkili firma pencere dışında olsa da gelmeli (U9 ilişkisel kapanış).'
        );
    }

    /**
     * FIX-BE U9 — a company nobody references stays excluded: closure is
     * strictly depth-1 and demand-driven, not "ship the whole table".
     */
    public function test_bootstrap_window_still_excludes_an_unreferenced_ancient_company(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $ancient = Company::factory()->create();
        DB::table('companies')->where('id', $ancient->id)->update(['updated_at' => now()->subDays(90)]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0, 'deals' => 0, 'contacts' => 0],
            'window_days' => 30,
        ])->json('tables.companies');

        $this->assertNotContains(
            $ancient->id,
            array_column($body['rows'], 'id'),
            'Hiçbir satırın işaret etmediği eski firma pencere dışında kalmalı — kapanış talebe bağlıdır.'
        );
    }

    /**
     * FIX-BE U10 — `tags` is reference/lookup data (§4.1 groups it with
     * `taggables`/`custom_field_values`, not the time-ordered entity list),
     * so it must ship on a bootstrap pull even when every tag is old.
     */
    public function test_bootstrap_window_never_excludes_tags(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $tag = Tag::factory()->create();
        DB::table('tags')->where('id', $tag->id)->update(['updated_at' => now()->subDays(200)]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['tags' => 0],
            'window_days' => 30,
        ])->json('tables.tags');

        $this->assertContains(
            $tag->id,
            array_column($body['rows'], 'id'),
            'tags pencereden muaf olmalı (U10) — etiket kullanım dışı görünmesi silinmesi gerektiği anlamına gelmez.'
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

    /**
     * RISK-2 K2 - reference/lookup tables are exempt from the bootstrap
     * `window_days` filter, ALL of them, not just `tags`.
     *
     * The window is a VOLUME tool for time-ordered records. On a lookup table
     * `updated_at` records when somebody last edited the DEFINITION, which
     * says nothing about whether the row is still in use, so filtering by it
     * is a category error - FIX-BE measured `products` shipping 0/20 and
     * `users` 7/10 on a 30-day window, which renders quote lines with a blank
     * product and every assignment as "Atanan: -".
     *
     * Driven off `SyncableRegistry::windowExemptTables()` itself so a table
     * added to the RO set is covered here the moment it exists.
     */
    public function test_the_bootstrap_window_never_excludes_a_reference_table(): void
    {
        [$user, $token] = $this->deviceUser('Admin');

        $priceList = PriceList::factory()->create();

        $rowIds = [
            'tags' => Tag::factory()->create()->id,
            'pipeline_stages' => PipelineStage::factory()->create()->id,
            'custom_fields' => CustomField::factory()->create()->id,
            'products' => Product::factory()->create()->id,
            'price_lists' => $priceList->id,
            'price_list_items' => PriceListItem::factory()->create(['price_list_id' => $priceList->id])->id,
            'saved_views' => SavedView::factory()->create(['user_id' => $user->id])->id,
            'settings' => Setting::factory()->create(['is_public' => true])->id,
            'users' => $user->id,
        ];

        $this->assertSame(
            array_keys($rowIds),
            SyncableRegistry::windowExemptTables(),
            'Muafiyet listesi degisti ama bu test guncellenmedi - yeni referans tablosu kanitsiz kalir.'
        );

        // Aged with a raw statement so no observer drags the row back into the
        // window through a fresh timestamp.
        foreach (array_keys($rowIds) as $table) {
            DB::table($table)->update(['updated_at' => now()->subDays(200)]);
        }

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => array_fill_keys(array_keys($rowIds), 0),
            'window_days' => 30,
        ])->json('tables');

        foreach ($rowIds as $table => $id) {
            $this->assertContains(
                $id,
                array_column($body[$table]['rows'], 'id'),
                "`{$table}` pencereden muaf olmali (K2) - 200 gundur duzenlenmemis bir kayit kullanimdan kalkmis demek degildir."
            );
        }
    }

    /**
     * RISK-2 K2, the other half: `exchange_rates` is the ONE reference table
     * that stays windowed, and that is not an exception to the rule. Section
     * 4.1 writes its own tighter bound into the table list ("son 7 gun")
     * because an FX rate really is a dated series - yesterday's row is a
     * different fact, not a lookup entry that happens to be old.
     */
    public function test_the_bootstrap_window_still_applies_to_exchange_rates(): void
    {
        [, $token] = $this->deviceUser('Admin');

        // Inside the 7-day `rate_date` row scope, so only the window can cut it.
        $rate = ExchangeRate::factory()->create(['rate_date' => now()->toDateString()]);
        DB::table('exchange_rates')->where('id', $rate->id)->update(['updated_at' => now()->subDays(90)]);

        $body = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['exchange_rates' => 0],
            'window_days' => 30,
        ])->json('tables.exchange_rates');

        $this->assertNotContains(
            $rate->id,
            array_column($body['rows'], 'id'),
            '`exchange_rates` muafiyetin DISINDADIR - zaman serisi verisidir, lookup degil.'
        );

        $this->assertNotContains('exchange_rates', SyncableRegistry::windowExemptTables());
    }

    /**
     * RISK-2 K2, the volume answer. Exempting the reference tables means they
     * now ship in FULL on a bootstrap, and the only enterprise-scale growth
     * candidate among them is `price_list_items` (lists x products). Its
     * answer is not the window - a price the client cannot see is a wrong
     * quote - but the `has_more` pagination section 4.4 already mandates.
     *
     * So: a read-only table must page through a bootstrap window pull with a
     * cursor that actually MOVES, every row exactly once, no repeats and no
     * skips. Without this the exemption would trade an empty mirror for an
     * unbounded one.
     */
    public function test_a_read_only_table_pages_through_has_more_on_a_bootstrap_pull(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $priceList = PriceList::factory()->create();

        foreach (Product::factory()->count(12)->create() as $product) {
            PriceListItem::factory()->create([
                'price_list_id' => $priceList->id,
                'product_id' => $product->id,
            ]);
        }

        DB::table('price_list_items')->update(['updated_at' => now()->subDays(200)]);

        $seen = [];
        $cursor = 0;
        $pages = 0;

        for ($page = 0; $page < 10; $page++) {
            $body = $this->withToken($token)->postJson('/api/sync/pull', [
                'cursors' => ['price_list_items' => $cursor],
                'limit' => 5,
                'window_days' => 30,
            ])->json('tables.price_list_items');

            $pages++;

            foreach ($body['rows'] as $row) {
                $seen[] = (int) $row['id'];
            }

            $this->assertGreaterThanOrEqual($cursor, (int) $body['next_cursor']);
            $cursor = (int) $body['next_cursor'];

            if (! $body['has_more']) {
                break;
            }
        }

        $this->assertSame(3, $pages, 'RO tablo tek sayfada geldi ya da imlec ilerlemedi - `has_more` dongusu kirik.');
        $this->assertCount(12, $seen, 'Sayfalama satir atladi ya da tekrarladi.');
        $this->assertCount(12, array_unique($seen), 'Ayni satir iki kez dondu.');
    }

    /**
     * RISK-2 O3 / TM-F2 - a tombstone must not name a row the caller was
     * never allowed to see.
     *
     * `sync_deletions` carried no owner column and the row is gone by the time
     * the tombstone is read, so the owner has to be captured at DELETE time
     * (`owner_key`). Before that, every device received every user's deleted
     * notification uuids: existence only, no content, but existence of another
     * user's notification is still that user's data.
     */
    public function test_a_deleted_notification_tombstone_is_invisible_to_another_user(): void
    {
        [$owner, $ownerToken] = $this->deviceUser('Admin');
        [, $strangerToken] = $this->deviceUser('Admin');

        $deal = Deal::factory()->create(['owner_id' => $owner->id]);
        $owner->notify(DealAssignedNotification::make($deal, null));

        /** @var DatabaseNotification $notification */
        $notification = $owner->notifications()->firstOrFail();
        $uuid = (string) $notification->getKey();

        $cursor = SyncCounter::current();
        $notification->delete();

        $this->assertDatabaseHas('sync_deletions', [
            'table_name' => 'notifications',
            'row_key' => $uuid,
            'owner_key' => $owner->getMorphClass().':'.$owner->getKey(),
        ]);

        $ownerDeletions = $this->withToken($ownerToken)->postJson('/api/sync/pull', [
            'cursors' => ['notifications' => $cursor],
        ])->json('tables.notifications.deletions');

        $this->assertSame(
            [$uuid],
            array_column($ownerDeletions, 'row_key'),
            'Sahibi kendi sildigi bildirimin mezar tasini GORMELI, yoksa aynasi kuculemez.'
        );

        // MANDATORY between two tokens in ONE test: the application instance
        // is reused across requests here (it is not in production, where every
        // request boots its own), so Sanctum's already-resolved user would
        // survive into the next call and this test would silently re-pull as
        // the OWNER - reporting green for a leak it never exercised.
        $this->app['auth']->forgetGuards();

        $strangerDeletions = $this->withToken($strangerToken)->postJson('/api/sync/pull', [
            'cursors' => ['notifications' => $cursor],
        ])->json('tables.notifications.deletions');

        $this->assertNotContains(
            $uuid,
            array_column($strangerDeletions, 'row_key'),
            'Baska bir kullanicinin silinmis bildirim uuid degeri siziyor (O3/TM-F2).'
        );
        $this->assertSame([], $strangerDeletions);
    }

    /**
     * RISK-2 O3 - the unscoped tombstone tables stay unscoped. `tags` is
     * org-wide vocabulary (`permission => null`), so every authenticated
     * caller pulls every tag row and a tag tombstone can reveal nothing its
     * rows do not already show the same caller. Scoping it by accident would
     * silently stop clients from ever dropping a deleted tag.
     */
    public function test_a_tag_tombstone_still_reaches_every_user(): void
    {
        [, $tokenA] = $this->deviceUser('Admin');
        [, $tokenB] = $this->deviceUser('Admin');

        $tag = Tag::factory()->create();
        $cursor = SyncCounter::current();
        $tag->delete();

        foreach ([$tokenA, $tokenB] as $token) {
            // See the O3 test above: without this the second iteration would
            // still be authenticated as the first user.
            $this->app['auth']->forgetGuards();

            $deletions = $this->withToken($token)->postJson('/api/sync/pull', [
                'cursors' => ['tags' => $cursor],
            ])->json('tables.tags.deletions');

            $this->assertSame([(string) $tag->id], array_column($deletions, 'row_key'));
        }

        $this->assertNull(
            DB::table('sync_deletions')->where('table_name', 'tags')->value('owner_key'),
            '`tags` sahibi olmayan bir tablodur - owner_key NULL kalmali.'
        );
    }

    /**
     * RISK-2 #3 - `window_days` is a BOOTSTRAP HINT, never an error.
     *
     * Section 4.4 says the filter applies only at `cursor = 0` ("delta'da
     * filtre yok"), but the request rule said `min:1` unconditionally, so a
     * client that kept the field in its delta payload got a 422 for a value
     * the server was going to ignore. `0` and `null` now mean the same thing -
     * "no window" - on BOTH kinds of pull.
     */
    public function test_window_days_zero_is_accepted_on_a_delta_pull_and_changes_nothing(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $company = Company::factory()->create();
        DB::table('companies')->where('id', $company->id)->update(['updated_at' => now()->subDays(90)]);

        $response = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 1],
            'window_days' => 0,
        ]);

        $response->assertOk();

        $this->assertContains(
            $company->id,
            array_column($response->json('tables.companies.rows'), 'id'),
            'Delta cekiminde `window_days` yok sayilir - 422 de uretmez, satir da kesmez.'
        );
    }

    /**
     * The same value on a BOOTSTRAP pull: `0` is "no window", not "zero days
     * of history". Nobody can want an empty bootstrap, so it degrades to the
     * no-filter branch instead of 422-ing or returning nothing.
     */
    public function test_window_days_zero_means_no_window_on_a_bootstrap_pull(): void
    {
        [, $token] = $this->deviceUser('Admin');

        $ancient = Company::factory()->create();
        DB::table('companies')->where('id', $ancient->id)->update(['updated_at' => now()->subDays(900)]);

        $response = $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0],
            'window_days' => 0,
        ]);

        $response->assertOk();

        $this->assertContains(
            $ancient->id,
            array_column($response->json('tables.companies.rows'), 'id'),
            '`window_days: 0` filtresizlik demektir; `null` ile ayni yaniti vermeli.'
        );
    }

    /**
     * The ceiling stays a hard 422: 365 days is a disk budget (K8/K12,
     * "Download archive" is the deliberate way to widen it), and silently
     * clamping an over-wide ask would hide the client bug that produced it.
     */
    public function test_a_window_wider_than_the_ceiling_is_still_rejected(): void
    {
        [, $token] = $this->deviceUser('Admin');

        // This API wraps validation failures in its own envelope
        // (`errors.code = VALIDATION_ERROR`, offending keys under
        // `errors.fields`), so `assertJsonValidationErrors()` - which expects
        // Laravel's default top-level `errors` bag keyed by field - does not
        // apply here. Same shape SyncPushTest asserts on.
        $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0],
            'window_days' => 400,
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['fields' => ['window_days']]]);

        $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['companies' => 0],
            'window_days' => -1,
        ])->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['fields' => ['window_days']]]);
    }
}
