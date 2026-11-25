<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product on a purchase.
 *
 * `quantity` is always in the product's own unit: it is what went on the
 * shelf. What was typed is kept beside it — `unit_quantity` of `unit_id`,
 * at `unit_factor` each, bought at `unit_price` each — so 5 boxes at 400
 * reads back as it was entered. A null `unit_id` means the product's own
 * unit.
 *
 * `unit_cost` is what one piece cost: the figure the batch takes, a
 * purchase return gives back, and the product's cost follows. Bought by
 * the piece it equals `unit_price`; bought by the box it is the line total
 * shared over the pieces, to the centavo.
 *
 * No `Auditable`: the document is the thing a person did.
 */
class PurchaseLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id', 'purchase_order_line_id', 'product_id', 'unit_id', 'unit_factor', 'unit_quantity',
        'unit_price', 'inventory_layer_id', 'quantity', 'returned_quantity',
        'unit_cost', 'line_total',
    ];

    /**
     * A line written without saying what was typed was typed in the
     * product's own unit, at the piece cost.
     */
    protected static function booted(): void
    {
        static::creating(function (PurchaseLine $line) {
            if ($line->unit_id === null && ! $line->unit_quantity) {
                $line->unit_quantity = $line->quantity;
            }

            if ($line->unit_id === null && ! (float) $line->unit_price) {
                $line->unit_price = $line->unit_cost;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'returned_quantity' => QuantityCast::class,
            'unit_factor' => QuantityCast::class,
            'unit_quantity' => QuantityCast::class,
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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

    /** Every return line drawn against this purchase line. */
    public function purchaseReturnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /** The order line this delivery line fills, if any (phase 23). */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /** The batch this line opened. */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLayer::class, 'inventory_layer_id');
    }

    /**
     * How many of these can still go back to the supplier.
     *
     * Never more than is left of this delivery's own batch: the rest has
     * been sold, and cannot be handed back.
     */
    public function returnable(): int|float
    {
        $notYetReturned = max(0, Quantity::toMilli($this->quantity) - Quantity::toMilli($this->returned_quantity));

        return Quantity::fromMilli(min($notYetReturned, Quantity::toMilli($this->layer?->quantity_remaining)));
    }
}
