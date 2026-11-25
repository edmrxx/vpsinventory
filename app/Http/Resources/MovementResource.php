<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Support\DocumentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the stock card.
 *
 * The document is named from `source_type` and `source_id` alone, never by
 * loading the morph, so a page of forty rows costs no extra query.
 *
 * @mixin InventoryMovement
 */
class MovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seesCost = $request->user()?->can('viewCost', Sale::class) ?? false;

        return array_filter([
            'id' => $this->id,
            'date' => $this->movement_date?->toDateString(),
            'reference' => $this->reference,
            'document' => DocumentLink::label($this->source_type),
            'document_id' => $this->source_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product?->id,
                'sku' => $this->product?->sku,
                'name' => $this->product?->name,
            ]),
            'quantity_in' => $this->quantity_in,
            'quantity_out' => $this->quantity_out,
            'balance_after' => $this->balance_after,
            // On a sale row this is the cost of goods, so it follows the
            // same permission it does on the sale itself.
            'unit_cost' => $seesCost ? $this->unit_cost : null,
            'total_cost' => $seesCost ? $this->total_cost : null,
        ], fn ($value) => $value !== null);
    }
}
