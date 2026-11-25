<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customers.view');
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->can('customers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('customers.create');
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->can('customers.update');
    }

    /**
     * The walk-in customer is protected, the way the Admin role is. Every
     * counter sale names it, so the app stops working without it.
     *
     * A customer named on a sale stays as well, or those sales would be
     * left unreadable. Switch it off instead.
     */
    public function delete(User $actor, Customer $customer): Response
    {
        if (! $actor->can('customers.delete')) {
            return Response::deny('You may not delete customers.');
        }

        if ($customer->is_walk_in) {
            return Response::deny('The walk-in customer is used by counter sales and cannot be deleted.');
        }

        $sales = $this->saleCount($customer);

        if ($sales > 0) {
            return Response::deny(
                "This customer is named on {$sales} sale(s). "
                    .'Deleting it would leave them unreadable, so switch it off instead.'
            );
        }

        $quotes = (int) ($customer->quotations_count ?? $customer->quotations()->count())
            + (int) ($customer->sales_orders_count ?? $customer->salesOrders()->count())
            + (int) ($customer->pending_payments_count ?? $customer->pendingPayments()->count());

        if ($quotes > 0) {
            return Response::deny(
                "This customer is named on {$quotes} quotation(s), sales order(s) or pending payment(s). "
                    .'Deleting it would leave them unreadable, so switch it off instead.'
            );
        }

        return Response::allow();
    }

    /**
     * How many sales name this customer.
     *
     * The list screen loads the figure with `withCount`, so a page of rows
     * asks the database nothing extra.
     */
    protected function saleCount(Customer $customer): int
    {
        return (int) ($customer->sales_count ?? $customer->sales()->count());
    }

    public function updateStatus(User $actor, Customer $customer): Response
    {
        if (! $actor->can('customers.status')) {
            return Response::deny('You may not change the status of customers.');
        }

        if ($customer->is_walk_in) {
            return Response::deny('The walk-in customer is used by counter sales and must stay active.');
        }

        return Response::allow();
    }
}
