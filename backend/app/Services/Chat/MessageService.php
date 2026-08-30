<?php

namespace App\Services\Chat;

use App\Events\Chat\ChatUnread;
use App\Events\Chat\MessageCreated;
use App\Events\Chat\MessageDeleted;
use App\Events\Chat\MessageDelivered;
use App\Events\Chat\MessageRead;
use App\Events\Chat\MessageUpdated;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ChatMentionNotification;
use App\Notifications\Support\NotificationDispatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mesaj iş mantığı — yazma, düzenleme, mezar taşı, imleçler ve fan-out.
 *
 * -----------------------------------------------------------------------------
 * YAYINLAR TRANSACTION'IN DIŞINDA
 * -----------------------------------------------------------------------------
 * Her yazma önce `DB::transaction` içinde kalıcılaşır, olay ANCAK SONRA
 * yayınlanır. Faz 7'de (DealMoveService) öğrenilen ders: yayın transaction'ın
 * içinden atılırsa, Reverb'e ulaşan istemci henüz COMMIT olmamış bir satırı
 * `GET` ile isteyebilir ve 404 alır; transaction geri alınırsa da hiç var
 * olmamış bir mesaj tüm ekranlarda görünmüş olur.
 *
 * -----------------------------------------------------------------------------
 * İMLEÇLİ (CURSOR) SAYFALAMA — `?before=`
 * -----------------------------------------------------------------------------
 * Sohbet listesi OFFSET ile sayfalanamaz. `?page=2` istenirken yeni bir mesaj
 * gelirse tüm pencere bir satır kayar ve kullanıcı aynı mesajı iki kez görür
 * (ya da bir mesajı hiç görmez). Kayan pencere sorunu, sabit bir çıpaya —
 * "şu id'den ESKİ olanlar" — geçilerek tamamen ortadan kalkar. Çıpa
 * `created_at` değil `id`'dir: aynı saniyeye düşen iki mesajda zaman damgası
 * eşit olabilir ve sıralama kararsızlaşır.
 */
class MessageService
{
    /**
     * `?per_page` varsayılanı ve tavanı (Faz 12 sözleşmesi).
     */
    public const DEFAULT_PER_PAGE = 30;

    public const MAX_PER_PAGE = 50;

    /**
     * `.chat.unread` gövdesindeki önizlemenin karakter sınırı.
     */
    public const PREVIEW_LIMIT = 140;

    /**
     * @var array<int, string>
     */
    protected const RELATIONS = ['user', 'attachment'];

    public function __construct(
        protected ChatReadState $readState,
        protected MentionResolver $mentions,
    ) {}

    /**
     * `GET /api/conversations/{conversation}/messages?before=&per_page=`
     *
     * SİLİNMİŞ MESAJLAR DA DÖNER (`withTrashed`): mezar taşı listede kalır,
     * içeriği MessageResource maskeler. Sorgudan elemek, `before=` imleçli
     * sayfalamada delikler ve okuma imleçlerinde tutarsızlık üretirdi.
     *
     * @return array{data: array<int, MessageResource>, meta: array{has_more: bool, next_before: int|null}}
     */
    public function list(Conversation $conversation, int $viewerId, ?int $before, ?int $perPage): array
    {
        $perPage = max(1, min((int) ($perPage ?: self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));

        // Bir fazla çekilir: "daha var mı" sorusunu ikinci bir COUNT sorgusu
        // atmadan cevaplamanın en ucuz yolu.
        $rows = Message::withTrashed()
            ->with(self::RELATIONS)
            ->where('conversation_id', $conversation->getKey())
            ->when($before !== null, fn (Builder $query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $messages = $rows->take($perPage);

        $ticks = TickState::forConversation((int) $conversation->getKey(), $viewerId);

        return [
            'data' => MessageResource::manyWithTicks($messages, $ticks),
            'meta' => [
                'has_more' => $hasMore,
                'next_before' => $hasMore ? (int) $messages->last()->getKey() : null,
            ],
        ];
    }

    /**
     * `GET /api/messages/search?q=&conversation_id=`
     *
     * Yalnızca kullanıcının ÜYESİ OLDUĞU konuşmalarda arar — üyelik kontrolü
     * `whereExists` ile sorgunun içindedir, sonuçlar sonradan filtrelenmez
     * (sonradan filtrelemek sayfa boyutunu yalanlar: 25 satır çekip 3'ünü
     * elemek "25 sonuç" diyen bir sayfada 22 satır gösterir).
     *
     * MEZAR TAŞLARI ARAMAYA GİRMEZ: içeriği maskelenmiş bir mesajın arama
     * sonucunda çıkması, kullanıcıya boş bir satır göstermekten başka bir şey
     * yapmaz — ve silinmiş metnin hâlâ indekslendiğini ele verirdi.
     */
    public function search(int $viewerId, string $q, ?int $conversationId, ?int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($perPage ?: 25), 100));

        return Message::query()
            ->with(self::RELATIONS)
            ->where('body', 'like', '%'.$q.'%')
            ->when($conversationId !== null, fn (Builder $query) => $query->where('conversation_id', $conversationId))
            ->whereExists(function ($query) use ($viewerId) {
                $query->select(DB::raw(1))
                    ->from('conversation_user')
                    ->whereColumn('conversation_user.conversation_id', 'messages.conversation_id')
                    ->where('conversation_user.user_id', $viewerId);
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * `POST /api/conversations/{conversation}/messages`
     *
     * `type` İSTEMCİDEN ALINMAZ, ekten türetilir: ek varsa `file`, yoksa
     * `text`. `system` hiçbir istemci isteğiyle üretilemez — üretilebilseydi
     * kullanıcı, arayüzde sistem sesiyle konuşan sahte bir satır yazabilirdi.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Conversation $conversation, User $sender, array $data): Message
    {
        $senderId = (int) $sender->getKey();
        $attachmentId = $data['attachment_id'] ?? null;

        // `type=record` sohbetlerde üyelik, kaydı görebilen kullanıcı için
        // otomatiktir; yazmadan önce pivot satırının var olduğundan emin ol,
        // yoksa fan-out bu kullanıcıyı hiç görmez ve kendi okuma imleci
        // ilerlemez.
        $this->ensureSenderIsMember($conversation, $senderId);

        $message = DB::transaction(function () use ($conversation, $senderId, $data, $attachmentId): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->getKey(),
                'user_id' => $senderId,
                'body' => $data['body'] ?? null,
                'attachment_id' => $attachmentId,
                'type' => $attachmentId !== null ? Message::TYPE_FILE : Message::TYPE_TEXT,
            ]);

            // Konuşma listesinin sıralaması bu kolonun index'ine dayanır.
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            $this->readState->fanOutNewMessage(
                (int) $conversation->getKey(),
                $senderId,
                (int) $message->getKey(),
            );

            return $message;
        });

        $message->load(self::RELATIONS);

        $this->broadcastCreated($conversation, $message, $sender);
        $this->notifyMentions($conversation, $message, $sender, $data['mentions'] ?? []);

        return $message;
    }

    /**
     * `PATCH /api/messages/{message}` — yalnızca kendi metin mesajı
     * (MessagePolicy::update). Zaman sınırı YOKTUR: bir yazım hatasını üç gün
     * sonra düzeltmek de meşrudur ve `edited_at` şeffaflığı zaten sağlar.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Message $message, array $data): Message
    {
        $message->fill([
            'body' => $data['body'],
            'edited_at' => now(),
        ])->save();

        $message->load(self::RELATIONS);

        $conversationId = (int) $message->conversation_id;

        broadcast(new MessageUpdated(
            $conversationId,
            MessageResource::payload($message, TickState::forConversation($conversationId, (int) $message->user_id)),
        ));

        return $message;
    }

    /**
     * `DELETE /api/messages/{message}` — soft delete (mezar taşı).
     */
    public function delete(Message $message): void
    {
        $conversationId = (int) $message->conversation_id;
        $messageId = (int) $message->getKey();

        $message->delete();

        broadcast(new MessageDeleted($conversationId, $messageId));
    }

    /**
     * `POST /api/conversations/{conversation}/read`.
     *
     * @return array{last_read_message_id: int, last_delivered_message_id: int, unread_count: int}
     */
    public function markRead(Conversation $conversation, int $userId, ?int $messageId): array
    {
        $messageId = $messageId ?? $this->latestMessageId($conversation);

        if ($messageId === null) {
            return ['last_read_message_id' => 0, 'last_delivered_message_id' => 0, 'unread_count' => 0];
        }

        $cursors = $this->readState->markRead((int) $conversation->getKey(), $userId, $messageId);

        broadcast(new MessageRead(
            (int) $conversation->getKey(),
            $userId,
            $cursors['last_read_message_id'],
        ));

        return $cursors;
    }

    /**
     * `POST /api/conversations/{conversation}/delivered`.
     *
     * @return array{last_read_message_id: int, last_delivered_message_id: int, unread_count: int}
     */
    public function markDelivered(Conversation $conversation, int $userId, ?int $messageId): array
    {
        $messageId = $messageId ?? $this->latestMessageId($conversation);

        if ($messageId === null) {
            return ['last_read_message_id' => 0, 'last_delivered_message_id' => 0, 'unread_count' => 0];
        }

        $cursors = $this->readState->markDelivered((int) $conversation->getKey(), $userId, $messageId);

        broadcast(new MessageDelivered(
            (int) $conversation->getKey(),
            $userId,
            $cursors['last_delivered_message_id'],
        ));

        return $cursors;
    }

    /**
     * Mezar taşları DAHİL en büyük mesaj id'si. Silinmiş bir mesaj da
     * "okundu" sayılmalıdır; aksi halde sohbetin son mesajı silindiğinde imleç
     * bir gerideki mesajda takılır ve rozet asla sönmez.
     */
    public function latestMessageId(Conversation $conversation): ?int
    {
        $id = Message::withTrashed()
            ->where('conversation_id', $conversation->getKey())
            ->max('id');

        return $id === null ? null : (int) $id;
    }

    protected function ensureSenderIsMember(Conversation $conversation, int $senderId): void
    {
        if ($conversation->hasMember($senderId)) {
            return;
        }

        DB::table('conversation_user')->insertOrIgnore([
            'conversation_id' => $conversation->getKey(),
            'user_id' => $senderId,
            'unread_count' => 0,
            'is_muted' => false,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conversation->unsetRelation('users');
    }

    /**
     * Sohbet kanalına mesajı, ve HER alıcının kişisel kanalına hafif rozet
     * olayını yayınlar.
     *
     * Toplam iki sorgu ile: pivot satırları (konuşma içi sayaç + susturma) ve
     * kullanıcı başına genel toplam. Alıcı başına sorgu atmak 20 kişilik bir
     * grupta mesaj başına 40 sorgu demekti.
     */
    protected function broadcastCreated(Conversation $conversation, Message $message, User $sender): void
    {
        $conversationId = (int) $conversation->getKey();
        $senderId = (int) $sender->getKey();

        broadcast(new MessageCreated(
            $conversationId,
            MessageResource::payload($message, TickState::forConversation($conversationId, $senderId)),
        ));

        $recipientIds = $this->memberIds($conversation)
            ->reject(fn (int $id): bool => $id === $senderId)
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $pivots = $this->readState->pivotsFor($conversationId, $recipientIds->all());
        $totals = $this->readState->totalsForMany($recipientIds->all());
        $preview = $this->preview($message);

        foreach ($recipientIds as $recipientId) {
            if (($pivots[$recipientId]['is_muted'] ?? false) === true) {
                continue;
            }

            broadcast(new ChatUnread(
                $recipientId,
                $conversationId,
                $pivots[$recipientId]['unread_count'] ?? 0,
                $totals[$recipientId] ?? 0,
                $preview,
                $sender->name,
            ));
        }
    }

    /**
     * @param  array<int, int|string>  $mentionedUserIds
     */
    protected function notifyMentions(Conversation $conversation, Message $message, User $sender, array $mentionedUserIds): void
    {
        if ($mentionedUserIds === []) {
            return;
        }

        $recipients = $this->mentions->recipients($conversation, $mentionedUserIds, (int) $sender->getKey());

        foreach ($recipients as $recipient) {
            // Tek gönderim kapısı (Faz 10): kendine bildirim gitmez, pasif
            // kullanıcıya gitmez, toplu içe aktarma bağlamında susturulur.
            NotificationDispatcher::send(
                $recipient,
                $sender,
                ChatMentionNotification::make(
                    (int) $recipient->getKey(),
                    $message,
                    $conversation,
                    $sender,
                ),
            );
        }
    }

    /**
     * @return Collection<int, int>
     */
    protected function memberIds(Conversation $conversation): Collection
    {
        return DB::table('conversation_user')
            ->where('conversation_id', $conversation->getKey())
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id);
    }

    protected function preview(Message $message): ?string
    {
        if ($message->type === Message::TYPE_FILE) {
            return $message->attachment?->original_name ?? $message->body;
        }

        return $message->body === null ? null : Str::limit($message->body, self::PREVIEW_LIMIT);
    }
}
