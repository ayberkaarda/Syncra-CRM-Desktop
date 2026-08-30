<?php

namespace App\Http\Resources\Chat;

use App\Models\Message;
use App\Services\Chat\TickState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * =============================================================================
 * Mesaj gösterimi — Faz 12 uç sözleşmesi
 * =============================================================================
 *
 * -----------------------------------------------------------------------------
 * SİLİNMİŞ MESAJ: SATIR KALIR, İÇERİK MASKELENİR
 * -----------------------------------------------------------------------------
 * `deleted_at` dolu bir mesaj listeden düşmez ama `body` ve `attachment`
 * KOŞULSUZ `null` döner. Maskeleme burada, yani API'nin ÇIKIŞ kapısında
 * yapılır; "silinmiş mesajı sorgudan ele" yaklaşımı seçilmedi çünkü mesaj
 * id'leri okuma/iletim imleçlerinin ve `before=` imleçli sayfalamanın
 * omurgasıdır — satır kaybolsa imleçler delik alırdı.
 *
 * "Bu mesaj silindi" METNİ SUNUCUDAN GİTMEZ. Sunucunun gönderdiği tek bilgi
 * `deleted_at`'in dolu olmasıdır; cümleyi arayüz kendi diliyle yazar. Metni
 * sunucuya koymak, gövdeyi çeviri dosyası hâline getirir ve arayüzün mezar
 * taşını farklı (soluk, italik, ikonlu) çizmesini engellerdi.
 *
 * -----------------------------------------------------------------------------
 * `tick` — N+1 ÜRETMEDEN
 * -----------------------------------------------------------------------------
 * Tik durumu mesaj başına bir sorgu ile DEĞİL, konuşma başına tek bir imleç
 * çiftiyle (bkz. App\Services\Chat\TickState) hesaplanır ve bu Resource'a
 * `withTicks()` ile ENJEKTE edilir. Resource kendi başına veritabanına HİÇ
 * dokunmaz — bu, listeleme ucunun sorgu sayısını mesaj sayısından bağımsız
 * tutan asıl kuraldır.
 *
 * Enjeksiyon `additional()` ile yapılamaz (o yalnızca yanıtın en üst düzeyine
 * anahtar ekler) ve modelin üzerine geçici bir attribute yazmak da tercih
 * edilmedi (Eloquent onu "dirty" sayar; ileride araya girecek bir `save()`
 * var olmayan bir kolona yazmaya çalışırdı). Bu yüzden akıcı bir setter
 * kullanılır ve koleksiyon ConversationMessages üzerinden tek tek kurulur.
 *
 * @property-read Message $resource
 */
class MessageResource extends JsonResource
{
    protected ?TickState $ticks = null;

    /**
     * Tik durumunu enjekte eder ve `$this`'i döner (akıcı kullanım).
     */
    public function withTicks(?TickState $ticks): static
    {
        $this->ticks = $ticks;

        return $this;
    }

    /**
     * Bir mesaj koleksiyonunu, hepsi aynı tik durumunu paylaşacak şekilde
     * Resource dizisine çevirir.
     *
     * @param  iterable<int, Message>  $messages
     * @return array<int, self>
     */
    public static function manyWithTicks(iterable $messages, ?TickState $ticks): array
    {
        $resources = [];

        foreach ($messages as $message) {
            $resources[] = (new self($message))->withTicks($ticks);
        }

        return $resources;
    }

    /**
     * Yayın (broadcast) gövdesi için düz dizi.
     *
     * Olay nesneleri model DEĞİL skaler taşır (Faz 7 DealMoved dersi:
     * `SerializesModels` kuyruğa yalnızca sınıf + id koyar ve işçi satırı
     * commit'ten önce arayabilir), bu yüzden gövde olay üretilirken burada
     * hesaplanır.
     *
     * @return array<string, mixed>
     */
    public static function payload(Message $message, ?TickState $ticks): array
    {
        return (new self($message))->withTicks($ticks)->toArray(request());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Message $message */
        $message = $this->resource;

        $isDeleted = $message->deleted_at !== null;

        return [
            'id' => (int) $message->getKey(),
            'conversation_id' => (int) $message->conversation_id,
            'user' => ChatUserResource::payload(
                $message->relationLoaded('user') ? $message->user : null
            ),
            // Mezar taşı: içerik maskelenir, satır kalır.
            'body' => $isDeleted ? null : $message->body,
            'type' => $message->type,
            'attachment' => $isDeleted
                ? null
                : ChatAttachmentResource::payload(
                    $message->relationLoaded('attachment') ? $message->attachment : null
                ),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'deleted_at' => $message->deleted_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'tick' => ($this->ticks ?? TickState::empty(0))->for($message),
        ];
    }
}
