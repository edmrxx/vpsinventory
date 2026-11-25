<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('units.view');
    }

    public function view(User $actor, Unit $unit): bool
    {
        return $actor->can('units.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('units.create');
    }

    public function update(User $actor, Unit $unit): bool
    {
        return $actor->can('units.update');
    }

    /**
     * A unit that products are counted in stays, so those products keep
     * reading correctly. Switch it off instead.
     */
    public function delete(User $actor, Unit $unit): Response
    {
        if (! $actor->can('units.delete')) {
            return Response::deny('You may not delete units.');
        }

        $products = $this->productCount($unit);

        if ($products > 0) {
            return Response::deny(
                "This unit is used by {$products} ".($products === 1 ? 'product' : 'products')
                    .'. Move them first, or switch the unit off instead.'
            );
        }

        $packs = $this->otherUnitCount($unit);

        if ($packs > 0) {
            return Response::deny(
                "{$packs} ".($packs === 1 ? 'product is' : 'products are')
                    .' also sold in this unit. Take it off them first, or switch the unit off instead.'
            );
        }

        if ($this->lineCount($unit) > 0) {
            return Response::deny(
                'Documents already on the books were typed in this unit, so it stays. Switch it off instead.'
            );
        }

        return Response::allow();
    }

    public function updateStatus(User $actor, Unit $unit): bool
    {
        return $actor->can('units.status');
    }

    /**
     * How many products are counted in this unit.
     *
     * The list screen loads the figure with `withCount`, so a page of rows
     * asks nothing extra. Only a single-record route falls back to a query.
     */
    protected function productCount(Unit $unit): int
    {
        return (int) ($unit->products_count ?? $unit->products()->count());
    }

    /** How many products are also sold in this unit, as a box or a roll. */
    protected function otherUnitCount(Unit $unit): int
    {
        return (int) ($unit->product_units_count ?? $unit->productUnits()->count());
    }

    /** How many sale, purchase, request and order lines were typed in this unit. */
    protected function lineCount(Unit $unit): int
    {
        return (int) ($unit->sale_lines_count ?? $unit->saleLines()->count())
            + (int) ($unit->purchase_lines_count ?? $unit->purchaseLines()->count())
            + (int) ($unit->purchase_request_lines_count ?? $unit->purchaseRequestLines()->count())
            + (int) ($unit->purchase_order_lines_count ?? $unit->purchaseOrderLines()->count())
            + (int) ($unit->quotation_lines_count ?? $unit->quotationLines()->count())
            + (int) ($unit->sales_order_lines_count ?? $unit->salesOrderLines()->count())
            + (int) ($unit->pending_payment_lines_count ?? $unit->pendingPaymentLines()->count());
    }
}
