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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Goods sold.
 *
 * Posted the moment it is saved, and never edited. A mistake is put right
 * with a void while nothing has moved on, or with a sales return.
 */
class Sale extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'reference', 'location_id', 'customer_id', 'sale_date', 'status',
        'subtotal', 'discount', 'total_amount', 'total_cost',
        'on_account', 'balance_due', 'due_date', 'terms_days',
        'notes', 'idempotency_key', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'sale_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'on_account' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'due_date' => 'date',
            'terms_days' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** Where the goods left the shelf (phase 31.4). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** How it was paid: one row per method. With `on_account`, the total. */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** What customer payments put towards this sale, voided ones included. */
    /** The sales order this sale fulfilled, if any (phase 29). */
    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }

    /** The quotation this sale was made from, if any (phase 28). */
    public function quotation(): HasOne
    {
        return $this->hasOne(Quotation::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /**
     * Has a customer payment that still stands paid part of this sale?
     *
     * `$locking` is for a void, holding the sale's row: a plain read inside
     * the transaction could see a snapshot from before a payment that the
     * lock made it wait for.
     */
    public function hasLivePayments(bool $locking = false): bool
    {
        return $this->allocations()
            ->whereHas('payment', fn (Builder $q) => $q->posted())
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->exists();
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(InventoryMovement::class, 'source');
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

    /** Has anything been handed back against this sale? */
    public function hasReturns(): bool
    {
        return $this->lines()->where('returned_quantity', '>', 0)->exists();
    }

    /**
     * The VAT inside the sale, taken from what was actually charged.
     *
     * The rate is the one the switch is set to right now, not one stored on
     * the row: this is a view for comparing VAT against non-VAT, so it has
     * to move when the switch moves. Nothing here is saved.
     *
     * The discount comes off before the split, so the VAT follows the money
     * and not the price list. That means the line splits will not add up to
     * this when a discount was given; this is the figure that counts.
     *
     * @return array{net: string, vat: string}
     */
    public function vatSplit(): array
    {
        return Vat::split($this->total_amount, Vat::currentRate());
    }

    /** What was handed back across the counter. Only cash gives change. */
    public function changeGiven(): string
    {
        return $this->payments->reduce(
            fn (string $carried, SalePayment $payment) => bcadd($carried, $payment->change(), 2),
            '0.00',
        );
    }

    /** What this sale earned: what was charged, less what the goods cost. */
    public function grossProfit(): string
    {
        return bcsub((string) $this->total_amount, (string) $this->total_cost, 2);
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }

    public function auditName(): string
    {
        return 'sale';
    }
}
