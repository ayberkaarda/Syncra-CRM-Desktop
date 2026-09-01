<?php

namespace Tests\Feature\Sync;

use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KARAR A29 (defter O90) — an attached message's `messages` pull row carries
 * the attachment's metadata as four FLATTENED fields (`attachment_name`,
 * `attachment_mime`, `attachment_size`, `attachment_is_image`), because
 * `attachments` itself never joins the sync scope (protocol §1.3: file bytes
 * are out of scope). Root cause this closes: the desktop mirror had no
 * `attachments` table, `messages` mirrored only `attachment_id`, and
 * `mapMessage` hard-coded `attachment: null` - every attached-message bubble
 * rendered with a timestamp and nothing else, for every device, not just a
 * regression window.
 *
 * `SyncPullService::attachMessageAttachments()` sources the four fields by
 * loading `Attachment` models and calling `Attachment::isInlineEligibleImage()`
 * for `attachment_is_image` - the SAME allowlist check `AttachmentResource`
 * (upload response) and `AttachmentController::show()`'s `?inline=1` gate use
 * (K7: ONE definition, never re-derived from a MIME prefix). This is
 * deliberately narrower than `str_starts_with($mime, 'image/')`:
 * `image/svg+xml` matches that prefix but is excluded from the allowlist on
 * purpose (inline SVG rendering is a known XSS vector).
 */
class SyncPullMessageAttachmentTest extends TestCase
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
     * @return array{0: User, 1: string, 2: Conversation}
     */
    private function participant(): array
    {
        [$user, $token] = $this->deviceUser('Admin');

        $conversation = Conversation::factory()->dm()->createdBy($user)->withMembers([$user])->create();

        return [$user, $token, $conversation];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pullMessageRows(string $token): array
    {
        return $this->withToken($token)->postJson('/api/sync/pull', [
            'cursors' => ['messages' => 0],
        ])->assertOk()->json('tables.messages.rows');
    }

    // ---------------------------------------------------------- happy path

    public function test_an_attached_message_row_carries_the_four_flattened_fields_matching_the_attachment(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create([
            'original_name' => 'Sözleşme_Taslağı.pdf',
            'mime_type' => 'application/pdf',
            'size' => 204800,
        ]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertNotNull($row, 'Ekli mesaj pull yanıtında bulunamadı.');
        $this->assertSame($attachment->original_name, $row['attachment_name']);
        $this->assertSame($attachment->mime_type, $row['attachment_mime']);
        $this->assertSame((int) $attachment->size, $row['attachment_size']);
        $this->assertFalse($row['attachment_is_image']);
    }

    public function test_attachment_is_image_true_for_an_image_mime(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->image()->create(); // mime_type: image/png

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertSame('image/png', $row['attachment_mime']);
        $this->assertTrue($row['attachment_is_image']);
    }

    public function test_attachment_is_image_false_for_a_non_image_mime(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create(['mime_type' => 'text/plain']);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertSame('text/plain', $row['attachment_mime']);
        $this->assertFalse($row['attachment_is_image']);
    }

    /**
     * The regression this closes: `str_starts_with($mime, 'image/')` matches
     * `image/svg+xml`, but the allowlist (`config('chat.attachments.inline_mime_types')`)
     * deliberately excludes it - inline SVG rendering is a known XSS vector.
     * Before the fix, the pull row disagreed with `AttachmentResource` and
     * would have flagged an SVG as inline-eligible the moment a preview
     * channel started trusting `attachment_is_image`.
     */
    public function test_attachment_is_image_false_for_svg_despite_matching_the_image_mime_prefix(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create(['mime_type' => 'image/svg+xml']);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertSame('image/svg+xml', $row['attachment_mime']);
        $this->assertFalse(
            $row['attachment_is_image'],
            'SVG "image/" ön ekiyle eşleşir ama allowlist dışıdır (XSS vektörü) - true dönmemeliydi.'
        );
    }

    /**
     * Every MIME type on the allowlist (`config('chat.attachments.inline_mime_types')`)
     * must come back `true` - the four raster formats the inline preview
     * channel actually supports.
     *
     * @return array<string, array{0: string}>
     */
    public static function allowlistedMimeProvider(): array
    {
        return [
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ];
    }

    /**
     * @dataProvider allowlistedMimeProvider
     */
    public function test_attachment_is_image_true_for_each_allowlisted_mime(string $mime): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create(['mime_type' => $mime]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertSame($mime, $row['attachment_mime']);
        $this->assertTrue($row['attachment_is_image']);
    }

    /**
     * An `image/*` MIME that is NOT on the allowlist must still come back
     * `false` - the definition is the allowlist, not the prefix.
     */
    public function test_attachment_is_image_false_for_a_non_allowlisted_image_mime(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create(['mime_type' => 'image/bmp']);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertSame('image/bmp', $row['attachment_mime']);
        $this->assertFalse($row['attachment_is_image']);
    }

    /**
     * A non-image MIME must come back `false` - covered again explicitly
     * (distinct from `test_attachment_is_image_false_for_a_non_image_mime`'s
     * `text/plain` case) with `application/pdf`, the MIME the happy-path test
     * above already uses.
     */
    public function test_attachment_is_image_false_for_a_non_image_document_mime(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create(['mime_type' => 'application/pdf']);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertSame('application/pdf', $row['attachment_mime']);
        $this->assertFalse($row['attachment_is_image']);
    }

    /**
     * The lock: the pull row's `attachment_is_image` and `AttachmentResource`'s
     * `is_image` (the K7 source definition) must agree for the SAME
     * attachment, across every MIME above. If this ever diverges, the two
     * definitions drifted apart again.
     *
     * @dataProvider parityMimeProvider
     */
    public function test_pull_row_attachment_is_image_matches_attachment_resource_is_image(string $mime): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->create(['mime_type' => $mime]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $resourcePayload = (new AttachmentResource($attachment->fresh()))->toArray(request());

        $this->assertSame(
            $resourcePayload['is_image'],
            $row['attachment_is_image'],
            "MIME {$mime} için pull satırı ile AttachmentResource anlaşmadı - iki tanım tekrar ayrıştı."
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parityMimeProvider(): array
    {
        return [
            'jpeg (allowlisted)' => ['image/jpeg'],
            'png (allowlisted)' => ['image/png'],
            'gif (allowlisted)' => ['image/gif'],
            'webp (allowlisted)' => ['image/webp'],
            'svg (image/* but not allowlisted)' => ['image/svg+xml'],
            'bmp (image/* but not allowlisted)' => ['image/bmp'],
            'pdf (not image/*)' => ['application/pdf'],
        ];
    }

    // -------------------------------------------------------------- absence

    public function test_a_message_without_an_attachment_does_not_carry_the_four_fields(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->create(); // attachment_id stays null (factory default)

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertNotNull($row, 'Eksiz mesaj pull yanıtında bulunamadı.');
        $this->assertArrayNotHasKey('attachment_name', $row);
        $this->assertArrayNotHasKey('attachment_mime', $row);
        $this->assertArrayNotHasKey('attachment_size', $row);
        $this->assertArrayNotHasKey('attachment_is_image', $row);
    }

    /**
     * Parity with `MessageResource::toArray()` (see its docblock: a deleted
     * message's `attachment` is unconditionally null on the web surface). A
     * pull row that still carried the attachment's name/mime/size for a
     * "deleted" message would disagree with the surface it mirrors.
     */
    public function test_a_deleted_message_with_an_attachment_does_not_carry_the_four_fields(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $attachment = Attachment::factory()->image()->create();

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment($attachment)
            ->create();

        $message->delete(); // soft delete: deleted_at set, row remains (tombstone)

        $row = collect($this->pullMessageRows($token))->firstWhere('id', $message->id);

        $this->assertNotNull($row, 'Silinmiş mesaj satırı (mezar taşı) pull yanıtından tamamen düşmemeli.');
        $this->assertNotNull($row['deleted_at']);
        $this->assertArrayNotHasKey('attachment_name', $row);
        $this->assertArrayNotHasKey('attachment_mime', $row);
        $this->assertArrayNotHasKey('attachment_size', $row);
        $this->assertArrayNotHasKey('attachment_is_image', $row);
    }

    // ----------------------------------------------------------------- N+1

    /**
     * `attachMessageAttachments()` must run exactly ONE query against
     * `attachments` for the whole page, regardless of how many attached
     * messages are on it - matching `attachEmbeds()`'s discipline
     * (SyncPullService.php:433 docblock). A per-row query would show up here
     * as one `attachments` hit per message instead of one for the page.
     */
    public function test_metadata_is_attached_with_exactly_one_query_regardless_of_page_size(): void
    {
        [$user, $token, $conversation] = $this->participant();

        $messageCount = 5;

        for ($i = 0; $i < $messageCount; $i++) {
            $attachment = Attachment::factory()->create();

            Message::factory()
                ->inConversation($conversation)
                ->fromUser($user)
                ->withAttachment($attachment)
                ->create();
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $rows = $this->pullMessageRows($token);

        $attachmentQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from `attachments`')
        ));

        $this->assertCount(
            $messageCount,
            array_filter($rows, static fn (array $row): bool => array_key_exists('attachment_name', $row)),
            'Beklenenden az/çok ekli mesaj satırı döndü.'
        );

        $this->assertCount(
            1,
            $attachmentQueries,
            '`attachments` tablosuna sayfa başına TEK sorgu yerine '.count($attachmentQueries)
            .' sorgu gitti - N+1 regresyonu: '.implode(' | ', $attachmentQueries)
        );
    }

    // ------------------------------------------------------------ backfill

    /**
     * The one-shot backfill migration (2026_09_01_100011) gives every
     * pre-existing attached, non-deleted `messages` row a FRESH, PER-ROW
     * UNIQUE `sync_version`, so it re-enters a client's next delta and picks
     * up the four new fields. A message with no attachment, or a soft-deleted
     * attached message, is left completely alone.
     *
     * `App\Sync\SyncVersionBackfill::run()` is NOT called (it targets
     * `sync_version = 0` rows only, per its own docblock) - the migration
     * file is required directly and its `up()` invoked, mirroring exactly
     * what `php artisan migrate` would run.
     */
    public function test_the_backfill_migration_gives_pre_existing_attached_messages_a_fresh_unique_sync_version(): void
    {
        [$user, , $conversation] = $this->participant();

        $withAttachment1 = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment(Attachment::factory()->create())
            ->create();

        $withAttachment2 = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment(Attachment::factory()->create())
            ->create();

        $withoutAttachment = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->create();

        $deletedWithAttachment = Message::factory()
            ->inConversation($conversation)
            ->fromUser($user)
            ->withAttachment(Attachment::factory()->create())
            ->create();
        $deletedWithAttachment->delete();

        $before = DB::table('messages')->whereIn('id', [
            $withAttachment1->id, $withAttachment2->id, $withoutAttachment->id, $deletedWithAttachment->id,
        ])->pluck('sync_version', 'id');

        $migration = require database_path('migrations/2026_09_01_100011_backfill_message_attachment_sync_version.php');
        $migration->up();

        $after = DB::table('messages')->whereIn('id', [
            $withAttachment1->id, $withAttachment2->id, $withoutAttachment->id, $deletedWithAttachment->id,
        ])->pluck('sync_version', 'id');

        $this->assertNotSame(
            (int) $before[$withAttachment1->id],
            (int) $after[$withAttachment1->id],
            'Ekli, silinmemiş mesaj #1 yeni bir sync_version almalıydı.'
        );
        $this->assertNotSame(
            (int) $before[$withAttachment2->id],
            (int) $after[$withAttachment2->id],
            'Ekli, silinmemiş mesaj #2 yeni bir sync_version almalıydı.'
        );
        $this->assertNotSame(
            (int) $after[$withAttachment1->id],
            (int) $after[$withAttachment2->id],
            'İki ekli mesaj SATIR BAŞINA BENZERSİZ sync_version almalıydı, ikisi de aynı değeri paylaşıyor.'
        );

        $this->assertSame(
            (int) $before[$withoutAttachment->id],
            (int) $after[$withoutAttachment->id],
            'Eksiz mesajın sync_version değeri backfill tarafından dokunulmamalıydı.'
        );
        $this->assertSame(
            (int) $before[$deletedWithAttachment->id],
            (int) $after[$deletedWithAttachment->id],
            'Silinmiş (mezar taşı) ekli mesajın sync_version değeri backfill tarafından dokunulmamalıydı.'
        );
    }
}
