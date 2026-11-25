<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One supplier's prices for an approved purchase request (phase 24.2).
 *
 * Not audited by itself: the request's trail says when a quote was recorded
 * or removed, in one line, as the documents do.
 */
class SupplierQuote extends Model
{
    protected $fillable = [
        'purchase_request_id', 'supplier_id', 'delivery_days', 'payment_terms', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['delivery_days' => 'integer'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuoteLine::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
