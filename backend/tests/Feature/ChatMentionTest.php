<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Support\NotificationText;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * =============================================================================
 * BAHSETME (@mention) — `mentions: [user_id]`, METİN AYRIŞTIRMA YOK
 * =============================================================================
 *
 * Sözleşme: `body` görüntülenecek metni taşır ve içinde `@Ad Soyad` geçebilir;
 * sunucu bu metni OKUMAZ. Bildirimin tek kaynağı istemcinin gönderdiği
 * `mentions` kullanıcı id dizisidir (gerekçe: App\Services\Chat\
 * MentionResolver dokümanı — sınır/çakışma/sessiz başarısızlık).
 *
 * Bildirimler Faz 10 altyapısını kullanır: `CrmNotification` taban sınıfı,
 * `notifications` tablosu, `{type,link,meta}` + (Faz 14 / İz D'den itibaren)
 * `title_key`/`body_key`/`params` payload sözleşmesi ve tek gönderim kapısı
 * `NotificationDispatcher`. `title`/`body` artık DB satırında saklanmaz —
 * bu dosyadaki testler ham `data`'yı `NotificationText::resolve()` ile aynı
 * (uygulama varsayılanı `tr`) dilde render edip iddialarını ona karşı yapar,
 * `NotificationResource`'un okuma anında yaptığıyla birebir aynı yolu izleyerek.
 */
class ChatMentionTest extends TestCase
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
            ->create(['name' => 'Satış Ekibi']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function chatNotificationsFor(User $user): array
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->get()
            ->map(fn ($row): array => json_decode($row->data, true))
            ->filter(fn (array $data): bool => ($data['type'] ?? null) === 'chat.mention')
            ->values()
            ->all();
    }

    /**
     * `notifications.data`'nın anahtar+parametresini `NotificationResource` ile AYNI
     * yolla (`NotificationText::resolve()`) okuma-anı metnine çevirir — bkz. sınıf
     * dokümanı.
     *
     * @param  array<string, mixed>  $data
     * @return array{title: ?string, body: ?string}
     */
    protected function resolvedText(array $data): array
    {
        return NotificationText::resolve($data, app()->getLocale());
    }

    // -------------------------------------------------------------------

    public function test_a_mentioned_member_receives_a_chat_mention_notification(): void
    {
        $sender = $this->actor(['chat.use', 'notifications.view']);
        $mentioned = $this->actor(['chat.use', 'notifications.view']);
        $bystander = $this->actor(['chat.use', 'notifications.view']);

        $conversation = $this->group($sender, [$mentioned, $bystander]);

        $messageId = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                // Gövde metni AYRIŞTIRILMAZ; yalnızca gösterilir.
                'body' => '@'.$mentioned->name.' bu teklife bakar mısın?',
                'mentions' => [$mentioned->id],
            ])
            ->assertStatus(201)
            ->json('data.id');

        $notifications = $this->chatNotificationsFor($mentioned);

        $this->assertCount(1, $notifications);
        $this->assertSame('chat.mention', $notifications[0]['type']);
        $this->assertSame('/chat/'.$conversation->id, $notifications[0]['link']);
        $this->assertStringContainsString($sender->name, $this->resolvedText($notifications[0])['title']);
        $this->assertSame($conversation->id, $notifications[0]['meta']['conversation_id']);
        $this->assertSame($messageId, $notifications[0]['meta']['message_id']);
        $this->assertSame($sender->id, $notifications[0]['meta']['actor_id']);

        // Bahsedilmeyen üyeye bildirim GİTMEZ.
        $this->assertCount(0, $this->chatNotificationsFor($bystander));
    }

    /**
     * Metin ayrıştırılmadığı için `@Ad` yazıp `mentions` göndermemek bildirim
     * ÜRETMEZ — sözleşmenin en görünür sonucu budur.
     */
    public function test_an_at_sign_in_the_body_alone_produces_no_notification(): void
    {
        $sender = $this->actor();
        $mentioned = $this->actor();
        $conversation = $this->group($sender, [$mentioned]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => '@'.$mentioned->name.' bakabilir misin?',
            ])
            ->assertStatus(201);

        $this->assertCount(0, $this->chatNotificationsFor($mentioned));
    }

    public function test_mentioning_yourself_produces_no_notification(): void
    {
        $sender = $this->actor();
        $conversation = $this->group($sender, [$this->actor()]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'kendime not: @ben',
                'mentions' => [$sender->id],
            ])
            ->assertStatus(201);

        $this->assertCount(0, $this->chatNotificationsFor($sender));
    }

    /**
     * Konuşmanın üyesi OLMAYAN biri `mentions` ile hedeflenemez: bildirim
     * gövdesi mesaj metnini alıntıladığı için bu, göremeyeceği bir sohbetin
     * içeriğini ona ulaştıran bir sızıntı kanalı olurdu.
     */
    public function test_a_non_member_cannot_be_mentioned(): void
    {
        $sender = $this->actor();
        $outsider = $this->actor();
        $conversation = $this->group($sender, [$this->actor()]);

        // Mesaj REDDEDİLMEZ (422 değil), yalnızca bildirim üretilmez —
        // istemci ile sunucu arasında üyelik yarışı olabilir.
        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'gizli konu @dışarıdaki',
                'mentions' => [$outsider->id],
            ])
            ->assertStatus(201);

        $this->assertCount(0, $this->chatNotificationsFor($outsider));
    }

    public function test_a_muted_member_is_not_notified(): void
    {
        $sender = $this->actor();
        $muted = $this->actor();
        $conversation = $this->group($sender, [$muted]);

        $this->actingAs($muted)
            ->patchJson('/api/conversations/'.$conversation->id.'/mute', ['is_muted' => true])
            ->assertOk();

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => '@sessiz bakar mısın',
                'mentions' => [$muted->id],
            ])
            ->assertStatus(201);

        $this->assertCount(0, $this->chatNotificationsFor($muted));
    }

    public function test_an_inactive_user_is_not_notified(): void
    {
        $sender = $this->actor();
        $inactive = $this->actor();
        $conversation = $this->group($sender, [$inactive]);

        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => '@pasif',
                'mentions' => [$inactive->id],
            ])
            ->assertStatus(201);

        $this->assertCount(0, $this->chatNotificationsFor($inactive));
    }

    public function test_several_people_can_be_mentioned_at_once(): void
    {
        $sender = $this->actor();
        $first = $this->actor();
        $second = $this->actor();
        $conversation = $this->group($sender, [$first, $second]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => '@bir @iki toplantı 14:00',
                'mentions' => [$first->id, $second->id],
            ])
            ->assertStatus(201);

        $this->assertCount(1, $this->chatNotificationsFor($first));
        $this->assertCount(1, $this->chatNotificationsFor($second));
    }

    public function test_an_unknown_user_id_is_a_validation_error(): void
    {
        $sender = $this->actor();
        $conversation = $this->group($sender, [$this->actor()]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'merhaba',
                'mentions' => [999999],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['fields' => ['mentions.0']]]);
    }

    /**
     * Düzenleme YENİ bildirim üretmez — aksi halde aynı mesajı defalarca
     * düzenleyerek birini istediği kadar dürtmek mümkün olurdu.
     */
    public function test_editing_a_message_does_not_re_notify(): void
    {
        $sender = $this->actor();
        $mentioned = $this->actor();
        $conversation = $this->group($sender, [$mentioned]);

        $messageId = $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => '@ilk',
                'mentions' => [$mentioned->id],
            ])
            ->json('data.id');

        $this->actingAs($sender)
            ->patchJson('/api/messages/'.$messageId, ['body' => '@ilk tekrar tekrar tekrar'])
            ->assertOk();

        $this->assertCount(1, $this->chatNotificationsFor($mentioned));
    }

    /**
     * Bildirim gövdesi mesajın kendisidir (kırpılmış) — kullanıcı sohbeti
     * açmadan neyin söylendiğini görebilsin.
     */
    public function test_the_notification_body_quotes_the_message(): void
    {
        $sender = $this->actor();
        $mentioned = $this->actor();
        $conversation = $this->group($sender, [$mentioned]);

        $this->actingAs($sender)
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'Sözleşme taslağını bugün göndermemiz gerekiyor.',
                'mentions' => [$mentioned->id],
            ])
            ->assertStatus(201);

        $notifications = $this->chatNotificationsFor($mentioned);
        $text = $this->resolvedText($notifications[0]);

        $this->assertSame('Sözleşme taslağını bugün göndermemiz gerekiyor.', $text['body']);
        $this->assertStringContainsString('Satış Ekibi', $text['title']);
    }
}
