<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a purchase request.
 *
 * Shaped like a purchase line, so a purchase order can copy it (phase 22).
 * `quantity` is in the product's own unit; what was typed sits beside it —
 * `unit_quantity` of `unit_id`, at `unit_factor` each. A null `unit_id`
 * means the product's own unit. `unit_cost` is an estimate per typed unit,
 * and `line_total` is that estimate times what was typed.
 *
 * No `Auditable`: the request is the thing a person did.
 */
class PurchaseRequestLine extends Model
{
    protected $fillable = [
        'purchase_request_id', 'product_id', 'unit_id', 'unit_factor',
        'unit_quantity', 'quantity', 'unit_cost', 'line_total',
        'awarded_quote_line_id', 'award_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_factor' => QuantityCast::class,
            'unit_quantity' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
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

    /** The price that won this line in the canvass (phase 24.2). */
    public function awardedQuoteLine(): BelongsTo
    {
        return $this->belongsTo(SupplierQuoteLine::class, 'awarded_quote_line_id');
    }
}
