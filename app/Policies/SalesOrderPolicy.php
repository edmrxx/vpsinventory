<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may read, take, approve and cancel a sales order (phase 29).
 *
 * Decision 2 is still open, so this is the default taken on GO!: whoever
 * holds `sales_orders.approve` approves — Admin, through `*` — their own
 * order included, because the shop has one approver; the trail says when
 * it was their own. Staff take orders and may cancel them; approving holds
 * stock, which turns customers away, so it stays with the approver.
 *
 * No update() or delete(). No query in here: it runs once per row.
 */
class SalesOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales_orders.view');
    }

    public function view(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales_orders.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales_orders.create');
    }

    public function approve(User $actor, SalesOrder $order): Response
    {
        if (! $actor->can('sales_orders.approve')) {
            return Response::deny('You may not approve sales orders.');
        }

        if (! $order->isPending()) {
            return Response::deny('This order is already '.strtolower($order->standing()['label']).'.');
        }

        return Response::allow();
    }

    public function cancel(User $actor, SalesOrder $order): Response
    {
        if (! $actor->can('sales_orders.cancel')) {
            return Response::deny('You may not cancel sales orders.');
        }

        if (! $order->isOpen()) {
            return Response::deny('This order is already '.strtolower($order->standing()['label']).'.');
        }

        return Response::allow();
    }

    /** Turn it into a sale: approved, and this person may sell. */
    public function sell(User $actor, SalesOrder $order): bool
    {
        return $order->isApproved() && $actor->can('sales.create');
    }
}
