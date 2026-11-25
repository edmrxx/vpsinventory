<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product, as the API sends it.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'description' => $this->description,
            // The absolute address, built from the host this request came
            // in on: a phone cannot resolve the path the screens use.
            'photo_url' => $this->photoUrl() ? url($this->photoUrl()) : null,
            // Only when the caller eager loaded them, so a list never turns
            // into one query per row.
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'preferred_supplier' => $this->whenLoaded('preferredSupplier', fn () => $this->preferredSupplier ? [
                'id' => $this->preferredSupplier->id,
                'name' => $this->preferredSupplier->name,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'allows_decimals' => (bool) $this->unit->allows_decimals,
            ]),
            // Other units it is bought and sold in: a box of 100, at its
            // own price or the piece price times the factor.
            'other_units' => $this->whenLoaded('otherUnits', fn () => $this->otherUnits->map(fn ($other) => [
                'unit' => [
                    'id' => $other->unit?->id,
                    'code' => $other->unit?->code,
                    'allows_decimals' => (bool) $other->unit?->allows_decimals,
                ],
                'factor' => $other->factor,
                'selling_price' => $other->priceFrom($this->selling_price),
            ])->all()),
            'cost' => $this->cost,
            'selling_price' => $this->selling_price,
            'on_hand' => $this->on_hand,
            'reorder_level' => $this->reorder_level,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
            // Only on the stock endpoint, which adds it as a sub-select so
            // a page of forty still costs one query. Formatted exactly as
            // the Stock screen formats it, and gated the same way: anyone
            // who may read stock may read what it is worth.
            'stock_value' => $this->when(
                isset($this->resource->stock_value),
                fn () => number_format((float) $this->stock_value, 2, '.', ''),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
