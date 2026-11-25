<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money the shop paid a supplier against its bills (phase 24).
 *
 * Posted the moment it is saved, and never edited. A mistake is voided, and
 * the bills it paid are owed again exactly what it took off them. `amount`
 * is money that went out; `credit_used` is supplier credit, from goods sent
 * back, put towards the same bills. The bills add up to both together.
 */
class SupplierPayment extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'supplier_id', 'payment_date', 'status',
        'payment_method_id', 'amount', 'credit_used', 'method_reference',
        'notes', 'idempotency_key', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'credit_used' => 'decimal:2',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /** Which bills it paid, and how much of each. */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
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

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }

    public function auditName(): string
    {
        return 'supplier payment';
    }
}
