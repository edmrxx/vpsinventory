<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a sales order.
 *
 * Shaped like a sale line, so the sale made from the order copies it:
 * `quantity` in the product's own unit, beside what was typed —
 * `unit_quantity` of `unit_id` at `unit_factor` each — at `unit_price` per
 * typed unit.
 *
 * No `Auditable`: the order is the thing a person did.
 */
class SalesOrderLine extends Model
{
    protected $fillable = [
        'sales_order_id', 'product_id', 'unit_id', 'unit_factor',
        'unit_quantity', 'quantity', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_factor' => QuantityCast::class,
            'unit_quantity' => QuantityCast::class,
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The unit the line was typed in, or null for the product's own. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
