<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's price for one request line, per the unit the line was typed
 * in, VAT included (phase 24.2).
 */
class SupplierQuoteLine extends Model
{
    protected $fillable = ['supplier_quote_id', 'purchase_request_line_id', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id');
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestLine::class, 'purchase_request_line_id');
    }
}
