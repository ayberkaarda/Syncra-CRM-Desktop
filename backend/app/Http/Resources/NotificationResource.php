<?php

namespace App\Http\Resources;

use App\Notifications\Support\NotificationText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * `Illuminate\Notifications\DatabaseNotification` satırını API zarfına çevirir.
 *
 * ---------------------------------------------------------------------------
 * FAZ 14 / İZ D — METİN BURADA, OKUMA ANINDA ÜRETİLİR
 * ---------------------------------------------------------------------------
 * `data` sütunu artık (yeni satırlarda) render edilmiş cümle değil, çeviri ANAHTARI +
 * PARAMETRE taşır; `title`/`body` bu Resource'ta, İSTEĞİ YAPAN kullanıcının diliyle çözülür.
 * Böylece kullanıcı dilini değiştirdiğinde GEÇMİŞ bildirimleri de yeni dilde okur —
 * PHASE-INTL §1.4'ün "önemli olan OKUYANIN dili ve o değişebilir" kararı.
 *
 * ESKİ SATIRLAR GÖÇ EDİLMEZ: düz `title`/`body` taşıyan satırlar (ve henüz dönüştürülmemiş
 * bildirim tipleri) `NotificationText::resolve()` içindeki fallback ile aynen basılır.
 * Bu davranış `LocalizationNotificationTest` ile kilitlenmiştir.
 *
 * DIŞA VERİLEN ALANLAR ARTMIŞTIR (`title_key`/`body_key`/`params`), AZALMAMIŞTIR: mevcut
 * istemci sözleşmesi (`features/notifications/types.ts` — id/type/title/body/link/meta/...)
 * olduğu gibi karşılanır; ek alanlar, metni kendi tarafında render etmek isteyen ileriki bir
 * istemci (veya bir e-posta/dışa aktarma yüzeyi) içindir.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data ?? [];

        /*
         * `app()->getLocale()`, `$request->user()->locale` DEĞİL: `SetLocale` middleware'i
         * isteğin başında zaten kullanıcının tercihini (yoksa Accept-Language'ı, yoksa
         * uygulama varsayılanını) uygulamıştır. Kullanıcıyı burada ikinci kez okumak, aynı
         * kararın ikinci bir kopyasını üretir ve ikisi ayrışabilirdi.
         */
        $text = NotificationText::resolve($data, app()->getLocale());

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? null,
            'title' => $text['title'],
            'body' => $text['body'],
            'title_key' => $data['title_key'] ?? null,
            'body_key' => $data['body_key'] ?? null,
            'params' => $data['params'] ?? [],
            'link' => $data['link'] ?? null,
            'meta' => $data['meta'] ?? [],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
