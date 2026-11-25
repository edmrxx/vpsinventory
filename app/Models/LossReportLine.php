<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a loss report, in the product's own unit. While the report
 * waits, this quantity is held at the report's location.
 *
 * No `Auditable` here on purpose: the report is the thing a person did.
 */
class LossReportLine extends Model
{
    protected $fillable = ['loss_report_id', 'product_id', 'quantity', 'unit_cost', 'total_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(LossReport::class, 'loss_report_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
