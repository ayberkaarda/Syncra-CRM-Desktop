<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.message.delivered` — `private-conversation.{id}`
 *
 * Gövde: `{ user_id, conversation_id, last_delivered_message_id }`.
 *
 * `.message.read` ile aynı imleç mantığı (gerekçe orada). Ayrı bir olay
 * olmasının nedeni tek tik ile çift tik arasındaki farkın istemcide GECİKMESİZ
 * görünmesi gerekmesidir: alıcı sohbeti hiç açmasa bile — uygulama arka planda
 * `private-conversation.{id}` kanalına bağlı olduğu sürece — istemci
 * `POST /api/conversations/{id}/delivered` çağırır ve gönderende ikinci tik
 * belirir.
 */
class MessageDelivered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly int $lastDeliveredMessageId,
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
        return 'message.delivered';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'last_delivered_message_id' => $this->lastDeliveredMessageId,
        ];
    }
}
