<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A price offered to a customer, valid until a date (phase 28).
 *
 * Moves no stock, owes nothing and reserves nothing. Never edited and never
 * deleted: accepted when it becomes a sale, cancelled with a reason, or left
 * to expire. An expired one is quoted again rather than reopened, because
 * prices move.
 */
class Quotation extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'customer_id', 'attention', 'quote_date', 'valid_until',
        'status', 'subtotal', 'discount', 'mobilization', 'total_amount', 'notes',
        'idempotency_key', 'prepared_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'quote_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'mobilization' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The sales order it became, if it was ordered (phase 29). */
    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }

    /** The sale it became, once accepted. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Open and still within its date: it may become a sale. */
    public function scopeLive(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->where('status', QuotationStatus::Open->value)
            ->where('valid_until', '>=', ($today ?? Carbon::today())->toDateString());
    }

    /** Open but past its date. */
    public function scopeExpired(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->where('status', QuotationStatus::Open->value)
            ->where('valid_until', '<', ($today ?? Carbon::today())->toDateString());
    }

    public function isOpen(): bool
    {
        return $this->status === QuotationStatus::Open;
    }

    /** Open, and its last valid day has passed. Read off the row. */
    public function isExpired(?Carbon $today = null): bool
    {
        return $this->isOpen()
            && $this->valid_until !== null
            && $this->valid_until->lt(($today ?? Carbon::today())->copy()->startOfDay());
    }

    /** Open and within its date: it may still become a sale. */
    public function isLive(?Carbon $today = null): bool
    {
        return $this->isOpen() && ! $this->isExpired($today);
    }

    /**
     * What it reads as on screen: the stored status, or Expired for an open
     * one past its date.
     *
     * @return array{value:string, label:string, tone:string}
     */
    public function standing(?Carbon $today = null): array
    {
        if ($this->isExpired($today)) {
            return ['value' => 'expired', 'label' => 'Expired', 'tone' => 'warning'];
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
