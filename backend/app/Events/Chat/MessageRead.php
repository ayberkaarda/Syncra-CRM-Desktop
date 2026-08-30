<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.message.read` — `private-conversation.{id}`
 *
 * Gövde: `{ user_id, conversation_id, last_read_message_id }`.
 *
 * -----------------------------------------------------------------------------
 * NEDEN MESAJ LİSTESİ DEĞİL TEK BİR İMLEÇ TAŞINIYOR
 * -----------------------------------------------------------------------------
 * "42, 43, 44, ... 91 okundu" biçiminde bir dizi göndermek, uzun süre kapalı
 * kalmış bir sohbette yüzlerce id taşır ve büyüklüğü öngörülemez bir yayın
 * üretir. İmleçlerin MONOTONLUĞU (bkz. TickState) bunu gereksiz kılar: tek bir
 * "buraya kadar okudum" sayısı, altındaki TÜM mesajların durumunu belirler.
 * Arayüz kendi listesinde `id <= last_read_message_id` olan ve KENDİSİNE ait
 * mesajların tikini maviye çevirir.
 *
 * `user_id` gövdede çünkü grup sohbetinde imleç kişiye özeldir; hangi üyenin
 * ilerlediğini bilmeden alıcı "en az bir diğer katılımcı" kuralını yeniden
 * hesaplayamaz.
 */
class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly int $lastReadMessageId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
