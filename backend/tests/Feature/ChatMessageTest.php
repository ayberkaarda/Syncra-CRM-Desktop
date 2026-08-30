<?php

namespace Tests\Feature;

use App\Events\Chat\ChatUnread;
use App\Events\Chat\MessageCreated;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageUpdated;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * `GET|POST /api/conversations/{id}/messages`, `PATCH|DELETE /api/messages/{id}`
 * ve `GET /api/messages/search` — imleçli sayfalama, mezar taşı, düzenleme
 * kuralı, moderasyon ve yayınlar.
 */
class ChatMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function actor(array $permissions = ['chat.use']): User
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    /**
     * @param  array<int, User>  $members
     */
    protected function group(User $founder, array $members = []): Conversation
    {
        return Conversation::factory()
            ->group()
            ->createdBy($founder)
            ->withMembers(array_merge([$founder], $members))
            ->create(['name' => 'Ekip']);
    }

    // -------------------------------------------------------------------
    // Route sırası
    // -------------------------------------------------------------------

    public function test_search_resolves_before_the_message_parameter(): void
    {
        $actor = $this->actor();

        // `search` bir mesaj id'si sanılsaydı 404 gelirdi; 422 doğrulama
        // hatası rotanın DOĞRU controller'a düştüğünü kanıtlar.
        $this->actingAs($actor)
            ->getJson('/api/messages/search')
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Gönderme
    // -------------------------------------------------------------------

    public function test_sending_a_message_persists_broadcasts_and_bumps_the_conversation(): void
    {
        Event::fake([MessageCreated::class, ChatUnread::class]);

        $sender = $this->actor();
        $mate = $this->actor();
        $conversation = $this->group($sender, [$mate]);

        $response = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'Selam ekip'])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Selam ekip')
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.tick', 'sent')
            ->assertJsonPath('data.user.id', $sender->id);

        $this->assertDatabaseHas('messages', [
            'id' => $response->json('data.id'),
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
        ]);

        // Konuşma listesi sıralaması bu kolona dayanır.
        $this->assertNotNull($conversation->fresh()->last_message_at);

        Event::assertDispatched(MessageCreated::class);
        Event::assertDispatched(
            ChatUnread::class,
            fn (ChatUnread $event): bool => $event->recipientId === $mate->id
                && $event->conversationUnread === 1
                && $event->totalUnread === 1
                && $event->senderName === $sender->name
        );

        // Gönderene rozet olayı GİTMEZ.
        Event::assertNotDispatched(
            ChatUnread::class,
            fn (ChatUnread $event): bool => $event->recipientId === $sender->id
        );
    }

    public function test_a_muted_member_still_counts_unread_but_gets_no_badge_event(): void
    {
        Event::fake([ChatUnread::class]);

        $sender = $this->actor();
        $muted = $this->actor();
        $conversation = $this->group($sender, [$muted]);

        DB::table('conversation_user')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $muted->id)
            ->update(['is_muted' => true]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'Duyuru'])
            ->assertStatus(201);

        Event::assertNotDispatched(ChatUnread::class);

        $this->assertDatabaseHas('conversation_user', [
            'conversation_id' => $conversation->id,
            'user_id' => $muted->id,
            'unread_count' => 1,
        ]);
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $sender = $this->actor();
        $conversation = $this->group($sender);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => '   '])
            ->assertStatus(422);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [])
            ->assertStatus(422);
    }

    public function test_a_file_message_derives_its_type_and_exposes_the_attachment(): void
    {
        $sender = $this->actor();
        $conversation = $this->group($sender);

        $attachment = Attachment::factory()->create([
            'uploaded_by' => $sender->id,
            'mime_type' => 'image/png',
            'original_name' => 'ekran.png',
        ]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'attachment_id' => $attachment->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'file')
            ->assertJsonPath('data.attachment.original_name', 'ekran.png')
            ->assertJsonPath('data.attachment.is_image', true)
            ->assertJsonPath('data.attachment.url', '/api/attachments/'.$attachment->id);
    }

    public function test_someone_elses_attachment_cannot_be_attached(): void
    {
        $sender = $this->actor();
        $stranger = $this->actor();
        $conversation = $this->group($sender);

        $attachment = Attachment::factory()->create(['uploaded_by' => $stranger->id]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'attachment_id' => $attachment->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['attachment_id']]]);
    }

    public function test_a_client_cannot_forge_a_system_message(): void
    {
        $sender = $this->actor();
        $conversation = $this->group($sender);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'Sistem: herkes kovuldu',
                'type' => 'system',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'text');
    }

    public function test_a_non_member_cannot_post_and_gets_404(): void
    {
        $outsider = $this->actor();
        $conversation = $this->group($this->actor());

        $this->actingAs($outsider)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'Sızma'])
            ->assertStatus(404);

        $this->actingAs($outsider)
            ->getJson('/api/conversations/'.$conversation->id.'/messages')
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // İmleçli sayfalama
    // -------------------------------------------------------------------

    public function test_messages_are_returned_newest_first_with_a_cursor(): void
    {
        $actor = $this->actor();
        $conversation = $this->group($actor);

        $ids = [];

        for ($i = 1; $i <= 7; $i++) {
            $ids[] = Message::factory()
                ->inConversation($conversation)
                ->fromUser($actor)
                ->create(['body' => 'Mesaj '.$i])->id;
        }

        $first = $this->actingAs($actor)
            ->getJson('/api/conversations/'.$conversation->id.'/messages?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.has_more', true);

        $this->assertSame(array_reverse(array_slice($ids, 4)), array_column($first->json('data'), 'id'));

        $nextBefore = $first->json('meta.next_before');
        $this->assertSame($ids[4], $nextBefore);

        $second = $this->actingAs($actor)
            ->getJson('/api/conversations/'.$conversation->id.'/messages?per_page=3&before='.$nextBefore)
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame(array_reverse(array_slice($ids, 1, 3)), array_column($second->json('data'), 'id'));

        $last = $this->actingAs($actor)
            ->getJson('/api/conversations/'.$conversation->id.'/messages?per_page=3&before='.$second->json('meta.next_before'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_before', null);

        $this->assertSame([$ids[0]], array_column($last->json('data'), 'id'));
    }

    public function test_per_page_is_capped(): void
    {
        $actor = $this->actor();
        $conversation = $this->group($actor);

        $this->actingAs($actor)
            ->getJson('/api/conversations/'.$conversation->id.'/messages?per_page=500')
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Düzenleme
    // -------------------------------------------------------------------

    public function test_the_author_can_edit_their_own_text_message(): void
    {
        Event::fake([MessageUpdated::class]);

        $author = $this->actor();
        $conversation = $this->group($author, [$this->actor()]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($author)
            ->create(['body' => 'yanlsı']);

        $this->actingAs($author)
            ->patchJson('/api/messages/'.$message->id, ['body' => 'yanlış'])
            ->assertOk()
            ->assertJsonPath('data.body', 'yanlış');

        $this->assertNotNull($message->fresh()->edited_at);

        Event::assertDispatched(MessageUpdated::class);
    }

    public function test_nobody_can_edit_someone_elses_message(): void
    {
        $author = $this->actor();
        $mate = $this->actor();
        $conversation = $this->group($author, [$mate]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($author)
            ->create();

        $this->actingAs($mate)
            ->patchJson('/api/messages/'.$message->id, ['body' => 'değiştim'])
            ->assertStatus(403);
    }

    public function test_only_text_messages_can_be_edited(): void
    {
        $author = $this->actor();
        $conversation = $this->group($author);

        $attachment = Attachment::factory()->create(['uploaded_by' => $author->id]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($author)
            ->withAttachment($attachment)
            ->create();

        $this->actingAs($author)
            ->patchJson('/api/messages/'.$message->id, ['body' => 'başka bir şey'])
            ->assertStatus(403);
    }

    public function test_a_message_in_a_conversation_i_cannot_see_is_a_404(): void
    {
        $outsider = $this->actor();
        $conversation = $this->group($this->actor());

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($conversation->creator)
            ->create();

        $this->actingAs($outsider)
            ->patchJson('/api/messages/'.$message->id, ['body' => 'x'])
            ->assertStatus(404);

        $this->actingAs($outsider)
            ->deleteJson('/api/messages/'.$message->id)
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // Silme — mezar taşı
    // -------------------------------------------------------------------

    public function test_a_deleted_message_stays_in_the_list_with_masked_content(): void
    {
        Event::fake([MessageDeleted::class]);

        $author = $this->actor();
        $mate = $this->actor();
        $conversation = $this->group($author, [$mate]);

        $attachment = Attachment::factory()->create(['uploaded_by' => $author->id]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($author)
            ->withAttachment($attachment)
            ->create(['body' => 'gizli']);

        $this->actingAs($author)
            ->deleteJson('/api/messages/'.$message->id)
            ->assertStatus(204);

        $this->assertSoftDeleted('messages', ['id' => $message->id]);

        Event::assertDispatched(
            MessageDeleted::class,
            fn (MessageDeleted $event): bool => $event->messageId === $message->id
        );

        $row = $this->actingAs($mate)
            ->getJson('/api/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame($message->id, $row['id']);
        $this->assertNull($row['body']);
        $this->assertNull($row['attachment']);
        $this->assertNotNull($row['deleted_at']);
    }

    public function test_a_plain_member_cannot_delete_someone_elses_message(): void
    {
        $author = $this->actor();
        $mate = $this->actor();
        $conversation = $this->group($author, [$mate]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($author)
            ->create();

        $this->actingAs($mate)
            ->deleteJson('/api/messages/'.$message->id)
            ->assertStatus(403);
    }

    /**
     * Moderasyon `settings.manage` iznine DEĞİL, Super Admin rolüne bağlıdır.
     */
    public function test_settings_manage_does_not_grant_moderation_but_super_admin_does(): void
    {
        $author = $this->actor();
        $admin = $this->actor(['chat.use', 'settings.manage']);
        $conversation = $this->group($author, [$admin]);

        $message = Message::factory()
            ->inConversation($conversation)
            ->fromUser($author)
            ->create();

        $this->actingAs($admin)
            ->deleteJson('/api/messages/'.$message->id)
            ->assertStatus(403);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $this->actingAs($superAdmin)
            ->deleteJson('/api/messages/'.$message->id)
            ->assertStatus(204);

        $this->assertSoftDeleted('messages', ['id' => $message->id]);
    }

    // -------------------------------------------------------------------
    // Arama
    // -------------------------------------------------------------------

    public function test_search_only_looks_inside_my_own_conversations(): void
    {
        $actor = $this->actor();
        $stranger = $this->actor();

        $mine = $this->group($actor);
        $theirs = $this->group($stranger);

        $hit = Message::factory()
            ->inConversation($mine)
            ->fromUser($actor)
            ->create(['body' => 'sözleşme taslağı hazır']);

        Message::factory()
            ->inConversation($theirs)
            ->fromUser($stranger)
            ->create(['body' => 'sözleşme gizli']);

        $data = $this->actingAs($actor)
            ->getJson('/api/messages/search?q=sözleşme')
            ->assertOk()
            ->json('data');

        $this->assertSame([$hit->id], array_column($data, 'id'));
    }

    public function test_search_excludes_tombstones_and_can_be_narrowed_to_one_conversation(): void
    {
        $actor = $this->actor();
        $a = $this->group($actor);
        $b = $this->group($actor);

        $kept = Message::factory()->inConversation($a)->fromUser($actor)->create(['body' => 'rapor a']);
        Message::factory()->inConversation($b)->fromUser($actor)->create(['body' => 'rapor b']);
        Message::factory()->inConversation($a)->fromUser($actor)->create(['body' => 'rapor silinmiş'])->delete();

        $all = $this->actingAs($actor)->getJson('/api/messages/search?q=rapor')->assertOk()->json('data');
        $this->assertCount(2, $all);

        $narrowed = $this->actingAs($actor)
            ->getJson('/api/messages/search?q=rapor&conversation_id='.$a->id)
            ->assertOk()
            ->json('data');

        $this->assertSame([$kept->id], array_column($narrowed, 'id'));
    }

    public function test_search_requires_at_least_two_characters(): void
    {
        $this->actingAs($this->actor())
            ->getJson('/api/messages/search?q=a')
            ->assertStatus(422);
    }
}
