<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The unit a product is counted in, for example pcs or box.
 *
 * `allows_decimals` decides whether its products may be counted in parts:
 * on for kg or m, off for pcs or bag. A product follows its unit, so 2.5
 * pieces is refused on every form and in the ledger.
 */
class Unit extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['code', 'name', 'allows_decimals', 'is_active'];

    protected function casts(): array
    {
        return [
            'allows_decimals' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Products bought and sold in this unit as well as their own: a box of 100. */
    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /** Sale lines typed in this unit. */
    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /** Purchase lines typed in this unit. */
    public function purchaseLines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /** Sales order lines typed in this unit (phase 29). */
    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    /** Pending payment lines typed in this unit (CR-2). */
    public function pendingPaymentLines(): HasMany
    {
        return $this->hasMany(PendingPaymentLine::class);
    }

    /** Quotation lines typed in this unit (phase 28). */
    public function quotationLines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    /** Purchase order lines typed in this unit. */
    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** Purchase request lines typed in this unit. */
    public function purchaseRequestLines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class);
    }

    /** The code is what a document shows, so it names the row in the trail. */
    public function auditLabel(): string
    {
        return (string) $this->code;
    }

    /**
     * How many of its products hold a quantity with decimals.
     *
     * While any does, the switch cannot be turned off: 0.5 pcs could then
     * never be sold, returned or adjusted away.
     */
    public function productsHoldingFractions(): int
    {
        return $this->products()
            ->where(fn (Builder $q) => $q
                ->whereRaw('ROUND(on_hand, 0) <> on_hand')
                ->orWhereRaw('ROUND(reorder_level, 0) <> reorder_level'))
            ->count();
    }

    /**
     * How many of its products are sold in another unit holding a part of
     * this one: a roll of 75.5 m.
     *
     * While any is, the switch cannot be turned off: every roll sold would
     * come to 75.5 of a unit counted whole, and be refused.
     */
    public function productsPackedInParts(): int
    {
        return $this->products()
            ->whereHas('otherUnits', fn (Builder $q) => $q->whereRaw('ROUND(factor, 0) <> factor'))
            ->count();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
