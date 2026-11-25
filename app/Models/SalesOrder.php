<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\SalesOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An order a customer has placed, holding stock for them (phase 29).
 *
 * Pending until approved; approved, it reserves its lines until
 * `reserve_until`. It becomes a sale on the sale form, or is cancelled,
 * which releases the stock. Never edited and never deleted.
 */
class SalesOrder extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'location_id', 'customer_id', 'attention', 'order_date', 'reserve_until',
        'status', 'subtotal', 'discount', 'total_amount', 'notes',
        'idempotency_key', 'created_by', 'quotation_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'reserve_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    /** Where the order holds its stock (phase 31.4). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** The sale it became, once fulfilled. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Approved and still within its date: holding stock now. */
    public function scopeReserving(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->where('status', SalesOrderStatus::Approved->value)
            ->where('reserve_until', '>=', ($today ?? Carbon::today())->toDateString());
    }

    /** Approved but past its date: holding nothing any more. */
    public function scopeLapsed(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->where('status', SalesOrderStatus::Approved->value)
            ->where('reserve_until', '<', ($today ?? Carbon::today())->toDateString());
    }

    public function isPending(): bool
    {
        return $this->status === SalesOrderStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === SalesOrderStatus::Approved;
    }

    /** Pending or approved: it may still be cancelled. */
    public function isOpen(): bool
    {
        return $this->isPending() || $this->isApproved();
    }

    /** Approved, and its last reserved day has passed. Read off the row. */
    public function isExpired(?Carbon $today = null): bool
    {
        return $this->isApproved()
            && $this->reserve_until !== null
            && $this->reserve_until->lt(($today ?? Carbon::today())->copy()->startOfDay());
    }

    /** Approved and within its date: holding its stock. */
    public function isReserving(?Carbon $today = null): bool
    {
        return $this->isApproved() && ! $this->isExpired($today);
    }

    /**
     * What it reads as on screen: the stored status, or Expired for an
     * approved one past its date.
     *
     * @return array{value:string, label:string, tone:string}
     */
    public function standing(?Carbon $today = null): array
    {
        if ($this->isExpired($today)) {
            return ['value' => 'expired', 'label' => 'Expired', 'tone' => 'neutral'];
        }

        return [
            'value' => $this->status->value,
            'label' => $this->status->label(),
            'tone' => $this->status->tone(),
        ];
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
