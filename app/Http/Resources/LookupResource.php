<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category or a unit: a short reference row.
 *
 * @mixin Category|Unit
 */
class LookupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            // A unit has a code; a category does not.
            'code' => $this->code ?? null,
            'name' => $this->name,
            'description' => $this->description ?? null,
            'products_count' => $this->whenCounted('products'),
            'is_active' => $this->is_active,
        ], fn ($value) => $value !== null);
    }
}
