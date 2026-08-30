<?php

namespace App\Http\Resources\Chat;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mesaja iliştirilmiş dosyanın sohbet görünümü.
 *
 * -----------------------------------------------------------------------------
 * `url` NEDEN `route()` İLE ÜRETİLMİYOR
 * -----------------------------------------------------------------------------
 * Dosya servis eden uç (`GET /api/attachments/{id}`) ve onun policy'si PARALEL
 * bir şeridin sahipliğinde. `route('attachments.show', ...)` çağırmak bu
 * Resource'u o şeridin rota ADINI seçmesine bağlar; rota adı farklı olursa
 * burası çalışma zamanında `RouteNotFoundException` ile patlar ve hata sohbet
 * listesinde görünür. Sözleşmede sabitlenen şey YOL'dur (`/api/attachments/
 * {id}`), rota adı değil — bu yüzden yol doğrudan yazılır.
 *
 * -----------------------------------------------------------------------------
 * `is_image` NEDEN SUNUCUDA TÜRETİLİYOR
 * -----------------------------------------------------------------------------
 * Arayüzün "önizleme mi göstereyim, dosya kartı mı" kararı tek bir boolean'a
 * indirgenir. Bunu istemcide uzantıya bakarak yapmak (`.png`) yanıltıcıdır:
 * `rapor.png` adlı bir PDF de olabilir. Karar, dosya yüklenirken doğrulanan
 * `mime_type` üzerinden verilir.
 */
class ChatAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function payload(?Attachment $attachment): ?array
    {
        if ($attachment === null) {
            return null;
        }

        $mime = (string) $attachment->mime_type;

        return [
            'id' => (int) $attachment->getKey(),
            'original_name' => $attachment->original_name,
            'mime_type' => $mime,
            'size' => (int) $attachment->size,
            'is_image' => str_starts_with($mime, 'image/'),
            'url' => '/api/attachments/'.$attachment->getKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Attachment $attachment */
        $attachment = $this->resource;

        return self::payload($attachment) ?? [];
    }
}
