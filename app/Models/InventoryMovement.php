<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the stock card: what moved, which way, and what was left.
 *
 * The table is append-only. A mistake is corrected with another movement —
 * a return, an adjustment or a void — never by changing a row that is
 * already written.
 */
class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'location_id', 'source_type', 'source_id', 'reference', 'type',
        'movement_date', 'quantity_in', 'quantity_out', 'balance_after',
        'unit_cost', 'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'movement_date' => 'date',
            'quantity_in' => QuantityCast::class,
            'quantity_out' => QuantityCast::class,
            'balance_after' => QuantityCast::class,
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
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

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** The batches this movement ate, when it was an outgoing one. */
    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryLayerConsumption::class);
    }

    public function scopeForProduct(Builder $query, ?int $productId): Builder
    {
        return $query->when($productId, fn (Builder $q) => $q->where('product_id', $productId));
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $query->when($type, fn (Builder $q) => $q->where('type', $type));
    }

    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        // A plain comparison, not whereDate: movement_date is already a
        // DATE column, and wrapping it in DATE() would stop MySQL using the
        // index on the largest table in the app.
        return $query
            ->when($from, fn (Builder $q) => $q->where('movement_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('movement_date', '<=', $to));
    }
}
