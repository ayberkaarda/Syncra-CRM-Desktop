<?php

namespace App\Http\Resources;

use App\Support\Search\SearchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `App\Support\Search\SearchResult` DTO'sunu API üslubuna (Faz 6 liste
 * sözleşmesi ruhu) uygun bir JSON gövdesine çevirir. Eloquent modeli SARMAZ
 * — DTO zaten 7 modülün ortak paydasına indirgenmiş düz veridir; bu yüzden
 * burada `whenLoaded`/ilişki mantığı YOK, sadece dört alanın sabit çıktısı var.
 *
 * @property SearchResult $resource
 */
class SearchResultResource extends JsonResource
{
    /**
     * @return array{type: string, id: int, title: string, subtitle: ?string, link: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource->type,
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'subtitle' => $this->resource->subtitle,
            'link' => $this->resource->link,
        ];
    }
}
