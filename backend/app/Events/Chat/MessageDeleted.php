<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.message.deleted` — `private-conversation.{id}`
 *
 * Gövde: `{ message_id, conversation_id }` — bilinçli olarak MESAJIN KENDİSİ
 * TAŞINMAZ. Silme olayının amacı içeriği ulaştırmak değil, ulaşmış olan
 * içeriği GERİ ÇEKMEKTİR; silinen gövdeyi yayında bir kez daha dolaştırmak bu
 * amacın tam tersidir (yayını dinleyen bir istemci onu loglayabilir).
 *
 * Arayüz mesajı id ile bulur ve mezar taşına çevirir. Mesaj o istemcide hiç
 * yüklenmemişse yapılacak bir şey yoktur; listeye sonradan girdiğinde `GET
 * /api/conversations/{id}/messages` zaten maskelenmiş hâlini döner.
 */
class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
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
        return 'message.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
        ];
    }
}
