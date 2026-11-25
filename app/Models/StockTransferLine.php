<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a transfer, in the product's own unit.
 *
 * No `Auditable` here on purpose: the document is the thing a person did.
 */
class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_id', 'quantity', 'unit_cost', 'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
