<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may read, place and cancel a purchase order.
 *
 * Placing an order commits the shop to a supplier, so Staff only read
 * orders; Admin places and cancels them through `*`. Since phase 24.4 an
 * order is approved before it is sent: whoever holds
 * `purchase_orders.approve` (Admin) approves or rejects it. Whether they
 * may answer an order they placed is the setting `purchasing.self_approval`
 * — the same switch as for requests; on, their own is approved as they
 * place it.
 *
 * No update() or delete(): an order is never edited or deleted, and the
 * routes do not exist either. No query in here: it runs once per row.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase_orders.view');
    }

    public function view(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase_orders.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase_orders.create');
    }

    public function cancel(User $actor, PurchaseOrder $order): Response
    {
        if (! $actor->can('purchase_orders.cancel')) {
            return Response::deny('You may not cancel purchase orders.');
        }

        if (! $order->isOpen() && ! $order->isPendingApproval()) {
            return Response::deny('This order is '.strtolower($order->status->label()).', so it cannot be cancelled.');
        }

        return Response::allow();
    }

    public function approve(User $actor, PurchaseOrder $order): Response
    {
        return $this->decide($actor, $order);
    }

    public function reject(User $actor, PurchaseOrder $order): Response
    {
        return $this->decide($actor, $order);
    }

    /** Approve or reject: an order waiting, and not one's own unless allowed. */
    protected function decide(User $actor, PurchaseOrder $order): Response
    {
        if (! $actor->can('purchase_orders.approve')) {
            return Response::deny('You may not approve purchase orders.');
        }

        if (! $order->isPendingApproval()) {
            return Response::deny('This order is '.strtolower($order->status->label()).', so it is not waiting for approval.');
        }

        if ($order->wasPlacedBy($actor) && ! PurchaseRequest::selfApprovalAllowed()) {
            return Response::deny('You placed this order, so somebody else must approve or reject it.');
        }

        return Response::allow();
    }

    /** Close a partly received order short. The same permission as cancelling. */
    public function close(User $actor, PurchaseOrder $order): Response
    {
        if (! $actor->can('purchase_orders.cancel')) {
            return Response::deny('You may not close purchase orders.');
        }

        if ($order->status !== PurchaseOrderStatus::PartlyReceived) {
            return Response::deny('Only a partly received order is closed short.');
        }

        return Response::allow();
    }
}
