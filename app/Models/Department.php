<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A part of the business that asks to buy goods, for example Electrical or
 * Construction (phase 24.1). A purchase request is raised for one.
 */
class Department extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /** Only the departments a new request may still pick. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The pick list a purchase request offers: the active departments, plus
     * a switched-off one when `$keep` names it, so an old choice still reads.
     *
     * @return list<array{value:int,label:string}>
     */
    public static function options(?int $keep = null): array
    {
        return static::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhere('id', $keep)))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name])
            ->all();
    }
}
