<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another unit a product is bought and sold in: a box of 100 pieces, a
 * roll of 75 m.
 *
 * `factor` is how many of the product's own unit one of these holds. The
 * stock never leaves the product's own unit: selling one box takes 100
 * pieces off the shelf.
 *
 * `selling_price` is the price of one of these. Left empty, it is the piece
 * price times the factor; filled in, it is usually less, which is the point
 * of selling by the box.
 *
 * Not `Auditable` on its own: the product writes one trail row naming
 * every change, so a box price changed on the product form reads as a
 * change to that product.
 */
class ProductUnit extends Model
{
    protected $fillable = ['product_id', 'unit_id', 'factor', 'selling_price'];

    protected function casts(): array
    {
        return [
            'factor' => QuantityCast::class,
            'selling_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * What one of these sells for: its own price, or the piece price times
     * the factor, to the centavo.
     */
    public function priceFrom(int|float|string|null $piecePrice): string
    {
        if ($this->selling_price !== null) {
            return (string) $this->selling_price;
        }

        return number_format(Quantity::lineTotal($this->factor, $piecePrice), 2, '.', '');
    }

    /** What one of these costs to buy, from the piece cost. */
    public function costFrom(int|float|string|null $pieceCost): string
    {
        return number_format(Quantity::lineTotal($this->factor, $pieceCost), 2, '.', '');
    }
}
