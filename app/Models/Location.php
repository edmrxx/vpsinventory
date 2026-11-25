<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place that holds stock: the store or the warehouse (phase 31.1).
 *
 * Since phase 31.2 every batch and movement names one, and
 * `location_stocks` holds what each product has at each. **One location is
 * the default** (`is_default`): every document posts there until phases 31.3
 * and 31.4 let a document name its own. The default stays active, and it
 * does not change while it holds stock — the till would otherwise sell from
 * an empty place while the screen still showed the stock.
 *
 * A location holding stock is not switched off; one named on a batch or a
 * movement is never deleted. `LocationPolicy` holds those rules.
 */
class Location extends Model
{
    use Auditable, HasFactory;

    /**
     * `is_default` is left out on purpose: it changes only through
     * `LocationController`, which clears the old default in the same
     * transaction.
     */
    protected $fillable = ['name', 'type', 'address', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** What each product has here. */
    public function stocks(): HasMany
    {
        return $this->hasMany(LocationStock::class);
    }

    public function layers(): HasMany
    {
        return $this->hasMany(InventoryLayer::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Sales orders holding stock here (phase 31.4). */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** Purchase orders asking to be delivered here (phase 31.3). */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'deliver_to_location_id');
    }

    /**
     * The location every document posts at until it can name one, or null
     * when none is marked — the ledger then refuses with a message the
     * person at the screen can act on.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('id')->first();
    }

    /**
     * Where a form starts (phase 31.4): the location asked for, if it is
     * still active (and of the type asked for); else the fallback — where
     * the order holds its stock — on the same terms; else the default, when
     * it qualifies; else the first active one that does. Null only when no
     * active location qualifies.
     *
     * Only ever a starting point. The form request and the poster still
     * decide what is posted.
     */
    public static function startingAt(?int $requested, ?int $fallback = null, ?LocationType $type = null): ?self
    {
        $usable = fn (?self $l) => $l && $l->is_active && ($type === null || $l->type === $type) ? $l : null;

        return $usable($requested ? static::find($requested) : null)
            ?? $usable($fallback ? static::find($fallback) : null)
            ?? $usable(static::default())
            ?? static::query()->where('is_active', true)
                ->when($type, fn ($q) => $q->where('type', $type->value))
                ->orderBy('name')->first();
    }

    /**
     * Whether any product has stock here. Read from the list's `withExists`
     * when it was loaded, so a page of rows asks nothing extra.
     */
    public function holdsStock(): bool
    {
        if (array_key_exists('holds_stock', $this->attributes)) {
            return (bool) $this->attributes['holds_stock'];
        }

        return $this->stocks()->where('on_hand', '!=', 0)->exists();
    }

    /**
     * Whether its history needs it: a batch or movement names it, or an
     * order does — a sales order holding stock here, a purchase order to be
     * delivered here — or a count sheet (phase 32) or a loss report (phase 33). None of those moves
     * stock, so without them a location named only by one would pass and the
     * database would refuse the delete.
     */
    public function hasHistory(): bool
    {
        if (array_key_exists('has_history', $this->attributes)) {
            return (bool) $this->attributes['has_history'] || (bool) ($this->attributes['named_on_orders'] ?? false);
        }

        return $this->movements()->exists() || $this->layers()->exists()
            || $this->salesOrders()->exists() || $this->purchaseOrders()->exists()
            || StockCount::where('location_id', $this->id)->exists()
            || LossReport::where('location_id', $this->id)->exists()
            || PendingPayment::where('location_id', $this->id)->exists();
    }

    /**
     * Adds `holds_stock`, `has_history` and `named_on_orders` to a list
     * query: sub-selects, not a query per row.
     */
    public function scopeWithUsage(Builder $query): Builder
    {
        return $query
            ->withExists(['stocks as holds_stock' => fn ($q) => $q->where('on_hand', '!=', 0)])
            ->withExists('movements as has_history')
            ->selectRaw('(EXISTS (SELECT 1 FROM sales_orders WHERE sales_orders.location_id = locations.id)'
                .' OR EXISTS (SELECT 1 FROM purchase_orders WHERE purchase_orders.deliver_to_location_id = locations.id)'
                .' OR EXISTS (SELECT 1 FROM stock_counts WHERE stock_counts.location_id = locations.id)'
                .' OR EXISTS (SELECT 1 FROM loss_reports WHERE loss_reports.location_id = locations.id)'
                .' OR EXISTS (SELECT 1 FROM pending_payments WHERE pending_payments.location_id = locations.id)) AS named_on_orders');
    }

    /** Only the locations a new document may still pick. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The pick list a document will offer: the active locations, plus a
     * switched-off one when `$keep` names it, so an old choice still reads.
     *
     * @return list<array{value:int,label:string,type:string}>
     */
    public static function options(?int $keep = null): array
    {
        return static::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhere('id', $keep)))
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name, 'type' => $l->type->value])
            ->all();
    }
}
