<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one batch a single outgoing movement took.
 *
 * This is what makes a sales return honest: the stock goes back at the cost
 * it actually left at, not at whatever the product costs today.
 */
class InventoryLayerConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_movement_id', 'inventory_layer_id',
        'quantity', 'returned_quantity', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'returned_quantity' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLayer::class, 'inventory_layer_id');
    }
}
