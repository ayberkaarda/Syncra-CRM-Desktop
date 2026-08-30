<?php

namespace App\Http\Resources;

use App\Models\PriceListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PriceListItem $resource
 */
class PriceListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PriceListItem $item */
        $item = $this->resource;

        return [
            'product_id' => $item->product_id,
            'product_name' => $item->relationLoaded('product') ? $item->product?->name : null,
            'product_sku' => $item->relationLoaded('product') ? $item->product?->sku : null,
            'unit_price' => (float) $item->unit_price,
            // Ürünün kendi katalog fiyatı — arayüz "liste fiyatı vs katalog
            // fiyatı" farkını gösterebilsin diye.
            'catalog_price' => $item->relationLoaded('product') && $item->product
                ? (float) $item->product->unit_price
                : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
