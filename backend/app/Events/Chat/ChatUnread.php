<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.chat.unread` — `private-user.{id}` (Faz 10'da açılan KİŞİSEL kanal)
 *
 * Gövde: `{ conversation_id, conversation_unread, total_unread, preview,
 * sender_name }`.
 *
 * -----------------------------------------------------------------------------
 * NEDEN AYRI VE HAFİF BİR OLAY
 * -----------------------------------------------------------------------------
 * `.message.created` yalnızca `private-conversation.{id}` üzerinde akar ve o
 * kanala ancak sohbeti AÇMIŞ olan istemci abonedir. Oysa kenar çubuğundaki
 * genel okunmamış rozeti, kullanıcı hangi sayfada olursa olsun (rapor ekranı,
 * Kanban, ayarlar) anında artmalıdır. Kullanıcının her konuşmasına önden abone
 * olmak — 200 konuşmalı bir hesapta 200 kanal — ne ölçeklenir ne de gerekir;
 * kişisel kanal zaten açıktır ve tam olarak bu iş içindir.
 *
 * Gövde kasıtlı olarak HAFİFTİR: mesaj nesnesi taşınmaz, yalnızca rozetin ve
 * bildirim balonunun ihtiyaç duyduğu beş alan gider. `preview` sohbete
 * girmeden "kim ne yazmış" gösterebilmek içindir ve sunucuda kırpılır.
 *
 * -----------------------------------------------------------------------------
 * KİMLERE GİTMEZ
 * -----------------------------------------------------------------------------
 *   - Gönderenin kendisine (kendi mesajı rozet üretmez).
 *   - `is_muted = true` olan üyelere. Susturma tam olarak BUNU susturur;
 *     sohbetin içindeki `unread_count` yine de artar (bkz. ChatReadState::
 *     fanOutNewMessage()) çünkü kullanıcı sohbeti açtığında kaç mesaj
 *     kaçırdığını görmelidir.
 *
 * `user.{id}` KANALININ KENDİSİ DEĞİŞTİRİLMEDİ — routes/channels.php'deki
 * callback (kesin kimlik eşitliği, admin istisnası yok) olduğu gibi duruyor;
 * bu olay yalnızca var olan kanalın ÜZERİNE yayınlanır.
 */
class ChatUnread implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $recipientId,
        public readonly int $conversationId,
        public readonly int $conversationUnread,
        public readonly int $totalUnread,
        public readonly ?string $preview,
        public readonly ?string $senderName,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.unread';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'conversation_unread' => $this->conversationUnread,
            'total_unread' => $this->totalUnread,
            'preview' => $this->preview,
            'sender_name' => $this->senderName,
        ];
    }
}
