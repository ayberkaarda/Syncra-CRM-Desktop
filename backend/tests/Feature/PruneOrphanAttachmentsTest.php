<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `attachments:prune-orphans` — data-loss regression suite.
 *
 * The command deletes rows AND their files on disk with no recovery path, so
 * every attachment reachable from another row must be provably out of its
 * reach. Two independent attachment paths exist and both are covered here:
 *
 *   1. `attachments.attachable_type/attachable_id` (polymorphic; lead/contact
 *      timeline uploads) — excluded by `Attachment::scopeUnattached()`.
 *   2. `messages.attachment_id` (chat; the ONLY foreign key in the schema
 *      that points at `attachments.id`) — excluded by `baseQuery()`.
 *
 * The chat path is the one that regressed: `MessageService::create()` writes
 * `messages.attachment_id` and never writes `attachable_*`, so a chat
 * attachment looks "unattached" to path 1 alone.
 */
class PruneOrphanAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function chatUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['chat.use']);

        return $user;
    }

    /**
     * Backdate an attachment past the retention window without touching any
     * other column (a plain query-builder update, so no model events fire).
     */
    private function ageAttachment(Attachment $attachment, int $hours): void
    {
        DB::table('attachments')
            ->where('id', $attachment->getKey())
            ->update(['created_at' => now()->subHours($hours)]);
    }

    /**
     * PROOF / REGRESSION: an attachment sent into a conversation through the
     * real HTTP path (upload endpoint + message endpoint, exactly what the
     * chat UI does) must survive the pruner, no matter how old it is.
     *
     * Before the fix this test failed on the very first assertion: the row was
     * force-deleted, the file was removed from disk, and the surviving
     * `messages` row was left pointing at nothing (FK `nullOnDelete`).
     */
    public function test_attachment_sent_in_a_message_is_never_pruned(): void
    {
        Storage::fake('local');

        $sender = $this->chatUser();
        $peer = $this->chatUser();

        $conversation = Conversation::factory()->dm()->create(['created_by' => $sender->id]);
        $conversation->users()->attach([$sender->id, $peer->id]);

        // 1. Upload, exactly as the composer does.
        $uploadId = $this->actingAs($sender)
            ->postJson('/api/attachments', [
                'file' => UploadedFile::fake()->create('sozlesme.pdf', 120, 'application/pdf'),
            ])
            ->assertStatus(201)
            ->json('data.id');

        $attachment = Attachment::findOrFail($uploadId);
        Storage::disk($attachment->disk)->assertExists($attachment->path);

        // 2. Send it. MessageService::create() writes messages.attachment_id
        //    and deliberately leaves attachable_* NULL.
        $messageId = $this->actingAs($sender)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'body' => 'Sözleşme taslağı ekte.',
                'attachment_id' => $attachment->id,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertNull($attachment->fresh()->attachable_id, 'Guard: the chat path must not write attachable_id, otherwise this test proves nothing.');

        // 3. Age it past the 24h retention window and run the pruner.
        $this->ageAttachment($attachment, 25);

        $this->artisan('attachments:prune-orphans', ['--force' => true])->assertExitCode(0);

        // 4. Nothing may have been lost.
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'attachment_id' => $attachment->id,
        ]);
    }

    /**
     * Same guarantee stated directly against the schema, without the HTTP
     * layer: a `messages.attachment_id` reference alone is enough to protect
     * the row, even for a soft-deleted message (its attachment is still
     * reachable by restoring the message).
     */
    public function test_attachment_referenced_by_a_soft_deleted_message_is_kept(): void
    {
        Storage::fake('local');

        $sender = $this->chatUser();
        $conversation = Conversation::factory()->dm()->create(['created_by' => $sender->id]);
        $conversation->users()->attach([$sender->id]);

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $sender->id,
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake');

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($sender)
            ->withAttachment($attachment)
            ->create();
        $message->delete();

        $this->ageAttachment($attachment, 200);

        $this->artisan('attachments:prune-orphans', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * POSITIVE CONTROL. Without this the fix could degenerate into "keep
     * everything" and still look green: a genuinely orphaned upload past the
     * window must still lose both its row and its file.
     */
    public function test_genuinely_orphaned_attachment_past_the_window_is_deleted(): void
    {
        Storage::fake('local');

        $uploader = $this->chatUser();

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $uploader->id,
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake');

        $this->ageAttachment($attachment, 25);

        $this->artisan('attachments:prune-orphans', ['--force' => true])->assertExitCode(0);

        // forceDelete: the row is gone, not soft-deleted.
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    /**
     * An orphan still inside the retention window is untouched — the user may
     * simply not have pressed "send" yet.
     */
    public function test_orphaned_attachment_inside_the_window_is_kept(): void
    {
        Storage::fake('local');

        $attachment = Attachment::factory()->create([
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake');

        $this->ageAttachment($attachment, 23);

        $this->artisan('attachments:prune-orphans', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * The second attachment path: a lead/contact timeline upload carries
     * `attachable_*` and no `messages` row at all.
     */
    public function test_polymorphically_attached_attachment_is_kept(): void
    {
        Storage::fake('local');

        $lead = Lead::factory()->create();

        $attachment = Attachment::factory()->create([
            'attachable_type' => $lead->getMorphClass(),
            'attachable_id' => $lead->getKey(),
            'path' => 'attachments/'.fake()->uuid().'.pdf',
        ]);
        Storage::disk('local')->put($attachment->path, '%PDF-1.4 fake');

        $this->ageAttachment($attachment, 500);

        $this->artisan('attachments:prune-orphans', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * `--dry-run` reports the same set the real run would delete: message
     * attachments must not be counted either.
     */
    public function test_dry_run_counts_only_truly_orphaned_attachments(): void
    {
        Storage::fake('local');

        $sender = $this->chatUser();
        $conversation = Conversation::factory()->dm()->create(['created_by' => $sender->id]);
        $conversation->users()->attach([$sender->id]);

        $messageAttachment = Attachment::factory()->create(['path' => 'attachments/'.fake()->uuid().'.pdf']);
        Message::factory()
            ->inConversation($conversation)
            ->fromUser($sender)
            ->withAttachment($messageAttachment)
            ->create();
        $this->ageAttachment($messageAttachment, 25);

        $orphan = Attachment::factory()->create(['path' => 'attachments/'.fake()->uuid().'.pdf']);
        $this->ageAttachment($orphan, 25);

        $this->artisan('attachments:prune-orphans', ['--dry-run' => true])
            ->expectsOutputToContain('1 sahipsiz ek')
            ->assertExitCode(0);

        $this->assertDatabaseHas('attachments', ['id' => $messageAttachment->id]);
        $this->assertDatabaseHas('attachments', ['id' => $orphan->id]);
    }
}
