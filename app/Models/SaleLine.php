<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product on a sale.
 *
 * `quantity` is always in the product's own unit: it is what left the
 * shelf, and what the ledger, the returns and the reports read. What the
 * cashier typed is kept beside it — `unit_quantity` of `unit_id`, at
 * `unit_factor` each — so "1 box (100 pcs)" reads back exactly. A null
 * `unit_id` means the product's own unit.
 *
 * `unit_price` is what the customer paid for one of the typed unit, so
 * `line_total` is `unit_quantity` times `unit_price`. `unit_cost` is what
 * one piece actually cost, worked out from the batches the line ate.
 */
class SaleLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'product_id', 'unit_id', 'unit_factor', 'unit_quantity',
        'inventory_movement_id', 'quantity', 'returned_quantity',
        'unit_price', 'line_total', 'unit_cost', 'line_cost',
    ];

    /**
     * A line written without saying what was typed was typed in the
     * product's own unit, so what was typed is what moved.
     */
    protected static function booted(): void
    {
        static::creating(function (SaleLine $line) {
            if ($line->unit_id === null && ! $line->unit_quantity) {
                $line->unit_quantity = $line->quantity;
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
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_cost' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
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

    /** The movement that took this line's stock out. */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    /** Every return line drawn against this sale line. */
    public function salesReturnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    /** How many of these can still come back. */
    public function returnable(): int|float
    {
        return Quantity::fromMilli(max(0, Quantity::toMilli($this->quantity) - Quantity::toMilli($this->returned_quantity)));
    }
}
