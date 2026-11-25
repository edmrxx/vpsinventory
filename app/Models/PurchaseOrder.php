<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PurchaseOrderStatus;
use App\Support\Vat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Goods the shop has asked a supplier to deliver (phase 22).
 *
 * Placed from an approved purchase request, or directly. It moves no stock
 * and owes nothing: the delivery posted under Purchases does both. Never
 * edited and never deleted; an order placed wrongly is cancelled with a
 * reason.
 *
 * Since phase 24.4 an order is approved before it is sent: it starts *For
 * approval* unless whoever placed it may approve their own, and nothing is
 * received against it until it is approved.
 */
class PurchaseOrder extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'supplier_id', 'deliver_to_location_id', 'purchase_request_id', 'order_date',
        'expected_date', 'status', 'total_amount', 'vat_rate', 'notes',
        'idempotency_key', 'ordered_by', 'approved_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'total_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Where the supplier is to deliver (phase 31.3). Optional: an order from
     * before this phase names none. Receiving fills *Receive at* from it.
     */
    public function deliverTo(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'deliver_to_location_id');
    }

    /** The approved request this order came from, if any. */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** Waiting for somebody who may approve orders (phase 24.4). */
    public function isPendingApproval(): bool
    {
        return $this->status === PurchaseOrderStatus::PendingApproval;
    }

    /** Waiting for approval or rejected: it prints only as a draft. */
    public function isUnapproved(): bool
    {
        return $this->status?->isUnapproved() ?? false;
    }

    /** Did this person place it? Read off the row, so it asks nothing. */
    public function wasPlacedBy(User $user): bool
    {
        return $this->ordered_by !== null && (int) $this->ordered_by === (int) $user->id;
    }

    /**
     * What is waiting for approval: how many, what they come to, and since
     * when. One query, for the dashboard card. The Staff filter narrows it to
     * the orders that person placed.
     *
     * @return array{count:int, total:float, oldest:?Carbon}
     */
    public static function waitingForApproval(?int $staffId = null): array
    {
        $row = static::query()
            ->where('status', PurchaseOrderStatus::PendingApproval->value)
            ->when($staffId, fn ($q, $id) => $q->where('ordered_by', $id))
            ->selectRaw('COUNT(*) AS waiting, COALESCE(SUM(total_amount), 0) AS total, MIN(order_date) AS oldest')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->waiting ?? 0),
            'total' => round((float) ($row->total ?? 0), 2),
            'oldest' => ! empty($row->oldest) ? Carbon::parse($row->oldest)->startOfDay() : null,
        ];
    }

    /** The deliveries posted against it, voided ones included. */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** Still waiting for goods: open or partly received. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', PurchaseOrderStatus::outstandingValues());
    }

    /** Placed, and nothing delivered yet: it may still be cancelled. */
    public function isOpen(): bool
    {
        return $this->status === PurchaseOrderStatus::Open;
    }

    /** Open or partly received: a delivery may be posted against it. */
    public function isOutstanding(): bool
    {
        return $this->status?->isOutstanding() ?? false;
    }

    /** Waiting for goods and past the day they were expected. */
    public function isLate(?Carbon $today = null): bool
    {
        return $this->isOutstanding()
            && $this->expected_date !== null
            && $this->expected_date->lt($today ?? Carbon::today());
    }

    /**
     * The total, net of VAT and the VAT in it, at the rate stamped on the
     * order. A Non-VAT supplier's order splits nothing.
     *
     * @return array{net:string, vat:string}
     */
    public function vatSplit(): array
    {
        return Vat::split($this->total_amount, $this->vat_rate);
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
