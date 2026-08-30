<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TimelineBuilder tarafından üretilen, zaten
 * {type, id, title, description, icon_hint, occurred_at, user, meta} şeklinde
 * hazırlanmış düz (plain) dizileri olduğu gibi dışa verir. Kaynak zaten
 * normalize edilmiş olduğundan burada ekstra bir dönüşüm yapılmaz — tek
 * sorumluluk, farklı kaynaklardan (activity/task/deal/ticket/attachment)
 * gelen kayıtların ortak bir JSON sözleşmesi altında dışa çıkmasını garanti
 * etmektir.
 *
 * @property-read array<string, mixed> $resource
 */
class TimelineItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'id' => $this->resource['id'],
            'title' => $this->resource['title'],
            'description' => $this->resource['description'],
            'icon_hint' => $this->resource['icon_hint'],
            'occurred_at' => $this->resource['occurred_at'],
            'user' => $this->resource['user'],
            'meta' => $this->resource['meta'],
        ];
    }
}
