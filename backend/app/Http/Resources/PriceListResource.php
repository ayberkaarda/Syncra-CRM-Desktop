<?php

namespace App\Http\Resources;

use App\Models\PriceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PriceList $resource
 */
class PriceListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PriceList $priceList */
        $priceList = $this->resource;

        return [
            'id' => $priceList->id,
            'name' => $priceList->name,
            'code' => $priceList->code,
            'description' => $priceList->description,
            'currency' => $priceList->currency,
            'is_default' => $priceList->is_default,
            'is_active' => $priceList->is_active,
            'valid_from' => $priceList->valid_from?->toDateString(),
            'valid_until' => $priceList->valid_until?->toDateString(),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $priceList->created_at?->toIso8601String(),
            'updated_at' => $priceList->updated_at?->toIso8601String(),
        ];
    }
}
