<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SupplierPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('suppliers.view');
    }

    public function view(User $actor, Supplier $supplier): bool
    {
        return $actor->can('suppliers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('suppliers.create');
    }

    public function update(User $actor, Supplier $supplier): bool
    {
        return $actor->can('suppliers.update');
    }

    /**
     * A supplier named on a purchase stays, so those purchases keep reading
     * correctly. Switch it off instead.
     */
    public function delete(User $actor, Supplier $supplier): Response
    {
        if (! $actor->can('suppliers.delete')) {
            return Response::deny('You may not delete suppliers.');
        }

        $purchases = $this->purchaseCount($supplier);

        if ($purchases > 0) {
            return Response::deny(
                "This supplier is named on {$purchases} purchase(s). "
                    .'Deleting it would leave them unreadable, so switch it off instead.'
            );
        }

        $orders = (int) ($supplier->purchase_orders_count ?? $supplier->purchaseOrders()->count());

        if ($orders > 0) {
            return Response::deny(
                "This supplier is named on {$orders} purchase order(s). "
                    .'Deleting it would leave them unreadable, so switch it off instead.'
            );
        }

        return Response::allow();
    }

    /**
     * How many purchases name this supplier.
     *
     * The list screen loads the figure with `withCount`, so a page of rows
     * asks the database nothing extra.
     */
    protected function purchaseCount(Supplier $supplier): int
    {
        return (int) ($supplier->purchases_count ?? $supplier->purchases()->count());
    }

    public function updateStatus(User $actor, Supplier $supplier): bool
    {
        return $actor->can('suppliers.status');
    }
}
