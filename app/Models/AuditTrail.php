<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line in the audit trail.
 *
 * Rows are written once and never changed, so the model carries no update
 * path and the screen that shows it is read-only.
 */
class AuditTrail extends Model
{
    /** The table only keeps created_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'username',
        'role',
        'module',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'browser',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The account that did it. Null once that account is deleted; the
     * username column still holds who it was.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The record it touched, when there is one.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------- Filters */

    public function scopeForUser(Builder $query, int|string|null $userId): Builder
    {
        return $query->when($userId, fn (Builder $q) => $q->where('user_id', $userId));
    }

    public function scopeForModule(Builder $query, ?string $module): Builder
    {
        return $query->when($module, fn (Builder $q) => $q->where('module', $module));
    }

    public function scopeForAction(Builder $query, ?string $action): Builder
    {
        return $query->when($action, fn (Builder $q) => $q->where('action', $action));
    }

    /**
     * Both ends are inclusive, and the dates are read in the app timezone.
     */
    public function scopeBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', "{$from} 00:00:00"))
            ->when($to, fn (Builder $q) => $q->where('created_at', '<=', "{$to} 23:59:59"));
    }
}
