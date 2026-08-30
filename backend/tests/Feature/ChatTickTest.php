<?php

namespace Tests\Feature;

use App\Events\Chat\MessageDelivered;
use App\Events\Chat\MessageRead;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * =============================================================================
 * ÇİFT TİK DURUM MAKİNESİ
 * =============================================================================
 *
 * `sent` -> `delivered` -> `read`, üçü de `conversation_user` pivotundaki iki
 * imleçten TÜRETİLİR; mesaj başına durum satırı YOKTUR. Bu dosya sözleşmenin
 * tamamını sabitler: geçişler, "en az bir diğer katılımcı" grup kuralı,
 * imlecin geri gitmemesi, okunmamış sayacının yeniden hesaplanması ve tik
 * hesabının mesaj sayısından BAĞIMSIZ sorgu maliyeti.
 */
class ChatTickTest extends TestCase
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

    protected function firstTick(User $viewer, Conversation $conversation): string
    {
        return $this->actingAs($viewer)
            ->getJson('/api/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->json('data.0.tick');
    }

    // -------------------------------------------------------------------
    // Üç durum
    // -------------------------------------------------------------------

    public function test_a_fresh_message_is_sent_then_delivered_then_read(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        $messageId = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'Merhaba'])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertSame('sent', $this->firstTick($sender, $conversation));

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/delivered', ['message_id' => $messageId])
            ->assertOk()
            ->assertJsonPath('data.last_delivered_message_id', $messageId);

        $this->assertSame('delivered', $this->firstTick($sender, $conversation));

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $messageId])
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $messageId)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame('read', $this->firstTick($sender, $conversation));
    }

    /**
     * Tik göstergesi yalnızca KENDİ mesajının sorusudur.
     */
    public function test_someone_elses_message_always_reads_as_sent(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        $messageId = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'Merhaba'])
            ->json('data.id');

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $messageId])
            ->assertOk();

        // Gönderende `read`, alıcıda `sent`.
        $this->assertSame('read', $this->firstTick($sender, $conversation));
        $this->assertSame('sent', $this->firstTick($receiver, $conversation));
    }

    /**
     * Okuma iletimi İMA EDER — "okundu ama iletilmedi" imkânsız bir ara
     * durumdur ve tek `UPDATE` içinde kapatılır.
     */
    public function test_marking_as_read_also_advances_the_delivery_cursor(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        $messageId = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'x'])
            ->json('data.id');

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $messageId])
            ->assertOk()
            ->assertJsonPath('data.last_delivered_message_id', $messageId);
    }

    /**
     * Yolda kalmış eski bir istek imleci GERİ ÇEKEMEZ.
     */
    public function test_a_stale_cursor_request_cannot_move_the_pointer_backwards(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        $old = Message::factory()->inConversation($conversation)->fromUser($sender)->create();
        $new = Message::factory()->inConversation($conversation)->fromUser($sender)->create();

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $new->id);

        // Gecikmiş "eskiye kadar okudum" isteği ETKİSİZ olmalı.
        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $old->id])
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $new->id)
            ->assertJsonPath('data.unread_count', 0);
    }

    /**
     * Grup kuralı: EN AZ BİR diğer katılımcı yeter.
     */
    public function test_one_other_member_is_enough_in_a_group(): void
    {
        $sender = $this->actor();
        $quick = $this->actor();
        $slow = $this->actor();
        $conversation = $this->group($sender, [$quick, $slow]);

        $messageId = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'Duyuru'])
            ->json('data.id');

        $this->actingAs($quick)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $messageId])
            ->assertOk();

        // `slow` hiç okumadı ama tik yine de `read`.
        $this->assertSame('read', $this->firstTick($sender, $conversation));
    }

    // -------------------------------------------------------------------
    // Okunmamış sayacı
    // -------------------------------------------------------------------

    public function test_unread_increments_for_others_and_stays_zero_for_the_sender(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        foreach (['bir', 'iki', 'üç'] as $body) {
            $this->actingAs($sender)
                ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => $body])
                ->assertStatus(201);
        }

        $this->assertDatabaseHas('conversation_user', [
            'conversation_id' => $conversation->id,
            'user_id' => $receiver->id,
            'unread_count' => 3,
        ]);

        $this->assertDatabaseHas('conversation_user', [
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'unread_count' => 0,
        ]);
    }

    /**
     * Kısmî okuma sayacı SIFIRLAMAZ, yeniden HESAPLAR.
     */
    public function test_reading_up_to_an_older_message_leaves_the_rest_unread(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        $ids = [];

        foreach (range(1, 5) as $i) {
            $ids[] = $this->actingAs($sender)
                ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'm'.$i])
                ->json('data.id');
        }

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read', ['message_id' => $ids[2]])
            ->assertOk()
            // 4. ve 5. mesajlar hâlâ okunmamış.
            ->assertJsonPath('data.unread_count', 2);
    }

    /**
     * Kendi mesajın kendi okunmamışını artırmaz — sayaç yalnızca BAŞKASININ
     * mesajlarını sayar.
     */
    public function test_partial_read_ignores_my_own_messages(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $conversation = $this->group($a, [$b]);

        Message::factory()->inConversation($conversation)->fromUser($b)->create();
        Message::factory()->inConversation($conversation)->fromUser($a)->create();
        Message::factory()->inConversation($conversation)->fromUser($a)->create();

        $this->actingAs($a)
            ->postJson('/api/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_delivered_does_not_clear_the_unread_badge(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', ['body' => 'x'])
            ->assertStatus(201);

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/delivered')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    /**
     * `message_id` gönderilmezse konuşmanın EN SON mesajı kabul edilir.
     */
    public function test_the_cursor_defaults_to_the_latest_message(): void
    {
        $sender = $this->actor();
        $receiver = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        Message::factory()->inConversation($conversation)->fromUser($sender)->create();
        $latest = Message::factory()->inConversation($conversation)->fromUser($sender)->create();

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', $latest->id)
            ->assertJsonPath('data.unread_count', 0);
    }

    /**
     * Mesaj id'leri GLOBAL bir dizidir; başka bir konuşmadan alınan id ile
     * imleç ileri taşınamaz.
     */
    public function test_a_message_from_another_conversation_is_rejected(): void
    {
        $actor = $this->actor();
        $mine = $this->group($actor, [$this->actor()]);
        $other = $this->group($this->actor());

        $foreign = Message::factory()->inConversation($other)->fromUser($other->creator)->create();

        $this->actingAs($actor)
            ->postJson('/api/conversations/'.$mine->id.'/read', ['message_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['message_id']]]);
    }

    public function test_cursor_endpoints_broadcast_and_refuse_non_members(): void
    {
        Event::fake([MessageRead::class, MessageDelivered::class]);

        $sender = $this->actor();
        $receiver = $this->actor();
        $outsider = $this->actor();
        $conversation = $this->group($sender, [$receiver]);

        Message::factory()->inConversation($conversation)->fromUser($sender)->create();

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/read')
            ->assertOk();

        $this->actingAs($receiver)
            ->postJson('/api/conversations/'.$conversation->id.'/delivered')
            ->assertOk();

        Event::assertDispatched(
            MessageRead::class,
            fn (MessageRead $event): bool => $event->userId === $receiver->id
        );
        Event::assertDispatched(MessageDelivered::class);

        // Üyesi olmadığın konuşmada imleç ilerletilemez — ve varlığı sızmaz.
        $this->actingAs($outsider)
            ->postJson('/api/conversations/'.$conversation->id.'/read')
            ->assertStatus(404);
    }

    /**
     * Mesajı olmayan konuşmada imleç ucu patlamaz.
     */
    public function test_marking_an_empty_conversation_is_a_no_op(): void
    {
        $actor = $this->actor();
        $conversation = $this->group($actor, [$this->actor()]);

        $this->actingAs($actor)
            ->postJson('/api/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.last_read_message_id', 0);
    }

    // -------------------------------------------------------------------
    // N+1 YOK — tik maliyeti mesaj sayısından BAĞIMSIZ
    // -------------------------------------------------------------------

    /**
     * Asıl değişmez şudur: mesaj sayısını beş katına çıkarmak sorgu sayısını
     * DEĞİŞTİRMEZ. Sabit bir üst sınır iddia etmek (ör. "<= 8") çerçeve
     * sürümüne ve auth katmanına bağımlı kırılgan bir testtir; oran ise
     * doğrudan N+1'in tanımını ölçer.
     */
    public function test_the_message_list_query_count_does_not_grow_with_the_number_of_messages(): void
    {
        $actor = $this->actor();
        $a = $this->actor();
        $b = $this->actor();

        $small = $this->group($actor, [$a, $b]);
        $large = $this->group($actor, [$a, $b]);

        foreach (range(1, 5) as $i) {
            Message::factory()->inConversation($small)->fromUser($i % 2 === 0 ? $a : $b)->create();
        }

        foreach (range(1, 45) as $i) {
            Message::factory()->inConversation($large)->fromUser($i % 2 === 0 ? $a : $b)->create();
        }

        $count = function (Conversation $conversation) use ($actor): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($actor)
                ->getJson('/api/conversations/'.$conversation->id.'/messages?per_page=50')
                ->assertOk();

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Isınma turu: spatie'nin rol/izin önbelleği İLK yetkilendirmede iki
        // ek sorgu atar (`model_has_roles` + `model_has_permissions`). Bu
        // maliyet mesaj sayısıyla ilgisizdir; ölçüme karışmasın diye önce
        // önbellek doldurulur.
        $count($small);

        $withFive = $count($small);
        $withFortyFive = $count($large);

        $this->assertSame(
            $withFive,
            $withFortyFive,
            'Mesaj listesi N+1 üretiyor: sorgu sayısı mesaj sayısıyla birlikte arttı.'
        );
    }

    /**
     * Konuşma listesi de aynı değişmeze tabidir (üyeler, son mesaj, ek).
     */
    public function test_the_conversation_list_query_count_does_not_grow_with_the_number_of_conversations(): void
    {
        $actor = $this->actor();
        $mate = $this->actor();

        $count = function () use ($actor): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($actor)->getJson('/api/conversations?per_page=100')->assertOk();

            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $seed = function (int $howMany) use ($actor, $mate): void {
            foreach (range(1, $howMany) as $i) {
                $conversation = $this->group($actor, [$mate]);
                Message::factory()->inConversation($conversation)->fromUser($mate)->create();
            }
        };

        $seed(2);
        // Isınma turu — gerekçe yukarıdaki testte.
        $count();
        $withTwo = $count();

        $seed(10);
        $withTwelve = $count();

        $this->assertSame(
            $withTwo,
            $withTwelve,
            'Konuşma listesi N+1 üretiyor: sorgu sayısı konuşma sayısıyla birlikte arttı.'
        );
    }
}
