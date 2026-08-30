<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Product $resource
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'category' => $product->category,
            'unit_price' => (float) $product->unit_price,
            'currency' => $product->currency,
            'tax_rate' => (float) $product->tax_rate,
            'unit' => $product->unit,
            'stock_quantity' => $product->stock_quantity,
            'is_active' => $product->is_active,
            'tags' => $product->relationLoaded('tags')
                ? $product->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()
                : [],
            'custom_fields' => $product->relationLoaded('customFieldValues')
                ? $product->customFieldValues
                    ->mapWithKeys(fn ($value) => [$value->customField->key => $value->value])
                    ->all()
                : [],
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
