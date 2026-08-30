<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * `.message.created` — `private-conversation.{id}`
 *
 * Gövde: `{ message: Message }` (Faz 12 kaynak şekli).
 *
 * -----------------------------------------------------------------------------
 * NEDEN `toOthers()` KULLANILMIYOR (Faz 7 DealMoved'den FARKLI)
 * -----------------------------------------------------------------------------
 * Kanban'da kartı taşıyan kullanıcı hareketi optimistic update ile zaten
 * yapmıştır ve kendi yankısını alması kartı ikinci kez zıplatır — orada
 * `toOthers()` doğrudur. Sohbette denklem tersine döner: kullanıcı aynı anda
 * masaüstü ve dizüstünde açık olabilir ve mesajı A sekmesinden gönderdiğinde
 * B sekmesinin de anında görmesi BEKLENEN davranıştır. `toOthers()` yalnızca
 * gönderen SOKETİ değil, o kullanıcının diğer oturumlarını da değil — sadece
 * isteği yapan tek soketi hariç tutar, ama pratikte istenen "hepsine gitsin"
 * olduğu için hariç tutma hiç yapılmaz.
 *
 * Bunun bedeli, gönderen sekmenin mesajı iki kez (HTTP yanıtı + yayın)
 * görmesidir; ARAYÜZ SÖZLEŞMESİ bunu `message.id` üzerinden tekilleştirmektir.
 * Bu zaten bağımsız olarak gerekli: iki farklı kullanıcının aynı anda mesaj
 * göndermesi, yeniden bağlanma sonrası tekrar yayın, ve `before=` imleçli
 * sayfalamanın canlı akışla örtüşmesi de aynı tekilleştirmeye ihtiyaç duyar.
 *
 * -----------------------------------------------------------------------------
 * NEDEN MODEL DEĞİL DÜZ DİZİ TAŞINIYOR
 * -----------------------------------------------------------------------------
 * `SerializesModels` kuyruğa yalnızca sınıf + id koyar ve işçi satırı YENİDEN
 * SORGULAR; dispatch bir transaction'ın hemen ardından geldiği için işçi
 * satırı commit'ten önce arayabilir. Ayrıca yeniden hidrasyon oturum açmış
 * kullanıcısı olmayan bir bağlamda gerçekleşir ve `tick` hesabı bağlamsızdır.
 * Bu yüzden gövde olay üretilirken tek seferde hesaplanıp skaler taşınır
 * (Faz 5 ve Faz 7'de öğrenilen aynı ders).
 */
class MessageCreated implements ShouldBroadcast
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
        return 'message.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
