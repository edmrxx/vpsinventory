<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PurchaseRequestStatus;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Somebody asking for goods to be bought (phase 21).
 *
 * Raised, then approved or rejected by somebody holding
 * `purchase_requests.approve` who did not raise it. Never edited and never
 * deleted: a request typed wrongly is closed with a reason and raised again.
 * It moves no stock and owes no money, so neither the ledger nor any figure
 * on the books reads it — only the Purchase requests card on the dashboard.
 */
class PurchaseRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'request_date', 'needed_by', 'status', 'supplier_id', 'department_id',
        'reason', 'estimated_total', 'notes', 'idempotency_key', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseRequestStatus::class,
            'request_date' => 'date',
            'needed_by' => 'date',
            'estimated_total' => 'decimal:2',
            'decided_at' => 'datetime',
            'awarded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class);
    }

    /** The supplier the requester would buy from. A suggestion only. */
    /** Who it is raised for, for example Electrical (phase 24.1). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The order this request became, once it was ordered (phase 22). */
    /**
     * The orders it became: one when it was ordered by hand, one for each
     * winning supplier when it was ordered from its award (phase 24.3).
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->orderBy('id');
    }

    /** It has become at least one order. */
    public function isOrdered(): bool
    {
        return $this->relationLoaded('purchaseOrders')
            ? $this->purchaseOrders->isNotEmpty()
            : $this->purchaseOrders()->exists();
    }

    /** The suppliers' prices against it (phase 24.2). */
    public function supplierQuotes(): HasMany
    {
        return $this->hasMany(SupplierQuote::class);
    }

    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    /** A supplier has been chosen for its items (phase 24.2). */
    public function isAwarded(): bool
    {
        return $this->awarded_at !== null;
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PurchaseRequestStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === PurchaseRequestStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === PurchaseRequestStatus::Approved;
    }

    /** Pending or approved: it may still be closed. */
    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? false;
    }

    /**
     * May an approver answer a request they raised themselves?
     *
     * The setting `purchasing.self_approval`, on by default (12 November
     * 2026): with one approver, a strict rule would leave the owner's own
     * requests waiting for ever. Read from the settings already in memory,
     * so the list can ask it once per row.
     */
    public static function selfApprovalAllowed(): bool
    {
        return (bool) settings('purchasing.self_approval');
    }

    /** Did this person raise it? Read off the row, so it asks nothing. */
    public function wasRaisedBy(User $user): bool
    {
        return $this->requested_by !== null && (int) $this->requested_by === (int) $user->id;
    }

    /**
     * What is waiting for approval: how many, roughly what they would cost,
     * and since when. One query.
     *
     * @param  int|null  $staffId  Only the requests this person raised.
     * @return array{count:int, estimated:float, oldest:?Carbon}
     */
    public static function waiting(?int $staffId = null): array
    {
        $row = static::query()
            ->pending()
            ->when($staffId, fn ($q, $id) => $q->where('requested_by', $id))
            ->selectRaw('COUNT(*) AS waiting, COALESCE(SUM(estimated_total), 0) AS estimated, MIN(request_date) AS oldest')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->waiting ?? 0),
            'estimated' => Quantity::centavos((float) ($row->estimated ?? 0)) / 100,
            'oldest' => ! empty($row->oldest) ? Carbon::parse($row->oldest)->startOfDay() : null,
        ];
    }

    /**
     * The requests that have waited longest, for the dashboard's list.
     *
     * @return list<array<string, mixed>>
     */
    public static function longestWaiting(?int $staffId = null, int $limit = 5): array
    {
        $today = Carbon::today();

        return static::query()
            ->pending()
            ->when($staffId, fn ($q, $id) => $q->where('requested_by', $id))
            ->with(['requestedBy:id,name', 'supplier:id,name'])
            ->withCount('lines')
            ->orderBy('request_date')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (self $request) => [
                'id' => $request->id,
                'reference' => $request->reference,
                'requested_by' => $request->requestedBy?->name,
                'supplier' => $request->supplier?->name,
                'lines' => (int) $request->lines_count,
                'estimated' => (float) $request->estimated_total,
                'raised_on' => $request->request_date?->format('d M Y'),
                'days' => (int) $request->request_date?->diffInDays($today),
            ])
            ->all();
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
