<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A correction to stock that no purchase or sale explains: the opening
 * count, a breakage, a recount.
 *
 * The document carries `Auditable`, its lines do not. One adjustment of ten
 * lines must read as one row in the trail, not eleven.
 *
 * Phase 7 builds the table and the posting. Phase 10 adds the screens.
 */
class InventoryAdjustment extends Model
{
    use Auditable, HasFactory;

    public const KIND_OPENING = 'opening';

    public const KIND_INCREASE = 'increase';

    public const KIND_DECREASE = 'decrease';

    protected $fillable = [
        'reference', 'location_id', 'kind', 'status', 'adjustment_date',
        'reason', 'notes', 'total_cost', 'idempotency_key', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'adjustment_date' => 'date',
            'total_cost' => 'decimal:2',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** Where the stock was counted in or out (phase 31.4). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
    }

    public function layers(): MorphMany
    {
        return $this->morphMany(InventoryLayer::class, 'source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function auditModule(): string
    {
        return 'Inventory Adjustments';
    }

    public function auditName(): string
    {
        return 'adjustment';
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
