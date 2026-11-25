<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\StockCountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A count sheet for one location (phase 32).
 *
 * Started, it holds what the books said at the cutoff; the counters write
 * what they find beside it; an approver approves it, and only then do the
 * differences post — as adjustments with the reason *count*. Until then it
 * moves nothing, so the counted figures may be entered and changed. Never
 * deleted: cancelled with a reason.
 *
 * Only one count may be open at a location at a time: two sheets of the same
 * shelf would post the same difference twice.
 *
 * The document carries `Auditable`, its lines do not.
 */
class StockCount extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'location_id', 'category_id', 'status', 'count_date', 'cutoff_at',
        'notes', 'idempotency_key', 'started_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'count_date' => 'date',
            'cutoff_at' => 'datetime',
            'submitted_at' => 'datetime',
            'sent_back_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'gain_value' => 'decimal:2',
            'loss_value' => 'decimal:2',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function sentBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_back_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** What was found over, posted as an increase. */
    public function gainAdjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'gain_adjustment_id');
    }

    /** What was short, posted as a decrease. */
    public function lossAdjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'loss_adjustment_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isCounting(): bool
    {
        return $this->status === StockCountStatus::Counting;
    }

    public function isSubmitted(): bool
    {
        return $this->status === StockCountStatus::Submitted;
    }

    /** Started or handed in by this person: approving it is their own. */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->started_by === (int) $user->id
            || (int) $this->submitted_by === (int) $user->id;
    }

    public function auditModule(): string
    {
        return 'Stock Counts';
    }

    public function auditName(): string
    {
        return 'stock count';
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
