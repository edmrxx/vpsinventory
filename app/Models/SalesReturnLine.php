<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product coming back from a sale.
 *
 * The refund is prorated: a sale with a discount on it must not hand back
 * more than the customer was actually charged.
 */
class SalesReturnLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_return_id', 'sale_line_id', 'product_id',
        'quantity', 'unit_refund', 'line_refund', 'line_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_refund' => 'decimal:2',
            'line_refund' => 'decimal:2',
            'line_cost' => 'decimal:2',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
