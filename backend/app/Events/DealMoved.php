<?php

namespace App\Events;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Bir Kanban kartı taşındı — panoyu açık tutan herkese anlık haber.
 *
 * ---------------------------------------------------------------------------
 * NEDEN `private-deals` (PANO KANALI), `presence-record.deal.{id}` DEĞİL
 * ---------------------------------------------------------------------------
 * `presence-record.deal.{id}` "şu an bu KAYDA kim bakıyor" sorusunun cevabıdır
 * ve kart kart aboneliktir. Kanban'da haber verilmesi gereken kitle ise kartı
 * açmış olanlar değil, PANOYU açmış olanlardır — kart onların ekranında bir
 * sütundan diğerine geçmeli. 50 kart için 50 abonelik açmak yerine tek bir
 * pano kanalı kullanılır. Yetki `deals.view`: panoyu göremeyen, panonun
 * hareketlerini de duymaz.
 *
 * ---------------------------------------------------------------------------
 * NEDEN DÜZ SKALER PAYLOAD (MODEL DEĞİL)
 * ---------------------------------------------------------------------------
 * Faz 5'te öğrenildi: `SerializesModels` kuyruğa yalnızca sınıf + id koyar ve
 * işçi satırı YENİDEN SORGULAR. Dispatch, çağıranın transaction'ı içinde ya da
 * hemen ardından gerçekleşebildiği için işçi satırı commit'ten önce arayabilir
 * ve bulamaz; ayrıca yeniden hidrasyon, oturum açmış kullanıcısı olmayan bir
 * bağlamda olur. Bu yüzden yayınlanacak her şey, olay üretildiği anda hesaplanıp
 * skaler olarak taşınır.
 *
 * ---------------------------------------------------------------------------
 * `from_stage_id` NEDEN PAYLOAD'DA
 * ---------------------------------------------------------------------------
 * İstemcinin elindeki karta değil, olayın kendisine güvenmesi gerekir: kartı
 * DOĞRU sütundan kaldırıp doğru sütuna eklemek için taşımadan ÖNCEKİ aşama
 * gerekir. Yoksa uzaktaki pano, kartı bulmak için tüm sütunları taramak
 * zorunda kalır ve kaçırılan bir olaydan sonra kart iki sütunda birden görünür.
 *
 * ---------------------------------------------------------------------------
 * `toOthers()` — dispatch tarafında zorunlu
 * ---------------------------------------------------------------------------
 * Taşıyan kullanıcı kartı zaten optimistic update ile yerine koymuştur. Kendi
 * hareketinin yankısını da alırsa kart bir kez daha zıplar (ve yanıt ile yayın
 * yarışırsa yanlış yere zıplar). Bu yüzden DealMoveService yayını
 * `broadcast(...)->toOthers()` ile gönderir; `toOthers()` isteğin
 * `X-Socket-ID` başlığını olaya iliştirir ve Reverb o soketi fan-out'tan
 * hariç tutar.
 */
class DealMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  bkz. self::payload()
     */
    public function __construct(public readonly array $payload) {}

    /**
     * Yayın gövdesi — taşıma anında, tek seferde hesaplanır.
     *
     * @return array<string, mixed>
     */
    public static function payload(Deal $deal, int $fromStageId, User $actor): array
    {
        return [
            'deal_id' => (int) $deal->getKey(),
            'from_stage_id' => $fromStageId,
            'to_stage_id' => (int) $deal->pipeline_stage_id,
            'position' => $deal->position,
            'version' => (int) $deal->version,
            'status' => $deal->status,
            'title' => $deal->title,
            'amount' => (string) $deal->amount,
            'currency' => $deal->currency,
            'owner_id' => $deal->owner_id === null ? null : (int) $deal->owner_id,
            // Sahibin adı da taşınır: uzaktaki pano kartı çizmek için ek bir
            // /users sorgusu atmak zorunda kalmasın.
            'owner_name' => $deal->owner?->name,
            'moved_by_id' => (int) $actor->getKey(),
            'moved_by_name' => $actor->name,
            'moved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deals'),
        ];
    }

    /**
     * Kısa, sabit olay adı — FQCN yayınlamak SPA'yı backend namespace'ine
     * bağlardı (bkz. ActivityLogged/UserDeactivated aynı sözleşme).
     */
    public function broadcastAs(): string
    {
        return 'deal.moved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
