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
 * Goods a customer brought back.
 *
 * Always tied to the sale it undoes, so every unit goes back into the batch
 * it actually left and is costed at what it actually cost.
 *
 * A return is never voided. If one is wrong, it is corrected with another
 * return or with an adjustment — undoing an undo helps nobody read the
 * history.
 */
class PurchaseReturn extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'reference', 'purchase_id', 'return_date', 'status', 'reason', 'notes',
        'total_cost', 'credited_amount', 'supplier_credit_amount', 'idempotency_key', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'return_date' => 'date',
            'total_cost' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'supplier_credit_amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function auditModule(): string
    {
        return 'Purchase Returns';
    }

    public function auditName(): string
    {
        return 'purchase return';
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
