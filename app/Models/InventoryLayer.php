<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One batch of stock, at the cost it came in at.
 *
 * FIFO is nothing more than eating the oldest of these that still holds
 * something. There is no `Auditable` here on purpose: a purchase of ten
 * lines would otherwise write ten more rows into the trail. The document is
 * audited; its layers are not.
 */
class InventoryLayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'location_id', 'source_type', 'source_id', 'transferred_from_layer_id', 'reference',
        'layer_date', 'quantity_received', 'quantity_remaining', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'layer_date' => 'date',
            'quantity_received' => QuantityCast::class,
            'quantity_remaining' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Where this stock sits (phase 31.2). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** The batch this one was split from by a transfer (phase 31.5). */
    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_layer_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The batches this product may still be sold from, oldest first.
     *
     * The order is the id, not the date. Posting order and date order are
     * the same thing here, because a document dated before the last posted
     * one is refused.
     */
    public function scopeFifo(Builder $query, int $productId, ?int $locationId = null): Builder
    {
        return $query->where('product_id', $productId)
            // Phase 31.2: a sale at the store eats the store's batches only.
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->where('quantity_remaining', '>', 0)
            ->orderBy('id');
    }

    /** What this batch is still worth. */
    public function remainingValue(): string
    {
        return bcmul((string) $this->quantity_remaining, (string) $this->unit_cost, 2);
    }
}
