<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product on a purchase order.
 *
 * Shaped like a purchase line, so the delivery posted against it in phase 23
 * can copy it. `quantity` is in the product's own unit; what was typed sits
 * beside it — `unit_quantity` of `unit_id`, at `unit_factor` each, at the
 * agreed `unit_cost` per typed unit.
 *
 * No `Auditable`: the order is the thing a person did.
 */
class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id', 'purchase_request_line_id', 'product_id', 'unit_id', 'unit_factor',
        'unit_quantity', 'quantity', 'received_quantity', 'unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'received_quantity' => QuantityCast::class,
            'unit_factor' => QuantityCast::class,
            'unit_quantity' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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

    /** The delivery lines that filled it, posted or voided. */
    public function purchaseLines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /** What is still to come, in the product's own unit, in thousandths. */
    public function outstandingMilli(): int
    {
        return max(0, Quantity::toMilli($this->quantity) - Quantity::toMilli($this->received_quantity));
    }

    /**
     * The agreed cost of one of the product's own unit, to the centavo: the
     * line total shared over the pieces, the way a purchase works out its
     * piece cost. A delivery's piece cost is compared against this.
     */
    public function agreedPieceCost(): float
    {
        $milli = Quantity::toMilli($this->quantity);

        if ($milli <= 0) {
            return (float) $this->unit_cost;
        }

        $cents = Quantity::centavos((float) $this->line_total);

        return intdiv(2 * $cents * Quantity::SCALE + $milli, 2 * $milli) / 100;
    }

    /** The request line it was ordered for, when it came from an award (phase 24.3). */
    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestLine::class, 'purchase_request_line_id');
    }
}
