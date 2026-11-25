<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\LossReportStatus;
use App\Http\Requests\Adjustment\StoreAdjustmentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loss somebody found: goods damaged, expired, rusted, stolen or missing
 * (phase 33).
 *
 * Reported, it holds the goods aside at their location until an approver
 * answers: approved, it posts a decrease adjustment with the same reason;
 * rejected or withdrawn, the goods are free again. Never edited or deleted.
 *
 * The document carries `Auditable`, its lines and photos do not.
 */
class LossReport extends Model
{
    use Auditable;

    protected $fillable = [
        'reference', 'location_id', 'reason', 'status', 'report_date', 'notes',
        'idempotency_key', 'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LossReportStatus::class,
            'report_date' => 'date',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_cost' => 'decimal:2',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LossReportLine::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LossReportPhoto::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** The write-off approving it posted. */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id');
    }

    public function isPending(): bool
    {
        return $this->status === LossReportStatus::Pending;
    }

    public function wasReportedBy(User $user): bool
    {
        return (int) $this->reported_by === (int) $user->id;
    }

    public function reasonLabel(): string
    {
        return StoreAdjustmentRequest::REASONS[$this->reason] ?? (string) $this->reason;
    }

    public function auditModule(): string
    {
        return 'Loss Reports';
    }

    public function auditName(): string
    {
        return 'loss report';
    }

    public function auditLabel(): string
    {
        return (string) $this->reference;
    }
}
