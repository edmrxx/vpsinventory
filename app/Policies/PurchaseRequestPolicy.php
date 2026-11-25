<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may raise, decide and close a purchase request.
 *
 * Decision 2 in `SCOPE.md` asks who approves. Until the client answers,
 * the default (taken on GO!, 12 November 2026) is: whoever holds
 * `purchase_requests.approve` — Admin, through `*`. Whether that person may
 * answer a request they raised is the setting `purchasing.self_approval`,
 * on by default, because the shop has one approver. Naming somebody else is
 * a tick on the Roles screen, not a change here.
 *
 * There is deliberately no update() or delete(): a request is never edited
 * or deleted, and the routes do not exist either. No query in here: it runs
 * once per row on the list.
 */
class PurchaseRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase_requests.view');
    }

    public function view(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase_requests.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase_requests.create');
    }

    public function approve(User $actor, PurchaseRequest $request): Response
    {
        return $this->decide($actor, $request);
    }

    public function reject(User $actor, PurchaseRequest $request): Response
    {
        return $this->decide($actor, $request);
    }

    /**
     * Close a request nobody needs any more.
     *
     * The person who raised it may withdraw it; somebody who may approve
     * may close any. Pending or approved only.
     */
    public function close(User $actor, PurchaseRequest $request): Response
    {
        if (! $request->isOpen()) {
            return Response::deny('This request is already '.strtolower($request->status->label()).'.');
        }

        if ($actor->can('purchase_requests.approve')) {
            return Response::allow();
        }

        if ($request->wasRaisedBy($actor) && $actor->can('purchase_requests.create')) {
            return Response::allow();
        }

        return Response::deny('Only the person who raised this request, or somebody who may approve requests, may close it.');
    }

    /*
     * The canvass (phase 24.2). Each reads only the row and the order it
     * became, which the request's page has already loaded.
     */

    /** Record a supplier's prices, or remove one, before the award. */
    public function quote(User $actor, PurchaseRequest $request): Response
    {
        if (! $actor->can('canvass.create')) {
            return Response::deny('You may not record supplier quotes.');
        }

        return $this->openToCanvass($request);
    }

    /** Choose the supplier for each item. */
    public function award(User $actor, PurchaseRequest $request): Response
    {
        if (! $actor->can('canvass.award')) {
            return Response::deny('You may not award a canvass.');
        }

        return $this->openToCanvass($request);
    }

    /** Take the award back, until an order is made from it. */
    public function reopenAward(User $actor, PurchaseRequest $request): Response
    {
        if (! $actor->can('canvass.award')) {
            return Response::deny('You may not reopen an award.');
        }

        if (! $request->isAwarded()) {
            return Response::deny('This request has no award to reopen.');
        }

        if ($request->isOrdered()) {
            return Response::deny('An order was already made from this request.');
        }

        return Response::allow();
    }

    /**
     * Make the orders from the award (phase 24.3): one for each winning
     * supplier. Placing an order commits the shop, so it needs
     * `purchase_orders.create`, as an order placed by hand does.
     */
    public function orderFromAward(User $actor, PurchaseRequest $request): Response
    {
        if (! $actor->can('purchase_orders.create')) {
            return Response::deny('You may not place purchase orders.');
        }

        if (! $request->isApproved() || ! $request->isAwarded()) {
            return Response::deny('Only an approved, awarded request is ordered from its award.');
        }

        if ($request->isOrdered()) {
            return Response::deny('This request was already ordered.');
        }

        return Response::allow();
    }

    protected function openToCanvass(PurchaseRequest $request): Response
    {
        if (! $request->isApproved()) {
            return Response::deny('Suppliers are asked for prices only on an approved request.');
        }

        if ($request->isAwarded()) {
            return Response::deny('This request has already been awarded.');
        }

        if ($request->isOrdered()) {
            return Response::deny('An order was already made from this request.');
        }

        return Response::allow();
    }

    protected function decide(User $actor, PurchaseRequest $request): Response
    {
        if (! $actor->can('purchase_requests.approve')) {
            return Response::deny('You may not approve or reject purchase requests.');
        }

        if (! $request->isPending()) {
            return Response::deny('This request is already '.strtolower($request->status->label()).'.');
        }

        if ($request->wasRaisedBy($actor) && ! PurchaseRequest::selfApprovalAllowed()) {
            return Response::deny('You raised this request, so somebody else must approve or reject it.');
        }

        return Response::allow();
    }
}
