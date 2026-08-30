<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.message.updated` — `private-conversation.{id}`
 *
 * Gövde: `{ message: Message }`. Düzenlenen mesajın TAM gövdesi taşınır (delta
 * değil): arayüz kayıtlı mesajı id ile bulup yerine koyar, alan alan yamalamaz
 * — kaçırılmış bir olaydan sonra kısmi güncelleme sessizce bozuk bir mesaj
 * bırakırdı.
 *
 * Model değil düz dizi taşınmasının gerekçesi MessageCreated dokümanındadır.
 */
class MessageUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $message  bkz. MessageResource::payload()
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $message,
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
        return 'message.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
