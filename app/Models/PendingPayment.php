<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PendingPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An order the kiosk sent to the cashier, not yet paid (CR-2).
 *
 * It holds its goods at its location until `hold_until`, then reads as
 * expired and holds nothing. The cashier takes the money on the till and it
 * becomes a sale at exactly these lines and prices; or it is cancelled with
 * a reason. Never edited and never deleted.
 */
class PendingPayment extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'location_id', 'customer_id', 'order_date', 'hold_until',
        'status', 'subtotal', 'discount', 'total_amount', 'note',
        'idempotency_key', 'taken_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PendingPaymentStatus::class,
            'order_date' => 'date',
            'hold_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PendingPaymentLine::class);
    }

    /** Where its goods are held, and where it must be paid. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The sale it became, once paid. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Pending and within its date: holding its goods, waiting at the cashier. */
    public function scopeWaiting(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->where('status', PendingPaymentStatus::Pending->value)
            ->where('hold_until', '>=', ($today ?? Carbon::today())->toDateString());
    }

    public function isPending(): bool
    {
        return $this->status === PendingPaymentStatus::Pending;
    }

    /** Pending, and its last day has passed. Read off the row. */
    public function isExpired(?Carbon $today = null): bool
    {
        return $this->isPending()
            && $this->hold_until !== null
            && $this->hold_until->lt(($today ?? Carbon::today())->copy()->startOfDay());
    }

    /** Pending and within its date: it may be paid, and it holds its goods. */
    public function isWaiting(?Carbon $today = null): bool
    {
        return $this->isPending() && ! $this->isExpired($today);
    }

    /**
     * What it reads as on screen: the stored status, or Expired for a
     * pending one past its date.
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
