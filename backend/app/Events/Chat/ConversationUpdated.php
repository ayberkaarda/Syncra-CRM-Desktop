<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.conversation.updated` — `private-conversation.{id}`
 *
 * Gövde: `{ conversation: Conversation }`. Grup adı değiştiğinde, üye
 * eklendiğinde/çıkarıldığında, biri ayrıldığında ve sahiplik devrolduğunda
 * yayınlanır.
 *
 * -----------------------------------------------------------------------------
 * DİKKAT — İKİ ALAN BU GÖVDEDE BAĞLAYICI DEĞİLDİR
 * -----------------------------------------------------------------------------
 * `Conversation` kaynak şeklindeki `unread_count` ve `is_muted` alanları
 * KİŞİYE ÖZELDİR: aynı konuşmanın okunmamış sayısı her üyede farklıdır. Bu
 * olay ise TEK bir gövdeyi kanaldaki HERKESE gönderir — dolayısıyla bu iki
 * alan burada kişiselleştirilemez ve `0` / `false` olarak gider.
 *
 * ARAYÜZ SÖZLEŞMESİ: `.conversation.updated` geldiğinde yerel konuşma kaydına
 * `name`, `display_name`, `members`, `created_by`, `type`, `conversable`
 * alanları YAZILIR; `unread_count` ve `is_muted` YEREL DEĞERİYLE BIRAKILIR.
 * Rozet değişimi zaten kendi olayından gelir (`.chat.unread`), susturma ise
 * `PATCH /api/conversations/{id}/mute` yanıtından.
 *
 * Alternatif — üye başına ayrı bir olay üretip her birini `private-user.{id}`
 * üzerinden göndermek — kişiselleştirmeyi çözerdi ama 30 kişilik bir grupta
 * tek bir ad değişikliği için 30 yayın anlamına gelirdi ve sözleşmedeki
 * `.conversation.updated` kanalını (`private-conversation.{id}`) terk ederdi.
 */
class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $conversation  bkz. ConversationResource::payload()
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $conversation,
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
        return 'conversation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation' => $this->conversation];
    }
}
