<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\DocumentStatus;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Goods bought in.
 *
 * Posted the moment it is saved, and never edited afterwards. A mistake is
 * put right with a void while the stock is untouched, or with a purchase
 * return once some of it has been sold.
 *
 * The document carries `Auditable`; its lines do not.
 */
class Purchase extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'reference', 'supplier_reference', 'supplier_id', 'location_id', 'purchase_order_id', 'purchase_date',
        'on_account', 'balance_due', 'due_date',
        'status', 'total_amount', 'vat_rate', 'notes', 'idempotency_key',
        'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'purchase_date' => 'date',
            'total_amount' => 'decimal:2',
            'on_account' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'due_date' => 'date',
            'vat_rate' => 'decimal:2',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Where the goods were received and went on the shelf (phase 31.3). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** The order this delivery was received against, if any (phase 23). */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /** What supplier payments put towards this bill (phase 24). */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * Has a supplier payment that still stands paid part of this bill?
     *
     * `$locking` is for a void, holding the purchase's row, the same way a
     * sale's void asks about customer payments.
     */
    public function hasLivePayments(bool $locking = false): bool
    {
        return $this->allocations()
            ->whereHas('payment', fn (Builder $q) => $q->posted())
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->exists();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
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

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Posted->value);
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::Posted;
    }

    /**
     * The VAT inside the delivery.
     *
     * The rate it was **invoiced at**, read off the row, not the Tax
     * Settings switch. What a supplier charges is a fact about that
     * supplier, so the buying side does not follow our own registration —
     * and the day a supplier registers for VAT, last year's deliveries
     * from them must not quietly redraw.
     *
     * A purchase carries no document discount, so this is simply the total
     * split in two.
     *
     * @return array{net: string, vat: string}
     */
    public function vatSplit(): array
    {
        return Vat::split($this->total_amount, $this->vat_rate);
    }

    /** Was this delivery invoiced with VAT on it? */
    public function isVatable(): bool
    {
        return (float) $this->vat_rate > 0;
    }

    /**
     * Is every batch this purchase brought in still untouched?
     *
     * Once a single unit has been sold, voiding would have to take stock
     * back that is no longer there. That is what a purchase return is for.
     */
    public function stockIsUntouched(): bool
    {
        return ! $this->layers()
            ->whereColumn('quantity_remaining', '<', 'quantity_received')
            ->exists();
    }

    /** Has anything been sent back against this delivery? */
    public function hasReturns(): bool
    {
        return $this->lines()->where('returned_quantity', '>', 0)->exists();
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }

    public function auditName(): string
    {
        return 'purchase';
    }
}
