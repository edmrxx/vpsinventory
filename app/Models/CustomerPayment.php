<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money a customer brought in against what they owe.
 *
 * Posted the moment it is saved, and never edited. A mistake is voided, and
 * the invoices it paid owe again exactly what it took off them.
 *
 * It is not an official receipt and is never called one: an official or
 * collection receipt is a BIR document this system does not issue.
 */
class CustomerPayment extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'customer_id', 'payment_date', 'status',
        'payment_method_id', 'amount', 'method_reference',
        'notes', 'idempotency_key', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /** Which invoices it paid, and how much of each. */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
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
        return 'customer payment';
    }
}
