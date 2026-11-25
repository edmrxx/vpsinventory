<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Stock moved from one location to another (phase 31.5).
 *
 * Posted on save. It changes no total and no value: every batch it takes at
 * the source opens a batch at the destination at the same cost. Voided with
 * a reason while what it brought in is untouched; otherwise the goods are
 * moved back with another transfer.
 *
 * The document carries `Auditable`, its lines do not.
 */
class StockTransfer extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'from_location_id', 'to_location_id', 'status', 'transfer_date',
        'notes', 'total_cost', 'idempotency_key', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'transfer_date' => 'date',
            'total_cost' => 'decimal:2',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** Where the goods left. */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /** Where the goods arrived. */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
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

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::Posted;
    }

    public function auditModule(): string
    {
        return 'Stock Transfers';
    }

    public function auditName(): string
    {
        return 'transfer';
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
