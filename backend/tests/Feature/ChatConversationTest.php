<?php

namespace Tests\Feature;

use App\Events\Chat\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * `GET|POST|PATCH|DELETE /api/conversations*` — uç sözleşmesi, üç tipin iş
 * kuralları (dm / group / record), sahiplik modeli ve IDOR davranışı.
 *
 * Tik makinesi ChatTickTest'te, mesaj uçları ChatMessageTest'te, bahsetme
 * (mention) ChatMentionTest'tedir.
 */
class ChatConversationTest extends TestCase
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
    protected function group(User $founder, array $members = [], ?string $name = 'Satış Ekibi'): Conversation
    {
        return Conversation::factory()
            ->group()
            ->createdBy($founder)
            ->withMembers(array_merge([$founder], $members))
            ->create(['name' => $name]);
    }

    // -------------------------------------------------------------------
    // Kimlik doğrulama / modül kapısı
    // -------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/conversations')->assertStatus(401);
        $this->getJson('/api/conversations/unread-count')->assertStatus(401);
    }

    public function test_user_without_chat_permission_is_forbidden(): void
    {
        $actor = $this->actor([]);

        $this->actingAs($actor)->getJson('/api/conversations')->assertStatus(403);
    }

    /**
     * Viewer rolünde `chat.use` KASITLI olarak yoktur (Faz 2 izin sözlüğü).
     */
    public function test_viewer_role_cannot_use_chat(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('İzleyici');

        $this->actingAs($viewer)->getJson('/api/conversations')->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Route sırası — sabit segmentler {conversation}'dan ÖNCE
    // -------------------------------------------------------------------

    public function test_fixed_segments_resolve_before_the_conversation_parameter(): void
    {
        $actor = $this->actor();

        $this->actingAs($actor)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.total_unread', 0);

        // `for-record` de bir konuşma id'si sanılmamalı: geçersiz gövdeyle
        // 422 (doğrulama) dönmesi rotanın DOĞRU controller'a düştüğünü
        // kanıtlar; 404 dönseydi `{conversation}` yakalamış olurdu.
        $this->actingAs($actor)
            ->postJson('/api/conversations/for-record', [])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------
    // Listeleme
    // -------------------------------------------------------------------

    public function test_index_returns_only_conversations_the_user_belongs_to(): void
    {
        $actor = $this->actor();
        $other = $this->actor();

        $mine = $this->group($actor, [$other]);
        $theirs = $this->group($other);

        $response = $this->actingAs($actor)->getJson('/api/conversations')->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_index_orders_by_last_message_and_puts_silent_conversations_last(): void
    {
        $actor = $this->actor();

        $silent = $this->group($actor, [], 'Sessiz');
        $older = $this->group($actor, [], 'Eski');
        $newer = $this->group($actor, [], 'Yeni');

        $older->forceFill(['last_message_at' => now()->subDay()])->save();
        $newer->forceFill(['last_message_at' => now()])->save();

        $ids = array_column(
            $this->actingAs($actor)->getJson('/api/conversations')->assertOk()->json('data'),
            'id'
        );

        $this->assertSame([$newer->id, $older->id, $silent->id], $ids);
    }

    public function test_index_filters_by_type_and_searches_the_other_party(): void
    {
        $actor = $this->actor();
        $partner = $this->actor();
        $partner->forceFill(['name' => 'Zeynep Kara'])->save();

        $dm = Conversation::factory()->dm()->withMembers([$actor, $partner])->create();
        $group = $this->group($actor, [], 'Proje Koordinasyon');

        $typed = $this->actingAs($actor)
            ->getJson('/api/conversations?filter[type]=dm')
            ->assertOk()
            ->json('data');

        $this->assertSame([$dm->id], array_column($typed, 'id'));

        // `dm` konuşmanın `name` kolonu boştur — adıyla bulunabilmesinin tek
        // yolu üye tablosuna bakmaktır.
        $searched = $this->actingAs($actor)
            ->getJson('/api/conversations?q=Zeynep')
            ->assertOk()
            ->json('data');

        $this->assertSame([$dm->id], array_column($searched, 'id'));

        $byName = $this->actingAs($actor)
            ->getJson('/api/conversations?q=Koordinasyon')
            ->assertOk()
            ->json('data');

        $this->assertSame([$group->id], array_column($byName, 'id'));
    }

    public function test_conversation_payload_carries_the_full_contract(): void
    {
        $actor = $this->actor();
        $partner = $this->actor();

        $conversation = Conversation::factory()->dm()->withMembers([$actor, $partner])->create();
        Message::factory()
            ->inConversation($conversation)
            ->fromUser($partner)
            ->create(['body' => 'Merhaba']);

        $conversation->forceFill(['last_message_at' => now()])->save();

        $this->actingAs($actor)
            ->getJson('/api/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'type', 'name', 'display_name', 'conversable', 'created_by',
                'last_message_at', 'last_message_preview', 'unread_count', 'is_muted',
                'members' => [['id', 'name', 'email']],
            ]])
            // dm'de başlık KARŞI TARAFIN adıdır.
            ->assertJsonPath('data.display_name', $partner->name)
            ->assertJsonPath('data.last_message_preview', 'Merhaba');
    }

    // -------------------------------------------------------------------
    // dm — get-or-create, tam iki kişi
    // -------------------------------------------------------------------

    public function test_creating_a_dm_attaches_both_participants(): void
    {
        $actor = $this->actor();
        $partner = $this->actor();

        $response = $this->actingAs($actor)
            ->postJson('/api/conversations', ['type' => 'dm', 'member_ids' => [$partner->id]])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'dm');

        $conversation = Conversation::find($response->json('data.id'));

        $this->assertTrue($conversation->hasMember($actor->id));
        $this->assertTrue($conversation->hasMember($partner->id));
        $this->assertCount(2, $conversation->users);
    }

    public function test_a_second_dm_between_the_same_two_people_returns_the_existing_one(): void
    {
        $actor = $this->actor();
        $partner = $this->actor();

        $first = $this->actingAs($actor)
            ->postJson('/api/conversations', ['type' => 'dm', 'member_ids' => [$partner->id]])
            ->assertStatus(201)
            ->json('data.id');

        // Ters yönden açılsa bile aynı konuşma dönmeli.
        $second = $this->actingAs($partner)
            ->postJson('/api/conversations', ['type' => 'dm', 'member_ids' => [$actor->id]])
            ->assertOk()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::where('type', 'dm')->count());
    }

    public function test_a_dm_must_have_exactly_one_other_participant(): void
    {
        $actor = $this->actor();

        $this->actingAs($actor)
            ->postJson('/api/conversations', [
                'type' => 'dm',
                'member_ids' => [$this->actor()->id, $this->actor()->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR');
    }

    public function test_a_user_without_chat_permission_cannot_be_added_to_a_conversation(): void
    {
        $actor = $this->actor();
        $viewer = $this->actor([]);

        $this->actingAs($actor)
            ->postJson('/api/conversations', ['type' => 'dm', 'member_ids' => [$viewer->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['fields' => ['member_ids']]]);
    }

    // -------------------------------------------------------------------
    // group — sahiplik modeli
    // -------------------------------------------------------------------

    public function test_creating_a_group_makes_the_creator_owner_and_member(): void
    {
        $actor = $this->actor();
        $mate = $this->actor();

        $response = $this->actingAs($actor)
            ->postJson('/api/conversations', [
                'type' => 'group',
                'name' => 'Yeni Ekip',
                'member_ids' => [$mate->id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.created_by', $actor->id)
            ->assertJsonPath('data.display_name', 'Yeni Ekip');

        $conversation = Conversation::find($response->json('data.id'));

        $this->assertTrue($conversation->hasMember($actor->id));
        $this->assertTrue($conversation->hasMember($mate->id));
    }

    public function test_a_group_without_a_name_is_rejected(): void
    {
        $actor = $this->actor();

        $this->actingAs($actor)
            ->postJson('/api/conversations', ['type' => 'group', 'member_ids' => [$this->actor()->id]])
            ->assertStatus(422);
    }

    public function test_only_the_founder_can_rename_a_group(): void
    {
        $founder = $this->actor();
        $member = $this->actor();
        $group = $this->group($founder, [$member]);

        Event::fake([ConversationUpdated::class]);

        $this->actingAs($member)
            ->patchJson('/api/conversations/'.$group->id, ['name' => 'Ele Geçirildi'])
            ->assertStatus(403);

        $this->actingAs($founder)
            ->patchJson('/api/conversations/'.$group->id, ['name' => 'Yeni Ad'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Yeni Ad');

        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_a_dm_cannot_be_renamed_or_archived(): void
    {
        $actor = $this->actor();
        $partner = $this->actor();
        $dm = Conversation::factory()->dm()->withMembers([$actor, $partner])->create();

        $this->actingAs($actor)
            ->patchJson('/api/conversations/'.$dm->id, ['name' => 'Olmaz'])
            ->assertStatus(403);

        $this->actingAs($actor)
            ->deleteJson('/api/conversations/'.$dm->id)
            ->assertStatus(403);
    }

    public function test_only_the_founder_can_archive_a_group(): void
    {
        $founder = $this->actor();
        $member = $this->actor();
        $group = $this->group($founder, [$member]);

        $this->actingAs($member)
            ->deleteJson('/api/conversations/'.$group->id)
            ->assertStatus(403);

        $this->actingAs($founder)
            ->deleteJson('/api/conversations/'.$group->id)
            ->assertStatus(204);

        $this->assertSoftDeleted('conversations', ['id' => $group->id]);
    }

    // -------------------------------------------------------------------
    // Üyelik
    // -------------------------------------------------------------------

    public function test_any_member_can_add_a_member_but_only_the_founder_can_remove_one(): void
    {
        $founder = $this->actor();
        $member = $this->actor();
        $newcomer = $this->actor();
        $group = $this->group($founder, [$member]);

        // Ekleme: herhangi bir üye.
        $this->actingAs($member)
            ->postJson('/api/conversations/'.$group->id.'/members', ['user_ids' => [$newcomer->id]])
            ->assertOk();

        $this->assertTrue($group->fresh()->hasMember($newcomer->id));

        // Çıkarma: yalnızca kurucu.
        $this->actingAs($member)
            ->deleteJson('/api/conversations/'.$group->id.'/members/'.$newcomer->id)
            ->assertStatus(403);

        $this->actingAs($founder)
            ->deleteJson('/api/conversations/'.$group->id.'/members/'.$newcomer->id)
            ->assertOk();

        $this->assertFalse($group->fresh()->hasMember($newcomer->id));
    }

    public function test_adding_a_user_without_chat_permission_is_a_validation_error(): void
    {
        $founder = $this->actor();
        $viewer = $this->actor([]);
        $group = $this->group($founder);

        $this->actingAs($founder)
            ->postJson('/api/conversations/'.$group->id.'/members', ['user_ids' => [$viewer->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['user_ids']]]);
    }

    public function test_the_founder_cannot_be_removed_as_a_member(): void
    {
        $founder = $this->actor();
        $group = $this->group($founder, [$this->actor()]);

        $this->actingAs($founder)
            ->deleteJson('/api/conversations/'.$group->id.'/members/'.$founder->id)
            ->assertStatus(422);
    }

    public function test_members_cannot_be_managed_on_a_dm(): void
    {
        $actor = $this->actor();
        $partner = $this->actor();
        $dm = Conversation::factory()->dm()->withMembers([$actor, $partner])->create();

        $this->actingAs($actor)
            ->postJson('/api/conversations/'.$dm->id.'/members', ['user_ids' => [$this->actor()->id]])
            ->assertStatus(403);

        $this->actingAs($actor)
            ->postJson('/api/conversations/'.$dm->id.'/leave')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------
    // Ayrılma + sahiplik devri
    // -------------------------------------------------------------------

    public function test_when_the_founder_leaves_ownership_passes_to_the_oldest_member(): void
    {
        $founder = $this->actor();
        $oldest = $this->actor();
        $newest = $this->actor();

        // withMembers() `joined_at`'i sırayla artırır: founder < oldest < newest.
        $group = $this->group($founder, [$oldest, $newest]);

        $this->actingAs($founder)
            ->postJson('/api/conversations/'.$group->id.'/leave')
            ->assertStatus(204);

        $group->refresh();

        $this->assertFalse($group->hasMember($founder->id));
        $this->assertSame($oldest->id, (int) $group->created_by);
        $this->assertNull($group->deleted_at);
    }

    public function test_the_last_member_leaving_archives_the_group(): void
    {
        $founder = $this->actor();
        $group = $this->group($founder);

        $this->actingAs($founder)
            ->postJson('/api/conversations/'.$group->id.'/leave')
            ->assertStatus(204);

        $this->assertSoftDeleted('conversations', ['id' => $group->id]);
    }

    // -------------------------------------------------------------------
    // Susturma
    // -------------------------------------------------------------------

    public function test_muting_is_personal(): void
    {
        $actor = $this->actor();
        $mate = $this->actor();
        $group = $this->group($actor, [$mate]);

        $this->actingAs($actor)
            ->patchJson('/api/conversations/'.$group->id.'/mute', ['is_muted' => true])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true);

        $this->assertDatabaseHas('conversation_user', [
            'conversation_id' => $group->id,
            'user_id' => $actor->id,
            'is_muted' => true,
        ]);

        $this->assertDatabaseHas('conversation_user', [
            'conversation_id' => $group->id,
            'user_id' => $mate->id,
            'is_muted' => false,
        ]);
    }

    // -------------------------------------------------------------------
    // IDOR — üyesi olmadığın konuşma 404 (403 DEĞİL)
    // -------------------------------------------------------------------

    public function test_a_non_member_gets_404_not_403(): void
    {
        $outsider = $this->actor();
        $group = $this->group($this->actor());

        $this->actingAs($outsider)
            ->getJson('/api/conversations/'.$group->id)
            ->assertStatus(404)
            ->assertJsonPath('errors.code', 'NOT_FOUND');

        // Var olmayan bir id ile BİREBİR aynı yanıt — varlık sızmaz.
        $this->actingAs($outsider)
            ->getJson('/api/conversations/999999')
            ->assertStatus(404)
            ->assertJsonPath('errors.code', 'NOT_FOUND');
    }

    // -------------------------------------------------------------------
    // record — presence-record.{type}.{id} ile AYNI kural
    // -------------------------------------------------------------------

    public function test_for_record_creates_once_and_auto_joins_the_viewer(): void
    {
        $actor = $this->actor(['chat.use', 'deals.view']);
        $mate = $this->actor(['chat.use', 'deals.view']);
        $deal = Deal::factory()->create();

        $first = $this->actingAs($actor)
            ->postJson('/api/conversations/for-record', [
                'conversable_type' => 'deal',
                'conversable_id' => $deal->id,
            ])
            // İlk açılış YENİ satır yazar.
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'record')
            ->assertJsonPath('data.conversable.type', 'deal')
            ->assertJsonPath('data.conversable.id', $deal->id)
            ->json('data.id');

        $second = $this->actingAs($mate)
            ->postJson('/api/conversations/for-record', [
                'conversable_type' => 'deal',
                'conversable_id' => $deal->id,
            ])
            // İkinci açılış VAR OLANI döner — get-or-create.
            ->assertOk()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::where('type', 'record')->count());

        $conversation = Conversation::find($first);
        $this->assertTrue($conversation->hasMember($actor->id));
        $this->assertTrue($conversation->hasMember($mate->id));
    }

    public function test_for_record_requires_the_modules_own_view_permission(): void
    {
        $actor = $this->actor(); // chat.use var, deals.view YOK
        $deal = Deal::factory()->create();

        $this->actingAs($actor)
            ->postJson('/api/conversations/for-record', [
                'conversable_type' => 'deal',
                'conversable_id' => $deal->id,
            ])
            ->assertStatus(403);
    }

    public function test_for_record_rejects_types_outside_the_whitelist_and_missing_records(): void
    {
        $actor = $this->actor(['chat.use', 'deals.view', 'contacts.view']);

        // `contact` kanal sözlüğünde var ama SOHBET beyaz listesinde yok.
        $this->actingAs($actor)
            ->postJson('/api/conversations/for-record', [
                'conversable_type' => 'contact',
                'conversable_id' => 1,
            ])
            ->assertStatus(422);

        // Sınıf enjeksiyonu denemesi.
        $this->actingAs($actor)
            ->postJson('/api/conversations/for-record', [
                'conversable_type' => 'App\\Models\\User',
                'conversable_id' => 1,
            ])
            ->assertStatus(422);

        // Var olmayan kayıt.
        $this->actingAs($actor)
            ->postJson('/api/conversations/for-record', [
                'conversable_type' => 'deal',
                'conversable_id' => 999999,
            ])
            ->assertStatus(422);
    }

    public function test_a_record_conversation_is_visible_to_anyone_who_can_see_the_record(): void
    {
        $owner = $this->actor(['chat.use', 'deals.view']);
        $colleague = $this->actor(['chat.use', 'deals.view']);
        $stranger = $this->actor(); // deals.view YOK

        $deal = Deal::factory()->create();

        $conversation = Conversation::factory()
            ->record($deal)
            ->createdBy($owner)
            ->withMembers([$owner])
            ->create();

        // Üye DEĞİL ama kaydı görebiliyor.
        $this->actingAs($colleague)
            ->getJson('/api/conversations/'.$conversation->id)
            ->assertOk();

        // Kaydı göremeyen için konuşma YOK.
        $this->actingAs($stranger)
            ->getJson('/api/conversations/'.$conversation->id)
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------
    // Okunmamış toplamı
    // -------------------------------------------------------------------

    public function test_unread_count_sums_every_conversation(): void
    {
        $actor = $this->actor();
        $mate = $this->actor();

        $a = $this->group($actor, [$mate], 'A');
        $b = $this->group($actor, [$mate], 'B');

        DB::table('conversation_user')
            ->where('user_id', $actor->id)
            ->whereIn('conversation_id', [$a->id, $b->id])
            ->update(['unread_count' => 3]);

        $this->actingAs($actor)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJsonPath('data.total_unread', 6)
            ->assertJsonPath('data.conversation_count', 2);
    }
}
