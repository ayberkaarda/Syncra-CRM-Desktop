<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Attachment $resource
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Attachment $attachment */
        $attachment = $this->resource;

        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            // Frontend önizleme kararı için: raster görsel mi. `?inline=1`
            // ile inline servis edilebilecek TÜRLERLE birebir aynı tanım
            // (bkz. Attachment::isInlineEligibleImage(), AttachmentController::show()).
            'is_image' => $attachment->isInlineEligibleImage(),
            // Mutlak yol, host YOK — AttachmentController::show() ucu.
            'url' => "/api/attachments/{$attachment->id}",
        ];
    }
}
