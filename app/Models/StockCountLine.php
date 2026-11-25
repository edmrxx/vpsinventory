<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a count sheet, in the product's own unit.
 *
 * `counted_quantity` is null until somebody counts it — not zero. A line
 * left uncounted is left as the books have it when the count is approved.
 *
 * No `Auditable` here on purpose: the count is the thing a person did.
 */
class StockCountLine extends Model
{
    protected $fillable = [
        'stock_count_id', 'product_id', 'system_quantity', 'counted_quantity',
        'counted_at', 'counted_by', 'variance', 'variance_value',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => QuantityCast::class,
            'counted_quantity' => QuantityCast::class,
            'variance' => QuantityCast::class,
            'variance_value' => 'decimal:2',
            'counted_at' => 'datetime',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }
}
